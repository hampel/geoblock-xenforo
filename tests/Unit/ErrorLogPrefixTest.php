<?php namespace Tests\Unit;

use Tests\TestCase;

/**
 * Every row this add-on writes to XenForo's server error log begins with `Geoblock: `, so that a
 * reader - a person, a log mirror, or the Admin API's error summary - can tell whose row it is
 * without reading the stack trace.
 *
 * A source scan rather than a behavioural test, because the failure it guards against is a NEW
 * call site added without the prefix, and no test of existing behaviour can see one of those.
 */
class ErrorLogPrefixTest extends TestCase
{
	private const PREFIX = 'Geoblock: ';

	/** Where the prefix comes from errorLogPrefix() instead of being written inline. */
	private const USES_THE_TRAIT = ['Cli/RendersReport.php'];

	public function test_every_error_log_call_carries_the_add_on_prefix()
	{
		$unprefixed = [];

		foreach ($this->sourceFiles() AS $relative => $path)
		{
			if (in_array($relative, self::USES_THE_TRAIT, true))
			{
				continue;
			}

			foreach (file($path) AS $i => $line)
			{
				if (!preg_match('/XF::log(Error|Exception)\s*\(/', $line))
				{
					continue;
				}

				if (strpos($line, self::PREFIX) === false)
				{
					$unprefixed[] = $relative . ':' . ($i + 1) . ' ' . trim($line);
				}
			}
		}

		$this->assertSame([], $unprefixed,
			'every XF::logError() and logException() must begin with "' . self::PREFIX . '"');
	}

	public function test_the_trait_builds_the_prefix_from_errorLogPrefix()
	{
		// The commands get theirs from the trait, which is abstract on that method so a command
		// without one does not load. This asserts the mechanism is still the mechanism.
		$trait = file_get_contents($this->addOnRoot() . '/Cli/RendersReport.php');

		$this->assertStringContainsString('abstract protected function errorLogPrefix()', $trait);
		$this->assertStringContainsString('{$this->errorLogPrefix()}', $trait);
	}

	public function test_every_command_returns_the_add_on_prefix()
	{
		$wrong = [];

		foreach (glob($this->addOnRoot() . '/Cli/Command/*.php') ?: [] AS $file)
		{
			$class = 'Hampel\\Geoblock\\Cli\\Command\\' . basename($file, '.php');
			$method = new \ReflectionMethod($class, 'errorLogPrefix');
			$method->setAccessible(true);

			$prefix = $method->invoke(new $class());
			if ($prefix !== self::PREFIX)
			{
				$wrong[] = "{$class}: " . var_export($prefix, true);
			}
		}

		$this->assertSame([], $wrong);
	}

	// ------------------------------------------------------------

	/** @return array<string,string> relative path => absolute path */
	private function sourceFiles(): array
	{
		$root = $this->addOnRoot();
		$files = [];

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator AS $file)
		{
			$path = $file->getPathname();
			$relative = substr($path, strlen($root) + 1);

			if (substr($path, -4) !== '.php')
			{
				continue;
			}

			// the add-on's own source only: vendor/ is other people's and tests/ may quote a
			// message deliberately
			if (strpos($relative, 'vendor/') === 0 || strpos($relative, 'tests/') === 0)
			{
				continue;
			}

			$files[$relative] = $path;
		}

		return $files;
	}

	private function addOnRoot(): string
	{
		return dirname(__DIR__, 2);
	}
}
