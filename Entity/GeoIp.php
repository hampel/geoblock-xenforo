<?php namespace Hampel\Geoblock\Entity;

use XF\Util\Ip;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class GeoIp extends Entity
{
	protected function verifyIp(&$ip)
	{
		$ip = Ip::convertIpStringToBinary($ip);
		if ($ip === false)
		{
			// this will fail later
			$ip = '';
		}

		return true;
	}

	public static function getStructure(Structure $structure)
	{
		$structure->table = 'xf_geoip_cache';
		$structure->shortName = 'Hampel\Geoblock:GeoIp';
		$structure->primaryKey = 'ip';
		$structure->columns = [
			'ip' => ['type' => self::BINARY, 'maxLength' => 16, 'required' => true],
			'iso_code' => ['type' => self::STR, 'maxLength' => 2, 'required' => true],
			'name' => ['type' => self::STR, 'maxLength' => 64, 'required' => true],
			'eu' => ['type' => self::BOOL, 'default' => false],
			'lookup_date' => ['type' => self::UINT, 'default' => \XF::$time]
		];
		$structure->getters = ['ip' => true];
		$structure->relations = [];

		return $structure;
	}

	public function getIp()
	{
		$ip = Ip::convertIpBinaryToString($this->getValue('ip'));
		if ($ip === false) return '';

		return $ip;
	}
}
