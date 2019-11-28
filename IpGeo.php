<?php namespace Hampel\Geoblock;

class IpGeo
{
	protected $ip;
	protected $iso_code;
	protected $name;
	protected $eu = false;

	public function __construct($ip, $iso_code, $name, $eu = false)
	{
		$this->ip = $ip;
		$this->iso_code = strtoupper($iso_code);
		$this->name = strval($name);
		$this->eu = $eu;
	}

	/**
	 * @param Entity\GeoIp $geo
	 *
	 * @return IpGeo
	 */
	public static function newFromEntity(\Hampel\Geoblock\Entity\GeoIp $geo)
	{
		return new self(
			$geo->ip,
			$geo->iso_code,
			$geo->name,
			$geo->eu
		);
	}

	/**
	 * @param \GeoIp2\Model\Country $geo
	 *
	 * @return IpGeo
	 */
	public static function newFromModel(\GeoIp2\Model\Country $geo)
	{
		if (isset($geo->country->isoCode))
		{
			return new self(
				$geo->traits->ipAddress,
				$geo->country->isoCode,
				$geo->country->name,
				$geo->country->isInEuropeanUnion
			);
		}

		if (isset($geo->registeredCountry->isoCode))
		{
			return new self(
				$geo->traits->ipAddress,
				$geo->registeredCountry->isoCode,
				$geo->registeredCountry->name,
				$geo->registeredCountry->isInEuropeanUnion
			);
		}

		if (isset($geo->continent->code))
		{
			return new self(
				$geo->traits->ipAddress,
				'01',
				"Anonymous Proxy ({$geo->continent->name})",
				null
			);
		}

		return new self(
			$geo->traits->ipAddress,
			'00',
			'Other Country',
			null
		);
	}

	/**
	 * @param array $geo
	 *
	 * @return IpGeo|null
	 */
	public static function newFromArray(array $geo)
	{
		if (!isset($geo['ip']) || !isset($geo['iso_code']) || !isset($geo['name'])) return null;
		if (!isset($geo['eu'])) $geo['eu'] = null;

		return new self(
			$geo['ip'],
			$geo['iso_code'],
			$geo['name'],
			$geo['eu']
		);
	}

	/**
	 * @return string
	 */
	public function getIp()
	{
		return $this->ip;
	}
	/**
	 * @return string
	 */
	public function getIsoCode()
	{
		return $this->iso_code;
	}
	/**
	 * @return string
	 */
	public function getName()
	{
		return $this->name;
	}

	/**
	 * @return integer
	 */
	public function getEu()
	{
		return $this->eu;
	}

	/**
	 * @return bool
	 */
	public function isInEu()
	{
		return $this->eu ? true : false;
	}

	/**
	 * @return array
	 */
	public function toArray()
	{
		return [
			'ip' => $this->ip,
			'iso_code' => $this->iso_code,
			'name' => $this->name,
			'eu' => $this->eu
		];
	}
}
