<?php namespace Hampel\Geoblock\Option;

use XF\Option\AbstractOption;

class RejectEu extends AbstractOption
{
	/**
	 * @return bool
	 */
	public static function get()
	{
		return (bool)\XF::options()->geoblockRejectEu;
	}

	/**
	 * @return bool
	 */
	public static function isEnabled()
	{
		return self::get();
	}
}
