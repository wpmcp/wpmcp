<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Query_Monitor_Status;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;

/**
 * Query Monitor page profile (issue #703): one free, read-only op on the
 * theme dispatcher pair next to the other operations status packs, so it
 * adds no top-level tool.
 *
 * get-query-monitor-page-profile loads one page of this site as a logged-out
 * visitor, with a one-time token in the URL. The profiled request lets Query
 * Monitor collect (Query Monitor stops collecting for a visitor who cannot
 * view its output, so the token request may, and only for
 * view_query_monitor), keeps Query Monitor's own output out of the page,
 * and at shutdown stores a summary of the collectors' data under the token
 * for the calling request to read and delete.
 *
 * Query Monitor is not installed in the shared test core. Presence is driven
 * through wpmcp_query_monitor_active, the loopback request is answered by a
 * pre_http_request filter that runs the profiled request's two steps in
 * process, and the collectors' data comes through
 * wpmcp_query_monitor_collector_data, shaped as Query Monitor 4.1.1 stores
 * it (QM_Data_Overview, QM_Data_DB_Queries rows with a stack, QM_Data_HTTP
 * requests, QM_Data_PHP_Errors keyed errors, QM_Data_Hooks hooks).
 */
class QueryMonitorStatusTest extends \WP_UnitTestCase
{
    private const OP = 'get-query-monitor-page-profile';

    /** @var array<int, array{url: string, args: array}> */
    private array $requests = [];

    /** @var array<string, mixed> */
    private array $collector_data = [];

    /** Whether the fake loopback runs the profiled request's hooks. */
    private bool $run_profiled_request = true;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_query_monitor_active', '__return_true');
        add_filter('wpmcp_query_monitor_collector_data', fn ($data, string $id) => $this->collector_data[ $id ] ?? null, 10, 2);
        add_filter('pre_http_request', [ $this, 'fake_loopback' ], 10, 3);
        $this->collector_data = self::sample_data();
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_query_monitor_active');
        remove_all_filters('wpmcp_query_monitor_collector_data');
        remove_filter('pre_http_request', [ $this, 'fake_loopback' ], 10);
        unset($_GET[ Query_Monitor_Status::PARAM ]);
        Query_Monitor_Status::end_profile();
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /**
     * Stands in for the web server: records the request and, when asked,
     * runs what the profiled request would run (the init step that reads
     * the token, then the shutdown step that stores the summary).
     *
     * @return array<string, mixed>
     */
    public function fake_loopback($pre, array $args, string $url): array
    {
        $this->requests[] = [ 'url' => $url, 'args' => $args ];
        if ($this->run_profiled_request) {
            $query = (string) wp_parse_url($url, PHP_URL_QUERY);
            parse_str($query, $params);
            $_GET[ Query_Monitor_Status::PARAM ] = (string) ($params[ Query_Monitor_Status::PARAM ] ?? '');
            Query_Monitor_Status::start_profile();
            Query_Monitor_Status::finish_profile();
            unset($_GET[ Query_Monitor_Status::PARAM ]);
        }
        return [
            'headers'  => [],
            'body'     => '<html></html>',
            'response' => [ 'code' => 200, 'message' => 'OK' ],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    private function catalog(): array
    {
        return array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
    }

    private function read(array $args = []): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => self::OP, 'args' => $args ]);
    }

    private function profile(array $args = []): array
    {
        $out = $this->read($args);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'];
    }

    /** A stack frame list the way Query Monitor keeps it without its db.php drop-in. */
    private static function sample_data(): array
    {
        $plugin = ABSPATH . 'wp-content/plugins/slow-plugin/slow.php';
        return [
            'overview'   => [
                'time_taken'   => 0.8123,
                'time_limit'   => 30,
                'memory'       => 41943040,
                'memory_limit' => 268435456.0,
            ],
            'db_queries' => [
                'total_qs' => 4,
                'rows'     => [
                    [ 'sql' => "SELECT option_value FROM wp_options WHERE option_name = 'siteurl'", 'ltime' => 0.0004, 'stack' => [ 'require_once()', 'get_option()' ], 'result' => 1 ],
                    [ 'sql' => "SELECT * FROM wp_users WHERE user_email = 'ada@example.com'", 'ltime' => 0.25, 'stack' => [ 'do_action()', 'Slow_Plugin->lookup()' ], 'result' => 1 ],
                    [ 'sql' => "SELECT * FROM wp_slow WHERE token = 'tok-secret-123'", 'ltime' => 0.0101, 'stack' => [ 'Slow_Plugin->load()' ], 'result' => new \WP_Error('qmdb', "Table 'wp_slow' doesn't exist") ],
                    [ 'sql' => "SELECT * FROM wp_slow WHERE token = 'tok-secret-123'", 'ltime' => 0.0102, 'stack' => [ 'Slow_Plugin->load()' ], 'result' => new \WP_Error('qmdb', "Table 'wp_slow' doesn't exist") ],
                ],
            ],
            'http'       => [
                'ltime' => 1.5,
                'http'  => [
                    [
                        'url'    => 'https://api.example.net/v1/check?license_key=LIC-SECRET&site=demo',
                        'args'   => [ 'method' => 'GET', 'timeout' => 5.0 ],
                        'ltime'  => 1.5,
                        'result' => [ 'code' => 200, 'message' => 'OK' ],
                        'stack'  => [ 'Slow_Plugin->check()' ],
                    ],
                    [
                        'url'    => 'https://down.example.net/ping',
                        'args'   => [ 'method' => 'POST' ],
                        'ltime'  => 5.0,
                        'result' => new \WP_Error('http_request_failed', 'cURL error 28: timed out'),
                    ],
                ],
            ],
            'php_errors' => [
                'errors' => [
                    'warning' => [
                        'abc' => [
                            'errno'   => E_WARNING,
                            'level'   => 'warning',
                            'message' => 'Undefined array key "x"',
                            'file'    => $plugin,
                            'line'    => 42,
                            'count'   => 3,
                        ],
                    ],
                ],
            ],
            'hooks'      => [
                'hooks' => [
                    [ 'name' => 'init', 'actions' => array_fill(0, 12, []) ],
                    [ 'name' => 'wp_head', 'actions' => array_fill(0, 30, []) ],
                    [ 'name' => 'shutdown', 'actions' => [ [] ] ],
                ],
            ],
        ];
    }

    // ---------------------------------------------------------------
    // Catalog and presence
    // ---------------------------------------------------------------

    public function test_the_op_is_a_free_manage_options_read_on_the_theme_pair(): void
    {
        Gate::set_pro_for_tests(false);
        $op = $this->catalog()[ self::OP ] ?? null;

        $this->assertNotNull($op, 'the profile op must be on the theme pair without a license');
        $this->assertSame('read', $op['mode']);
        $this->assertSame('manage_options', $op['capability']);
        $this->assertTrue($op['dependency_met']);
    }

    public function test_presence_defaults_to_query_monitors_own_constant(): void
    {
        remove_all_filters('wpmcp_query_monitor_active');
        $this->assertSame(defined('QM_VERSION'), $this->catalog()[ self::OP ]['dependency_met']);
    }

    public function test_without_query_monitor_it_names_the_plugin_and_fetches_nothing(): void
    {
        remove_all_filters('wpmcp_query_monitor_active');
        add_filter('wpmcp_query_monitor_active', '__return_false');

        $out = $this->read();

        $this->assertSame('query_monitor_inactive', $out['error']['code'] ?? null);
        $this->assertStringContainsString('Install and activate Query Monitor', $out['error']['message']);
        $this->assertSame('query-monitor', $out['error']['data']['plugin']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url']);
        $this->assertSame([], $this->requests);
    }

    // ---------------------------------------------------------------
    // The profile
    // ---------------------------------------------------------------

    public function test_a_page_is_fetched_logged_out_with_a_one_time_token_and_summarised(): void
    {
        $post = self::factory()->post->create([ 'post_status' => 'publish' ]);

        $out = $this->profile([ 'post_id' => $post ]);

        $this->assertCount(1, $this->requests);
        $request = $this->requests[0];
        $this->assertStringStartsWith(get_permalink($post), $request['url']);
        $this->assertStringContainsString(Query_Monitor_Status::PARAM . '=', $request['url']);
        $this->assertSame([], $request['args']['cookies']);
        $this->assertSame(0, $request['args']['redirection']);

        $this->assertSame(get_permalink($post), $out['url']);
        $this->assertSame(200, $out['status']);
        $this->assertSame('logged_out_visitor', $out['profiled_as']);
        $this->assertSame(0.8123, $out['overview']['time_seconds']);
        $this->assertSame(41943040, $out['overview']['memory_bytes']);

        $db = $out['database'];
        $this->assertSame(4, $db['queries']);
        $this->assertEqualsWithDelta(0.2707, $db['time_seconds'], 0.0001);
        $this->assertSame(1, $db['slow_queries']);
        $this->assertSame(2, $db['errors']['total']);
        $this->assertSame(1, $db['duplicates']['total']);
        $this->assertSame(2, $db['duplicates']['queries'][0]['count']);
        // Slowest first, with the caller Query Monitor recorded.
        $this->assertSame(0.25, $db['slowest'][0]['time_seconds']);
        $this->assertSame('Slow_Plugin->lookup()', $db['slowest'][0]['caller']);

        $this->assertSame(2, $out['http']['calls']);
        $this->assertSame('GET', $out['http']['requests'][0]['method']);
        $this->assertSame(200, $out['http']['requests'][0]['status']);
        $this->assertSame('cURL error 28: timed out', $out['http']['requests'][1]['error']);

        $this->assertSame(1, $out['php_errors']['total']);
        $error = $out['php_errors']['errors'][0];
        $this->assertSame('warning', $error['level']);
        $this->assertSame(3, $error['count']);
        $this->assertSame(42, $error['line']);
        $this->assertSame('wp-content/plugins/slow-plugin/slow.php', $error['file']);

        $this->assertSame(3, $out['hooks']['total']);
        $this->assertSame('wp_head', $out['hooks']['busiest'][0]['hook']);
        $this->assertSame(30, $out['hooks']['busiest'][0]['callbacks']);

        $this->assertTrue($out['masked']);
    }

    public function test_sql_literals_and_url_query_values_are_masked(): void
    {
        $out  = $this->profile();
        $json = (string) wp_json_encode($out);

        foreach ([ 'ada@example.com', 'tok-secret-123', 'LIC-SECRET' ] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
        $this->assertStringContainsString("user_email = 'a***@example.com'", $json);
        $this->assertStringContainsString('license_key=L***', $out['http']['requests'][0]['url']);
        $this->assertStringStartsWith('https://api.example.net/v1/check?', $out['http']['requests'][0]['url']);
    }

    public function test_limit_caps_the_slowest_queries(): void
    {
        $out = $this->profile([ 'limit' => 1 ]);
        $this->assertCount(1, $out['database']['slowest']);
    }

    public function test_the_stored_profile_is_deleted_after_it_is_read(): void
    {
        global $wpdb;
        $this->profile();

        $left = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like('wpmcp_qm_profile_') . '%'));
        $this->assertSame(0, $left);
    }

    public function test_a_page_served_from_a_cache_reports_that_nothing_was_captured(): void
    {
        $this->run_profiled_request = false;

        $out = $this->read();

        $this->assertSame('profile_not_captured', $out['error']['code'] ?? null);
        $this->assertStringContainsString('cache', $out['error']['message']);
    }

    public function test_a_url_off_this_site_is_refused_before_any_request(): void
    {
        $out = $this->read([ 'url' => 'https://evil.example/' ]);

        $this->assertSame('invalid_url', $out['error']['code'] ?? null);
        $this->assertSame([], $this->requests);
    }

    public function test_a_path_is_joined_onto_the_home_url(): void
    {
        $this->profile([ 'url' => '/sample-page/' ]);
        $this->assertStringStartsWith(home_url('/sample-page/'), $this->requests[0]['url']);
    }

    // ---------------------------------------------------------------
    // The profiled request
    // ---------------------------------------------------------------

    public function test_an_unknown_token_grants_nothing(): void
    {
        wp_set_current_user(0);
        $_GET[ Query_Monitor_Status::PARAM ] = 'not-a-token';

        Query_Monitor_Status::start_profile();

        $this->assertFalse(current_user_can('view_query_monitor'));
        $this->assertFalse(Query_Monitor_Status::profiling());
    }

    public function test_a_valid_token_lets_query_monitor_collect_without_output_and_is_single_use(): void
    {
        $token = Query_Monitor_Status::issue_token();
        wp_set_current_user(0);
        $_GET[ Query_Monitor_Status::PARAM ] = $token;

        Query_Monitor_Status::start_profile();

        $this->assertTrue(Query_Monitor_Status::profiling());
        $this->assertTrue(current_user_can('view_query_monitor'));
        $this->assertFalse(current_user_can('manage_options'), 'the token grants only view_query_monitor');
        foreach ([ 'html', 'ajax', 'redirect', 'rest', 'rest_envelope', 'wp_die' ] as $dispatcher) {
            $this->assertFalse(apply_filters("qm/dispatch/{$dispatcher}", true), "Query Monitor's {$dispatcher} output must stay off");
        }

        Query_Monitor_Status::finish_profile();
        $this->assertFalse(current_user_can('view_query_monitor'));

        // The token is spent: a second request with it is not profiled.
        Query_Monitor_Status::start_profile();
        $this->assertFalse(Query_Monitor_Status::profiling());
    }
}
