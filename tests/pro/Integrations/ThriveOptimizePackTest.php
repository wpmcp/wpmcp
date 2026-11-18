<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\Thrive_Optimize_Pack;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/thrive-optimize-stubs.php';

/**
 * Thrive Optimize (issue #827): paid-tier A/B test ops on the plugin-data
 * dispatcher pair, so they add no top-level tools.
 *
 * Thrive Optimize is not loaded in the shared test core. Presence is driven
 * through wpmcp_thrive_optimize_active, and the storage is seeded the way
 * Thrive Optimize 10.9.2.1 keeps it (tests/support/thrive-optimize-stubs.php):
 * tests and their variations' results in {prefix}tab_tests and
 * {prefix}tab_test_items, variations as tab_variation child posts of the
 * page, and each one's traffic share in thrive_ab_traffic post meta.
 *
 * Writes are off until the site opts in (wpmcp_enable_thrive_optimize_write).
 * A traffic split is a post snapshot per variation and a stopped test a
 * db_rows image of its row plus a post snapshot of the page, so
 * rollback-session puts both back. Picking a winner copies the winner's
 * design over the page through Thrive Optimize itself: not undoable, and it
 * needs confirm:true.
 */
class ThriveOptimizePackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'thrive-optimize-list-tests'           => 'read',
        'thrive-optimize-get-test'             => 'read',
        'thrive-optimize-update-traffic-split' => 'write',
        'thrive-optimize-stop-test'            => 'destructive',
        'thrive-optimize-pick-winner'          => 'destructive',
    ];

    private const VARIATIONS = [
        'Original' => [ 50, 200, 20, '0.00' ],
        'Short'    => [ 30, 180, 27, '0.00' ],
        'Long'     => [ 20, 150, 9, '0.00' ],
    ];

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_thrive_optimize_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_thrive_optimize_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_thrive_optimize_active', '__return_true');
        add_filter('wpmcp_enable_thrive_optimize_write', '__return_true');
        \Thrive_AB_Ajax::$calls = [];
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_thrive_optimize_active');
        remove_all_filters('wpmcp_enable_thrive_optimize_write');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if (isset($args['confirm'])) {
            $call['confirm'] = $args['confirm'];
            unset($call['args']['confirm']);
        }
        return (new Plugin_Data_Integration())->handle_write($call);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    // ---------------------------------------------------------------
    // Catalog and gating
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_thrive_optimize_op_with_its_mode_on_the_plugin_data_pair(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            $this->assertSame('manage_options', $ops[ $name ]['capability'], $name);
        }
        $this->assertSame('Thrive Optimize', (new Plugin_Data_Integration())->operation_hosts()['thrive-optimize-get-test']['name']);
    }

    public function test_without_pro_the_ops_are_not_in_the_catalog(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], 'name');
        foreach (array_keys(self::OPS) as $name) {
            $this->assertNotContains($name, $ops);
        }
        $this->assertFalse(Thrive_Optimize_Pack::active());
    }

    public function test_while_thrive_optimize_is_inactive_its_ops_name_it(): void
    {
        remove_all_filters('wpmcp_thrive_optimize_active');
        add_filter('wpmcp_thrive_optimize_active', '__return_false');
        add_filter('wpmcp_buddypress_active', '__return_true');
        $out = $this->read('thrive-optimize-list-tests');
        remove_all_filters('wpmcp_buddypress_active');
        $this->assertSame('thrive_optimize_inactive', $out['error']['code'] ?? null);
        $this->assertStringContainsString('Thrive Optimize', $out['error']['message']);
    }

    public function test_presence_defaults_to_the_plugin_file_constant(): void
    {
        remove_all_filters('wpmcp_thrive_optimize_active');
        $this->assertSame(defined('THRIVE_AB_PLUGIN_FILE'), Thrive_Optimize_Pack::active());
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $t = wpmcp_test_thrive_optimize_test(self::VARIATIONS);
        remove_all_filters('wpmcp_enable_thrive_optimize_write');
        $out = $this->write('thrive-optimize-update-traffic-split', [ 'test_id' => $t['test'], 'traffic' => [ (string) $t['page'] => 100, (string) $t['variations']['Short'] => 0, (string) $t['variations']['Long'] => 0 ] ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertStringContainsString('wpmcp_enable_thrive_optimize_write', $out['error']['message']);
        $this->assertSame('50', get_post_meta($t['page'], 'thrive_ab_traffic', true));
    }

    public function test_every_op_takes_manage_options(): void
    {
        $t = wpmcp_test_thrive_optimize_test(self::VARIATIONS);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->read('thrive-optimize-list-tests')['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->read('thrive-optimize-get-test', [ 'test_id' => $t['test'] ])['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_tests_returns_goal_status_and_totals_and_filters_by_status(): void
    {
        $running = wpmcp_test_thrive_optimize_test(self::VARIATIONS);
        $done    = wpmcp_test_thrive_optimize_test([ 'Old' => [ 100, 10, 1, '0.00' ] ], [ 'title' => 'Old test', 'status' => 'completed', 'date_completed' => '2026-11-10 10:00:00' ]);

        $out   = $this->ok($this->read('thrive-optimize-list-tests'))['result'];
        $tests = array_column($out['tests'], null, 'id');
        $this->assertArrayHasKey($running['test'], $tests);
        $this->assertArrayHasKey($done['test'], $tests);
        $row = $tests[ $running['test'] ];
        $this->assertSame('Headline test', $row['title']);
        $this->assertSame($running['page'], $row['page_id']);
        $this->assertSame('Original', $row['page_title']);
        $this->assertSame('running', $row['status']);
        $this->assertSame('optins', $row['goal']);
        $this->assertSame(3, $row['variations']);
        $this->assertSame(530, $row['unique_impressions']);
        $this->assertSame(56, $row['conversions']);
        $this->assertNull($row['date_completed']);

        $only = $this->ok($this->read('thrive-optimize-list-tests', [ 'status' => 'completed' ]))['result'];
        $this->assertSame([ $done['test'] ], array_column($only['tests'], 'id'));
        $this->assertSame('2026-11-10 10:00:00', $only['tests'][0]['date_completed']);

        $by_page = $this->ok($this->read('thrive-optimize-list-tests', [ 'page_id' => $running['page'] ]))['result'];
        $this->assertSame([ $running['test'] ], array_column($by_page['tests'], 'id'));
    }

    public function test_get_test_returns_variations_traffic_and_results(): void
    {
        $t   = wpmcp_test_thrive_optimize_test(self::VARIATIONS);
        $out = $this->ok($this->read('thrive-optimize-get-test', [ 'test_id' => $t['test'] ]))['result'];

        $test = $out['test'];
        $this->assertSame($t['test'], $test['id']);
        $this->assertSame('optins', $test['goal']);
        $this->assertSame(100, $test['traffic_total']);
        $this->assertTrue($test['auto_win']['enabled']);
        $variations = array_column($test['variations'], null, 'title');
        $this->assertSame([ 'Original', 'Short', 'Long' ], array_column($test['variations'], 'title'), 'control first');

        $control = $variations['Original'];
        $this->assertTrue($control['is_control']);
        $this->assertSame($t['page'], $control['variation_id']);
        $this->assertSame($t['items']['Original'], $control['item_id']);
        $this->assertSame(50, $control['traffic']);
        $this->assertSame(10.0, $control['conversion_rate']);
        $this->assertSame(0.0, $control['improvement']);

        $short = $variations['Short'];
        $this->assertFalse($short['is_control']);
        $this->assertTrue($short['active']);
        $this->assertFalse($short['is_winner']);
        $this->assertSame(30, $short['traffic']);
        $this->assertSame(180, $short['unique_impressions']);
        $this->assertSame(27, $short['conversions']);
        $this->assertSame(15.0, $short['conversion_rate']);
        $this->assertSame(50.0, $short['improvement']);
        $this->assertGreaterThan(90.0, $short['chance_to_beat_original']);
        $this->assertLessThan(50.0, $variations['Long']['chance_to_beat_original']);
    }

    public function test_get_test_refuses_an_unknown_test(): void
    {
        $out = $this->read('thrive-optimize-get-test', [ 'test_id' => 987654 ]);
        $this->assertSame('test_not_found', $out['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_update_traffic_split_writes_each_share_and_rollback_session_puts_them_back(): void
    {
        $t     = wpmcp_test_thrive_optimize_test(self::VARIATIONS);
        $split = [ (string) $t['page'] => 34, (string) $t['variations']['Short'] => 33, (string) $t['variations']['Long'] => 33 ];

        $out = $this->ok($this->write('thrive-optimize-update-traffic-split', [ 'test_id' => $t['test'], 'traffic' => $split ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('34', get_post_meta($t['page'], 'thrive_ab_traffic', true));
        $this->assertSame('33', get_post_meta($t['variations']['Short'], 'thrive_ab_traffic', true));
        $this->assertSame('33', get_post_meta($t['variations']['Long'], 'thrive_ab_traffic', true));
        $this->assertSame([ 34, 33, 33 ], array_column($out['result']['test']['variations'], 'traffic'));

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame([], Rollback_Service::take_warnings());
        $this->assertSame('50', get_post_meta($t['page'], 'thrive_ab_traffic', true));
        $this->assertSame('30', get_post_meta($t['variations']['Short'], 'thrive_ab_traffic', true));
        $this->assertSame('20', get_post_meta($t['variations']['Long'], 'thrive_ab_traffic', true));
    }

    public function test_update_traffic_split_refuses_bad_splits_before_any_snapshot(): void
    {
        $t     = wpmcp_test_thrive_optimize_test(self::VARIATIONS);
        $cases = [
            'traffic_sum'          => [ (string) $t['page'] => 50, (string) $t['variations']['Short'] => 30, (string) $t['variations']['Long'] => 30 ],
            'variations_mismatch'  => [ (string) $t['page'] => 50, (string) $t['variations']['Short'] => 50 ],
        ];
        $cases['variations_mismatch_extra'] = [ (string) $t['page'] => 50, (string) $t['variations']['Short'] => 30, (string) $t['variations']['Long'] => 10, '999999' => 10 ];
        foreach ($cases as $code => $traffic) {
            $out = $this->write('thrive-optimize-update-traffic-split', [ 'test_id' => $t['test'], 'traffic' => $traffic ]);
            $this->assertSame(str_starts_with($code, 'variations_mismatch') ? 'variations_mismatch' : $code, $out['error']['code'] ?? null, $code);
        }
        $this->assertSame('50', get_post_meta($t['page'], 'thrive_ab_traffic', true));

        $done = wpmcp_test_thrive_optimize_test([ 'Old' => [ 100, 10, 1, '0.00' ] ], [ 'status' => 'completed' ]);
        $out  = $this->write('thrive-optimize-update-traffic-split', [ 'test_id' => $done['test'], 'traffic' => [ (string) $done['page'] => 100 ] ]);
        $this->assertSame('test_not_running', $out['error']['code'] ?? null);
    }

    public function test_stop_test_needs_confirm_then_completes_it_and_rollback_session_restarts_it(): void
    {
        $t = wpmcp_test_thrive_optimize_test(self::VARIATIONS);

        $preview = $this->write('thrive-optimize-stop-test', [ 'test_id' => $t['test'] ]);
        $this->assertSame('confirmation_required', $preview['error']['code'] ?? null);
        $this->assertSame('running', wpmcp_test_thrive_optimize_row('tab_tests', $t['test'])['status']);

        $out = $this->ok($this->write('thrive-optimize-stop-test', [ 'test_id' => $t['test'], 'confirm' => true ]));
        $this->assertTrue($out['recoverable']);
        $row = wpmcp_test_thrive_optimize_row('tab_tests', $t['test']);
        $this->assertSame('completed', $row['status']);
        $this->assertNotSame('0000-00-00 00:00:00', $row['date_completed']);
        $this->assertEmpty(get_post_meta($t['page'], 'thrive_ab_running_test_id', true));
        $this->assertSame('completed', $out['result']['test']['status']);

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame([], Rollback_Service::take_warnings());
        $row = wpmcp_test_thrive_optimize_row('tab_tests', $t['test']);
        $this->assertSame('running', $row['status']);
        $this->assertSame('0000-00-00 00:00:00', $row['date_completed']);
        $this->assertSame((string) $t['test'], get_post_meta($t['page'], 'thrive_ab_running_test_id', true));
    }

    public function test_stop_test_refuses_a_test_that_is_not_running(): void
    {
        $done = wpmcp_test_thrive_optimize_test([ 'Old' => [ 100, 10, 1, '0.00' ] ], [ 'status' => 'completed' ]);
        $out  = $this->write('thrive-optimize-stop-test', [ 'test_id' => $done['test'], 'confirm' => true ]);
        $this->assertSame('test_not_running', $out['error']['code'] ?? null);
    }

    public function test_pick_winner_runs_thrive_optimize_s_own_winner_path_and_is_not_undoable(): void
    {
        $t = wpmcp_test_thrive_optimize_test(self::VARIATIONS);

        $preview = $this->write('thrive-optimize-pick-winner', [ 'test_id' => $t['test'], 'variation_id' => $t['variations']['Short'] ]);
        $this->assertSame('confirmation_required', $preview['error']['code'] ?? null);
        $this->assertStringContainsString('Short', $preview['error']['message']);
        $this->assertStringContainsString('cannot be undone', $preview['error']['message']);
        $this->assertSame([], \Thrive_AB_Ajax::$calls);

        $out = $this->ok($this->write('thrive-optimize-pick-winner', [ 'test_id' => $t['test'], 'variation_id' => $t['variations']['Short'], 'confirm' => true ]));
        $this->assertFalse($out['recoverable']);
        $this->assertSame([ [ 'id' => $t['items']['Short'], 'page_id' => $t['page'], 'variation_id' => $t['variations']['Short'], 'test_id' => $t['test'] ] ], \Thrive_AB_Ajax::$calls);
        $this->assertSame('completed', $out['result']['test']['status']);
        $winner = array_column($out['result']['test']['variations'], null, 'title')['Short'];
        $this->assertTrue($winner['is_winner']);
    }

    public function test_pick_winner_refuses_a_variation_of_another_test(): void
    {
        $t     = wpmcp_test_thrive_optimize_test(self::VARIATIONS);
        $other = wpmcp_test_thrive_optimize_test([ 'Other' => [ 50, 1, 0, '0.00' ], 'B' => [ 50, 1, 0, '0.00' ] ]);
        $out   = $this->write('thrive-optimize-pick-winner', [ 'test_id' => $t['test'], 'variation_id' => $other['variations']['B'], 'confirm' => true ]);
        $this->assertSame('variation_not_in_test', $out['error']['code'] ?? null);
        $this->assertSame([], \Thrive_AB_Ajax::$calls);
    }
}
