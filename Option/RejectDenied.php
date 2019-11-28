<?php namespace Hampel\Geoblock\Option;

use XF\Option\AbstractOption;

class RejectDenied extends AbstractOption
{
	/**
	 * @return bool
	 */
	public static function get()
	{
		return (bool)\XF::options()->geoblockRejectDenied;
	}

	/**
	 * @return bool
	 */
	public static function isEnabled()
	{
		return self::get();
	}
}
