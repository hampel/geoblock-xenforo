<?php namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
	/**
	 * Placeholder so the Feature suite has something to collect. Git does not track empty
	 * directories, so without a committed file here PHPUnit aborts the whole run on a fresh
	 * clone with "Test directory not found".
	 *
	 * It guards nothing beyond that - a run collecting this one test still reports OK when a
	 * real feature test is present but not collected. Check the count from
	 * `vendor/bin/phpunit --testsuite Feature` after adding the first real one.
	 */
	public function testBasicTest()
	{
		$this->assertTrue(true);
	}
}
