<?php namespace Tests\Unit;

use Hampel\Geoblock\IpGeo;
use Hampel\Geoblock\SubContainer\Maxmind;
use Mockery as m;
use Tests\TestCase;
use XF\Entity\User;

/**
 * Covers the EU rejection path in the XF\Service\User\Registration extension.
 *
 * This is the second of the add-on's two block paths and the only one that rejects a user
 * outright, which is why it cannot run as a spam provider. It had no coverage at all until
 * 2026-09-27, and writing it found that the path had never worked in any released version.
 *
 * Two things to know before changing these tests.
 *
 * getIpGeo() takes a DOTTED STRING, not packed binary. Conversion happens downstream, in
 * Finder\GeoIp::getGeoIp() and Entity\GeoIp::verifyIp(), at the database boundary where the
 * varbinary(16) column needs it. Pass binary in and
 * Repository\GeoIp::checkPrivateOrReserved() fails filter_var() and returns iso '??' before any
 * lookup runs, so isInEu() is false for every address on earth and the EU branch is unreachable.
 * test_passes_dotted_string_to_lookup() pins that, and it is the regression test for the bug.
 *
 * The user is a mock, not a real entity, because rejectUser() calls save(). A real save cannot
 * work here: with $addonsToLoad isolating this add-on, the entity structure carries only its own
 * columns while xf_user still has NOT NULL columns from other add-ons with no default. Asserting
 * on rejectUser() is also the better assertion - it is this add-on's decision, where the user's
 * resulting state is XenForo's own behaviour.
 */
class RegistrationEuTest extends TestCase
{
	protected $ip = '8.8.8.8';

	protected function service(?User $user = null)
	{
		$service = $this->app->service(\XF\Service\User\Registration::class);

		if ($user)
		{
			$property = new \ReflectionProperty($service, 'user');
			$property->setAccessible(true);
			$property->setValue($service, $user);
		}

		return $service;
	}

	protected function user($state = 'valid')
	{
		$user = m::mock(User::class);

		// Entity::__get() delegates to get(), so get() is the seam - mocking __get() alone
		// leaves the real one running and it calls straight through to an unmocked get().
		$user->allows()->get('user_state')->andReturns($state);

		return $user;
	}

	protected function expectLookup($return)
	{
		return $this->mock('geoblock', Maxmind::class, function ($mock) use ($return) {
			// a dotted string, never binary - see the class docblock
			$mock->expects()->getIpGeo($this->ip)->once()->andReturns($return);

			// building the real spam userChecker fires this add-on's own spam_user_providers
			// listener, which asks whether MaxMind is configured before registering the provider.
			// false keeps the provider out of the way; the EU path does not go through it.
			$mock->allows()->isConfigured()->andReturns(false);
		});
	}

	protected function expectIp()
	{
		$this->mockRequest(function ($mock) {
			$mock->expects()->getIp(true)->once()->andReturns($this->ip);
		});
	}

	public function test_extension_is_applied()
	{
		$this->assertInstanceOf(
			\Hampel\Geoblock\XF\Service\User\Registration::class,
			$this->service(),
			'the class extension is not applied, so every other test here would exercise core'
		);
	}

	public function test_passes_dotted_string_to_lookup()
	{
		$this->setOption('geoblockRejectEu', 1);
		$this->expectIp();
		$this->expectLookup(null);

		$this->service($this->user())->geoblockCheckEu();
	}

	public function test_rejects_when_in_eu()
	{
		$this->setOptions(['geoblockRejectEu' => 1, 'geoblockApproved' => '']);
		$this->expectIp();
		$this->expectLookup(new IpGeo($this->ip, 'DE', 'Germany', true));

		$user = $this->user();
		$user->expects()->rejectUser(m::any())->once();

		$this->service($user)->geoblockCheckEu();
	}

	public function test_approved_list_wins_over_eu()
	{
		$this->setOptions(['geoblockRejectEu' => 1, 'geoblockApproved' => 'DE']);
		$this->expectIp();
		$this->expectLookup(new IpGeo($this->ip, 'DE', 'Germany', true));

		$user = $this->user();
		$user->expects()->rejectUser(m::any())->never();

		$this->service($user)->geoblockCheckEu();
	}

	public function test_does_not_reject_outside_eu()
	{
		$this->setOptions(['geoblockRejectEu' => 1, 'geoblockApproved' => '']);
		$this->expectIp();
		$this->expectLookup(new IpGeo($this->ip, 'US', 'United States', false));

		$user = $this->user();
		$user->expects()->rejectUser(m::any())->never();

		$this->service($user)->geoblockCheckEu();
	}

	public function test_already_rejected_user_is_left_alone()
	{
		$this->setOption('geoblockRejectEu', 1);

		$user = $this->user('rejected');
		$user->expects()->rejectUser(m::any())->never();

		// no lookup at all - the early return happens before the IP is read
		$this->mock('geoblock', Maxmind::class, function ($mock) {
			$mock->expects()->getIpGeo(m::any())->never();
		});

		$this->service($user)->geoblockCheckEu();
	}
}
