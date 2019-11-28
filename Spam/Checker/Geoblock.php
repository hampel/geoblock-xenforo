<?php namespace Hampel\Geoblock\Spam\Checker;

use Hampel\Geoblock\IpGeo;
use Hampel\Geoblock\Option\Denied;
use Hampel\Geoblock\Option\Approved;
use Hampel\Geoblock\SubContainer\Maxmind;
use XF\Spam\Checker\AbstractProvider;
use Hampel\Geoblock\Option\RejectDenied;
use Hampel\Geoblock\Option\ModerateOthers;
use XF\Spam\Checker\UserCheckerInterface;

class Geoblock extends AbstractProvider implements UserCheckerInterface
{
	protected function getType()
	{
		return 'Geoblock';
	}

	public function check(\XF\Entity\User $user, array $extraParams = [])
	{
		$ip = $this->app()->request()->getIp(true);

		/** @var Maxmind $api */
		$api = $this->app->get('geoblock');

		/** @var IpGeo $geo */
		$geo = $api->getIpGeo($ip);
		if (!$geo)
		{
			// api not configured, or error occurred - we can't do any further checks, so just allow the registration
			$this->logDecision('allowed');
			return;
		}

		$iso_code = $geo->getIsoCode();
		if (Approved::inList($iso_code))
		{
			$this->logDecision('allowed');
			return;
		}

		if (Denied::inList($iso_code))
		{
			$action = 'moderated';
			if (RejectDenied::isEnabled())
			{
				$action = 'denied';
			}

			$this->logDecision($action);
			$this->logDetail('geoblock_registration_denied_list');

			return;
		}

		if (ModerateOthers::isEnabled())
		{
			$this->logDecision('moderated');
			$this->logDetail('geoblock_registration_neither_allowed_nor_denied');

			return;
		}

		$this->logDecision('allowed');
	}

	public function submit(\XF\Entity\User $user, array $extraParams = [])
	{
		return;
	}
}