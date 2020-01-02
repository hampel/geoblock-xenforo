<?php namespace Hampel\Geoblock\Maxmind;

use XF\App;
use XF\Util\File;


class DatabaseExtractor
{
	// TODO: unit tests for this class

	/** @var App */
	protected $app;

	protected $urlPrefix = "https://download.maxmind.com/app/geoip_download";

	protected $edition = "GeoLite2-Country";

	protected $suffix = "tar.gz";

	public function __construct(App $app)
	{
		$this->app = $app;
	}

	public function setPrefix($prefix)
	{
		$this->urlPrefix = $prefix;
	}

	public function setEdition($edition)
	{
		$this->edition = $edition;
	}

	public function setSuffix($suffix)
	{
		$this->suffix = $suffix;
	}

	public function updateDatabase($licenseKey, $destPath)
	{
		$downloadUrl = $this->buildDownloadUrl($licenseKey);

		$compressedDatabaseFile = $this->getTempFile();

		if (!$this->downloadDatabase($downloadUrl, $compressedDatabaseFile))
		{
			return false;
		}

		$extractedDatabasePath = $this->getTempDest();

		if (!$this->extractDatabase($compressedDatabaseFile, $extractedDatabasePath))
		{
			return false;
		}

		$abstractedDatabasePath = $this->getAbstractedTempDest();

		if (!$this->moveDatabase($abstractedDatabasePath, $destPath))
		{
			return false;
		}

		return $this->cleanupDatabase($abstractedDatabasePath);
	}

	protected function downloadDatabase($source, $dest)
	{
		if(!$this->app->http()->reader()->getUntrusted($source, [], $dest, [], $error))
		{
			\XF::logError($error);
			return false;
		}

		return true;
	}

	protected function buildDownloadUrl($licenseKey, $prefix = "https://download.maxmind.com/app/geoip_download", $edition = "GeoLite2-Country", $suffix = "tar.gz")
	{
		return sprintf("%s?edition_id=%s&license_key=%s&suffix=%s", $prefix, $edition, $licenseKey, $suffix);
	}

	protected function extractDatabase($source, $dest)
	{
		stream_wrapper_restore('phar');

		try
		{
			$archive = new \PharData($source);

			$db = $this->recurseArchive($archive);
		}
		catch (\UnexpectedValueException $e)
		{
			\XF::logException($e, false, "Could not read PharData [{$source}]: ");
			return false;
		}

		try
		{
			$archive->extractTo($dest, $db, true);
		}
		catch (\PharException $e)
		{
			\XF::logException($e, false, "Could not extract PharData from [{$source}] to [{$dest}]: ");
			return false;
		}

		return true;
	}

	protected function moveDatabase($source, $dest)
	{
		$fs = $this->app->fs();

		$contents = $fs->listContents($source, true);

		foreach ($contents as $object)
		{
			if ($object['type'] == 'file' && $object['extension'] == 'mmdb')
			{
				$sourceFile = sprintf("%s://%s", $object['filesystem'], $object['path']);
				if ($fs->has($dest))
				{
					$fs->delete($dest);
				}
				return $fs->move($sourceFile, $dest);
			}
		}

		return false;
	}

	protected function cleanupDatabase($dir)
	{
		return $this->app->fs()->deleteDir($dir);
	}

	protected function getTempFile()
	{
		$filename = sprintf("%s.%s", $this->edition, $this->suffix);

		return File::getNamedTempFile($filename);
	}

	protected function getTempDest()
	{
		return sprintf("%s/maxmind", File::getTempDir());
	}

	protected function getAbstractedTempPath()
	{
		return sprintf($this->app->config('tempDataPath'), 'internal-data://');
	}

	protected function getAbstractedTempDest()
	{
		return sprintf("%s/maxmind", $this->getAbstractedTempPath());
	}

	protected function recurseArchive($archive, $path = '')
	{
		foreach ($archive as $contents)
		{
			if ($contents->isFile())
			{
				if ($contents->getExtension() == 'mmdb')
				{
					return "{$path}/" . $contents->getBasename();
				}
			}
			elseif ($contents->isDir())
			{
				return $this->recurseArchive(new \PharData($contents), $contents->getBasename());
			}
		}
	}
}
