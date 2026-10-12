# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Scope

This is one XenForo add-on — `Hampel/Geoblock`, "Geoblock Registrations". It sits at
`src/addons/Hampel/Geoblock/` inside a XenForo install and is its own git repository; the
install around it is not.

Where the install has an `AGENTS.md` at its root, read it first — it carries the XF conventions,
the `_output`/`_data` boundary, the `cmd.php` command signatures and the per-add-on git layout.
This file covers only what is specific to Geoblock.

## Commands

Run from this directory — it is the git repo, so `git`, `composer` and `vendor/bin/phpunit` all
act on the right thing. `cmd.php` resolves the install from its own location rather than the
working directory, so the relative path below works unchanged.

```bash
composer install                                  # first time; vendor/ is gitignored
vendor/bin/phpunit                                # whole suite
vendor/bin/phpunit --filter SubcontainerTest      # one test class
vendor/bin/phpunit tests/Unit/IpGeoTest.php       # one file

php ../../../../cmd.php xf-dev:import --addon=Hampel/Geoblock
php ../../../../cmd.php xf-addon:export Hampel/Geoblock
php ../../../../cmd.php xf-addon:build-release Hampel/Geoblock

php ../../../../cmd.php geoblock:config             # settings, and never the license key
php ../../../../cmd.php geoblock:validate           # whether a lookup and a decision would work
php ../../../../cmd.php geoblock:update-database    # download the database now
```

`validate` exits 1 for a failure and 0 for a warning, with `--strict` turning a warning into 2 —
the shared contract, which is **not** the monitoring-plugin numbering. `xenforo-addon-validate` is
the authority; `Cli/RendersReport.php` is a copy of `Hampel/Monolog`'s with only the namespace
changed, so **fix that add-on's and take it again rather than improving this copy**.

`build.json` runs `composer install --no-dev` into the build output, then removes `phpunit.xml`,
`tests/`, `TESTING.md` and both Claude files before moving every remaining root `*.md` up to the
zip root. **The `rm` line has to stay before the `mv`** — after it, the files it names have
already been renamed and `rm -f` reports nothing.

## The PHP floor, and why `composer.json` looks contradictory

The declared floor is **7.2**, in three places that must agree: `addon.json` `require.php`,
`composer.json` `require.php`, and the README. It is set by the runtime dependencies —
`geoip2/geoip2` and `maxmind-db/reader` both want `>=7.2` — not by any syntax in this add-on.

`config.platform.php` is **8.3**, and that is deliberately *not* the floor. Composer applies the
platform pin to dev dependencies as well as runtime ones, and the test toolchain
(`hampel/xenforo-test-framework ^4.0`, PHPUnit 12) requires 8.3. Pinning to 7.2 does not merely
downgrade the tooling — the solve fails outright.

The cost of that is real: the solve can select **runtime** packages above the declared floor, and
they ship, and the gap the pin opens is now more than a full major version wide.
`maxmind/web-service-common` is pinned to `^0.9.0` for exactly this reason — 0.11 requires PHP
8.1, arrives transitively through `geoip2/geoip2`, and would land in the release zip of an add-on
claiming 7.2.

**`league/flysystem-memory` is capped at `^1.0` by XenForo, not by choice, and the lock does not
describe what runs.** XF 2.3.12 ships `league/flysystem` 1.0.70 in `src/vendor/`, and core's
autoloader is registered first, so core's copy wins every `League\Flysystem\*` class. The add-on's
own lock resolves 1.1.10 — measured, and it never loads; only `MemoryAdapter`, which core does not
ship, comes from the add-on's tree. It works because the adapter needs nothing beyond flysystem 1.x
interfaces. So moving the adapter to 3.x would pair it with a flysystem it never gets, and
`composer outdated` reporting that major as available is not an invitation.

So after any `composer update`, check that the shipped tree still matches what the add-on
promises — the `packages` array in `composer.lock`, not `packages-dev`, which never ships. The
command is in `TESTING.md`.

## Two unrelated things called "test"

The single most confusing thing in this repo:

- **`Test/`** (capital T, `Hampel\Geoblock\Test\*`) — **not** PHPUnit. These are the interactive
  diagnostic tools on the ACP *Checks and Tests* page. `AbstractTest` subclasses implement `run()`
  and accumulate `$messages`; they are constructed through the `geoblock.test` container factory
  registered in `Listener::appAdminSetup()`, which maps a short name to `\Hampel\Geoblock\Test\%s`
  and injects the `XF\App` as the first constructor argument.
- **`tests/`** (lowercase) — the PHPUnit suite, bootstrapped by `hampel/xenforo-test-framework`.

`Test/IsoDeciderTest::decider()` reimplements the block/moderate decision so an admin can probe a
country code without a live registration. **It is a second copy of the live logic** — the real
decisions are in `Spam\Checker\Geoblock::check()` and `XF\Service\User\Registration::geoblockCheckEu()`.
Change the policy in one and the other silently disagrees; update all three together.

## Lookup pipeline

Everything funnels through `SubContainer\Maxmind::getIpGeo($ip, $bypassCache = false)`, registered
as the `geoblock` sub-container in `Listener::appSetup()`. Order matters:

1. **Test mode** (`Option\TestMode`) short-circuits to a canned `IpGeo('10.0.0.1', 'XX', 'Test Mode')`.
2. `Repository\GeoIp::checkPrivateOrReserved()` — invalid IPs get iso `??`/`geoblock_invalid_address`;
   private/reserved ranges get `??`/`geoblock_private_or_reserved`. A **valid, routable** IP returns
   `null` here, which is the "keep going" signal.
3. Request-scoped in-memory cache (`$lookupCache` array on the repository).
4. DB cache — `xf_geoblock_cache`, via `Finder\GeoIp::getGeoIp()`.
5. The MaxMind `.mmdb` reader, but only `if ($this->isConfigured())`. A hit writes back to both caches.

Returning `null` means "we could not decide" and every caller treats that as **allow**.

**`isConfigured()` asks whether there is a database, not whether there is a license key**, and that
distinction is load-bearing: it gates the spam provider registration, step 5 above, and the ACP
diagnostics. The key is what *downloads* the database — `updateDatabase()` checks it there — so
requiring it to look anything up made the add-on inert on any forum holding a database it did not
download itself. Before 1.5.0 nothing could reach that state; the option to manage updates
externally creates it. `SubcontainerTest` pins both directions.

**`getIpGeo()` takes a dotted-quad string, never packed binary**, and the pipeline converts at the
database boundary itself — `Finder\GeoIp::getGeoIp()` and `Entity\GeoIp::verifyIp()` each call
`XF\Util\Ip::convertIpStringToBinary()` for the `varbinary(16)` column. A caller that converts
first gets no error: `checkPrivateOrReserved()` fails `filter_var()` on the raw bytes and returns
iso `??` at step 2, before any lookup, so the result looks like an ordinary undecidable address.
`geoblockCheckEu()` did exactly that from 2019 until 1.4.0, which made `isInEu()` false for every
address on earth and EU blocking a no-op in all eleven releases. `tests/Unit/RegistrationEuTest.php`
pins the contract.

`IpGeo` is a plain immutable value object (not an entity) and is the only currency between layers.
`IpGeo::newFromModel()` degrades through `country` → `registeredCountry` → continent-only (iso `01`,
"Anonymous Proxy") → `00` "Other Country", so the reader never throws for a thin record.

`Entity\GeoIp` stores `ip` as `varbinary(16)`, converting via `XF\Util\Ip` in both a `verifyIp()`
setter and an `getIp()` getter — never assign a dotted-quad to `ip` expecting it to round-trip
without going through the entity.

## Two block paths, and why

- **Denied / approved country lists** run as a XenForo **spam provider** (`Spam\Checker\Geoblock`),
  registered from `Listener::spamUserProviders()` — and only when `Maxmind::isConfigured()` is true,
  so an unconfigured install adds no provider at all. A provider can `logDecision('denied')` and XF
  handles the rest.
- **EU blocking** cannot go there, because it needs to reject a user outright. It lives in the
  `XF\Service\User\Registration` class extension, in `geoblockCheckEu()` called after
  `parent::checkForSpam()`. It bails if the user is already `rejected`, then calls
  `$user->rejectUser()` and writes its own decision under the type `Geoblock-EU`.

Approved list wins over everything, including EU.

## Options are wrapped, always

Every option has a class in `Option/` and business logic reads it there — `Denied::inList($iso)`,
`RejectEu::isEnabled()`, `DatabasePath::getCanonicalPath()` — never `\XF::options()->geoblockX`
inline. `Option\Helper` normalises the comma/whitespace country-code lists (uppercase, sorted) on
save and splits them on read. `DatabasePath` is the one that matters: it yields both a canonical
filesystem path (for the `GeoIp2\Database\Reader`, which needs a real path) and an
`internal-data://` abstracted path (for `$app->fs()`), and the tests swap both.

## MaxMind database updates

`Maxmind\DatabaseExtractor` downloads `GeoLite2-Country.tar.gz` with the licence key as a query
parameter, extracts it with `PharData`, and moves the `.mmdb` into `internal_data/` via the
abstracted filesystem. Two things bite:

- It calls `stream_wrapper_restore('phar')` first, because XenForo unregisters the phar wrapper.
  The `phar` extension is a declared requirement in `addon.json` for this reason.
- `recurseArchive()` descends one directory level to find the `.mmdb` inside the dated folder
  MaxMind ships.

**`Option\AutoUpdate` gates the weekly task, and turning it off has to stay silent.** With updates
managed externally there is no license key to find, so `updateDatabase()` would log one as missing
every week for a configuration somebody chose — `Cron\UpdateDatabase` returns before calling it.
What replaces that signal is `geoblock:validate`'s age check, which reports however the database is
updated; without it the option would trade a loud failure for a silent one, which is worse, because
a stale database answers confidently.

Triggered weekly by `Cron\UpdateDatabase`, and manually from ACP *Tools → Update MaxMind Database*
(`XF\Admin\Controller\Tools::actionHampelGeoblockUpdateMaxmind`). `Cron\CleanUp` prunes cache rows
older than `Option\CacheExpiry` days — note the entry and method are named "daily" but
`_output/cron_entries/geoblockDailyCleanup.json` actually runs it on the last day of each month.

## Tests

`tests/TestCase.php` sets `$addonsToLoad = ['Hampel/Geoblock']`, so the suite boots a real XF app
with only this add-on active.

**`hampel/xenforo-test-framework` is constrained to `^5.0` for a reason — do not relax it to reach
an older PHP.** With `$addonsToLoad` set, every release before 4.3.2 built its isolated extension
map from the raw `from_class` spelling, while XF 2.3 keys its own map on `XF::getClassForAlias()`.
Both of this add-on's extensions are registered on pre-2.3 names, which the `2020070` floor
requires, so on an older framework **neither is applied inside the suite** and a test of either
silently exercises XenForo's class instead. Nothing reports it; the suite is green either way. The
one-line check, which belongs in any test that covers an extension:

```php
$this->assertInstanceOf(
    \Hampel\Geoblock\XF\Service\User\Registration::class,
    $this->app->service(\XF\Service\User\Registration::class)
);
``` The framework's helpers do the heavy lifting: `mockRepository()`,
`swap()` (used to replace `database.canonical`/`database.abstracted` inside the sub-container),
`setOption()`, `fakesErrors()` + `assertExceptionLogged()`, `expectPhrase()`.

`tests/mock/country.mmdb` is a real cut-down MaxMind database — `mockMmdb()` points the reader at
it and mounts it as a `mock://` filesystem, so lookups for `8.8.8.8` exercise the genuine reader
with no network. The `*-country.json` fixtures feed `IpGeo::newFromModel()` directly.

`tests/mock/GeoLite2-Country.tar.gz` and `GeoLite2-NoDatabase.tar.gz` are pre-built archives, not
built at run time: `PharData` would otherwise need the phar wrapper restored a second time in the
same process, which emits a notice.

`DatabaseExtractorTest` is the awkward one. Its class docblock records why — the download goes
through `XF\Http\Reader::getUntrusted()`, which validates the URL before the client is reached
(so `setPrefix()` has to name a host that passes) and saves through Guzzle's `sink` (which
`fakesHttp()` honours and `fakesHttpByUrl()` silently does not). It also extracts to a real path
and reads back through `internal-data://`, so `swapFs('internal-data')` breaks it; the tests use
the real filesystem under a namespaced directory and clean up in `tearDown`.

`GeoIpCacheTest` uses `UsesDatabaseTransactions` against the real connection, because the `ip`
column is `varbinary(16)` with conversion in both directions and a mocked finder cannot tell you
that round trip is wrong.

## Two conventions that are easy to leave stale

**`Listener::adminApiRedaction()` declares `geoblockLicenseKey` as this add-on's only secret.**
Until an add-on declares, the Admin API withholds every text setting it has as a precaution — which
here hid the country lists and the database path while protecting the key only because its name
reads like a credential. Add or rename an option and the declaration goes stale silently;
`tests/Unit/SecretsDeclarationTest.php` is what catches that. `xenforo-addon-secrets` has the
procedure. It is **not** a dependency on the Admin API: an event no installed add-on defines is
never fired.

**Every `\XF::logError()` and `logException()` begins with `Geoblock: `**, so a row is attributable
without reading its trace. The commands get theirs from `RendersReport::errorLogPrefix()`, abstract
so a command that forgot it does not load; everywhere else it is written inline and
`tests/Unit/ErrorLogPrefixTest.php` scans the source for a call that lacks it.

## Versioning

`addon.json` carries `version_id` and `version_string`, and they must be bumped together. The
encoding is documented beside `XF::$versionId` in `src/XF.php` — `abbccde`, where `d` is the
stability (alpha 1, beta 3, RC 5, stable 7, PL 9) and `e` is the level *within* that stability.
So `1040070` / `"1.4.0"` is stable level 0, and `1050011` / `"1.5.0a1"` is alpha 1 of the next
minor.

**Open the development cycle before the work, not at release time.** A tree still carrying the last
released version names its build zip over that release: the builder derives the filename from
`version_string` alone and renames over the destination with no prompt and no backup. From a
released `1.4.0` the cycle opens at the smallest bump, `1040111` / `"1.4.1a1"`. This cycle was
then raised to a minor once its scope was clear, so from `1050011` the moves are:

    next alpha     1050012 / "1.5.0a2"
    release it     1050070 / "1.5.0"

**Take the smallest bump even when the work looks bigger.** Raising later is free; lowering is not
available at all, because `AddOn::canUpgrade()` requires the json `version_id` to be strictly
greater than the installed one, and `AddOnController::actionUpgrade()` offers users no override.
Both of the last two cycles are the worked example, in both directions: each opened at the next
patch alpha, each was raised to a minor once its scope was clear, and neither could then be
released as that patch — `1040170` is below the installed `1050011`, so the ACP would refuse it.
That is the asymmetry rather than a mistake: the raise cost nothing and the reverse is simply
unavailable.

Nothing enforces the id/string pairing: `version_id` drives upgrade ordering and `version_string`
only shows in the ACP and names the zip, so a mismatch survives unnoticed. `1030110` paired with
`"1.3.1a1"` is the easy slip — that id is alpha level **0**, because `e` is the level within the
stability, not a spare digit. If a bump is ever refused the number is wrong rather than the check;
`--force` only skips the downgrade guard and is not the answer.

**Keep spaces out of `version_string`.** `AddOn::prepareVersionForFilename()` strips
`[^a-z0-9-_. ]` but *keeps* spaces, so the XF-conventional `"1.0.0 Beta 2"` produces
`Hampel-Geoblock-1.0.0 Beta 2.zip` — this add-on shipped three of those in 2019. It is legal and
it breaks anything that splits a listing on whitespace. Use `1.3.0b1` or `1.3.0-beta1`. Note also
that `sort -V` places a suffixed version *after* the version it suffixes, the reverse of the order
they were built, so `tail -1` on a sorted release listing is only right when the newest release
carries no suffix.

**`addon.json` has no trailing newline, and that is the format** — no Hampel add-on's does. Do not
add one: `json_hash` comes from `hashTextFile`, which strips `\r` but not `\n`, so a hand-added
newline leaves the hash stale and the ACP reports the add-on as modified until
`xf-addon:sync-json` runs.

## Legacy identity

`addon.json` declares `"legacy_addon_id": "GeoIP"` — this add-on upgrades in place from the XF 1.5
"GeoIP" add-on. `Setup.php` therefore carries two historical table renames
(`xf_ip_geo` → `xf_geoip_cache` → `xf_geoblock_cache`); do not collapse or renumber those steps.
`checkRequirements()` fails the install if `vendor/` is absent, since `composer_autoload` points at
it and a missing autoloader would fatal the forum.
