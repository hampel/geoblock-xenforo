<?php namespace Hampel\Geoblock\Finder;

use XF\Mvc\Entity\Finder;

class GeoIp extends Finder
{
	/**
	 * @param $ip
	 *
	 * @return null|\Hampel\Geoblock\Entity\GeoIp
	 */
	public function getGeoIp($ip)
	{
		$ip = \XF\Util\Ip::convertIpStringToBinary($ip);
		if ($ip === false)
		{
			// this will fail later
			$ip = '';
		}

		return $this->where('ip', $ip)->fetchOne();
	}
}
