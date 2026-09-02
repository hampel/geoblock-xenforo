# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Scope

This is one XenForo add-on (`Hampel/Geoblock`, "Geoblock Registrations") inside the install at
`/srv/www/xenforo23.local`. Read that install's `AGENTS.md` first — it carries the XF conventions,
the `_output`/`_data` boundary, the `cmd.php` command signatures and the per-add-on git layout.
This file covers only what is specific to Geoblock.

## Commands

Run from this directory. `cmd.php` resolves the install from its own location, so the absolute path
works from here.

```bash
composer install                                        # first time; vendor/ is gitignored
vendor/bin/phpunit                                      # whole suite (43 tests)
vendor/bin/phpunit --filter SubcontainerTest            # one test class
vendor/bin/phpunit tests/Unit/IpGeoTest.php             # one file

php /srv/www/xenforo23.local/cmd.php xf-dev:import --addon=Hampel/Geoblock
php /srv/www/xenforo23.local/cmd.php xf-addon:export Hampel/Geoblock
php /srv/www/xenforo23.local/cmd.php xf-addon:build-release Hampel/Geoblock
```

`build.json` runs `composer install --no-dev` into the build output and strips `phpunit.xml`,
`tests/` and `TESTING.md`, then moves every root `*.md` out of the upload directory — so dev
dependencies and this file never reach a release zip.

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

Triggered weekly by `Cron\UpdateDatabase`, and manually from ACP *Tools → Update MaxMind Database*
(`XF\Admin\Controller\Tools::actionHampelGeoblockUpdateMaxmind`). `Cron\CleanUp` prunes cache rows
older than `Option\CacheExpiry` days — note the entry and method are named "daily" but
`_output/cron_entries/geoblockDailyCleanup.json` actually runs it on the last day of each month.

## Tests

`tests/TestCase.php` sets `$addonsToLoad = ['Hampel/Geoblock']`, so the suite boots a real XF app
with only this add-on active. The framework's helpers do the heavy lifting: `mockRepository()`,
`swap()` (used to replace `database.canonical`/`database.abstracted` inside the sub-container),
`setOption()`, `fakesErrors()` + `assertExceptionLogged()`, `expectPhrase()`.

`tests/mock/country.mmdb` is a real cut-down MaxMind database — `mockMmdb()` points the reader at
it and mounts it as a `mock://` filesystem, so lookups for `8.8.8.8` exercise the genuine reader
with no network. The `*-country.json` fixtures feed `IpGeo::newFromModel()` directly.

`Maxmind\DatabaseExtractor` has no coverage (there is a `// TODO` saying so).

## Legacy identity

`addon.json` declares `"legacy_addon_id": "GeoIP"` — this add-on upgrades in place from the XF 1.5
"GeoIP" add-on. `Setup.php` therefore carries two historical table renames
(`xf_ip_geo` → `xf_geoip_cache` → `xf_geoblock_cache`); do not collapse or renumber those steps.
`checkRequirements()` fails the install if `vendor/` is absent, since `composer_autoload` points at
it and a missing autoloader would fatal the forum.
