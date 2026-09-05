CHANGELOG
=========

1.3.0 (2026-09-05)
------------------

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

1.2.2 (2024-10-16)
------------------

* assert admin permission on tools
* latest composer dependencies

1.2.1 (2024-07-10)
------------------

* latest composer dependencies - no changes to addon

1.2.0 (2024-03-19)
------------------

* bugfix: don't try to uppercase a null iso_code
* php 8 compatibility fix: don't set a default parameter before non-default subsequent parameters
* slight re-arrange to order of code logic for EU checking - no point checking anything related to EU blocking if we 
  don't have EU blocking enabled
* rename table to xf_geoblock_cache to adhere to resource standards
* addon now requires php 7+
* explicitly check for phar extension on install
* add legacy upgrade from XF 1.5 to Setup
* bugfix: wrong link to database update tool in test tool error message

1.1.2 (2020-07-27)
------------------

* check that vendor folder exists to prevent breaking forum if we somehow didn't run composer install
* latest vendor dependencies
* use mock mmdb database for unit testing
* make subcontainer more testable by allowing paths to be replaced at runtime

1.1.1 (2020-01-02)
------------------

 * bugfix - was using hard coded URL components rather than class properties

1.1.0 (2020-01-02)
------------------

 * support for new license key URLs for maxmind downloads - see https://blog.maxmind.com/2019/12/18/significant-changes-to-accessing-and-using-geolite2-databases/ 

1.0.0 (2019-11-28)
------------------

 * initial working version
