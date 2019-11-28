<?php namespace Hampel\Geoblock\Option;

use XF\Option\AbstractOption;

class ModerateOthers extends AbstractOption
{
	/**
	 * @return bool
	 */
	public static function get()
	{
		return (bool)\XF::options()->geoblockModerateOthers;
	}

	/**
	 * @return bool
	 */
	public static function isEnabled()
	{
		return self::get();
	}
}
