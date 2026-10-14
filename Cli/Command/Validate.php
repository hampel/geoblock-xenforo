<?php namespace Hampel\Geoblock\Cli\Command;

use GeoIp2\Exception\AddressNotFoundException;
use Hampel\Geoblock\Cli\RendersReport;
use Hampel\Geoblock\Option\Approved;
use Hampel\Geoblock\Option\AutoUpdate;
use Hampel\Geoblock\Option\DatabasePath;
use Hampel\Geoblock\Option\Denied;
use Hampel\Geoblock\Option\LicenseKey;
use Hampel\Geoblock\Option\ModerateOthers;
use Hampel\Geoblock\Option\RejectEu;
use Hampel\Geoblock\Option\TestMode;
use Hampel\Geoblock\SubContainer\Maxmind;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prove this forum can actually geoblock a registration: that there is a database, that it opens,
 * that it is not stale, that a lookup through it returns a country, and that some decision would
 * be taken on the answer.
 *
 * It writes nothing and sends nothing. The lookup goes to the reader directly rather than through
 * getIpGeo(), which would write the result into both caches.
 *
 * It still accepts --unattended, which does nothing here. Every validate takes the flag so that
 * one command line - validate --strict --unattended - runs against every add-on; a command without
 * it would fail that line on an unknown option rather than run its checks.
 *
 * The age check is the one that earns this command. A database that stops being updated keeps
 * answering, with last year's data, and nothing else reports it - not the ACP, which shows the
 * build date without judging it, and not the weekly cron, which is silent when updates are managed
 * externally. An external updater that quietly stopped looks exactly like one that is working.
 */
class Validate extends Command
{
	/** Days after which a database is old enough to say so. MaxMind publishes GeoLite2 weekly. */
	private const STALE_AFTER_DAYS = 30;

	private const PROOF_IP = '8.8.8.8';

	use RendersReport;

	protected function configure()
	{
		$this
			->setName('geoblock:validate')
			->setDescription('Check that this forum can look up a country and act on it')
			->addOption('strict', null, InputOption::VALUE_NONE,
				'Exit 2 when there are warnings but no failures')
			->addOption('unattended', null, InputOption::VALUE_NONE,
				'Accepted for consistency with every other validate command. This one sends nothing, so it skips nothing');
	}

	protected function errorLogPrefix(): string
	{
		return 'Geoblock: ';
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$this->report = $output;
		$app = \XF::app();

		/** @var Maxmind $geoblock */
		$geoblock = $app->get('geoblock');

		$this->heading('Geoblock');
		$addOn = $app->addOnManager()->getById('Hampel/Geoblock');
		$this->detail('add-on', $addOn ? (string) $addOn->getJson()['version_string'] : '');
		$this->detail('XenForo', \XF::$version);

		$this->checkSection('Configuration');
		$this->checkTestMode();
		$this->checkUpdates();

		$this->checkSection('Database');
		$configured = $this->checkDatabasePresent($geoblock);
		if ($configured)
		{
			$this->checkDatabaseReadable($geoblock);
			$this->checkLookup($geoblock);
		}

		$this->checkSection('Decisions');
		$this->checkSomethingIsBlocked();

		$this->report->writeln('');

		return $this->checkExitCode((bool) $input->getOption('strict'));
	}

	/**
	 * Test mode is first because it makes every check below it describe something no registration
	 * will ever reach: getIpGeo() short-circuits to a canned result before the database is touched.
	 */
	private function checkTestMode(): void
	{
		$this->probe('test mode', function ()
		{
			if (TestMode::isEnabled())
			{
				$this->checkWarn('test mode', 'on - every lookup returns a canned result, the database is not read');

				return;
			}

			$this->checkOk('test mode', 'off');
		});
	}

	private function checkUpdates(): void
	{
		$this->probe('updates', function ()
		{
			$auto = AutoUpdate::isEnabled();
			$key = LicenseKey::get();

			if (!$auto)
			{
				$this->checkWarn('automatic updates', 'off - something else has to keep the database current');
				$this->checkSkip('license key', 'not needed while updates are managed externally');

				return;
			}

			$this->checkOk('automatic updates', 'on, weekly');

			if (empty($key))
			{
				$this->checkFail('license key', 'not set - the database can never be updated');

				return;
			}

			$this->checkOk('license key', 'set');
		});
	}

	private function checkDatabasePresent(Maxmind $geoblock): bool
	{
		return (bool) $this->probe('database', function () use ($geoblock)
		{
			$path = DatabasePath::get();

			if (empty($path))
			{
				$this->checkFail('database path', 'not set');

				return false;
			}

			if (!$geoblock->isConfigured())
			{
				$this->checkFail('database file', "not found at {$geoblock->databaseAbstracted()}");

				return false;
			}

			$this->checkOk('database file', $geoblock->databaseAbstracted());

			return true;
		});
	}

	private function checkDatabaseReadable(Maxmind $geoblock): void
	{
		$this->probe('database age', function () use ($geoblock)
		{
			$metadata = $geoblock->maxmind()->metadata();

			$description = $metadata->description['en'] ?? $metadata->databaseType;
			$this->checkOk('readable', $description);

			$built = (int) $metadata->buildEpoch;
			$days = (int) floor((\XF::$time - $built) / 86400);
			$on = gmdate('Y-m-d', $built);

			if ($days > self::STALE_AFTER_DAYS)
			{
				$this->checkWarn('age', "built {$on}, {$days} days ago - lookups are answering with stale data");

				return;
			}

			$this->checkOk('age', "built {$on}, {$days} days ago");
		});
	}

	private function checkLookup(Maxmind $geoblock): void
	{
		$this->probe('lookup', function () use ($geoblock)
		{
			try
			{
				$record = $geoblock->maxmind()->country(self::PROOF_IP);
			}
			catch (AddressNotFoundException $e)
			{
				$this->checkWarn('lookup', self::PROOF_IP . ' is not in this database');

				return;
			}

			$iso = $record->country->isoCode ?: $record->registeredCountry->isoCode;
			$this->checkOk('lookup', self::PROOF_IP . ' resolves to ' . ($iso ?: 'no country'));
		});
	}

	/**
	 * Whether any answer would change anything. Every list empty with non-listed countries allowed
	 * and EU rejection off is a working add-on that blocks nobody, which is a warning and not a
	 * failure: it is also exactly how a fresh install looks.
	 */
	private function checkSomethingIsBlocked(): void
	{
		$this->probe('decisions', function ()
		{
			$approved = Approved::get();
			$denied = Denied::get();

			$parts = [];
			if ($denied)
			{
				$parts[] = count($denied) . ' denied';
			}
			if ($approved)
			{
				$parts[] = count($approved) . ' approved';
			}
			if (ModerateOthers::isEnabled())
			{
				$parts[] = 'non-listed moderated';
			}
			if (RejectEu::isEnabled())
			{
				$parts[] = 'EU rejected';
			}

			if (!$denied && !ModerateOthers::isEnabled() && !RejectEu::isEnabled())
			{
				$this->checkWarn('blocking', 'nothing is denied, moderated or rejected - no registration is affected');

				return;
			}

			$this->checkOk('blocking', implode(', ', $parts));
		});
	}
}
