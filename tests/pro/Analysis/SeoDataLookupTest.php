<?php

namespace WPMCP\Tests\Pro\Analysis;

use WPMCP\Tools\Analysis\Analyze_Seo;
use WPMCP\Tools\Analysis\SeoData\Dataforseo_Provider;
use WPMCP\Tools\Analysis\SeoData\Seo_Data_Key_Store;
use WPMCP\Tools\Analysis\SeoData\Seo_Data_Lookup;
use WPMCP\Tools\Analysis\SeoData\Seo_Data_Provider;

/**
 * Keyword and backlink data from an SEO data provider (issue #304), served as
 * the keywords and backlinks ops of analyze-seo. Every request is mocked with
 * pre_http_request; the suite's network guard fails anything unmocked.
 */
class SeoDataLookupTest extends \WP_UnitTestCase
{
    private const CREDENTIAL = 'agent@example.test:s3cr3t-Pa55word';

    /** @var array<int, array{url:string,args:array}> */
    private array $requests = [];

    /** @var array<int, array{code:int, body:mixed, headers?:array}> queued responses, first in first out. */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();
        add_filter('pre_http_request', [$this, 'mock'], 10, 3);
        delete_option(Seo_Data_Key_Store::OPTION);
        Seo_Data_Lookup::flush_cache();
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'mock'], 10);
        delete_option(Seo_Data_Key_Store::OPTION);
        Seo_Data_Lookup::flush_cache();
        remove_all_filters('wpmcp_seo_data_cache_ttl');
        parent::tearDown();
    }

    public function mock($preempt, $args, $url)
    {
        $this->requests[] = ['url' => (string) $url, 'args' => (array) $args];
        $next = array_shift($this->responses) ?? ['code' => 500, 'body' => []];
        return [
            'headers'  => $next['headers'] ?? ['content-type' => 'application/json'],
            'body'     => is_string($next['body']) ? $next['body'] : (string) wp_json_encode($next['body']),
            'response' => ['code' => $next['code'], 'message' => ''],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    private function queue(int $code, $body, array $headers = []): void
    {
        $entry = ['code' => $code, 'body' => $body];
        if ($headers) {
            $entry['headers'] = $headers;
        }
        $this->responses[] = $entry;
    }

    /** A DataForSEO envelope around one task result. */
    private static function envelope(array $result, int $task_code = 20000, string $task_message = 'Ok.'): array
    {
        return [
            'version'        => '0.1.20250101',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'tasks'          => [[
                'status_code'    => $task_code,
                'status_message' => $task_message,
                'result'         => 20000 === $task_code ? [$result] : null,
            ]],
        ];
    }

    private static function keyword_overview(): array
    {
        return self::envelope([
            'location_code' => 2840,
            'language_code' => 'en',
            'items_count'   => 2,
            'items'         => [
                [
                    'keyword'            => 'running shoes',
                    'keyword_info'       => ['search_volume' => 201000, 'cpc' => 1.42, 'competition' => 0.98, 'competition_level' => 'HIGH'],
                    'keyword_properties' => ['keyword_difficulty' => 87],
                    'search_intent_info' => ['main_intent' => 'commercial'],
                ],
                [
                    'keyword'            => 'trail running shoes',
                    'keyword_info'       => ['search_volume' => 49500, 'cpc' => 0.91, 'competition' => 1, 'competition_level' => 'HIGH'],
                    'keyword_properties' => ['keyword_difficulty' => 54],
                    'search_intent_info' => ['main_intent' => 'transactional'],
                ],
            ],
        ]);
    }

    private static function backlinks_summary(): array
    {
        return self::envelope([
            'target'                 => 'example.com',
            'rank'                   => 512,
            'backlinks'              => 183042,
            'backlinks_spam_score'   => 7,
            'broken_backlinks'       => 1204,
            'referring_domains'      => 4211,
            'referring_main_domains' => 3890,
            'referring_ips'          => 3102,
            'referring_pages'        => 150233,
        ]);
    }

    /** Throws and returns the exception, so a test can inspect it. */
    private function refusal(array $args): \Throwable
    {
        try {
            (new Analyze_Seo())->handle($args);
        } catch (\Throwable $e) {
            return $e;
        }
        $this->fail('Expected analyze-seo to refuse ' . wp_json_encode($args));
    }

    public function test_the_provider_implements_the_provider_interface(): void
    {
        $this->assertInstanceOf(Seo_Data_Provider::class, new Dataforseo_Provider());
        $this->assertSame('dataforseo', (new Dataforseo_Provider())->slug());
    }

    public function test_keywords_without_a_key_gives_a_clear_error_and_sends_nothing(): void
    {
        $e = $this->refusal(['op' => 'keywords', 'keywords' => ['running shoes']]);

        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertStringContainsString('set-seo-data-key', $e->getMessage());
        $this->assertStringContainsString('dataforseo', $e->getMessage());
        $this->assertSame([], $this->requests, 'No request may leave without a key.');
    }

    public function test_backlinks_without_a_key_gives_a_clear_error_and_sends_nothing(): void
    {
        $e = $this->refusal(['op' => 'backlinks', 'target' => 'example.com']);

        $this->assertStringContainsString('set-seo-data-key', $e->getMessage());
        $this->assertSame([], $this->requests);
    }

    public function test_keyword_metrics_are_parsed_from_the_mocked_response(): void
    {
        Seo_Data_Key_Store::set('dataforseo', self::CREDENTIAL);
        $this->queue(200, self::keyword_overview());

        $out = (new Analyze_Seo())->handle([
            'op'       => 'keywords',
            'keywords' => ['Running Shoes', 'trail running shoes', 'running shoes', '  '],
        ]);

        $this->assertCount(1, $this->requests);
        $request = $this->requests[0];
        $this->assertSame('https://api.dataforseo.com/v3/dataforseo_labs/google/keyword_overview/live', $request['url']);
        $this->assertSame('POST', $request['args']['method']);
        $this->assertSame('Basic ' . base64_encode(self::CREDENTIAL), $request['args']['headers']['Authorization']);
        $body = json_decode((string) $request['args']['body'], true);
        $this->assertSame(['running shoes', 'trail running shoes'], $body[0]['keywords'], 'Keywords are trimmed, lowercased and de-duplicated.');
        $this->assertSame(2840, $body[0]['location_code']);
        $this->assertSame('en', $body[0]['language_code']);

        $this->assertSame('keywords', $out['op']);
        $this->assertSame('dataforseo', $out['provider']);
        $this->assertFalse($out['cached']);
        $this->assertCount(2, $out['keywords']);
        $first = $out['keywords'][0];
        $this->assertSame('running shoes', $first['keyword']);
        $this->assertTrue($first['found']);
        $this->assertSame(201000, $first['search_volume']);
        $this->assertSame(87, $first['keyword_difficulty']);
        $this->assertSame(1.42, $first['cpc']);
        $this->assertSame(0.98, $first['competition']);
        $this->assertSame('commercial', $first['intent']);
        $this->assertSame(54, $out['keywords'][1]['keyword_difficulty']);
    }

    public function test_a_keyword_the_provider_has_no_data_for_is_reported_not_found(): void
    {
        Seo_Data_Key_Store::set('dataforseo', self::CREDENTIAL);
        $this->queue(200, self::keyword_overview());

        $out = (new Analyze_Seo())->handle(['op' => 'keywords', 'keywords' => ['running shoes', 'zzqx unknown term']]);

        $this->assertSame('zzqx unknown term', $out['keywords'][1]['keyword']);
        $this->assertFalse($out['keywords'][1]['found']);
        $this->assertNull($out['keywords'][1]['search_volume']);
        $this->assertNull($out['keywords'][1]['keyword_difficulty']);
    }

    public function test_keywords_are_validated_before_any_request(): void
    {
        Seo_Data_Key_Store::set('dataforseo', self::CREDENTIAL);

        $this->assertInstanceOf(\InvalidArgumentException::class, $this->refusal(['op' => 'keywords']));
        $this->assertInstanceOf(\InvalidArgumentException::class, $this->refusal(['op' => 'keywords', 'keywords' => ['  ']]));
        $this->assertInstanceOf(
            \InvalidArgumentException::class,
            $this->refusal(['op' => 'keywords', 'keywords' => array_map(static fn ($i) => 'kw ' . $i, range(1, Seo_Data_Lookup::MAX_KEYWORDS + 1))])
        );
        $this->assertSame([], $this->requests);
    }

    public function test_backlink_summary_is_parsed_and_a_url_is_reduced_to_its_domain(): void
    {
        Seo_Data_Key_Store::set('dataforseo', self::CREDENTIAL);
        $this->queue(200, self::backlinks_summary());

        $out = (new Analyze_Seo())->handle(['op' => 'backlinks', 'target' => 'https://www.Example.com/']);

        $this->assertCount(1, $this->requests);
        $this->assertSame('https://api.dataforseo.com/v3/backlinks/summary/live', $this->requests[0]['url']);
        $body = json_decode((string) $this->requests[0]['args']['body'], true);
        $this->assertSame('example.com', $body[0]['target']);

        $this->assertSame('backlinks', $out['op']);
        $this->assertSame('example.com', $out['target']);
        $this->assertSame('domain', $out['target_type']);
        $this->assertFalse($out['cached']);
        $this->assertSame(183042, $out['summary']['backlinks']);
        $this->assertSame(4211, $out['summary']['referring_domains']);
        $this->assertSame(3890, $out['summary']['referring_main_domains']);
        $this->assertSame(150233, $out['summary']['referring_pages']);
        $this->assertSame(1204, $out['summary']['broken_backlinks']);
        $this->assertSame(512, $out['summary']['rank']);
        $this->assertSame(7, $out['summary']['spam_score']);
    }

    public function test_a_page_url_is_sent_as_an_absolute_url(): void
    {
        Seo_Data_Key_Store::set('dataforseo', self::CREDENTIAL);
        $this->queue(200, self::backlinks_summary());

        $out = (new Analyze_Seo())->handle(['op' => 'backlinks', 'target' => 'https://example.com/blog/post-1']);

        $body = json_decode((string) $this->requests[0]['args']['body'], true);
        $this->assertSame('https://example.com/blog/post-1', $body[0]['target']);
        $this->assertSame('page', $out['target_type']);
    }

    public function test_an_invalid_target_is_refused_before_any_request(): void
    {
        Seo_Data_Key_Store::set('dataforseo', self::CREDENTIAL);

        foreach (['', 'not a domain', 'ftp://example.com/x', 'localhost'] as $target) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $this->refusal(['op' => 'backlinks', 'target' => $target]), $target);
        }
        $this->assertSame([], $this->requests);
    }

    public function test_results_are_cached_so_a_repeat_lookup_sends_no_request(): void
    {
        Seo_Data_Key_Store::set('dataforseo', self::CREDENTIAL);
        $this->queue(200, self::keyword_overview());
        $this->queue(200, self::backlinks_summary());

        (new Analyze_Seo())->handle(['op' => 'keywords', 'keywords' => ['running shoes', 'trail running shoes']]);
        (new Analyze_Seo())->handle(['op' => 'backlinks', 'target' => 'example.com']);
        $this->assertCount(2, $this->requests);

        $again = (new Analyze_Seo())->handle(['op' => 'keywords', 'keywords' => ['trail running shoes']]);
        $links = (new Analyze_Seo())->handle(['op' => 'backlinks', 'target' => 'https://example.com']);

        $this->assertCount(2, $this->requests, 'Cached keywords and targets must not be fetched again.');
        $this->assertTrue($again['cached']);
        $this->assertSame(49500, $again['keywords'][0]['search_volume']);
        $this->assertTrue($links['cached']);
        $this->assertSame(183042, $links['summary']['backlinks']);
    }

    public function test_only_uncached_keywords_are_requested(): void
    {
        Seo_Data_Key_Store::set('dataforseo', self::CREDENTIAL);
        $this->queue(200, self::keyword_overview());
        (new Analyze_Seo())->handle(['op' => 'keywords', 'keywords' => ['running shoes']]);

        $this->queue(200, self::keyword_overview());
        $out = (new Analyze_Seo())->handle(['op' => 'keywords', 'keywords' => ['running shoes', 'trail running shoes']]);

        $body = json_decode((string) $this->requests[1]['args']['body'], true);
        $this->assertSame(['trail running shoes'], $body[0]['keywords']);
        $this->assertFalse($out['cached']);
        $this->assertSame(['running shoes', 'trail running shoes'], array_column($out['keywords'], 'keyword'));
    }

    public function test_the_cache_is_scoped_to_location_and_language(): void
    {
        Seo_Data_Key_Store::set('dataforseo', self::CREDENTIAL);
        $this->queue(200, self::keyword_overview());
        $this->queue(200, self::keyword_overview());

        (new Analyze_Seo())->handle(['op' => 'keywords', 'keywords' => ['running shoes']]);
        (new Analyze_Seo())->handle(['op' => 'keywords', 'keywords' => ['running shoes'], 'location_code' => 2826, 'language_code' => 'en']);

        $this->assertCount(2, $this->requests);
        $body = json_decode((string) $this->requests[1]['args']['body'], true);
        $this->assertSame(2826, $body[0]['location_code']);
    }

    public function test_a_zero_cache_ttl_disables_caching(): void
    {
        add_filter('wpmcp_seo_data_cache_ttl', '__return_zero');
        Seo_Data_Key_Store::set('dataforseo', self::CREDENTIAL);
        $this->queue(200, self::backlinks_summary());
        $this->queue(200, self::backlinks_summary());

        (new Analyze_Seo())->handle(['op' => 'backlinks', 'target' => 'example.com']);
        (new Analyze_Seo())->handle(['op' => 'backlinks', 'target' => 'example.com']);

        $this->assertCount(2, $this->requests);
    }

    public function test_a_rate_limit_answer_starts_a_cooldown_that_sends_nothing(): void
    {
        Seo_Data_Key_Store::set('dataforseo', self::CREDENTIAL);
        $this->queue(429, ['status_code' => 40202, 'status_message' => 'The rate-limit per minute has been exceeded.'], ['retry-after' => '42']);

        $first = $this->refusal(['op' => 'backlinks', 'target' => 'example.com']);
        $this->assertStringContainsString('rate', strtolower($first->getMessage()));
        $this->assertStringContainsString('42', $first->getMessage());
        $this->assertCount(1, $this->requests);

        $second = $this->refusal(['op' => 'keywords', 'keywords' => ['running shoes']]);
        $this->assertStringContainsString('rate', strtolower($second->getMessage()));
        $this->assertCount(1, $this->requests, 'During the cooldown no request may be sent.');
    }

    public function test_a_provider_level_rate_limit_status_also_starts_the_cooldown(): void
    {
        Seo_Data_Key_Store::set('dataforseo', self::CREDENTIAL);
        $this->queue(200, self::envelope([], 40202, 'The rate-limit per minute has been exceeded.'));

        $this->refusal(['op' => 'backlinks', 'target' => 'example.com']);
        $this->refusal(['op' => 'backlinks', 'target' => 'example.org']);

        $this->assertCount(1, $this->requests);
    }

    public function test_rejected_credentials_give_a_clear_error_without_the_key(): void
    {
        Seo_Data_Key_Store::set('dataforseo', self::CREDENTIAL);
        $this->queue(401, ['status_code' => 40100, 'status_message' => 'You are not authorized to access this resource.']);

        $e = $this->refusal(['op' => 'keywords', 'keywords' => ['running shoes']]);

        $this->assertStringContainsString('set-seo-data-key', $e->getMessage());
        $this->assert_no_secret($e->getMessage());
    }

    public function test_a_failed_task_is_reported_with_the_provider_message(): void
    {
        Seo_Data_Key_Store::set('dataforseo', self::CREDENTIAL);
        $this->queue(200, self::envelope([], 40210, 'Insufficient Funds.'));

        $e = $this->refusal(['op' => 'backlinks', 'target' => 'example.com']);

        $this->assertStringContainsString('Insufficient Funds', $e->getMessage());
        $this->assert_no_secret($e->getMessage());
    }

    public function test_a_transport_error_never_echoes_the_key(): void
    {
        Seo_Data_Key_Store::set('dataforseo', self::CREDENTIAL);
        remove_filter('pre_http_request', [$this, 'mock'], 10);
        $leaky = static fn () => new \WP_Error('http_request_failed', 'cURL error 6 for Basic ' . base64_encode(self::CREDENTIAL) . ' ' . self::CREDENTIAL);
        add_filter('pre_http_request', $leaky, 10, 3);

        try {
            $e = $this->refusal(['op' => 'backlinks', 'target' => 'example.com']);
        } finally {
            remove_filter('pre_http_request', $leaky, 10);
        }

        $this->assertStringContainsString('cURL error 6', $e->getMessage());
        $this->assert_no_secret($e->getMessage());
    }

    public function test_the_key_never_appears_in_a_successful_response(): void
    {
        Seo_Data_Key_Store::set('dataforseo', self::CREDENTIAL);
        $this->queue(200, self::keyword_overview());
        $this->queue(200, self::backlinks_summary());

        $this->assert_no_secret((string) wp_json_encode((new Analyze_Seo())->handle(['op' => 'keywords', 'keywords' => ['running shoes']])));
        $this->assert_no_secret((string) wp_json_encode((new Analyze_Seo())->handle(['op' => 'backlinks', 'target' => 'example.com'])));
    }

    public function test_an_unknown_op_or_provider_is_refused(): void
    {
        $this->assertInstanceOf(\InvalidArgumentException::class, $this->refusal(['op' => 'nope']));
        $this->assertInstanceOf(\InvalidArgumentException::class, $this->refusal(['op' => 'keywords', 'keywords' => ['x'], 'provider' => 'nope']));
        $this->assertSame([], $this->requests);
    }

    public function test_the_default_score_op_is_unchanged(): void
    {
        $id  = self::factory()->post->create(['post_title' => 'Hi', 'post_content' => '<p>Body.</p>']);
        $out = (new Analyze_Seo())->handle(['post_id' => $id]);

        $this->assertSame($id, $out['post_id']);
        $this->assertArrayHasKey('report', $out);
        $this->assertSame([], $this->requests);
    }

    private function assert_no_secret(string $haystack): void
    {
        [$login, $password] = explode(':', self::CREDENTIAL, 2);
        $this->assertStringNotContainsString($password, $haystack);
        $this->assertStringNotContainsString(self::CREDENTIAL, $haystack);
        $this->assertStringNotContainsString(base64_encode(self::CREDENTIAL), $haystack);
    }
}
