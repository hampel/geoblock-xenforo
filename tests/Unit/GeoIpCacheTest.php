<?php namespace Tests\Unit;

use Hampel\Geoblock\Entity\GeoIp as GeoIpEntity;
use Hampel\Geoblock\IpGeo;
use Hampel\Geoblock\Repository\GeoIp;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;
use XF\Util\Ip;

/**
 * The database side of the lookup cache, against a real connection.
 *
 * GeoIpRepoTest covers the same repository with the finder mocked, which proves the branching
 * but not that a row survives a round trip - the ip column is varbinary(16) with conversion in
 * both directions, which is exactly the kind of thing a mock cannot tell you is wrong.
 *
 * Every test here is wrapped in a transaction and rolled back, so nothing outlives the run.
 */
class GeoIpCacheTest extends TestCase
{
	use UsesDatabaseTransactions;

	/** @var GeoIp */
	protected $repo;

	protected function setUp() : void
	{
		parent::setUp();

		$this->repo = $this->app()->repository('Hampel\Geoblock:GeoIp');
	}

	// ------------------------------------------------ the wrapper itself

	public function test_a_the_transaction_wrapper_is_active()
	{
		$this->assertTrue($this->app()->db()->inTransaction());

		$this->repo->updateDb(new IpGeo('203.0.113.1', 'AU', 'Australia'));

		$this->assertDatabaseHas('xf_geoblock_cache', [
			'ip' => Ip::convertIpStringToBinary('203.0.113.1'),
		]);
	}

	/** proves the previous test's write was rolled back rather than committed */
	public function test_b_the_previous_write_did_not_survive()
	{
		$this->assertDatabaseMissing('xf_geoblock_cache', [
			'ip' => Ip::convertIpStringToBinary('203.0.113.1'),
		]);
	}

	// ------------------------------------------------ round trip

	public function test_updateDb_writes_a_row_that_reads_back_intact()
	{
		$this->repo->updateDb(new IpGeo('198.51.100.7', 'nz', 'New Zealand', true));

		$geo = $this->repo->getFromDb('198.51.100.7');

		$this->assertInstanceOf(IpGeo::class, $geo);
		$this->assertEquals('198.51.100.7', $geo->getIp(), 'binary ip did not survive the round trip');
		$this->assertEquals('NZ', $geo->getIsoCode(), 'iso code should be upper-cased on the way in');
		$this->assertEquals('New Zealand', $geo->getName());
		$this->assertTrue($geo->isInEu());
	}

	public function test_updateDb_updates_an_existing_row_rather_than_adding_one()
	{
		$this->repo->updateDb(new IpGeo('198.51.100.8', 'AU', 'Australia'));
		$this->repo->updateDb(new IpGeo('198.51.100.8', 'NZ', 'New Zealand'));

		$this->assertDatabaseCount('xf_geoblock_cache', 1, [
			'ip' => Ip::convertIpStringToBinary('198.51.100.8'),
		]);

		$this->assertEquals('NZ', $this->repo->getFromDb('198.51.100.8')->getIsoCode());
	}

	public function test_updateDb_stamps_the_lookup_date()
	{
		\XF::$time = 1700000000;

		$this->repo->updateDb(new IpGeo('198.51.100.9', 'AU', 'Australia'));

		$this->assertDatabaseHas('xf_geoblock_cache', [
			'ip' => Ip::convertIpStringToBinary('198.51.100.9'),
			'lookup_date' => 1700000000,
		]);
	}

	public function test_getFromDb_returns_null_for_an_ip_that_was_never_cached()
	{
		$this->assertNull($this->repo->getFromDb('198.51.100.254'));
	}

	// ------------------------------------------------ entity builders

	public function test_an_entity_built_directly_is_found_by_the_repository()
	{
		$this->createEntity('Hampel\Geoblock:GeoIp', [
			'ip' => '192.0.2.44',
			'iso_code' => 'GB',
			'name' => 'United Kingdom',
			'eu' => false,
		]);

		$geo = $this->repo->getFromDb('192.0.2.44');

		$this->assertInstanceOf(IpGeo::class, $geo);
		$this->assertEquals('GB', $geo->getIsoCode());
	}

	public function test_makeEntity_does_not_touch_the_database()
	{
		$entity = $this->makeEntity('Hampel\Geoblock:GeoIp', [
			'ip' => '192.0.2.45',
			'iso_code' => 'GB',
			'name' => 'United Kingdom',
		]);

		$this->assertInstanceOf(GeoIpEntity::class, $entity);
		$this->assertEquals('192.0.2.45', $entity->ip);

		$this->assertDatabaseMissing('xf_geoblock_cache', [
			'ip' => Ip::convertIpStringToBinary('192.0.2.45'),
		]);
	}

	// ------------------------------------------------ pruning

	public function test_pruneGeoCache_removes_only_rows_older_than_the_cutoff()
	{
		\XF::$time = 1700000000;
		$day = 86400;

		$this->createEntity('Hampel\Geoblock:GeoIp', [
			'ip' => '192.0.2.1', 'iso_code' => 'AU', 'name' => 'Old',
			'lookup_date' => \XF::$time - (40 * $day),
		]);
		$this->createEntity('Hampel\Geoblock:GeoIp', [
			'ip' => '192.0.2.2', 'iso_code' => 'AU', 'name' => 'Fresh',
			'lookup_date' => \XF::$time - (2 * $day),
		]);

		$this->repo->pruneGeoCache(\XF::$time - (30 * $day));

		$this->assertDatabaseMissing('xf_geoblock_cache', [
			'ip' => Ip::convertIpStringToBinary('192.0.2.1'),
		]);
		$this->assertDatabaseHas('xf_geoblock_cache', [
			'ip' => Ip::convertIpStringToBinary('192.0.2.2'),
		]);
	}

	public function test_pruneGeoCache_uses_the_configured_expiry_when_given_no_cutoff()
	{
		\XF::$time = 1700000000;
		$this->setOption('geoblockCacheExpiry', 7);

		$day = 86400;

		$this->createEntity('Hampel\Geoblock:GeoIp', [
			'ip' => '192.0.2.3', 'iso_code' => 'AU', 'name' => 'Just outside',
			'lookup_date' => \XF::$time - (8 * $day),
		]);
		$this->createEntity('Hampel\Geoblock:GeoIp', [
			'ip' => '192.0.2.4', 'iso_code' => 'AU', 'name' => 'Just inside',
			'lookup_date' => \XF::$time - (6 * $day),
		]);

		$this->repo->pruneGeoCache();

		$this->assertDatabaseMissing('xf_geoblock_cache', [
			'ip' => Ip::convertIpStringToBinary('192.0.2.3'),
		]);
		$this->assertDatabaseHas('xf_geoblock_cache', [
			'ip' => Ip::convertIpStringToBinary('192.0.2.4'),
		]);
	}

	public function test_the_daily_cleanup_cron_prunes_the_cache()
	{
		\XF::$time = 1700000000;
		$this->setOption('geoblockCacheExpiry', 30);

		$this->createEntity('Hampel\Geoblock:GeoIp', [
			'ip' => '192.0.2.5', 'iso_code' => 'AU', 'name' => 'Ancient',
			'lookup_date' => \XF::$time - (400 * 86400),
		]);

		\Hampel\Geoblock\Cron\CleanUp::runDailyCleanup();

		$this->assertDatabaseMissing('xf_geoblock_cache', [
			'ip' => Ip::convertIpStringToBinary('192.0.2.5'),
		]);
	}
}
