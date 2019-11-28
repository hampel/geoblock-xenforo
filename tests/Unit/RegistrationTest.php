<?php namespace Tests\Unit;

use GeoIp2\Database\Reader;
use Hampel\Geoblock\IpGeo;
use Hampel\Geoblock\SubContainer\Api;
use Mockery as m;
use Tests\TestCase;
use XF\Entity\User;
use XF\Service\User\Registration;
use XF\Spam\UserChecker;
use XF\SubContainer\Spam;

class RegistrationTest extends TestCase
{
	/** @var User */
	protected $user;

	protected $ip;

	protected $checker;

	protected $spam;

	protected function setUp() : void
	{
		parent::setUp();

		$this->isolateAddon('Hampel/Geoblock');

		$this->ip = '10.0.0.1';

		$this->user = $this->mockEntity('XF:User');

		$this->mockRepository('XF:User', function ($mock) {
			$mock->expects()->setupBaseUser()->once()->andReturns($this->user);
		});

		$this->checker = m::mock(UserChecker::class, function ($mock) {
			$mock->expects()->check()->once()->with($this->user);
			$mock->expects()->getFinalDecision()->once()->andReturns('allowed');
		});

		$this->spam = $this->mock('spam', Spam::class, function ($mock) {
			$mock->expects()->userChecker()->once()->andReturns($this->checker);
		});
	}

	public function test_registration_user_rejected()
	{
		$this->user->expects()->get('user_state')->once()->andReturns('rejected');

		/** @var Registration $reg */
		$reg = $this->app()->service('XF:User\Registration');
		$reg->setFromInput([]);

		$reg->checkForSpam();
	}

	public function test_registration_user_allowed_eu_not_rejected()
	{
		$this->user->expects()->get('user_state')->once()->andReturns('allowed');

		$this->setOption('geoblockRejectEu', false);

		$input = [];

		$reg = $this->app()->service('XF:User\Registration');
		$reg->setFromInput($input);

		$reg->checkForSpam();
	}

	public function test_registration_user_allowed_eu_rejected_but_not_in_eu()
	{
		$this->mockRequestGetIp();

		$ipgeo = $this->ipgeo(false);

		$this->mock('geoblock', Reader::class, function ($mock) use ($ipgeo) {
			$ipbin = \XF\Util\Ip::convertIpStringToBinary($this->ip);
			$mock->expects()->getIpGeo($ipbin)->once()->andReturns($ipgeo);
		});

		$this->user->expects()->get('user_state')->once()->andReturns('allowed');

		$this->setOption('geoblockRejectEu', true);

		$input = [];

		$reg = $this->app()->service('XF:User\Registration');
		$reg->setFromInput($input);

		$reg->checkForSpam();
	}

	public function test_registration_user_allowed_eu_rejected_and_is_in_eu_but_iso_in_approved()
	{
		$this->mockRequestGetIp();

		$ipgeo = $this->ipgeo(true);

		$this->mock('geoblock', Reader::class, function ($mock) use ($ipgeo) {
			$ipbin = \XF\Util\Ip::convertIpStringToBinary($this->ip);
			$mock->expects()->getIpGeo($ipbin)->once()->andReturns($ipgeo);
		});

		$this->user->expects()->get('user_state')->once()->andReturns('allowed');

		$this->setOptions([
			'geoblockRejectEu' => true,
			'geoblockApproved' => 'XX'
		]);

		$input = [];

		$reg = $this->app()->service('XF:User\Registration');
		$reg->setFromInput($input);

		$reg->checkForSpam();
	}

	public function test_registration_user_allowed_eu_rejected_and_is_in_eu_and_iso_not_in_approved()
	{
		$this->mockRequestGetIp();

		$ipgeo = $this->ipgeo(true);

		$this->mock('geoblock', Reader::class, function ($mock) use ($ipgeo) {
			$ipbin = \XF\Util\Ip::convertIpStringToBinary($this->ip);
			$mock->expects()->getIpGeo($ipbin)->once()->andReturns($ipgeo);
		});

		$phrase = $this->expectPhrase('geoblock_registration_rejected_eu');

		$this->user->expects()->get('user_state')->once()->andReturns('allowed');
		$this->spam->expects()->userChecker()->once()->andReturns($this->checker);
		$this->checker->expects()->logDetail('Geoblock-EU', 'geoblock_registration_in_eu')->once();
		$this->checker->expects()->logDecision('Geoblock-EU', 'denied')->once();
		$this->user->expects()->rejectUser($phrase)->once();

		$this->setOptions([
			'geoblockRejectEu' => true,
			'geoblockApproved' => ''
		]);

		$input = [];

		$reg = $this->app()->service('XF:User\Registration');
		$reg->setFromInput($input);

		$reg->checkForSpam();
	}

	// -------------------------------------------------------

	protected function mockRequestGetIp()
	{
		$this->mockRequest(function ($mock) {
			$mock->expects()->getIp(true)->once()->andReturns($this->ip);
		});
	}

	protected function ipgeo($inEu = false)
	{
		return IpGeo::newFromArray([
			'ip' => $this->ip,
			'iso_code' => 'XX',
			'name' => 'Foo',
			'eu' => $inEu,
		]);
	}
}
