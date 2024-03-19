<?php namespace Tests\Unit;

use Hampel\Geoblock\IpGeo;
use Hampel\Geoblock\Repository\GeoIp;
use Tests\TestCase;
use XF\Util\Ip;

class GeoIpRepoTest extends TestCase
{
	/**
	 * @var GeoIp
	 */
	private $repo;

	protected function setUp() : void
	{
		parent::setUp();

		$this->repo = $this->app()->repository('Hampel\Geoblock:GeoIp');
	}

	public function test_checkPrivateOrReserved_with_valid_ip_returns_null()
	{
		$this->assertNull($this->repo->checkPrivateOrReserved('1.1.1.1'));
	}

	public function test_checkPrivateOrReserved_with_null_ip_returns_default_invalid()
	{
		$this->expectPhrase('geoblock_invalid_address');

		$geo = $this->repo->checkPrivateOrReserved(null);
		$this->assertInstanceOf(IpGeo::class, $geo);
		$this->assertEmpty($geo->getIp());
		$this->assertEquals('??', $geo->getIsoCode());
		$this->assertEquals('geoblock_invalid_address', $geo->getName());
	}

	public function test_checkPrivateOrReserved_with_empty_ip_returns_default_invalid()
	{
		$this->expectPhrase('geoblock_invalid_address');

		$geo = $this->repo->checkPrivateOrReserved('');
		$this->assertInstanceOf(IpGeo::class, $geo);
		$this->assertEmpty($geo->getIp());
		$this->assertEquals('??', $geo->getIsoCode());
		$this->assertEquals('geoblock_invalid_address', $geo->getName());
	}

	public function test_checkPrivateOrReserved_with_invalid_ip_returns_default_invalid()
	{
		$this->expectPhrase('geoblock_invalid_address');

		$check = $this->repo->checkPrivateOrReserved('foo');
		$this->assertInstanceOf(IpGeo::class, $check);
		$this->assertEmpty($check->getIp());
		$this->assertEquals('??', $check->getIsoCode());
		$this->assertEquals('geoblock_invalid_address', $check->getName());
	}

	public function test_checkPrivateOrReserved_with_private_ip_returns_default_private()
	{
		$ip = '10.0.0.1';
		$this->expectPhrase('geoblock_private_or_reserved');

		$check = $this->repo->checkPrivateOrReserved($ip);
		$this->assertInstanceOf(IpGeo::class, $check);
		$this->assertEquals($ip, $check->getIp());
		$this->assertEquals('??', $check->getIsoCode());
		$this->assertEquals('geoblock_private_or_reserved', $check->getName());
	}

	public function test_checkPrivateOrReserved_with_reserved_ip_returns_default_private()
	{
		$ip = '127.0.0.1';
		$this->expectPhrase('geoblock_private_or_reserved');

		$check = $this->repo->checkPrivateOrReserved($ip);
		$this->assertInstanceOf(IpGeo::class, $check);
		$this->assertEquals($ip, $check->getIp());
		$this->assertEquals('??', $check->getIsoCode());
		$this->assertEquals('geoblock_private_or_reserved', $check->getName());
	}

	public function test_getFromCache_returns_null_when_not_cached()
	{
		$this->assertNull($this->repo->getFromCache('10.0.0.1'));
	}

	public function test_getFromCache_returns_IpGeo_when_cached()
	{
		$ip = '10.0.0.1';

		$ipgeo = new IpGeo($ip, 'XX', 'foo');
		$this->repo->updateCache($ipgeo);
		$cached = $this->repo->getFromCache($ip);

		$this->assertEquals($ipgeo, $cached);
	}

	public function test_getFromDb_returns_null_for_empty_ip()
	{
		$this->assertNull($this->repo->getFromDb(''));
	}

	public function test_getFromDb_returns_ipgeo()
	{
		$ip = '10.0.0.1';

		$geoip = $this->app()->em()->create('Hampel\Geoblock:GeoIp');

		$this->mockFinder('Hampel\Geoblock:GeoIp', function ($mock) use ($geoip, $ip)
		{
			$ipBinary = Ip::convertIpStringToBinary($ip);

			$mock->expects()->where('ip', '=', $ipBinary)->once()->andReturnSelf();
			$mock->expects()->fetchOne()->once()->andReturns($geoip);
			$mock->expects()->getGeoIp($ip)->once()->andReturns($geoip);
		});

		$geoip->ip = $ip;

		$ipgeo = $this->repo->getFromDb($ip);

		$this->assertEquals($ip, $ipgeo->getIp());
	}

	/**
	 * Note that we cannot currently test code that saves entities
	 */
	public function test_updateDb_finds_ip()
	{
		$ip = '10.0.0.1';
		$ipgeo = new IpGeo($ip, 'XX', 'foo');

		$data = $ipgeo->toArray();

		/*
		 * can't properly mock an entity which uses save()
		 *
		 * ... but that's okay - we'll use a fake mock class rather than inherit from the base class (2nd parameter)
		 */
		$entity = $this->mockEntity('Hampel\Geoblock:GeoIp', false, function ($mock) use ($data) {
			$mock->expects()->bulkset($data)->once();
			$mock->expects()->save()->once();
		});

		$this->mockFinder('Hampel\Geoblock:GeoIp', function ($mock) use ($entity, $ip) {
			$mock->expects()->getGeoIp($ip)->once()->andReturns($entity);
		});

		$this->repo->updateDb($ipgeo);
		$this->assertEquals(\XF::$time, $entity->lookup_date);
	}

	public function test_updateDb_does_not_find_ip()
	{
		$ip = '10.0.0.1';
		$ipgeo = new IpGeo($ip, 'XX', 'foo');

		$data = $ipgeo->toArray();

		/*
		 * can't properly mock an entity which uses save()
		 *
		 * ... but that's okay - we'll use a fake mock class rather than inherit from the base class (2nd parameter)
		 */
		$entity = $this->mockEntity('Hampel\Geoblock:GeoIp', false, function ($mock) use ($data) {
			$mock->expects()->bulkset($data)->once();
			$mock->expects()->save()->once();
		});

		$this->mockFinder('Hampel\Geoblock:GeoIp', function ($mock) use ($ip) {
			$mock->expects()->getGeoIp($ip)->once()->andReturns(null);
		});

		$this->repo->updateDb($ipgeo);
		$this->assertEquals(\XF::$time, $entity->lookup_date);
	}

	public function test_pruneGeoCache_without_cutoff()
	{
		$this->setOption('geoblockCacheExpiry', 1);

		$this->mockDatabase(function ($mock) {
			$cutoff = \XF::$time - 86400;

			$mock->expects()->delete('xf_geoblock_cache', 'lookup_date < ?', $cutoff);
		});

		$repo = $this->app()->repository('Hampel\Geoblock:GeoIp');
		$repo->pruneGeoCache();
	}
}
