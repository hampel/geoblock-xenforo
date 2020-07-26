<?php namespace Hampel\Geoblock\Option;

use XF\Option\AbstractOption;
use XF\Util\File;

class DatabasePath extends AbstractOption
{
	public static function get()
	{
		return \XF::options()->geoblockDatabasePath;
	}

	public static function getCanonicalPath()
	{
		$path = self::get();
		if (empty($path)) return;

		return File::canonicalizePath(sprintf("%s/%s", \XF::config('internalDataPath'), $path));
	}

	public static function getAbstractedPath()
	{
		$path = self::get();
		if (empty($path)) return;

		return sprintf("internal-data://%s", $path);
	}
}
