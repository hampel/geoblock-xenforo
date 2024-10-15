<?php namespace Hampel\Geoblock\XF\Admin\Controller;

use Hampel\Geoblock\Option\DatabasePath;
use Hampel\Geoblock\SubContainer\Maxmind;
use Hampel\Geoblock\Test\AbstractTest;

class Tools extends XFCP_Tools
{
	public function actionHampelGeoblockUpdateMaxmind()
	{
		$this->setSectionContext('hampelGeoblockUpdateMaxmind');
        $this->assertAdminPermission('option');

		$messages = [];
		/** @var Maxmind $geoblock */
		$geoblock = $this->app->get('geoblock');

		if ($this->isPost())
		{
			if (!$this->app->get('geoblock')->updateDatabase())
			{
				$messages[] = "There were errors received while updating the database, please check the server error log.";
			}
		}

		$description = "";
		$build = 0;

		if ($geoblock->isConfigured())
		{
			$metadata = $geoblock->maxmind()->metadata();
			$description = $metadata->description['en'];
			$build = $metadata->buildEpoch;
		}

		$viewParams = compact('messages', 'description', 'build');

		return $this->view('Hampel\Geoblock:Tools\UpdateMaxmind', 'hampel_geoblock_tools_update_maxmind', $viewParams);
	}

	public function actionHampelGeoblockTest()
	{
		$this->setSectionContext('hampelGeoblockTest');
        $this->assertAdminPermission('option');

		$messages = [];
		$results = false;
		$test = '';
		$options = [
			'ip' => '',
			'bypass' => true,
		];

		if ($this->isPost())
		{
			$test = $this->filter('test', 'str');
			$options = $this->filter('options', 'array');

			/** @var AbstractTest $tester */
			$tester = $this->app->container()->create('geoblock.test', $test, [$this, $options]);
			if ($tester)
			{
				$results = $tester->run();
				$messages = $tester->getMessages();
			}
			else
			{
				return $this->error(\XF::phrase('geoblock_this_test_could_not_be_run'), 500);
			}
		}

		$viewParams = compact('results', 'messages', 'test', 'options');
		return $this->view('Hampel\Geoblock:Tools\Test', 'hampel_geoblock_tools_test', $viewParams);
	}
}