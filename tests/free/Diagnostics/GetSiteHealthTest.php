<?php

namespace WPMCP\Tests\Free\Diagnostics;

use WPMCP\Tests\Free\Platform\RegisteredAbilities;
use WPMCP\Tools\Diagnostics\Get_Site_Health;

/**
 * get-site-health (issue #381): run the Site Health tests, core and those
 * other plugins register through site_status_tests, and return their results
 * as plain text, with a time bound on the async tests and a cached mode.
 */
class GetSiteHealthTest extends \WP_UnitTestCase
{
    /** @var float Fake clock read by the tool under test. */
    private float $now = 1000.0;

    /** @var array<string, int> How many times each fixture test ran. */
    private array $runs = [];

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        delete_transient(Get_Site_Health::CACHE_KEY);
        $this->runs = [];
        add_filter('site_status_tests', [$this, 'fixture_tests']);
        // Core's direct tests (and plugins' tests) make HTTP requests, which
        // the suite's network guard blocks; answer them all with a plain 200.
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    public function fake_http($pre, array $args, string $url): array
    {
        $body = '{}';
        if (false !== strpos($url, 'serve-happy')) {
            $body = (string) wp_json_encode([
                'recommended_version' => '8.3',
                'minimum_version'     => '7.4',
                'is_supported'        => true,
                'is_secure'           => true,
                'is_acceptable'       => true,
            ]);
        }

        return [
            'headers'  => [],
            'body'     => $body,
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    protected function tearDown(): void
    {
        remove_filter('site_status_tests', [$this, 'fixture_tests']);
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        parent::tearDown();
    }

    /**
     * Replace core's async tests (they make real HTTP requests) with
     * fixtures, keep core's direct tests, and add third-party tests the way a
     * plugin would.
     */
    public function fixture_tests(array $tests): array
    {
        $tests['async'] = [];

        $tests['direct']['acme_direct'] = [
            'label' => 'Acme direct check',
            'test'  => function () {
                $this->runs['acme_direct'] = ($this->runs['acme_direct'] ?? 0) + 1;
                return [
                    'label'       => 'Acme is <em>configured</em>',
                    'status'      => 'recommended',
                    'badge'       => ['label' => 'Performance', 'color' => 'orange'],
                    'description' => '<p>Acme caches <strong>pages</strong> &amp; feeds.</p>',
                    'actions'     => '<p><a href="https://example.com/acme-fix">Fix Acme</a></p>',
                    'test'        => 'acme_direct',
                ];
            },
        ];

        $tests['async']['acme_async'] = [
            'label'             => 'Acme async check',
            'test'              => 'acme_async',
            'has_rest'          => false,
            'async_direct_test' => function () {
                $this->runs['acme_async'] = ($this->runs['acme_async'] ?? 0) + 1;
                return [
                    'label'       => 'Acme remote reachable',
                    'status'      => 'good',
                    'badge'       => ['label' => 'Security', 'color' => 'blue'],
                    'description' => '<p>Reachable.</p>',
                    'actions'     => '',
                    'test'        => 'acme_async',
                ];
            },
        ];

        return $tests;
    }

    private function tool(): Get_Site_Health
    {
        return new Get_Site_Health(fn (): float => $this->now);
    }

    /** @return array<string, array<string, mixed>> results keyed by id. */
    private static function by_id(array $out): array
    {
        $map = [];
        foreach ($out['results'] as $row) {
            $map[ $row['id'] ] = $row;
        }
        return $map;
    }

    public function test_is_registered_as_a_non_read_only_read_at_view_site_health_checks(): void
    {
        $found = null;
        foreach (RegisteredAbilities::all() as $ability) {
            if ('wpmcp/get-site-health' === $ability->name) {
                $found = $ability;
            }
        }

        $this->assertNotNull($found, 'get-site-health must be registered');
        $this->assertSame('free', $found->tier);
        $this->assertSame('read', $found->operation);
        $this->assertSame('view_site_health_checks', $found->capability);
        $this->assertFalse($found->read_only_hint);
    }

    public function test_runs_core_and_third_party_tests(): void
    {
        $out     = $this->tool()->handle([]);
        $results = self::by_id($out);

        $this->assertFalse($out['cached']);
        $this->assertArrayHasKey('php_version', $results, 'core direct tests run');
        $this->assertArrayHasKey('acme_direct', $results, 'third-party direct tests run');
        $this->assertArrayHasKey('acme_async', $results, 'third-party async tests run');

        $this->assertSame('direct', $results['php_version']['type']);
        $this->assertContains($results['php_version']['status'], ['good', 'recommended', 'critical'], (string) ($results['php_version']['reason'] ?? ''));
        $this->assertSame('async', $results['acme_async']['type']);
        $this->assertSame('good', $results['acme_async']['status']);
        $this->assertTrue($results['acme_async']['completed']);

        $total = array_sum($out['summary']);
        $this->assertSame(count($out['results']), $total);
    }

    public function test_reduces_html_to_plain_text_and_keeps_action_links(): void
    {
        $row = self::by_id($this->tool()->handle(['tests' => ['acme_direct']]))['acme_direct'];

        $this->assertSame('recommended', $row['status']);
        $this->assertSame('Acme is configured', $row['label']);
        $this->assertSame(['label' => 'Performance', 'color' => 'orange'], $row['badge']);
        $this->assertSame('Acme caches pages & feeds.', $row['description']);
        $this->assertSame('Fix Acme', $row['actions']);
        $this->assertSame([['text' => 'Fix Acme', 'url' => 'https://example.com/acme-fix']], $row['action_links']);
    }

    public function test_site_status_test_result_filter_applies_like_the_screen(): void
    {
        $filter = static function (array $result): array {
            if ('acme_direct' === ($result['test'] ?? '')) {
                $result['status'] = 'critical';
            }
            return $result;
        };
        add_filter('site_status_test_result', $filter);

        $row = self::by_id($this->tool()->handle(['tests' => ['acme_direct']]))['acme_direct'];

        remove_filter('site_status_test_result', $filter);
        $this->assertSame('critical', $row['status']);
    }

    public function test_tests_filter_runs_only_the_named_subset(): void
    {
        $out = $this->tool()->handle(['tests' => ['acme_async', 'no_such_test']]);

        $this->assertSame(['acme_async'], array_keys(self::by_id($out)));
        $this->assertSame(['no_such_test'], $out['unknown_tests']);
        $this->assertArrayNotHasKey('acme_direct', $this->runs);
    }

    public function test_async_tests_past_the_time_bound_are_not_completed(): void
    {
        $slow_then_skipped = function (array $tests): array {
            $tests['async'] = [
                'acme_slow'  => [
                    'label'             => 'Acme slow check',
                    'test'              => 'acme_slow',
                    'async_direct_test' => function () {
                        $this->now += 60;
                        return ['label' => 'Slow', 'status' => 'good', 'test' => 'acme_slow'];
                    },
                ],
                'acme_after' => [
                    'label'             => 'Acme later check',
                    'test'              => 'acme_after',
                    'async_direct_test' => function () {
                        $this->runs['acme_after'] = 1;
                        return ['label' => 'Later', 'status' => 'good', 'test' => 'acme_after'];
                    },
                ],
            ];
            return $tests;
        };
        add_filter('site_status_tests', $slow_then_skipped, 20);

        $out     = $this->tool()->handle(['tests' => ['acme_slow', 'acme_after'], 'timeout' => 5]);
        $results = self::by_id($out);

        remove_filter('site_status_tests', $slow_then_skipped, 20);

        $this->assertFalse($results['acme_slow']['completed']);
        $this->assertSame('not_completed', $results['acme_slow']['status']);
        $this->assertSame('Acme slow check', $results['acme_slow']['label']);
        $this->assertNotEmpty($results['acme_slow']['reason']);

        $this->assertFalse($results['acme_after']['completed']);
        $this->assertSame('not_completed', $results['acme_after']['status']);
        $this->assertArrayNotHasKey('acme_after', $this->runs, 'a test past the deadline is not started');
        $this->assertSame(2, $out['summary']['not_completed']);
    }

    public function test_async_http_requests_are_capped_to_the_remaining_time(): void
    {
        $seen = null;
        $probe = function (array $tests) use (&$seen): array {
            $tests['async'] = [
                'acme_http' => [
                    'label'             => 'Acme HTTP check',
                    'test'              => 'acme_http',
                    'async_direct_test' => function () use (&$seen) {
                        $args = apply_filters('http_request_args', ['timeout' => 30], 'https://example.com/');
                        $seen = $args['timeout'];
                        return ['label' => 'HTTP', 'status' => 'good', 'test' => 'acme_http'];
                    },
                ],
            ];
            return $tests;
        };
        add_filter('site_status_tests', $probe, 20);

        $this->tool()->handle(['tests' => ['acme_http'], 'timeout' => 4]);

        remove_filter('site_status_tests', $probe, 20);
        $this->assertNotNull($seen);
        $this->assertLessThanOrEqual(4, $seen);
        $this->assertSame(30, apply_filters('http_request_args', ['timeout' => 30], 'https://example.com/')['timeout']);
    }

    public function test_async_tests_that_only_run_over_admin_ajax_are_reported_not_completed(): void
    {
        $ajax_only = static function (array $tests): array {
            $tests['async'] = [
                'acme_ajax' => [
                    'label'    => 'Acme ajax check',
                    'test'     => 'acme_ajax_action',
                    'has_rest' => false,
                ],
            ];
            return $tests;
        };
        add_filter('site_status_tests', $ajax_only, 20);

        $row = self::by_id($this->tool()->handle(['tests' => ['acme_ajax']]))['acme_ajax'];

        remove_filter('site_status_tests', $ajax_only, 20);
        $this->assertFalse($row['completed']);
        $this->assertSame('not_completed', $row['status']);
    }

    public function test_a_throwing_test_does_not_fail_the_call(): void
    {
        $boom = static function (array $tests): array {
            $tests['direct']['acme_boom'] = [
                'label' => 'Acme boom',
                'test'  => static function () {
                    throw new \RuntimeException('kaboom');
                },
            ];
            return $tests;
        };
        add_filter('site_status_tests', $boom, 20);

        $row = self::by_id($this->tool()->handle(['tests' => ['acme_boom']]))['acme_boom'];

        remove_filter('site_status_tests', $boom, 20);
        $this->assertFalse($row['completed']);
        $this->assertStringContainsString('kaboom', $row['reason']);
    }

    public function test_cached_mode_returns_last_full_run_without_rerunning(): void
    {
        $fresh = $this->tool()->handle([]);
        $this->assertSame(1, $this->runs['acme_direct']);

        $cached = $this->tool()->handle(['cached' => true]);

        $this->assertSame(1, $this->runs['acme_direct'], 'cached mode must not rerun tests');
        $this->assertTrue($cached['cached']);
        $this->assertSame($fresh['results'], $cached['results']);
        $this->assertSame($fresh['summary'], $cached['summary']);
        $this->assertSame($fresh['generated_at'], $cached['generated_at']);
    }

    public function test_subset_runs_do_not_replace_the_cached_full_result(): void
    {
        $this->tool()->handle([]);
        $this->tool()->handle(['tests' => ['acme_async']]);

        $cached = $this->tool()->handle(['cached' => true]);
        $this->assertArrayHasKey('acme_direct', self::by_id($cached));
    }

    public function test_cached_mode_without_a_stored_run_says_so(): void
    {
        $out = $this->tool()->handle(['cached' => true]);

        $this->assertTrue($out['cached']);
        $this->assertFalse($out['available']);
        $this->assertSame([], $out['results']);
        $this->assertEmpty($this->runs);
    }
}
