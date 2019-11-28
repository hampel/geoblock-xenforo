<?php namespace Hampel\Geoblock\SubContainer;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\GeoIp2Exception;
use Hampel\Geoblock\IpGeo;
use Hampel\Geoblock\Maxmind\DatabaseExtractor;
use Hampel\Geoblock\Option\DatabasePath;
use Hampel\Geoblock\Option\DatabaseUrl;
use Hampel\Geoblock\Option\TestMode;
use MaxMind\Db\Reader\InvalidDatabaseException;
use XF\Container;
use XF\SubContainer\AbstractSubContainer;

class Maxmind extends AbstractSubContainer
{
	public function initialize()
	{
		$container = $this->container;

		$container['maxmind'] = function($c)
		{
			return new Reader(DatabasePath::getCanonicalPath());
		};

		$container['extractor'] = function(Container $c)
		{
			return new DatabaseExtractor($this->app);
		};
	}

	public function isConfigured()
	{
		return !empty(DatabasePath::get())
			&& !empty(DatabaseUrl::get())
			&& $this->app->fs()->has(DatabasePath::getAbstractedPath());
	}

	/**
	 * @param string $ip
	 *
	 * @return null|IpGeo;
	 */
	public function getIpGeo($ip, $bypassCache = false)
	{
		if (TestMode::isEnabled())
		{
			return new IpGeo('10.0.0.1', 'XX', 'Test Mode');
		}

		$repo = $this->repo();

		$geo = $repo->checkPrivateOrReserved($ip);
		if ($geo) return $geo;

		if (!$bypassCache)
		{
			$geo = $repo->getFromCache($ip);
			if ($geo) return $geo;

			$geo = $repo->getFromDb($ip);
			if ($geo)
			{
				$repo->updateCache($geo);
				return $geo;
			}
		}

		if ($this->isConfigured())
		{
			$geo = $this->getFromMaxmindDb($ip);
			if ($geo)
			{
				$repo->updateDb($geo);
				$repo->updateCache($geo);
				return $geo;
			}
		}
	}

	/**
	 * @return Reader
	 */
	public function maxmind()
	{
		return $this->container['maxmind'];
	}

	/**
	 * @return DatabaseExtractor
	 */
	public function databaseExtractor()
	{
		return $this->container['extractor'];
	}

	/**
	 * @param $ip
	 *
	 * @return IpGeo|null
	 */
	protected function getFromMaxmindDb($ip)
	{
		try
		{
			$data = $this->maxmind()->country($ip);
			if (!empty($data)) return IpGeo::newFromModel($data);
		}
		catch (GeoIp2Exception $e)
		{
			\XF::logException($e, false, "Error retrieving data from Maxmind GeoLite2 database for IP [$ip]: ");
		}
		catch (InvalidDatabaseException $e)
		{
			\XF::logException($e, false, "Invalid database error retrieving data from Maxmind GeoLite2 database for IP [$ip]: ");
		}
	}

	/**
	 * @return \Hampel\Geoblock\Repository\GeoIp
	 */
	protected function repo()
	{
		return $this->app->repository('Hampel\Geoblock:GeoIp');
	}

	protected function logException(\Exception $e, $ip = '')
	{
		\XF::logError(\XF::phrase('geoblock_error', ['ip' => $ip, 'code' => $e->getCode(), 'message' => $e->getMessage()]));
	}

	public function updateDatabase()
	{
		$dbUrl = DatabaseUrl::get();
		if (empty($dbUrl))
		{
			$this->logError("Geoblock database URL not configured");
			return false;
		}

		$dbPath = DatabasePath::getAbstractedPath();
		if (empty($dbPath))
		{
			$this->logError("Geoblock database path not configured");
			return false;
		}

		$extractor = $this->databaseExtractor();

		$compressedDatabaseFile = $extractor->getTempFile(DatabaseUrl::getUrlBasename());

		if (!$extractor->downloadDatabase($dbUrl, $compressedDatabaseFile))
		{
			return false;
		}

		$extractedDatabasePath = $extractor->getTempDest();

		if (!$extractor->extractDatabase($compressedDatabaseFile, $extractedDatabasePath))
		{
			return false;
		}

		$abstractedDatabasePath = $extractor->getAbstractedTempDest();

		if (!$extractor->moveDatabase($abstractedDatabasePath, $dbPath))
		{
			return false;
		}

		return $extractor->cleanupDatabase($abstractedDatabasePath);
	}
}
