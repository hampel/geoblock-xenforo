<?php namespace Hampel\Geoblock\Option;

use XF\Option\AbstractOption;

class CacheExpiry extends AbstractOption
{
	public static function get()
	{
		return \XF::options()->geoblockCacheExpiry;
	}
}
