<?php namespace Hampel\Geoblock\Cli\Command;

use Hampel\Geoblock\Cli\RendersReport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Download and install the current MaxMind GeoLite2 country database.
 *
 * This is the command an external cron task calls when the "automatically update" option is off,
 * and it updates whether that option is on or off: running it is the consent. A forum that wants
 * XenForo to do it on a schedule needs nothing here.
 *
 * It reports what happened and exits 1 if the update failed. The reason for a failure is in the
 * server error log, written by updateDatabase() where it occurred - this command does not repeat
 * the checks, because a second copy of them is a second thing to keep in step.
 */
class UpdateDatabase extends Command
{
	use RendersReport;

	protected function configure()
	{
		$this
			->setName('geoblock:update-database')
			->setDescription('Download and install the current MaxMind GeoLite2 country database');
	}

	protected function errorLogPrefix(): string
	{
		return 'Geoblock: ';
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$this->report = $output;

		$this->heading('Geoblock');

		$updated = $this->probe('update', function ()
		{
			return \XF::app()->get('geoblock')->updateDatabase();
		});

		if (!$updated)
		{
			$this->report->writeln('');
			$this->report->writeln('  <error>The database was not updated.</error> The reason is in the server error log.');
			$this->report->writeln('');

			return 1;
		}

		$this->report->writeln('');
		$this->report->writeln('  <info>The database was updated.</info>');
		$this->report->writeln('');

		return 0;
	}
}
