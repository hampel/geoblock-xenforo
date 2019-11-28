<?php namespace Hampel\Geoblock\Test;

use Hampel\Geoblock\Util\IpGeo;

class GeoblockTest extends AbstractTest
{
	public function run()
	{
		if (!$this->api->isConfigured())
		{
			$group = $this->app->finder('XF:OptionGroup')->whereId('geoblock')->fetchOne();

			$this->errorMessage(\XF::phrase('geoblock_error_configuration_required', [
				'optionurl' => $this->controller->buildLink('full:options/groups', $group) . "#geoblockDatabaseUrl",
			]));
			return false;
		}

		$ip = $this->data['ip'];
		$bypass = isset($this->data['bypass']) ? true : false;

		try
		{
			$geo = $this->api->getIpGeo($ip, $bypass);
			if (!$geo)
			{
				$this->errorMessage(\XF::phrase('geoblock_not_found', ['ip' => $ip]));
				return false;
			}
			$inEu = $geo->isInEu() ? \XF::phrase('geoblock_is_in_eu') : '';
			$this->successMessage(\XF::phrase('geoblock_test_results', $geo->toArray()) . $inEu);

		}
		catch (\Exception $e)
		{
			$this->errorMessage($e->getMessage());
			return false;
		}

		return true;
	}

}
