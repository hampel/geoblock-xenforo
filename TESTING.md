# TESTING

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
* `spam_user_providers` — registers the spam provider, but **only when the add-on is configured**,
  which since 1.5.0 means a database is present rather than a license key being set. An
  unconfigured install adds no provider at all, which is deliberate and is the first thing to check
  when the country lists appear to do nothing.
* `hampel_admin_api_redaction` — declares the license key as this add-on's only secret. The event
  is defined by another add-on, so on a forum without it this listener is never fired and costs
  nothing; it is not a dependency.

**Other artifacts**

* Admin navigation: two entries under *Checks and Tests*, both gated on the `option` permission.
* Cron: a weekly database download and a monthly cache prune. Note the cleanup entry and method
  are named "daily" but the rule runs on the last day of the month.
* Options: ten, all under the `geoblock` group. Schema: one table, `xf_geoblock_cache`.
* CLI: `geoblock:config`, `geoblock:validate` and `geoblock:update-database`. XenForo loads every
  add-on's command classes just to build `cmd.php list`, so one that cannot load takes the whole
  CLI down on that forum - not only this add-on's commands.
* No template modifications — the two templates are the add-on's own ACP pages.

## Fragile points

* **The lookup is a five-step fallback chain** — test mode, private/reserved, in-memory cache, DB
  cache, MaxMind reader. A step returning `null` means "keep going"; the final `null` means "could
  not decide", and every caller treats that as **allow**. So a broken lookup fails open, silently.
  Registrations continue to be accepted and nothing appears in the error log.
* **`isConfigured()` short-circuits on the database file.** Everything downstream of it — the spam
  provider registration, the reader, the build date the ACP shows — is inert without one, and the
  symptom is silence rather than an error. It asked for the licence key as well until 1.5.0, which
  made the add-on inert on any forum holding a database it had not downloaded itself; the key now
  gates only `updateDatabase()`, which is what downloads one.
* **EU blocking is not in the spam provider.** A provider cannot reject outright, so the EU path
  lives in the `Registration` class extension and writes its own decision under a separate type.
  Changing the block policy means changing it in three places: the spam provider, the registration
  extension, and the ACP decider diagnostic, which reimplements the logic so an admin can probe a
  country code without a live registration. They drift silently.
* **`getIpGeo()` takes a dotted-quad string, never packed binary.** Conversion happens downstream,
  in `Finder\GeoIp::getGeoIp()` and `Entity\GeoIp::verifyIp()`, where the `varbinary(16)` column
  needs it. Convert before calling and there is no error to notice:
  `Repository\GeoIp::checkPrivateOrReserved()` fails `filter_var()` on the raw bytes and returns
  iso `??` at step 2, before any lookup, which is indistinguishable from an address the database
  genuinely cannot place. `geoblockCheckEu()` did this from 2019 until 1.4.0, so `isInEu()` was
  false for every address and EU blocking silently did nothing in all eleven releases. Both ACP
  diagnostics masked it — the lookup tool passes the admin's own string and so reports EU
  membership correctly, and the decider tool takes "in EU" from a checkbox rather than a lookup, so
  between them they look like end-to-end proof while neither executes the line.

* **An external database update fails silently, and only `geoblock:validate` reports it.** With
  *Automatically update the MaxMind database* off, the weekly task returns without doing anything
  and without logging - which is correct, because a missing license key is then a configuration
  somebody chose. What that removes is the alarm. If whatever took the job over stops, the database
  ages and lookups keep answering confidently with old data; nothing in the ACP judges the build
  date, and no error is written. `geoblock:validate` warns past 30 days however the database is
  updated, so **a forum that manages its own updates needs that command on a schedule**, not just
  the update itself.

* **A phrase body is trusted; its parameters are escaped.** Verified by rendering
  `admin:hampel_geoblock_tools_test` on 2026-09-27. `geoblock_error_configuration_required` and
  `geoblock_test_isocode_explain` both contain markup and it renders live, so `{{ $message.message }}`
  is not escaping the phrase itself — which is what makes those two work. Interpolated parameters
  *are* escaped, so `geoblock_not_found`'s `{ip}`, which comes from an admin form field, renders
  `&lt;script&gt;` rather than a live tag. Both halves matter: adding markup to a phrase is safe,
  and interpolating user input is safe, but putting markup *through* a parameter will not work and
  should not be made to.

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

`vendor/bin/phpunit` — 95 tests across two suites. Requires `composer install` first; `vendor/`
is not committed.

The suite boots a real XenForo app with only this add-on loaded, and covers the value object, the
repository, the spam provider's decision table, the sub-container's fallback chain, the database
update path, the cache table against a real connection, all three CLI commands through
`CommandTester`, the secrets declaration against `_output/options/`, and a source scan for an error
log call that lacks the add-on's prefix. `tests/mock/country.mmdb` is a real
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

* Every `_output/*.json` artifact carries every key its handler exports. XenForo adds columns
  between releases, and an artifact written by an older XF simply lacks the new key — nothing
  reports it, because `import()` does `bulkSet($json)` and an absent key leaves the entity at its
  default. It surfaces at the worst moment instead: `xf-addon:build-release` runs
  `xf-addon:export` internally, so the stale files turn dirty in the middle of cutting a release.
  Run this from the add-on directory (the fence is unindented so the heredoc terminator works):

```bash
python3 - <<'EOF'
import json, os, re, glob
h = {}
for f in glob.glob('../../../../src/XF/DevelopmentOutput/*.php'):
    s = open(f).read()
    m = re.search(r"getTypeDir\(\)\s*\{\s*return '([a-z_]+)'", s, re.S)
    k = re.search(r"\$keys = \[(.*?)\];", s, re.S)
    if m and k: h[m.group(1)] = re.findall(r"'([a-z_0-9]+)'", k.group(1))
for d in sorted(os.listdir('_output')):
    p = os.path.join('_output', d)
    if not os.path.isdir(p) or d not in h: continue
    for fn in sorted(os.listdir(p)):
        if not fn.endswith('.json') or fn == '_metadata.json': continue
        miss = [k for k in h[d] if k not in json.load(open(os.path.join(p, fn)))]
        if miss: print(f"{d}/{fn}: missing {miss}")
print('checked')
EOF
```

  Keys a handler adds *after* `pullEntityKeys()` are not in its `$keys` list, so they will read as
  "extra" if you also print those — `options` gains `default_value` and `relations` that way.
  Extras are expected; missing keys are the finding.

  **Fix by re-exporting, never by hand.** `DevelopmentOutput::export($entity)` is the call
  `DevOutputWritable::postSave()` makes, so the file and its `_metadata.json` hash come out
  canonical together:

  ```bash
  php -r '$r = "../../../.."; require "$r/src/XF.php"; XF::start($r);
    $app = XF::setupApp("XF\App"); $out = $app->developmentOutput();
    foreach ([XF\Finder\OptionGroupFinder::class, XF\Finder\OptionFinder::class,
              XF\Finder\AdminNavigationFinder::class] as $finder) {
      foreach (XF::finder($finder)->where("addon_id", "Hampel/Geoblock")->fetch() as $e) {
        $out->export($e);
      }
    }'
  ```

  Hand-writing a missing key, or copying the shape from a sibling artifact, is how this defect
  propagates: a sibling is only as current as the last thing that wrote it, so copying its shape
  reproduces whatever it was missing. Note this is **not** `xf-dev:export`, which rewrites the
  filesystem from the database for every add-on.

  The generated hint files drift the same way and are not JSON, so the check above misses them —
  compare `extension_hint.php` and `option_hint.php` against a recently-exported sibling. The JSON
  artifacts end with **no** trailing newline and the PHP hint files **do**; both are the
  generators' own output, not drift.

* `_output/*/_metadata.json` agrees with what is on disk in **both** directions — an entry with no
  file, and a file with no entry. The second is the more common drift and no XenForo command
  reports it; `xf-dev:rebuild-metadata` skips files that have no entry, so it looks like a fix and
  is not.
* `xf-dev:unused-phrase-finder --addon Hampel/Geoblock` — **read the list, never act on it, and
  never pass `--delete-all` here.** It reports nine phrases and **all nine are false positives**,
  because the finder needs a literal `\XF::phrase('name')` and this add-on reaches them two other
  ways: the six `geoblock_decision_*` are composed as `\XF::phrase("geoblock_decision_{$decision}")`
  in the ACP decider, and `geoblock_registration_denied_list`,
  `geoblock_registration_neither_allowed_nor_denied` and `geoblock_registration_in_eu` are passed to
  `logDetail()`, which XenForo resolves as a phrase internally and which is not an `XF::phrase()`
  call at all. Deleting them would empty the decider's output and the spam-log detail text.

  The documented guard grep — the one that lists calls the scanner cannot see — returns **nothing**
  here, so it does not catch this. Its exclusion pattern accepts any quoted run without spaces,
  which an interpolated double-quoted string satisfies. Read the finder's list against the code
  instead.

* Every package in `composer.lock`'s `packages` array satisfies the declared PHP floor. The
  platform pin is above the floor by necessity (see `CLAUDE.md`), so a `composer update` can
  quietly select a runtime dependency the add-on's own declaration cannot run:

  ```bash
  python3 -c "
  import json; l=json.load(open('composer.lock'))
  for p in l['packages']: print(f\"  {p['name']:35} {p.get('require',{}).get('php','-')}\")"
  ```

* **The add-on's own source parses at the declared floor.** The check above reads what the
  dependencies ask for, which is the wrong direction: the floor here is set by this add-on's
  syntax, not by its tree. Lint every shipped file with a PHP that *is* the floor — a container is
  the usual way, since a development box rarely has one that old installed:

  **Against an unzipped release**, which is the form that counts — it is what a forum receives, and
  its `vendor/` is the `--no-dev` tree that actually ships:

  ```bash
  docker run --rm -v "$z":/a:ro php:7.4-cli \
    sh -c 'cd /a && n=0; for f in $(find . -name "*.php"); do php -l "$f" >/dev/null || { echo "$f"; n=$((n+1)); }; done; echo "failures: $n"'
  ```

  **Against the working copy**, `vendor/` and `tests/` have to be pruned or the output is useless:
  the dev tree is solved against the 8.3 platform pin, so PHPUnit and the test framework are
  hundreds of parse errors at the floor, and none of them ships.

  ```bash
  docker run --rm -v "$PWD":/a:ro php:7.4-cli \
    sh -c 'cd /a && n=0; for f in $(find . \( -path ./vendor -o -path ./tests \) -prune -o -name "*.php" -print); do php -l "$f" >/dev/null || { echo "$f"; n=$((n+1)); }; done; echo "failures: $n"'
  ```

  Both forms were run at 7.4 when this was written: 0 failures each. The release form at 7.2 and
  at 7.3 reported exactly one file, which is how the defect below was pinned down.

  **Below the floor the symptom is not a broken add-on but a dead `cmd.php` for the whole forum**,
  because XenForo calls `class_exists()` on every add-on's command classes before any try/catch
  exists — so one unparseable file takes out every command on the forum, including XenForo's own,
  and writes nothing to the server error log. `CLAUDE.md` has the mechanism.

  **Measured on a real forum rather than reasoned about** — XenForo 2.2.19 Patch 2 on PHP 7.2.34,
  development off, installing the published 1.5.0 by zip. It is worse than the mechanism alone
  predicts, and the extra part is what a forum owner would actually report:

  | | |
  |---|---|
  | the add-on install | exits 0 and leaves `xf_addon.is_processing = 1` — it never completes |
  | every `cmd.php` command, this add-on's and XenForo's | *"The site is currently being upgraded. Please check back later."* |
  | `class_exists()` on a command class | dies with the parse error, so `registerCommands()` cannot finish |
  | `index.php` and `admin.php` | 200, both — the web app boots and the forum looks healthy |
  | `xf_error_log` | 0 rows |

  So recovery is through the ACP, because the CLI is the thing that is gone. From 1.6.0 the same
  install is refused instead: *"Geoblock Registrations requires PHP 7.4+."*, exit 1, nothing
  written and no add-on row created.

  This is here because the three declarations were checked against *each other* and recorded as
  clean, while the report trait had required 7.4 since the day it was copied in. 1.5.0 shipped and
  was published that way; 1.5.1 raised the floor. Agreement between declarations is not evidence.

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

* **Registration end to end.** The provider's decision paths — approved, denied-and-moderated,
  denied-and-rejected, moderate-others — are unit tested, and the EU path separately in
  `RegistrationEuTest` against the `Registration` extension, which is where it lives rather than in
  the provider. What no unit test settles is whether a real registration is actually blocked: that
  depends on XenForo's spam pipeline running the provider at all, and on the extension being in the
  chain on a forum where other add-ons extend the same service. Register through the browser with a
  country in each list, and once with the EU option on.
* **The upgrade path.** Install the previous release on a second install — `require.XF` is
  `2020070`, so any XenForo 2.2 or later qualifies — then upgrade it from a built zip. A
  `Setup.php` step that works on a fresh install and fails on an upgrade is the classic failure
  and no amount of testing the working copy finds it.

  **On a development install this silently tests the wrong path, and a second install does not
  fix that by itself.** `xf-addon:upgrade` calls `AddOnActionTrait::importAddOnData()`, which
  checks `DevelopmentOutput::isAddOnOutputAvailable()`. That returns false only if the add-on is
  skipped, **or** `development.enabled` is off, **or** `_output/` is missing. `_output/` is
  committed here, so on any install with development mode on — which a second development install
  also has — it runs `xf-dev:import` against the working copy instead of queueing the `AddOnData`
  job a real upgrade runs. `Setup.php`'s steps execute either way, so the run completes and looks
  correct while never exercising what a user does.

  **The way out is the skip list**, on whichever install you test on. Setting the key *replaces*
  the `['XF', 'XF*']` default rather than adding to it, so carry both across:

  ```php
  $config['development']['skipAddOns'] = ['XF', 'XF*', 'Hampel/Geoblock'];
  ```

  **Confirm it from the output, not from the config.** The real path prints `Importing add-on
  data`; the wrong one prints an `xf-dev:import` invocation. Read that line before trusting a
  green upgrade — it is the whole point of the exercise.

  This add-on has real code in that range: `upgrade1000070Step1` and `upgrade1020070Step1` are
  both gated, so "no steps run" is not the answer here the way it is for an add-on with none. The
  legacy XF 1.5 path (the `xf_ip_geo` table rename) needs a database that has actually been
  through it, which is the hardest fixture here to produce.
* **A real MaxMind download.** Needs a live licence key and reaches the network. The ACP tool
  reports success or failure, but only a human can decide whether to spend a download on a test.
* **Judgement on the ACP pages** — whether the diagnostic output reads clearly and the phrases make
  sense in context.

**Not human, and no longer blocked on tooling.** Whether a phrase resolved or is showing a raw
key, whether an admin without the `option` permission is refused, and what the page title is set to
are all assertable now that the suite is on `hampel/xenforo-test-framework` `^5.0`: `dispatch()`
runs the real route through `preDispatch()`, `renderTemplate()` and `renderReply()` return the HTML,
`assertNoTemplateErrors()` says whether the render was clean, and `pageParam()` reads what
`<xf:title>` set. None of it is written yet — these are the next tests worth adding, not human
checks. Two cautions from the framework's own notes: an admin route dispatched without `is_admin`
throws rather than returning 403, so the control case is `actingAsMember(['is_admin' => true])` with
no admin permissions granted; and `assertSee()` passing does not mean the render was clean, which is
what `assertNoTemplateErrors()` is for.

**Page furniture stays human.** Navigation, header and footer come from XenForo's own `Admin` app
classes rather than from this add-on's templates, so whether the two nav entries actually appear is
still something to look at.
