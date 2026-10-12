<?php namespace Hampel\Geoblock\Cron;

use Hampel\Geoblock\Option\AutoUpdate;

class UpdateDatabase
{
	public static function runWeeklyDownload()
	{
		// An administrator who has turned automatic updates off has taken the job elsewhere, so
		// there is nothing here to report: updateDatabase() logs a missing license key as a server
		// error, and doing that weekly for a configuration somebody chose is noise. The database
		// ageing is still reported either way - geoblock:validate warns on it however it is updated,
		// which is what stops an external update failing silently.
		if (!AutoUpdate::isEnabled())
		{
			return;
		}

		\XF::app()->get('geoblock')->updateDatabase();
	}
}
