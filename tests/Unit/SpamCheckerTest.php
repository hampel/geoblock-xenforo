<?php namespace Tests\Unit;

use Hampel\Geoblock\IpGeo;
use Hampel\Geoblock\Spam\Checker\Geoblock;
use Hampel\Geoblock\SubContainer\Api;
use Mockery as m;
use Tests\TestCase;
use XF\Entity\User;
use XF\Spam\AbstractChecker;

class SpamCheckerTest extends TestCase
{
	protected $ip;

	/** @var Api */
	protected $api;

	protected $checker;

	protected $provider;

	protected function setUp() : void
	{
		parent::setUp();

		$this->ip = '10.0.0.1';

		$this->api = $this->mockApi();

		$this->checker = m::mock(AbstractChecker::class);

		$this->provider = new Geoblock($this->checker, $this->app);

		$this->mockRequest(function ($mock) {
			$mock->expects()->getIp(true)->once()->andReturns($this->ip);
		});
	}

	public function test_getIpGeo_returns_null()
	{
		$this->expectIpGeo($this->ip, null);

		$this->checker->expects()->logDecision('Geoblock', 'allowed')->once();

		$this->provider->check($this->getUser());
	}

	public function test_IpGeo_in_approved_list()
	{
		$this->expectIpGeo($this->ip, $this->ipgeo());

		$this->setOption('geoblockApproved', 'XX');

		$this->checker->expects()->logDecision('Geoblock', 'allowed')->once();

		$this->provider->check($this->getUser());
	}

	public function test_IpGeo_in_approved_list_array()
	{
		$this->expectIpGeo($this->ip, $this->ipgeo());

		$this->setOption('geoblockApproved', 'WW, XX,YY,  ZZ');

		$this->checker->expects()->logDecision('Geoblock', 'allowed')->once();

		$this->provider->check($this->getUser());
	}

	public function test_IpGeo_denied_and_moderated()
	{
		$this->expectIpGeo($this->ip, $this->ipgeo());

		$this->setOptions([
			'geoblockApproved' => '',
			'geoblockDenied' => 'XX',
			'geoblockRejectDenied' => 0,
		]);

		$this->checker->expects()->logDecision('Geoblock', 'moderated')->once();
		$this->checker->expects()->logDetail('Geoblock', 'geoblock_registration_denied_list', [])->once();

		$this->provider->check($this->getUser());
	}

	public function test_IpGeo_denied_and_rejected()
	{
		$this->expectIpGeo($this->ip, $this->ipgeo());

		$this->setOptions([
			'geoblockApproved' => '',
			'geoblockDenied' => 'XX',
			'geoblockRejectDenied' => 1,
		]);

		$this->checker->expects()->logDecision('Geoblock', 'denied')->once();
		$this->checker->expects()->logDetail('Geoblock', 'geoblock_registration_denied_list', [])->once();

		$this->provider->check($this->getUser());
	}

	public function test_IpGeo_neither_allowed_nor_denied()
	{
		$this->expectIpGeo($this->ip, $this->ipgeo());

		$this->setOptions([
			'geoblockApproved' => '',
			'geoblockDenied' => '',
			'geoblockModerateOthers' => 0,
		]);

		$this->checker->expects()->logDecision('Geoblock', 'allowed')->once();

		$this->provider->check($this->getUser());
	}

	public function test_IpGeo_neither_allowed_nor_denied_moderate_others()
	{
		$this->expectIpGeo($this->ip, $this->ipgeo());

		$this->setOptions([
			'geoblockApproved' => '',
			'geoblockDenied' => '',
			'geoblockModerateOthers' => 1,
		]);

		$this->checker->expects()->logDecision('Geoblock', 'moderated')->once();
		$this->checker->expects()->logDetail('Geoblock', 'geoblock_registration_neither_allowed_nor_denied', [])->once();

		$this->provider->check($this->getUser());
	}

	// --------------------------------------------------------

	protected function expectIpGeo($ip, $return)
	{
		$this->api->expects()
		          ->getIpGeo($ip)
		          ->once()
		          ->andReturns($return);
	}

	protected function mockApi()
	{
		return $this->mock('geoblock', Api::class, function ($mock) {

		});
	}

	protected function getUser()
	{
		return m::mock(User::class);
	}

	protected function ipgeo()
	{
		return new IpGeo($this->ip, 'XX', 'Foo');
	}
}
