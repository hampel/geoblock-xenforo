<?php namespace Hampel\Geoblock\Option;

use XF\Option\AbstractOption;

class LicenseKey extends AbstractOption
{
	public static function get()
	{
		return \XF::options()->geoblockLicenseKey;
	}
}
