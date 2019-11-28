<?php namespace Hampel\Geoblock\Repository;

use Hampel\Geoblock\IpGeo;
use XF\Mvc\Entity\Repository;
use Hampel\Geoblock\Option\CacheExpiry;

class GeoIp extends Repository
{
	protected $lookupCache = [];

	/**
	 * If passed an invalid IP address, or an IP address which is private or reserved (ie not routable), then this will
	 * return a valid IpGeo object populated with some default data
	 *
	 * Otherwise, returns null for a completely valid, routable IP address
	 *
	 * @param $ip
	 *
	 * @return IpGeo|null
	 */
	public function checkPrivateOrReserved($ip)
	{
		if (filter_var($ip, FILTER_VALIDATE_IP) === false)
		{
			return new IpGeo('', '??', \XF::phrase('geoblock_invalid_address'));
		}

		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false)
		{
			return new IpGeo($ip, '??', \XF::phrase('geoblock_private_or_reserved'));
		}
	}

	/**
	 * @param $ip
	 *
	 * @return null|IpGeo
	 */
	public function getFromCache($ip)
	{
		if ($ip && isset($this->lookupCache[$ip]))
		{
			return $this->lookupCache[$ip];
		}
	}

	/**
	 * @param $ip
	 *
	 * @return null|IpGeo
	 */
	public function getFromDb($ip)
	{
		if (empty($ip)) return;

		$entity = $this->geoIpFinder()->getGeoIp($ip);
		if ($entity) return IpGeo::newFromEntity($entity);
	}

	public function updateDb(IpGeo $geo)
	{
		$entity = $this->geoIpFinder()->getGeoIp($geo->getIp());

		if (!$entity)
		{
			$entity = $this->em->create('Hampel\Geoblock:GeoIp');
		}

		$data = $geo->toArray();

		$entity->bulkSet($data);
		$entity->lookup_date = \XF::$time; // update the lookup date to current time
		$entity->save();
	}

	public function updateCache(IpGeo $geo)
	{
		$this->lookupCache[$geo->getIp()] = $geo;
	}

	public function pruneGeoCache($cutOff = null)
	{
		if ($cutOff === null)
		{
			$cutOff = $this->getGeoCacheCutoff();
		}

		$this->db()->delete('xf_geoip_cache', 'lookup_date < ?', $cutOff);
	}

	public function getGeoCacheCutoff()
	{
		return \XF::$time - (CacheExpiry::get() * 86400);
	}

	/**
	 * @return \Hampel\Geoblock\Finder\GeoIp
	 */
	public function geoIpFinder()
	{
		return $this->finder('Hampel\Geoblock:GeoIp');
	}
}
