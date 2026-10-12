<?php namespace Hampel\Geoblock;

use XF\App;
use XF\Container;
use Hampel\Geoblock\SubContainer\Maxmind;

class Listener
{
	public static function appSetup(App $app)
	{
		$container = $app->container();

		$container['geoblock'] = function(Container $c) use ($app)
		{
			$class = $app->extendClass(Maxmind::class);
			return new $class($c, $app);
		};
	}

	public static function appAdminSetup(App $app)
	{
		$container = $app->container();

		$container->factory('geoblock.test', function($class, array $params, Container $c) use ($app)
		{
			$class = \XF::stringToClass($class, '\%s\Test\%s');
			$class = $app->extendClass($class);

			array_unshift($params, $app);

			return $c->createObject($class, $params, true);
		}, false);
	}

	public static function spamUserProviders(\XF\SubContainer\Spam $container, \XF\Container $parentContainer, array &$providers)
	{
		/** @var Maxmind $api */
		$api = $parentContainer['geoblock'];

		if ($api->isConfigured())
		{
			$providers[] = 'Hampel\Geoblock:Geoblock';
		}
	}

	/**
	 * Declares which of this add-on's settings are secrets, so that the Admin API can show the
	 * rest of them. Until an add-on declares, every one of its text settings is withheld as a
	 * precaution - which here hid the country lists and the database path, none of which is
	 * sensitive, while the license key was protected only because its name happens to read like a
	 * credential.
	 *
	 * The license key is the one secret: it is what authenticates the GeoLite2 download. The
	 * database path is a path and the country lists are country codes, so neither is declared.
	 *
	 * No `config` entry: this add-on reads no config.php block of its own. It reads XenForo's
	 * tempDataPath, which is core's to declare and not ours to claim.
	 *
	 * This is not a dependency on Hampel/AdminApi. A code event no installed add-on defines is
	 * never fired, so on a forum without it this method is never called and costs nothing - keep
	 * it to strings and nothing from that add-on's namespace.
	 */
	public static function adminApiRedaction(array &$declarations)
	{
		$declarations['Hampel/Geoblock'] = [
			'options' => ['geoblockLicenseKey'],
		];
	}
}
