# Geoblock Registrations for XenForo

This XenForo addon provides GeoIP Geoblocking using Maxmind's GeoLite2 database.

This product includes GeoLite2 data created by MaxMind, available from 
[https://www.maxmind.com](https://www.maxmind.com)

Note that as of 30th Dec 2019, a Maxmind account with a free License key is required to download IP database updates - 
see [Significant Changes to Accessing and Using GeoLite2 Databases](https://blog.maxmind.com/2019/12/18/significant-changes-to-accessing-and-using-geolite2-databases/) 

* [Addon: Geoblock Registration](https://xenforo.com/community/resources/geoblock-registration.7516/)
* [Discussion and support: Geoblock Registration](https://xenforo.com/community/threads/geoblock-registration.172927/)

By [Simon Hampel](https://xenforo.com/community/members/sim.4264/).

## Requirements

 * PHP 7.4+
 * XenForo 2.2+
 * Phar PHP extension - used to extract the GeoLite2 database download
 * Maxmind account with license key for GeoLite2 Database downloads (free) - needed only if this
   addon does the downloading, which it does by default

## Database updates

A weekly cron task downloads the current GeoLite2 database using your license key. **An
out-of-date database still answers lookups, using stale data**, so keeping it current matters more
than it looks - which is why a missing license key is reported as a server error rather than
passed over.

To manage updates yourself instead, turn off *Automatically update the MaxMind database* in the
options and run this from your own cron task:

```bash
php cmd.php geoblock:update-database
```

With that option off no license key is needed and none is reported as missing. You can equally
place the database by any other means - rsync, a shared mount, your own script - as long as
something keeps it current. `geoblock:validate` reports the database's age however it is updated,
which is what catches an external update that has quietly stopped.

## Command line

```bash
php cmd.php geoblock:config             # what this forum is configured to block
php cmd.php geoblock:validate           # whether it can actually do it
php cmd.php geoblock:update-database    # download the current database now
```

`geoblock:config` prints settings only; the license key shows as set or not set, never its value.

`geoblock:validate` exits:

| | all fine | warnings only | any failure |
|---|---|---|---|
| `geoblock:validate` | 0 | 0 | 1 |
| `geoblock:validate --strict` | 0 | 2 | 1 |

A warning exits 0 without the flag, so `geoblock:validate && next-step` carries on past one. **This
is not the monitoring-plugin numbering**, where 1 is a warning and 2 is critical: here 1 always
means something failed, with the flag and without it.
 