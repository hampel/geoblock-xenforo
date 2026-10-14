# CHANGELOG

## 1.6.0 (2026-10-14)

* **now requires PHP 7.4+.** The declaration said 7.2 and was wrong: `Cli/RendersReport.php`, which
  all three command line tools use, has typed properties, and those arrived in PHP 7.4. On PHP 7.2
  or 7.3 that file cannot be parsed, and because XenForo loads every addon's command classes merely
  to list them, `php cmd.php` stopped working for the **whole forum** - every addon's commands and
  XenForo's own - with nothing written to the server error log. Pages were unaffected. If you are on
  PHP 7.2 or 7.3, this release will refuse to install, which is the point: 1.5.0 should have
  refused too
* `geoblock:validate` now accepts `--unattended`. It does nothing here, because this command sends
  nothing and so has nothing to skip. It is accepted so that one command line works against every
  addon that offers a `validate`
* the Maxmind link on the addon's own admin page pointed at a page that has moved

## 1.5.0 (2026-10-12)

* new option *Automatically update the MaxMind database*, on by default. Turn it off to keep the
  database current by your own means - an external cron task running the new
  `geoblock:update-database` command, or any other mechanism that puts a current database file in
  place. With it off, no license key is needed and none is reported as missing
* bugfix: a country lookup required a license key as well as a database. The key is what
  *downloads* the database, so a forum holding one the addon had not downloaded itself registered
  no spam provider, never reached a lookup, and reported nothing about either. No earlier release
  could reach that state - the new option is what creates it
* three new command line tools: `geoblock:config` shows what this forum is configured to block,
  `geoblock:validate` checks that it can actually do it, and `geoblock:update-database` downloads
  the database now. **`geoblock:validate` warns when the database is more than 30 days old,
  however it is updated** - which is what catches an external update that has quietly stopped
* every row this addon writes to the server error log now begins with `Geoblock: `
* the license key is declared as this addon's only secret, so a tool reading the forum's
  configuration over an API can report the other settings without exposing it

## 1.4.0 (2026-09-27)

* bugfix: EU registration blocking has never worked. The visitor's IP was converted to binary
  before the country lookup, which takes a dotted-quad string, so every address came back
  unplaceable and no EU registration was ever rejected. Present in every release since 1.0.0.
  **If you have "Reject EU registrations" enabled, EU registrations will start being rejected
  after this upgrade** - that is the option working for the first time, not a new behaviour
* bugfix: the *Checks and Tests* tools logged two PHP warnings, one of them a deprecation on PHP
  8.1 and above, when submitted with a field missing
* the admin-panel message shown when a MaxMind database update fails is now a phrase, so it can be
  translated

## 1.3.0 (2026-09-05)

* bugfix: a failed database download could silently reinstall the previous database - PHP caches
  `PharData` objects by path and the download always used the same temporary filename, so a second
  update within one process re-extracted the first archive and reported success
* bugfix: the "not configured" message in the admin control panel linked to `#geoblockDatabaseUrl`,
  an option removed in 1.1.0, so the link landed on the option group rather than the field
* run `enqueuePostUpgradeCleanUp()` on upgrade under XenForo 2.3 and above
* now requires XenForo 2.2+
* now requires PHP 7.2+ - the declaration said 7.0, but `geoip2/geoip2` and `maxmind-db/reader`
  have always needed 7.2
* the shipped dependency tree is now held within that floor - `maxmind/web-service-common` is
  capped at `^0.9.0`, since 0.11 requires PHP 8.1 and arrives transitively through `geoip2/geoip2`
* latest composer dependencies
* development files no longer ship inside the release zip

## 1.2.2 (2024-10-16)

* assert admin permission on tools
* latest composer dependencies

## 1.2.1 (2024-07-10)

* latest composer dependencies - no changes to addon

## 1.2.0 (2024-03-19)

* bugfix: don't try to uppercase a null iso_code
* php 8 compatibility fix: don't set a default parameter before non-default subsequent parameters
* slight re-arrange to order of code logic for EU checking - no point checking anything related to EU blocking if we 
  don't have EU blocking enabled
* rename table to xf_geoblock_cache to adhere to resource standards
* addon now requires php 7+
* explicitly check for phar extension on install
* add legacy upgrade from XF 1.5 to Setup
* bugfix: wrong link to database update tool in test tool error message

## 1.1.2 (2020-07-27)

* check that vendor folder exists to prevent breaking forum if we somehow didn't run composer install
* latest vendor dependencies
* use mock mmdb database for unit testing
* make subcontainer more testable by allowing paths to be replaced at runtime

## 1.1.1 (2020-01-02)

 * bugfix - was using hard coded URL components rather than class properties

## 1.1.0 (2020-01-02)

 * support for new license key URLs for maxmind downloads - see https://blog.maxmind.com/2019/12/18/significant-changes-to-accessing-and-using-geolite2-databases/ 

## 1.0.0 (2019-11-28)

 * initial working version
