<?php namespace Hampel\Geoblock\Cron;

class UpdateDatabase
{
	public static function runWeeklyDownload()
	{
		\XF::app()->get('geoblock')->updateDatabase();
	}
}
