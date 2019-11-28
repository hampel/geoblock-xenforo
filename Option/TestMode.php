<?php namespace Hampel\Geoblock\Option;

use XF\Option\AbstractOption;

class TestMode extends AbstractOption
{
	/**
	 * @return bool
	 */
	public static function get()
	{
		return (bool)\XF::options()->geoblockTestMode;
	}

	/**
	 * @return bool
	 */
	public static function isEnabled()
	{
		return self::get();
	}
}
