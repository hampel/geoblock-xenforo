<?php namespace Hampel\Geoblock\Test;

use Hampel\Geoblock\Option\Denied;
use Hampel\Geoblock\Option\RejectEu;
use Hampel\Geoblock\Option\Approved;
use Hampel\Geoblock\Option\RejectDenied;
use Hampel\Geoblock\Option\ModerateOthers;

class IsoDeciderTest extends AbstractTest
{
	public function run()
	{
		$isocode = strtoupper($this->data['isocode']);
		$ineu = isset($this->data['eu']) ? true : false;

		if (empty($isocode))
		{
			$this->errorMessage(\XF::phrase('geoblock_no_isocode'));
			return false;
		}

		$decision = $this->decider($isocode, $ineu);

		$this->successMessage(\XF::phrase("geoblock_decision_prefix", ['isocode' => $isocode]) . \XF::phrase("geoblock_decision_{$decision}"));
		return true;
	}

	protected function decider($iso_code, $ineu = false)
	{
		if (Approved::inList($iso_code))
		{
			return 'allowed';
		}

		$action = RejectDenied::isEnabled() ? 'denied' : 'moderated';

		if (Denied::inList($iso_code) && $action == 'denied')
		{
			return "denied_{$action}";
		}

		if (RejectEu::get() && $ineu)
		{
			return 'denied_eu';
		}

		if (Denied::inList($iso_code) && $action == 'moderated')
		{
			return "denied_{$action}";
		}

		if (ModerateOthers::isEnabled())
		{
			return 'moderated';
		}

		return 'no_decision';
	}
}
