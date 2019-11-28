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
			$providers[] = 'Hampel\Geoblock:GeoIp';
		}
	}
}