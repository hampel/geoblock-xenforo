<?php namespace Tests\Unit;

use GuzzleHttp\Psr7\Response;
use Hampel\Geoblock\Maxmind\DatabaseExtractor;
use Tests\TestCase;

/**
 * Covers Maxmind\DatabaseExtractor - download the GeoLite2 archive, extract the .mmdb from
 * inside it, move that onto the abstracted filesystem, clean up.
 *
 * Three things make this class awkward to test, and all three are worth knowing before changing
 * it:
 *
 *  - The download goes through XF\Http\Reader::getUntrusted(), which validates the URL against
 *    XenForo's SSRF rules *before* the http client is reached. Faking the client is necessary
 *    but not sufficient; setPrefix() points us at a host that passes validation.
 *
 *  - getUntrusted() saves to a file through Guzzle's `sink` option, so the fake has to honour
 *    it. fakesHttp() does; fakesHttpByUrl() does not, and silently writes nothing.
 *
 *  - The class extracts to a real path (XF\Util\File::getTempDir()) and then reads it back
 *    through the abstracted filesystem (internal-data://). Those are the same directory only
 *    while internal-data is the real filesystem, so swapFs('internal-data') breaks it. These
 *    tests therefore use the real filesystem and clean up after themselves.
 */
class DatabaseExtractorTest extends TestCase
{
	/** @var DatabaseExtractor */
	protected $extractor;

	/** namespaced so tearDown can remove it without touching anything else */
	protected $dest = 'internal-data://geoblock-test/country.mmdb';

	protected function setUp() : void
	{
		parent::setUp();

		$this->extractor = $this->app()->get('geoblock')->databaseExtractor();
		$this->extractor->setPrefix('http://example.com/geoip_download');
	}

	protected function tearDown() : void
	{
		$fs = $this->app()->fs();

		// unconditional: has() does not reliably report directories, so a guard here silently
		// skips the cleanup and leaks the destination into the next test
		$temp = sprintf($this->app()->config('tempDataPath'), 'internal-data://') . '/maxmind';

		foreach (['internal-data://geoblock-test', $temp] AS $dir)
		{
			try
			{
				$fs->deleteDir($dir);
			}
			catch (\Exception $e)
			{
			}
		}

		parent::tearDown();
	}

	// ------------------------------------------------ the request

	public function test_download_url_carries_the_edition_licence_key_and_suffix()
	{
		$this->fakesHttp([new Response(200, [], $this->archive())], true);

		$this->extractor->updateDatabase('key_foo', $this->dest);

		$this->assertHttpRequestSent(function ($request)
		{
			$url = (string) $request->getUri();

			return str_contains($url, 'edition_id=GeoLite2-Country')
				&& str_contains($url, 'license_key=key_foo')
				&& str_contains($url, 'suffix=tar.gz');
		});
	}

	public function test_makes_exactly_one_request()
	{
		$this->fakesHttp([new Response(200, [], $this->archive())], true);

		$this->extractor->updateDatabase('key_foo', $this->dest);

		$this->assertHttpRequestSentTimes(1);
	}

	public function test_edition_and_suffix_setters_change_what_is_requested()
	{
		$this->fakesHttp([new Response(200, [], $this->archive())], true);

		$this->extractor->setEdition('GeoLite2-City');
		$this->extractor->updateDatabase('key_foo', $this->dest);

		$this->assertHttpRequestSent(function ($request)
		{
			return str_contains((string) $request->getUri(), 'edition_id=GeoLite2-City');
		});
	}

	// ------------------------------------------------ the happy path

	public function test_extracts_the_mmdb_and_moves_it_to_the_destination()
	{
		$this->fakesHttp([new Response(200, [], $this->archive())], true);

		$this->assertTrue($this->extractor->updateDatabase('key_foo', $this->dest));
		$this->assertTrue($this->app()->fs()->has($this->dest));
	}

	public function test_what_lands_is_the_database_not_the_archive()
	{
		$this->fakesHttp([new Response(200, [], $this->archive())], true);
		$this->extractor->updateDatabase('key_foo', $this->dest);

		$written = $this->app()->fs()->read($this->dest);

		$this->assertNotEquals($this->archive(), $written, 'the compressed archive was stored verbatim');
		$this->assertStringContainsString('MaxMind.com', $written, 'expected mmdb metadata marker');
	}

	public function test_the_extracted_database_is_readable_by_the_maxmind_reader()
	{
		$this->fakesHttp([new Response(200, [], $this->archive())], true);
		$this->extractor->updateDatabase('key_foo', $this->dest);

		// prove the round trip produced a usable database, not just bytes of the right length
		$local = tempnam(sys_get_temp_dir(), 'mmdb');
		file_put_contents($local, $this->app()->fs()->read($this->dest));

		$reader = new \GeoIp2\Database\Reader($local);
		$this->assertEquals('US', $reader->country('8.8.8.8')->country->isoCode);

		$reader->close();
		@unlink($local);
	}

	public function test_replaces_an_existing_database()
	{
		$this->app()->fs()->put($this->dest, 'stale contents');

		$this->fakesHttp([new Response(200, [], $this->archive())], true);

		$this->assertTrue($this->extractor->updateDatabase('key_foo', $this->dest));
		$this->assertNotEquals('stale contents', $this->app()->fs()->read($this->dest));
	}

	public function test_cleans_up_the_temporary_extraction_directory()
	{
		$this->fakesHttp([new Response(200, [], $this->archive())], true);

		$this->extractor->updateDatabase('key_foo', $this->dest);

		$temp = sprintf($this->app()->config('tempDataPath'), 'internal-data://') . '/maxmind';

		$this->assertFalse($this->app()->fs()->has($temp), 'temporary directory was left behind');
	}

	// ------------------------------------------------ failure paths

	public function test_returns_false_when_the_response_is_not_an_archive()
	{
		$this->fakesErrors();
		$this->fakesHttp([new Response(200, [], 'this is not a tar.gz')], true);

		$this->assertFalse($this->extractor->updateDatabase('key_foo', $this->dest));
		$this->assertExceptionLogged(\UnexpectedValueException::class);
	}

	public function test_returns_false_when_the_archive_holds_no_mmdb()
	{
		$this->fakesErrors();
		$this->fakesHttp([new Response(200, [], $this->archiveWithoutDatabase())], true);

		$this->assertFalse($this->extractor->updateDatabase('key_foo', $this->dest));
		$this->assertFalse($this->app()->fs()->has($this->dest));
	}

	public function test_does_not_write_the_destination_when_the_download_fails()
	{
		$this->fakesErrors();
		$this->fakesHttp([new Response(500, [], '')], true);

		$this->extractor->updateDatabase('key_foo', $this->dest);

		$this->assertFalse($this->app()->fs()->has($this->dest));
	}

	public function test_leaves_an_existing_database_alone_when_the_download_fails()
	{
		$this->app()->fs()->put($this->dest, 'good database');

		$this->fakesErrors();
		$this->fakesHttp([new Response(500, [], '')], true);

		$this->extractor->updateDatabase('key_foo', $this->dest);

		$this->assertEquals('good database', $this->app()->fs()->read($this->dest));
	}

	/**
	 * Documents current behaviour rather than endorsing it. getUntrusted() reports only transport
	 * failures through $error, so an HTTP error status counts as a successful download: the error
	 * body is written to the temp file and the failure surfaces later, as Phar being unable to
	 * read it.
	 *
	 * MaxMind answers a bad licence key with an HTTP error, so this is the path a misconfigured
	 * forum actually takes, and what gets logged says nothing about the licence key.
	 */
	public function test_an_http_error_status_is_not_detected_as_a_download_failure()
	{
		$this->fakesErrors();
		$this->fakesHttp([new Response(401, [], 'Invalid license key')], true);

		$this->assertFalse($this->extractor->updateDatabase('key_foo', $this->dest));

		$this->assertEmpty($this->getErrors(), 'no plain error is logged for an HTTP failure');
		$this->assertExceptionLogged(\UnexpectedValueException::class);
	}

	// ------------------------------------------------ fixtures

	protected function archive()
	{
		return $this->getMockData('GeoLite2-Country.tar.gz');
	}

	/** a well-formed archive with no .mmdb inside it */
	protected function archiveWithoutDatabase()
	{
		return $this->getMockData('GeoLite2-NoDatabase.tar.gz');
	}
}
