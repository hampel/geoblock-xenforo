<?php namespace Hampel\Geoblock\Maxmind;

use XF\App;
use XF\Util\File;

class DatabaseExtractor
{
	// TODO: unit tests for this class

	/** @var App */
	protected $app;

	public function __construct(App $app)
	{
		$this->app = $app;
	}

	public function downloadDatabase($source, $dest)
	{
		if(!$this->app->http()->reader()->getUntrusted($source, [], $dest, [], $error))
		{
			\XF::logError($error);
			return false;
		}

		return true;
	}

	public function extractDatabase($source, $dest)
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

	public function moveDatabase($source, $dest)
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

	public function cleanupDatabase($dir)
	{
		return $this->app->fs()->deleteDir($dir);
	}

	public function getTempFile($filename)
	{
		return File::getNamedTempFile($filename);
	}

	public function getTempDest()
	{
		return sprintf("%s/maxmind", File::getTempDir());
	}

	public function getAbstractedTempPath()
	{
		return sprintf($this->app->config('tempDataPath'), 'internal-data://');
	}

	public function getAbstractedTempDest()
	{
		return sprintf("%s/maxmind", $this->getAbstractedTempPath());
	}

	public function recurseArchive($archive, $path = '')
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
