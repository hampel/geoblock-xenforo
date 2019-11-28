<?php namespace Hampel\Geoblock\Cron;

class CleanUp
{
	public static function runDailyCleanup()
	{
		$app = \XF::app();

		/** @var \Hampel\Geoblock\Repository\GeoIp $geoIpRepo */
		$geoIpRepo = $app->repository('Hampel\Geoblock:GeoIp');
		$geoIpRepo->pruneGeoCache();
	}
}
