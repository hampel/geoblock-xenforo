<?php namespace Hampel\Geoblock\Cli\Command;

use Hampel\Geoblock\Cli\RendersReport;
use Hampel\Geoblock\Option\Approved;
use Hampel\Geoblock\Option\AutoUpdate;
use Hampel\Geoblock\Option\CacheExpiry;
use Hampel\Geoblock\Option\DatabasePath;
use Hampel\Geoblock\Option\Denied;
use Hampel\Geoblock\Option\LicenseKey;
use Hampel\Geoblock\Option\ModerateOthers;
use Hampel\Geoblock\Option\RejectDenied;
use Hampel\Geoblock\Option\RejectEu;
use Hampel\Geoblock\Option\TestMode;
use Hampel\Geoblock\SubContainer\Maxmind;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What this forum is configured to do with a registration, and where the database comes from. It
 * reads and prints; it writes nothing and sends nothing. geoblock:validate is the command that
 * finds out whether any of it works.
 *
 * The license key prints as set or not set, never its value - output like this ends up pasted into
 * tickets. Everything here comes from the options page; this add-on reads no config.php keys, so
 * there is no source column to print.
 */
class Config extends Command
{
	use RendersReport;

	protected function configure()
	{
		$this
			->setName('geoblock:config')
			->setDescription('Show what this forum is configured to block, and where its database comes from');
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
		$this->detail('test mode', TestMode::isEnabled()
			? 'ON - every lookup returns a canned result' : 'off');

		$this->heading('Database');
		$this->detail('automatic updates', AutoUpdate::isEnabled() ? 'on, weekly' : 'off - managed externally');
		$this->detail('license key', LicenseKey::get() ? 'set' : '');
		$this->detail('path', (string) DatabasePath::get());
		$this->detail('file', $geoblock->isConfigured() ? 'present' : 'NOT FOUND');

		if ($geoblock->isConfigured())
		{
			// Guarded because metadata() opens the file; without one it throws, and config must not.
			$built = $this->probe('database', function () use ($geoblock)
			{
				return (int) $geoblock->maxmind()->metadata()->buildEpoch;
			});

			if ($built)
			{
				$days = (int) floor((\XF::$time - $built) / 86400);
				$this->detail('built', gmdate('Y-m-d', $built) . ", {$days} days ago");
			}
		}

		$this->detail('cache expiry', CacheExpiry::get() . ' days');

		$this->heading('Decisions');
		$this->detail('approved', $this->countryList(Approved::get()));
		$this->detail('denied', $this->countryList(Denied::get()));
		$this->detail('denied countries', RejectDenied::isEnabled() ? 'rejected' : 'moderated');
		$this->detail('non-listed countries', ModerateOthers::isEnabled() ? 'moderated' : 'allowed');
		$this->detail('EU countries', RejectEu::isEnabled() ? 'rejected' : 'allowed');

		$this->report->writeln('');

		return 0;
	}

	/**
	 * The codes themselves, because which countries are listed is the question being asked, with a
	 * count so a long list is still readable at a glance.
	 */
	private function countryList(array $codes): string
	{
		if (!$codes)
		{
			return '';
		}

		return count($codes) . ': ' . implode(' ', $codes);
	}
}
