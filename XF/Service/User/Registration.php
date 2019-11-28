<?php namespace Hampel\Geoblock\XF\Service\User;

use Hampel\Geoblock\Option\Approved;
use Hampel\Geoblock\Option\RejectEu;
use Hampel\Geoblock\SubContainer\Maxmind;

class Registration extends XFCP_Registration
{
	public function checkForSpam()
	{
		parent::checkForSpam();

		$this->geoblockCheckEu();
	}

	public function geoblockCheckEu()
	{
		$user = $this->user;

		if ($user->user_state == 'rejected')
		{
			return; // stop if user already rejected
		}

		if (RejectEu::isEnabled())
		{
			$ip = \XF\Util\Ip::convertIpStringToBinary(
				$this->app->request()->getIp(true)
			);

			/** @var Maxmind $api */
			$api = $this->app->get('geoblock');

			/** @var \Hampel\Geoblock\IpGeo $geo */
			$geo = $api->getIpGeo($ip);
			if ($geo && $geo->isInEu() && !Approved::inList($geo->getIsoCode()))
			{
				$type = 'Geoblock-EU';
				$userChecker = $this->app->spam()->userChecker();
				$userChecker->logDetail($type, 'geoblock_registration_in_eu');
				$userChecker->logDecision($type, 'denied');
				$user->rejectUser(\XF::phrase('geoblock_registration_rejected_eu'));
			}
		}
	}
}
