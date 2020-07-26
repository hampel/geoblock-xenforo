<?php namespace Tests\Unit;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\GeoIp2Exception;
use Hampel\Geoblock\IpGeo;
use Hampel\Geoblock\SubContainer\Maxmind;
use League\Flysystem\AdapterInterface;
use League\Flysystem\EventableFilesystem\EventableFilesystem;
use Tests\TestCase;
use XF\FsMounts;

class SubcontainerTest extends TestCase
{
	/** @var Maxmind */
	protected $geoblock;

	protected function setUp() : void
	{
		parent::setUp();

		$this->geoblock = $this->app()->get('geoblock');
	}

	// ------------------------------------------------

	public function test_initialisation()
	{
		$this->mockMmdb();

		$this->assertInstanceOf(Reader::class, $this->geoblock->maxmind());
	}

	public function test_not_configured()
	{
		$this->setRequiredOptions(); // no credentials

		$this->assertFalse($this->geoblock->isConfigured());
	}

	public function test_configured_but_database_not_present()
	{
		// swap to a memory database which won't have anything in it
		$this->swapFs('internal-data');

		$this->setRequiredOptions('url_foo', 'path_foo');

		$this->assertFalse($this->geoblock->isConfigured());
	}

	public function test_configured_and_database_present()
	{
		$this->mockMmdb();

		$this->setRequiredOptions('url_foo', 'path_foo');

		$this->assertTrue($this->geoblock->isConfigured());
	}

	public function test_getIpGeo_returns_correct_values_with_testmode_enabled()
	{
		$this->setTestMode();

		$geo = $this->geoblock->getIpGeo('');

		$this->assertInstanceOf(IpGeo::class, $geo);
		$this->assertEquals('10.0.0.1', $geo->getIp());
		$this->assertEquals('XX', $geo->getIsoCode());
		$this->assertEquals('Test Mode', $geo->getName());
		$this->assertEquals(false, $geo->isInEu());
	}

	public function test_getIpGeo_returns_private_or_reserved_ip()
	{
		$this->setTestMode(false);

		$ip = '10.0.0.1';
		$ipGeo = new IpGeo($ip, '??', 'foo');

		$this->mockRepository('Hampel\Geoblock:GeoIp', function ($mock) use ($ip, $ipGeo) {
			$mock->expects()->checkPrivateOrReserved()->with($ip)->andReturn($ipGeo);
			$mock->expects()->getFromCache()->never();
			$mock->expects()->getFromDb()->never();
		});

		/** @var IpGeo $geo */
		$geo = $this->geoblock->getIpGeo($ip);

		$this->assertInstanceOf(IpGeo::class, $geo);
		$this->assertEquals($ip, $geo->getIp());
		$this->assertEquals('??', $geo->getIsoCode());
		$this->assertEquals('foo', $geo->getName());
	}

	public function test_getIpGeo_fetches_from_cache()
	{
		$this->setTestMode(false);

		$ip = '1.1.1.1';
		$ipGeo = new IpGeo($ip, 'XX', 'foo');

		$this->mockRepository('Hampel\Geoblock:GeoIp', function ($mock) use ($ip, $ipGeo) {
			$mock->expects()->checkPrivateOrReserved()->with($ip)->andReturn(null);
			$mock->expects()->getFromCache()->with($ip)->andReturn($ipGeo);
			$mock->expects()->getFromDb()->never();
		});

		/** @var IpGeo $geo */
		$geo = $this->geoblock->getIpGeo($ip);

		$this->assertInstanceOf(IpGeo::class, $geo);
		$this->assertEquals($ip, $geo->getIp());
		$this->assertEquals('XX', $geo->getIsoCode());
		$this->assertEquals('foo', $geo->getName());
	}

	public function test_getIpGeo_fetches_from_db_but_fails_unconfigured()
	{
		$this->setTestMode(false);
		$this->setRequiredOptions();

		$ip = '1.1.1.1';

		$this->mockRepository('Hampel\Geoblock:GeoIp', function ($mock) use ($ip) {
			$mock->expects()->checkPrivateOrReserved()->with($ip)->andReturn(null);
			$mock->expects()->getFromCache()->with($ip)->andReturn(null);
			$mock->expects()->getFromDb()->with($ip)->andReturn(null);
			$mock->expects()->updateCache()->never();
		});

		/** @var IpGeo $geo */
		$geo = $this->geoblock->getIpGeo($ip);

		$this->assertNull($geo);
	}

	public function test_getIpGeo_fetches_from_db()
	{
		$this->setTestMode(false);

		$ip = '1.1.1.1';
		$ipGeo = new IpGeo($ip, 'XX', 'foo');

		$this->mockRepository('Hampel\Geoblock:GeoIp', function ($mock) use ($ip, $ipGeo) {
			$mock->expects()->checkPrivateOrReserved()->with($ip)->andReturn(null);
			$mock->expects()->getFromCache()->with($ip)->andReturn(null);
			$mock->expects()->getFromDb()->with($ip)->andReturn($ipGeo);
			$mock->expects()->updateCache()->with($ipGeo);
		});

		/** @var IpGeo $geo */
		$geo = $this->geoblock->getIpGeo($ip);

		$this->assertInstanceOf(IpGeo::class, $geo);
		$this->assertEquals($ip, $geo->getIp());
		$this->assertEquals('XX', $geo->getIsoCode());
		$this->assertEquals('foo', $geo->getName());
	}

	public function test_getIpGeo_returns_null_when_not_configured()
	{
		$this->setTestMode(false);
		$this->setRequiredOptions();

		$ip = '1.1.1.1';

		$this->mockRepository('Hampel\Geoblock:GeoIp', function ($mock) use ($ip) {
			$mock->expects()->checkPrivateOrReserved()->with($ip)->andReturn(null);
			$mock->expects()->getFromCache()->never();
			$mock->expects()->updateDb()->never();
			$mock->expects()->updateCache()->never();
		});

		$geo = $this->geoblock->getIpGeo('1.1.1.1', true);

		$this->assertNull($geo);
	}

	public function test_getIpGeo_fetches_from_api_using_country()
	{
		$this->setTestMode(false);
		$this->setRequiredOptions('url_foo', 'path_foo');

		$this->mockMmdb();

		$ip = '8.8.8.8';

		$this->mockRepository('Hampel\Geoblock:GeoIp', function ($mock) use ($ip) {
			$mock->expects()->checkPrivateOrReserved()->with($ip)->andReturn(null);
			$mock->shouldReceive('updateDb')->withArgs(function ($arg) {
				return $arg instanceof IpGeo;
			});
			$mock->shouldReceive('updateCache')->withArgs(function ($arg) {
				return $arg instanceof IpGeo;
			});
		});

		/** @var IpGeo $geo */
		$geo = $this->geoblock->getIpGeo($ip, true);

		$this->assertInstanceOf(IpGeo::class, $geo);
		$this->assertEquals('8.8.8.8', $geo->getIp());
		$this->assertEquals('US', $geo->getIsoCode());
		$this->assertEquals('United States', $geo->getName());
		$this->assertFalse($geo->isInEu());
	}

	public function test_getIpGeo_logs_error_from_api_call()
	{
		$this->fakesErrors();

		$this->setTestMode(false);
		$this->setRequiredOptions('url_foo', 'path_foo');

		$this->mockMmdb();

		$ip = '1.1.1.1';

		$this->mockRepository('Hampel\Geoblock:GeoIp', function ($mock) use ($ip) {
			$mock->expects()->checkPrivateOrReserved()->with($ip)->andReturn(null);
		});

		/** @var IpGeo $geo */
		$this->assertNull($this->geoblock->getIpGeo($ip, true));

		$this->assertExceptionLogged(GeoIp2Exception::class);
	}

	// ------------------------------------------------------------

	protected function setTestMode($enabled = true)
	{
		$this->setOption('geoblockTestMode', $enabled);
	}

	protected function setRequiredOptions($url = '', $path = '')
	{
		$this->setOption('geoblockDatabaseUrl', $url);
		$this->setOption('geoblockDatabasePath', $path);
	}

	protected function mockMmdb()
	{
		$mockPath = __DIR__ . '/../mock';

		$this->swap([$this->geoblock, 'database.canonical'], function ($c) use ($mockPath) {
			return "{$mockPath}/country.mmdb";
		});

		$mockAdapter = FsMounts::getLocalAdapter($mockPath);
		$mockFs = new EventableFilesystem($mockAdapter, ['visibility' => AdapterInterface::VISIBILITY_PUBLIC]);
		$this->app()->fs()->mountFilesystem('mock', $mockFs);

		$this->swap([$this->geoblock, 'database.abstracted'], function ($c) {
			return "mock://country.mmdb";
		});

		$this->setOptions([
			'geoblockLicenseKey' => 'key_foo',
			'geoblockDatabasePath' => 'path_foo',
		]);
	}
}
