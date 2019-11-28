<?php namespace Hampel\Geoblock\Option;

use XF\Option\AbstractOption;

class DatabaseUrl extends AbstractOption
{
	public static function verifyOption(&$value, \XF\Entity\Option $option)
	{
		if (!empty($value))
		{
			$urlValidator = \XF::app()->validator('Url');
			$value = $urlValidator->coerceValue($value);
			return $urlValidator->isValid($value);
		}

		return true;
	}

	public static function get()
	{
		return \XF::options()->geoblockDatabaseUrl;
	}

	public static function getUrlBasename()
	{
		return basename(parse_url(self::get(), PHP_URL_PATH));
	}
}
