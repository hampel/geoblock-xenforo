<?php namespace Hampel\Geoblock\Option;

class Helper
{
	public static function sortStringList(&$string)
	{
		$values = preg_split('/[\s,]+/', $string, -1, PREG_SPLIT_NO_EMPTY);
		array_walk($values, function(&$item, $key) {
			$item = strtoupper($item);
		});
		sort($values);
		$string = implode(', ', $values);
	}

	public static function stringListToArray($string)
	{
		if (empty(trim($string))) return [];

		return array_map('trim', explode(',', $string));
	}
}
