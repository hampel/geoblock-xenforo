<?php namespace Tests\Unit;

use GeoIp2\Model\Country;
use Hampel\Geoblock\IpGeo;
use Mockery as m;
use Tests\TestCase;

class IpGeoTest extends TestCase
{
	public function test_can_be_created_from_constructor()
	{
		$geo = new IpGeo('1.1.1.1', 'AU', 'Australia');

		$this->assertInstanceOf(IpGeo::class, $geo);
	}

	public function test_has_default_values()
	{
		$geo = new IpGeo('1.1.1.1', 'AU', 'Australia');

		$this->assertEquals('1.1.1.1', $geo->getIp());
		$this->assertEquals('AU', $geo->getIsoCode());
		$this->assertEquals('Australia', $geo->getName());
		$this->assertEquals(false, $geo->isInEu());
	}

	public function test_uppercases_iso_code()
	{
		$geo = new IpGeo('1.1.1.1', 'au', 'Australia');

		$this->assertEquals('AU', $geo->getIsoCode());
	}

	public function test_can_be_created_from_entity()
	{
		$entity = m::mock('Hampel\Geoblock\Entity\GeoIp');
		$entity->allows()->get('ip')->andReturns('1.1.1.1');
		$entity->allows()->get('iso_code')->andReturns('AU');
		$entity->allows()->get('name')->andReturns('Australia');
		$entity->allows()->get('eu')->andReturns(true);

		$geo = IpGeo::newFromEntity($entity);

		$this->assertInstanceOf(IpGeo::class, $geo);

		$this->assertEquals('1.1.1.1', $geo->getIp());
		$this->assertEquals('AU', $geo->getIsoCode());
		$this->assertEquals('Australia', $geo->getName());
		$this->assertEquals(true, $geo->isInEu());
	}

	public function test_can_be_created_from_country()
	{
		$raw = json_decode($this->getMockData('1.1.1.1-country.json'), true);
		$country = new Country($raw);

		$geo = IpGeo::newFromModel($country);

		$this->assertInstanceOf(IpGeo::class, $geo);

		$this->assertEquals('1.1.1.1', $geo->getIp());
		$this->assertEquals('AU', $geo->getIsoCode());
		$this->assertEquals('Australia', $geo->getName());
		$this->assertEquals(false, $geo->isInEu());
	}
	
	public function test_can_be_created_from_array()
	{
		$data = [
			'ip' => '1.1.1.1',
			'iso_code' => 'AU',
			'name' => 'Australia',
			'eu' => false,
		];

		$geo = IpGeo::newFromArray($data);

		$this->assertInstanceOf(IpGeo::class, $geo);

		$this->assertEquals('1.1.1.1', $geo->getIp());
		$this->assertEquals('AU', $geo->getIsoCode());
		$this->assertEquals('Australia', $geo->getName());
		$this->assertEquals(false, $geo->isInEu());
	}

	public function test_incomplete_array_returns_null()
	{
		$this->assertEquals(null, IpGeo::newFromArray([]));
	}

	public function test_country_has_no_country_record()
	{
		$raw = json_decode($this->getMockData('4.4.4.4-country.json'), true);
		$country = new Country($raw);

		$geo = IpGeo::newFromModel($country);

		$this->assertInstanceOf(IpGeo::class, $geo);

		$this->assertEquals('4.4.4.4', $geo->getIp());
		$this->assertEquals('RU', $geo->getIsoCode());
		$this->assertEquals('Russia', $geo->getName());
		$this->assertEquals(false, $geo->isInEu());
	}

	public function test_country_has_no_country_or_registered_country_record()
	{
		$raw = json_decode($this->getMockData('2.2.2.2-country.json'), true);
		$country = new Country($raw);

		$geo = IpGeo::newFromModel($country);

		$this->assertInstanceOf(IpGeo::class, $geo);

		$this->assertEquals('2.2.2.2', $geo->getIp());
		$this->assertEquals('00', $geo->getIsoCode());
		$this->assertEquals('Other Country', $geo->getName());
		$this->assertEquals(false, $geo->isInEu());
	}

	public function test_country_has_only_continent_record()
	{
		$raw = json_decode($this->getMockData('3.3.3.3-country.json'), true);
		$country = new Country($raw);

		$geo = IpGeo::newFromModel($country);

		$this->assertInstanceOf(IpGeo::class, $geo);

		$this->assertEquals('3.3.3.3', $geo->getIp());
		$this->assertEquals('01', $geo->getIsoCode());
		$this->assertEquals('Anonymous Proxy (Asia)', $geo->getName());
		$this->assertEquals(false, $geo->isInEu());
	}

	public function test_convert_to_array()
	{
		$raw = json_decode($this->getMockData('1.1.1.1-country.json'), true);
		$country = new Country($raw);

		$geo = IpGeo::newFromModel($country);
		$data = $geo->toArray();

		$this->assertEquals('1.1.1.1', $data['ip']);
		$this->assertEquals('AU', $data['iso_code']);
		$this->assertEquals('Australia', $data['name']);
		$this->assertEquals(false, $data['eu']);
	}
}
