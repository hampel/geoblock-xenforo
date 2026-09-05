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
```

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

## Legacy identity

`addon.json` declares `"legacy_addon_id": "GeoIP"` — this add-on upgrades in place from the XF 1.5
"GeoIP" add-on. `Setup.php` therefore carries two historical table renames
(`xf_ip_geo` → `xf_geoip_cache` → `xf_geoblock_cache`); do not collapse or renumber those steps.
`checkRequirements()` fails the install if `vendor/` is absent, since `composer_autoload` points at
it and a missing autoloader would fatal the forum.
