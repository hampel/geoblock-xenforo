<?php namespace Hampel\Geoblock\Option;

use XF\Option\AbstractOption;

class AutoUpdate extends AbstractOption
{
	/**
	 * @return bool
	 */
	public static function get()
	{
		return (bool)\XF::options()->geoblockAutoUpdate;
	}

	/**
	 * @return bool
	 */
	public static function isEnabled()
	{
		return self::get();
	}
}
