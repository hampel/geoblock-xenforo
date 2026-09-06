TESTING
=======

What this add-on touches, what breaks quietly, and which checks a machine can settle.

## Surfaces

**Class extensions**

* `XF\Admin\Controller\Tools` — adds two ACP actions, `hampelGeoblockUpdateMaxmind` (download and
  extract the GeoLite2 database) and `hampelGeoblockTest` (run a diagnostic). Both assert the
  `option` admin permission. This is a commonly extended controller, so on a real forum this
  add-on is usually one link in a chain of extensions — a conflict shows up as one add-on's
  actions disappearing rather than as an error.
* `XF\Service\User\Registration` — extends `checkForSpam()` to add the EU rejection. This runs on
  every registration, so a fatal here takes registration down entirely.

**Code event listeners**

* `app_setup` — registers the `geoblock` sub-container.
* `app_admin_setup` — registers the `geoblock.test` factory the ACP diagnostics resolve through.
* `spam_user_providers` — registers the spam provider, but **only when the add-on is configured**.
  An unconfigured install adds no provider at all, which is deliberate and is the first thing to
  check when the country lists appear to do nothing.

**Other artifacts**

* Admin navigation: two entries under *Checks and Tests*, both gated on the `option` permission.
* Cron: a weekly database download and a monthly cache prune. Note the cleanup entry and method
  are named "daily" but the rule runs on the last day of the month.
* Options: nine, all under the `geoblock` group. Schema: one table, `xf_geoblock_cache`.
* No template modifications — the two templates are the add-on's own ACP pages.

## Fragile points

* **The lookup is a five-step fallback chain** — test mode, private/reserved, in-memory cache, DB
  cache, MaxMind reader. A step returning `null` means "keep going"; the final `null` means "could
  not decide", and every caller treats that as **allow**. So a broken lookup fails open, silently.
  Registrations continue to be accepted and nothing appears in the error log.
* **`isConfigured()` short-circuits on the licence key.** Anything downstream of it — the spam
  provider registration, the database update tool, the reader — is inert without one, and the
  symptom is silence rather than an error.
* **EU blocking is not in the spam provider.** A provider cannot reject outright, so the EU path
  lives in the `Registration` class extension and writes its own decision under a separate type.
  Changing the block policy means changing it in three places: the spam provider, the registration
  extension, and the ACP decider diagnostic, which reimplements the logic so an admin can probe a
  country code without a live registration. They drift silently.
* **The database extractor restores the phar stream wrapper** that XenForo unregisters. If that
  call is lost, the download succeeds and the extraction fails.
* **The extractor descends one directory level** to find the `.mmdb` inside the dated folder
  MaxMind ships. A change to that archive layout breaks the update with the download still
  reported as successful.
* **The download's temp filename must stay unique per call.** PHP caches `Phar` objects by path
  for the life of the process, so a fixed name means a second update in one process re-extracts
  the *first* archive — silently, and reporting success. That shipped from 2019 until September
  2026, and was found only because four extractor tests passed alone and failed together — which
  is evidence rather than flakiness.
* **The release zip must contain `vendor/`.** The build's exec steps cannot fail the build — the
  exit status is discarded — so a Composer failure produces a zip with no dependencies in it, and
  `Setup.php::checkRequirements()` is the only thing standing between that and a broken forum.

## Automated

`vendor/bin/phpunit` — 68 tests across two suites. Requires `composer install` first; `vendor/`
is not committed.

The suite boots a real XenForo app with only this add-on loaded, and covers the value object, the
repository, the spam provider's decision table, the sub-container's fallback chain, the database
update path, and the cache table against a real connection. `tests/mock/country.mmdb` is a real
cut-down MaxMind database, so the lookup tests exercise the genuine reader with no network, and
`tests/mock/GeoLite2-*.tar.gz` are real archives, so the extractor tests exercise the genuine
`PharData` path.

Also settled by machine:

* `xf-dev:class-lint` — all classes load. Note it takes no add-on option and lints the whole
  install, so a failure it reports may belong elsewhere.
* Every option key named in code or templates has a file in `_output/options/`. `setOption()` and
  `\XF::options()->x` both fail silently on an unknown key, so a renamed option leaves working
  tests and a broken feature.
* Every `_output/class_extensions/*.json` `to_class` exists on disk and is listed in
  `extension_hint.php`, and every `from_class` is spelled the way the declared XF floor requires.
  XenForo 2.3 renamed core classes and aliases the old names forward; 2.2 has no such mechanism,
  so the **old** spellings are the only ones that work on both. Check before "modernising" one:

  ```bash
  php -r '$r = "../../../.."; require "$r/src/XF.php"; XF::start($r); XF::setupApp("XF\App");
    echo XF::getClassForAlias("XF\Service\User\Registration"), "\n";'
  ```

  An alias resolving here proves only that 2.3 forwards the name — it says nothing about 2.2,
  which has no `getClassForAlias` at all.

* `_output/*/_metadata.json` agrees with what is on disk in **both** directions — an entry with no
  file, and a file with no entry. The second is the more common drift and no XenForo command
  reports it; `xf-dev:rebuild-metadata` skips files that have no entry, so it looks like a fix and
  is not.
* Every package in `composer.lock`'s `packages` array satisfies the declared PHP floor. The
  platform pin is above the floor by necessity (see `CLAUDE.md`), so a `composer update` can
  quietly select a runtime dependency the add-on's own declaration cannot run:

  ```bash
  python3 -c "
  import json; l=json.load(open('composer.lock'))
  for p in l['packages']: print(f\"  {p['name']:35} {p.get('require',{}).get('php','-')}\")"
  ```

* Build the release and unzip it: `vendor/` present, and `tests/`, `phpunit.xml`, `TESTING.md`
  and the Claude files absent. Read the listing with `unzip -Z1`, not `unzip -l | awk`, which
  truncates any path containing a space. Ignore `vendor/**/tests/` hits — upstream packages ship
  their own. Sweep for dotfiles with the anchored pattern, which catches the zip **root** as well
  as nested directories — the root is where `build.json`'s `rm` line operates:

  ```bash
  unzip -Z1 "$z" | grep -E '(^|/)\.[^/]*'          # want no output
  ```

  **The builder names the zip from `addon.json`'s version string alone and renames over whatever
  is already there — no prompt, no backup.** On a branch still carrying a released version string
  that destination is the previous release. Bump to an alpha first, or move `_releases/` aside.

**Not covered:** an HTTP error status from MaxMind. `getUntrusted()` reports only transport
failures, so a bad licence key answers with a 401 whose body is written to the temp file, and the
failure surfaces later as `PharData` being unable to read it. `DatabaseExtractorTest` pins that as
current behaviour rather than endorsing it — what gets logged says nothing about the licence key.

## Needs a human

* **Registration end to end.** The decision paths — approved, denied-and-moderated,
  denied-and-rejected, EU-rejected, moderate-others — are unit tested against the provider, but
  whether a real registration is actually blocked depends on XenForo's spam pipeline running the
  provider at all. Register through the browser with a country in each list.
* **The upgrade path — on a second install, not the development one.** Install the previous
  release on a separate XenForo 2.2 install (`require.XF` is `2020070`, so 2.2 qualifies), then
  upgrade it from a built zip. A `Setup.php` step that works on a fresh install and fails on an
  upgrade is the classic failure and no amount of testing the working copy finds it.

  **Doing it on the development install tests a different code path and still passes.**
  `xf-addon:upgrade` calls `AddOnActionTrait::importAddOnData()`, which checks
  `isAddOnOutputAvailable()` first: with `_output/` present — it is committed here — it runs
  `xf-dev:import` against the working copy instead of queueing the `AddOnData` job a real user's
  upgrade runs. `Setup.php`'s steps still execute, so the run completes and looks correct while
  never exercising what a user does.

  This add-on has real code in that range: `upgrade1000070Step1` and `upgrade1020070Step1` are
  both gated, so "no steps run" is not the answer here the way it is for an add-on with none. The
  legacy XF 1.5 path (the `xf_ip_geo` table rename) needs a database that has actually been
  through it, which is the hardest fixture here to produce.
* **A real MaxMind download.** Needs a live licence key and reaches the network. The ACP tool
  reports success or failure, but only a human can decide whether to spend a download on a test.
* **Judgement on the ACP pages** — whether the diagnostic output reads clearly and the phrases make
  sense in context.

Not human, despite looking it: whether the nav entries appear, whether a phrase resolved or is
showing a raw key, and whether an admin without the `option` permission is refused. Those are
assertions about server-rendered HTML and they only sit here because dispatching a route in a test
is not yet set up for this add-on.
