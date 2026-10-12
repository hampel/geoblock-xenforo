<?php namespace Tests\Feature;

use Hampel\Geoblock\Cli\Command\Config;
use Hampel\Geoblock\Cli\Command\UpdateDatabase;
use Hampel\Geoblock\Cli\Command\Validate;
use League\Flysystem\AdapterInterface;
use League\Flysystem\EventableFilesystem\EventableFilesystem;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;
use XF\FsMounts;

/**
 * geoblock:config and geoblock:validate.
 *
 * The exit contract is the one every tool here shares, and it is not the monitoring-plugin
 * numbering: 1 always means a failure, a warning is 0 unless --strict is given, and --strict makes
 * a warning 2 and changes nothing else.
 *
 * The age check is the reason validate exists, so it is tested both ways. The mock database was
 * built in 2020, so it is stale against real time; setTestTime() moves the clock next to its build
 * date to get the fresh case, which is the only way round that works - the fixture's build epoch
 * is inside the file and cannot be moved.
 */
class CommandsTest extends TestCase
{
	public function test_every_command_loads_and_is_named()
	{
		// XenForo instantiates every add-on's command classes just to build `cmd.php list`, so one
		// that cannot load takes the whole CLI down on that forum - not only this add-on's commands.
		$expected = [
			Config::class => 'geoblock:config',
			Validate::class => 'geoblock:validate',
			UpdateDatabase::class => 'geoblock:update-database',
		];

		foreach ($expected as $class => $name)
		{
			$command = new $class();

			$this->assertSame($name, $command->getName(), "{$class} is named wrongly");
			$this->assertNotSame('', $command->getDescription(), "{$class} has no description");
		}
	}

	public function test_config_reports_settings_and_never_the_license_key()
	{
		$this->setOptions([
			'geoblockLicenseKey' => 'super-secret-key-value',
			'geoblockDenied' => 'CN, RU',
		]);

		$tester = $this->runCommand(new Config());

		$this->assertSame(0, $tester->getStatusCode());

		$output = $tester->getDisplay();
		$this->assertStringNotContainsString('super-secret-key-value', $output,
			'config printed the license key, and its output gets pasted into tickets');
		$this->assertStringContainsString('license key', $output);
		$this->assertStringContainsString('CN RU', $output);
	}

	public function test_validate_fails_without_a_database()
	{
		$this->setRequiredOptions('key_foo', 'path_foo');

		$tester = $this->runCommand(new Validate());

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('[fail]', $tester->getDisplay());
	}

	public function test_validate_fails_when_updates_are_automatic_and_no_key_is_set()
	{
		$this->mockMmdb();
		$this->setOptions(['geoblockAutoUpdate' => true, 'geoblockLicenseKey' => '']);

		$tester = $this->runCommand(new Validate());

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('license key', $tester->getDisplay());
	}

	public function test_validate_skips_the_license_key_when_updates_are_external()
	{
		// The point of the option: no key is needed, so its absence must not be a failure. The
		// database ageing is still reported, which is what keeps this safe to offer.
		$this->mockMmdb();
		$this->setOptions([
			'geoblockAutoUpdate' => false,
			'geoblockLicenseKey' => '',
			'geoblockDenied' => 'CN',
		]);

		$tester = $this->runCommand(new Validate());

		$this->assertSame(0, $tester->getStatusCode(), 'a missing key is not a failure when updates are external');
		$this->assertStringContainsString('managed externally', $tester->getDisplay());
	}

	public function test_validate_warns_when_the_database_is_stale()
	{
		$this->mockMmdb();
		$this->setOptions(['geoblockDenied' => 'CN', 'geoblockLicenseKey' => 'key_foo']);

		// real time, against a fixture built in 2020
		$tester = $this->runCommand(new Validate());

		$this->assertSame(0, $tester->getStatusCode(), 'a stale database still works, so it is not a failure');
		$this->assertStringContainsString('stale data', $tester->getDisplay());
	}

	public function test_strict_turns_that_warning_into_exit_2()
	{
		$this->mockMmdb();
		$this->setOptions(['geoblockDenied' => 'CN', 'geoblockLicenseKey' => 'key_foo']);

		$tester = $this->runCommand(new Validate(), ['--strict' => true]);

		$this->assertSame(2, $tester->getStatusCode());
	}

	public function test_validate_passes_with_a_fresh_database_and_blocking_configured()
	{
		$this->mockMmdb();
		$this->setOptions(['geoblockDenied' => 'CN', 'geoblockLicenseKey' => 'key_foo']);
		$this->setTestTime(1595663825 + 86400);   // the day after the fixture was built

		$tester = $this->runCommand(new Validate());

		$output = $tester->getDisplay();
		$this->assertStringNotContainsString('[fail]', $output);
		$this->assertStringNotContainsString('[warn]', $output);
		$this->assertStringContainsString('8.8.8.8 resolves to US', $output);
		$this->assertSame(0, $tester->getStatusCode());
	}

	public function test_validate_warns_when_nothing_would_be_blocked()
	{
		$this->mockMmdb();
		$this->setOptions([
			'geoblockDenied' => '',
			'geoblockApproved' => '',
			'geoblockModerateOthers' => false,
			'geoblockRejectEu' => false,
			'geoblockLicenseKey' => 'key_foo',
		]);
		$this->setTestTime(1595663825 + 86400);

		$tester = $this->runCommand(new Validate());

		$this->assertSame(0, $tester->getStatusCode(), 'a fresh install is not a failed one');
		$this->assertStringContainsString('no registration is affected', $tester->getDisplay());
	}

	public function test_validate_warns_in_test_mode()
	{
		$this->mockMmdb();
		$this->setOptions(['geoblockTestMode' => true, 'geoblockDenied' => 'CN', 'geoblockLicenseKey' => 'key_foo']);
		$this->setTestTime(1595663825 + 86400);

		$tester = $this->runCommand(new Validate());

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('canned result', $tester->getDisplay());
	}

	public function test_a_check_that_throws_does_not_end_the_run()
	{
		// No check may end the run: a validation that stops at its first exception hides the rest,
		// on the one occasion the whole list was wanted. Pointing the reader at a file that is not
		// a MaxMind database makes its constructor throw inside the database section.
		$this->fakesErrors();
		$this->mockMmdb();
		$this->setOptions(['geoblockDenied' => 'CN']);

		$geoblock = $this->app->get('geoblock');
		$this->swap([$geoblock, 'database.canonical'], function ($c)
		{
			return __DIR__ . '/../mock/au-country.json';
		});

		$tester = $this->runCommand(new Validate());
		$output = $tester->getDisplay();

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('[fail]', $output);
		// the section AFTER the one that threw still reported
		$this->assertStringContainsString('blocking', $output);
	}

	// ------------------------------------------------------------

	/**
	 * Not run(): PHPUnit\Framework\TestCase::run() is final, so a helper by that name is a fatal
	 * at class load and takes the whole suite with it. Symfony's Command::run() is public and
	 * collides the same way, which is why the convention is to avoid the name entirely.
	 */
	private function runCommand($command, array $input = []): CommandTester
	{
		$tester = new CommandTester($command);
		$tester->execute($input);

		return $tester;
	}

	private function setRequiredOptions($licenseKey = '', $path = '')
	{
		$this->setOptions([
			'geoblockLicenseKey' => $licenseKey,
			'geoblockDatabasePath' => $path,
		]);
	}

	private function mockMmdb()
	{
		$mockPath = __DIR__ . '/../mock';
		$geoblock = $this->app->get('geoblock');

		$this->swap([$geoblock, 'database.canonical'], function ($c) use ($mockPath)
		{
			return "{$mockPath}/country.mmdb";
		});

		$mockAdapter = FsMounts::getLocalAdapter($mockPath);
		$mockFs = new EventableFilesystem($mockAdapter, ['visibility' => AdapterInterface::VISIBILITY_PUBLIC]);
		$this->app()->fs()->mountFilesystem('mock', $mockFs);

		$this->swap([$geoblock, 'database.abstracted'], function ($c)
		{
			return 'mock://country.mmdb';
		});

		$this->setOptions([
			'geoblockDatabasePath' => 'path_foo',
		]);
	}
}
