<?php namespace Hampel\Geoblock\Option;

use XF\Option\AbstractOption;

class Approved extends AbstractOption
{
	public static function verifyOption(&$value, \XF\Entity\Option $option)
	{
		if (!empty($value))
		{
			Helper::sortStringList($value);
		}

		return true;
	}

	public static function get()
	{
		return Helper::stringListToArray(\XF::options()->geoblockApproved);
	}

	public static function inList($isocode)
	{
		return in_array($isocode, self::get());
	}
}
