<?php namespace Tests\Unit;

use Hampel\Geoblock\Listener;
use Tests\TestCase;

/**
 * Listener::adminApiRedaction() declares which settings are secrets, and the declaration goes
 * stale silently: rename or add an option and nothing fails, while the Admin API either exposes a
 * credential or withholds something harmless.
 *
 * Needs nothing from Hampel/AdminApi - the declaration is an array of strings, which is what keeps
 * the add-on free of a dependency on it.
 *
 * Every test here asserts once, outside any loop. The add-on has one secret and no array options,
 * so a test whose only assertions sat inside a loop would assert nothing on most of them, and
 * phpunit.xml sets failOnRisky, so that reports as a failure rather than passing quietly.
 */
class SecretsDeclarationTest extends TestCase
{
	/** Options or members that read like a credential and are not one, with the reason. */
	private const NOT_SECRETS = [];

	private const CREDENTIAL = '/key|token|secret|password|webhook/i';

	public function test_it_declares_this_add_on_only()
	{
		// An entry for another add-on's id would be a claim about code this session has not read.
		$this->assertSame(['Hampel/Geoblock'], array_keys($this->declarations()));
	}

	public function test_it_declares_the_license_key_and_nothing_else()
	{
		$this->assertSame(['geoblockLicenseKey'], $this->declared()['options']);
	}

	public function test_it_claims_no_config_block()
	{
		// This add-on reads no config.php block of its own. It reads XenForo's tempDataPath, which
		// is core's to declare: claiming a key we do not own is the same error as declaring another
		// add-on's id.
		$this->assertSame([], $this->declared()['config']);
	}

	public function test_every_declared_secret_is_an_option_this_add_on_has()
	{
		$problems = [];

		foreach ($this->declared()['options'] AS $path)
		{
			$parts = explode('.', $path);
			$file = $this->addOnRoot() . "/_output/options/{$parts[0]}.json";

			if (!is_file($file))
			{
				$problems[] = "{$path}: no such option";
				continue;
			}

			$members = json_decode(file_get_contents($file), true)['sub_options'] ?? [];
			if (isset($parts[1]) && !in_array($parts[1], $members, true)
				&& !in_array('*', $members, true))
			{
				$problems[] = "{$path}: not a member of {$parts[0]}";
			}
		}

		$this->assertSame([], $problems);
	}

	public function test_an_option_that_reads_like_a_credential_is_declared_or_decided()
	{
		$decided = array_merge(self::NOT_SECRETS, $this->declared()['options']);

		$undecided = [];

		foreach ($this->optionIds() AS $optionId)
		{
			$file = $this->addOnRoot() . "/_output/options/{$optionId}.json";

			if (!in_array($optionId, $decided, true) && preg_match(self::CREDENTIAL, $optionId))
			{
				$undecided[] = $optionId;
			}

			// a credential usually sits beside a switch inside one array option, under a member
			// name that no option id matches - so walk the members too
			foreach (json_decode(file_get_contents($file), true)['sub_options'] ?? [] AS $member)
			{
				$path = "{$optionId}.{$member}";

				if (preg_match(self::CREDENTIAL, $member) && !in_array($path, $decided, true))
				{
					$undecided[] = $path;
				}
			}
		}

		$this->assertSame([], $undecided, 'declare each as a secret, or add it to NOT_SECRETS');
	}

	// ------------------------------------------------------------

	private function declarations(): array
	{
		$declarations = [];
		Listener::adminApiRedaction($declarations);

		return $declarations;
	}

	private function declared(): array
	{
		return $this->declarations()['Hampel/Geoblock'] + ['options' => [], 'config' => []];
	}

	private function optionIds(): array
	{
		$ids = [];

		foreach (glob($this->addOnRoot() . '/_output/options/*.json') ?: [] AS $file)
		{
			if (basename($file) != '_metadata.json')
			{
				$ids[] = basename($file, '.json');
			}
		}

		return $ids;
	}

	private function addOnRoot(): string
	{
		return dirname(__DIR__, 2);
	}
}
