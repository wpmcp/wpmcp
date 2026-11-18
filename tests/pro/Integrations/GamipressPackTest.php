<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Gamipress_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Session;

require_once __DIR__ . '/../../support/gamipress-stubs.php';

/**
 * GamiPress (issue #833): paid-tier ops on the plugin-data dispatcher pair,
 * so they add no top-level tools.
 *
 * GamiPress is not loaded in the shared test core. Presence is driven
 * through wpmcp_gamipress_active, and the storage is the one GamiPress 7.1.5
 * keeps (tests/support/gamipress-stubs.php): balances and ranks in
 * _gamipress_* user meta, earnings in {prefix}gamipress_user_earnings and
 * every change in {prefix}gamipress_logs.
 *
 * Every write is off until the site opts in (wpmcp_enable_gamipress_write)
 * and is undone in a test: a points, achievement or rank write restores the
 * user's meta and earnings exactly and removes the log rows it added.
 */
class GamipressPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'gamipress-list-point-types'       => 'read',
        'gamipress-list-achievement-types' => 'read',
        'gamipress-list-rank-types'        => 'read',
        'gamipress-list-achievements'      => 'read',
        'gamipress-list-ranks'             => 'read',
        'gamipress-get-user-points'        => 'read',
        'gamipress-get-user-achievements'  => 'read',
        'gamipress-get-user-rank'          => 'read',
        'gamipress-create-achievement'     => 'write',
        'gamipress-update-achievement'     => 'write',
        'gamipress-award-points'           => 'write',
        'gamipress-deduct-points'          => 'write',
        'gamipress-award-achievement'      => 'write',
        'gamipress-revoke-achievement'     => 'write',
        'gamipress-set-user-rank'          => 'write',
    ];

    private int $admin;

    private int $player;

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_gamipress_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_gamipress_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wpmcp_test_register_gamipress_types();
        $this->admin  = self::factory()->user->create([ 'role' => 'administrator' ]);
        $this->player = self::factory()->user->create([ 'role' => 'subscriber' ]);
        wp_set_current_user($this->admin);
        add_filter('wpmcp_gamipress_active', '__return_true');
        add_filter('wpmcp_enable_gamipress_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_gamipress_active');
        remove_all_filters('wpmcp_enable_gamipress_write');
        wpmcp_test_unregister_gamipress_types();
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args, ?string $session = null): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if (null !== $session) {
            $call['session_id'] = $session;
        }
        return (new Plugin_Data_Integration())->handle_write($call);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function badge(string $title, int $points = 0, string $status = 'publish'): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'badge', 'post_title' => $title, 'post_status' => $status ]);
        update_post_meta($id, '_gamipress_points', (string) $points);
        update_post_meta($id, '_gamipress_points_type', 'coins');
        update_post_meta($id, '_gamipress_earned_by', 'admin');
        update_post_meta($id, '_gamipress_maximum_earnings', '1');
        return $id;
    }

    private function level(string $title, int $priority): int
    {
        return self::factory()->post->create([ 'post_type' => 'level', 'post_title' => $title, 'post_status' => 'publish', 'menu_order' => $priority ]);
    }

    /** The user's GamiPress state: meta, earnings and log count. */
    private function state(int $user): array
    {
        global $wpdb;
        $meta = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s ORDER BY meta_key", $user, '%' . $wpdb->esc_like('_gamipress_') . '%'), ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $earn = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}gamipress_user_earnings WHERE user_id = %d ORDER BY user_earning_id", $user), ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $logs = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}gamipress_logs WHERE user_id = %d", $user)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $lmet = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}gamipress_logs_meta m LEFT JOIN {$wpdb->prefix}gamipress_logs l ON l.log_id = m.log_id WHERE l.log_id IS NULL"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return [ 'meta' => $meta, 'earnings' => $earn, 'logs' => $logs, 'orphan_log_meta' => $lmet ];
    }

    // ---------------------------------------------------------------
    // Catalog and gating
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_gamipress_op_with_its_mode_on_the_plugin_data_pair(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            $this->assertSame('manage_options', $ops[ $name ]['capability'], $name);
        }
    }

    public function test_without_pro_the_gamipress_ops_are_not_in_the_catalog(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], 'name');
        foreach (array_keys(self::OPS) as $name) {
            $this->assertNotContains($name, $ops);
        }
        $this->assertFalse(Gamipress_Pack::active());
    }

    public function test_while_gamipress_is_missing_its_ops_answer_plugin_missing_with_the_next_step(): void
    {
        remove_all_filters('wpmcp_gamipress_active');
        add_filter('wpmcp_gamipress_active', '__return_false');
        add_filter('wpmcp_buddypress_active', '__return_true');
        $out = $this->read('gamipress-list-point-types');
        remove_all_filters('wpmcp_buddypress_active');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate GamiPress', $out['error']['message']);
        $this->assertStringContainsString('plugins.php', $out['error']['message']);
    }

    public function test_presence_defaults_to_the_gamipress_version_constant(): void
    {
        remove_all_filters('wpmcp_gamipress_active');
        $this->assertSame(defined('GAMIPRESS_VER'), Gamipress_Pack::plugin_loaded());
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters('wpmcp_enable_gamipress_write');
        $before = $this->state($this->player);
        $out    = $this->write('gamipress-award-points', [ 'user_id' => $this->player, 'points' => 10, 'points_type' => 'coins' ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertStringContainsString('wpmcp_enable_gamipress_write', $out['error']['message']);
        $this->assertSame($before, $this->state($this->player));
    }

    public function test_every_op_takes_the_gamipress_manager_capability(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        foreach ([ 'gamipress-list-point-types', 'gamipress-list-achievements', 'gamipress-list-ranks' ] as $op) {
            $this->assertSame('operation_denied', $this->read($op)['error']['code'] ?? null, $op);
        }
        $out = $this->write('gamipress-award-points', [ 'user_id' => $this->player, 'points' => 5, 'points_type' => 'coins' ]);
        $this->assertArrayHasKey('error', $out);
        $this->assertSame(0, gamipress_get_user_points($this->player, 'coins'));
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_type_lists_name_each_registered_type(): void
    {
        $points = $this->ok($this->read('gamipress-list-point-types'))['result']['types'];
        $this->assertSame([ [ 'slug' => 'coins', 'id' => 11, 'singular' => 'Coin', 'plural' => 'Coins' ] ], $points);
        $this->assertSame('badge', $this->ok($this->read('gamipress-list-achievement-types'))['result']['types'][0]['slug']);
        $this->assertSame('level', $this->ok($this->read('gamipress-list-rank-types'))['result']['types'][0]['slug']);
    }

    public function test_list_achievements_returns_settings_and_filters_by_status(): void
    {
        $gold  = $this->badge('Gold', 50);
        $draft = $this->badge('Draft badge', 0, 'draft');
        $all   = $this->ok($this->read('gamipress-list-achievements'))['result'];
        $this->assertSame(2, $all['total']);
        $byid = array_column($all['achievements'], null, 'id');
        $this->assertSame(50, $byid[ $gold ]['points']);
        $this->assertSame('coins', $byid[ $gold ]['points_type']);
        $this->assertSame('admin', $byid[ $gold ]['earned_by']);
        $drafts = $this->ok($this->read('gamipress-list-achievements', [ 'status' => 'draft' ]))['result']['achievements'];
        $this->assertSame([ $draft ], array_column($drafts, 'id'));
    }

    public function test_an_unknown_type_is_refused_with_the_registered_ones(): void
    {
        $out = $this->read('gamipress-list-achievements', [ 'type' => 'trophy' ]);
        $this->assertSame('achievement_type_not_found', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $out = $this->write('gamipress-award-points', [ 'user_id' => $this->player, 'points' => 1, 'points_type' => 'gems' ]);
        $this->assertSame('points_type_not_found', $out['error']['code'] ?? null);
        $this->assertSame([ 'coins' ], $out['error']['data']['types'] ?? null);
    }

    public function test_list_ranks_orders_by_priority(): void
    {
        $high = $this->level('Master', 3);
        $low  = $this->level('Novice', 1);
        $ids  = array_column($this->ok($this->read('gamipress-list-ranks'))['result']['ranks'], 'id');
        $this->assertSame([ $low, $high ], $ids);
    }

    public function test_user_reads_return_points_earnings_and_rank(): void
    {
        $gold = $this->badge('Gold', 20);
        $low  = $this->level('Novice', 1);
        update_user_meta($this->player, '_gamipress_coins_points', 70);
        update_user_meta($this->player, '_gamipress_coins_points_awarded', 90);
        $earning = wpmcp_test_gamipress_earning($this->player, $gold);

        $points = $this->ok($this->read('gamipress-get-user-points', [ 'user_id' => $this->player ]))['result'];
        $this->assertSame(70, $points['points'][0]['balance']);
        $this->assertSame(90, $points['points'][0]['awarded']);

        $earned = $this->ok($this->read('gamipress-get-user-achievements', [ 'user_id' => $this->player ]))['result'];
        $this->assertSame(1, $earned['total']);
        $this->assertSame($earning, $earned['achievements'][0]['earning_id']);
        $this->assertSame($gold, $earned['achievements'][0]['achievement_id']);

        $rank = $this->ok($this->read('gamipress-get-user-rank', [ 'user_id' => $this->player ]))['result'];
        $this->assertSame($low, $rank['ranks'][0]['rank_id']);
        $this->assertArrayNotHasKey('email', $rank);
    }

    public function test_a_user_read_of_a_missing_user_is_refused(): void
    {
        $out = $this->read('gamipress-get-user-points', [ 'user_id' => 999999 ]);
        $this->assertSame('user_not_found', $out['error']['code'] ?? null, (string) wp_json_encode($out));
    }

    // ---------------------------------------------------------------
    // Writes and their undo
    // ---------------------------------------------------------------

    public function test_award_points_goes_through_gamipress_and_rolls_back_meta_and_log(): void
    {
        update_user_meta($this->player, '_gamipress_coins_points', 5);
        $before = $this->state($this->player);
        $out    = $this->ok($this->write('gamipress-award-points', [ 'user_id' => $this->player, 'points' => 10, 'points_type' => 'coins', 'reason' => 'Helpful answer' ]));
        $this->assertSame(5, $out['result']['before']);
        $this->assertSame(15, $out['result']['balance']);
        $this->assertSame(15, gamipress_get_user_points($this->player, 'coins'));
        $this->assertSame($before['logs'] + 1, $this->state($this->player)['logs']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, $this->state($this->player));
    }

    public function test_deduct_points_is_refused_beyond_the_balance_and_rolls_back(): void
    {
        update_user_meta($this->player, '_gamipress_coins_points', 8);
        $out = $this->write('gamipress-deduct-points', [ 'user_id' => $this->player, 'points' => 9, 'points_type' => 'coins' ]);
        $this->assertSame('points_insufficient', $out['error']['code'] ?? null);

        $before = $this->state($this->player);
        $out    = $this->ok($this->write('gamipress-deduct-points', [ 'user_id' => $this->player, 'points' => 3, 'points_type' => 'coins' ]));
        $this->assertSame(5, $out['result']['balance']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, $this->state($this->player));
    }

    public function test_award_achievement_adds_the_earning_and_its_points_and_rolls_back(): void
    {
        $gold   = $this->badge('Gold', 25);
        $before = $this->state($this->player);
        $out    = $this->ok($this->write('gamipress-award-achievement', [ 'user_id' => $this->player, 'achievement_id' => $gold ]));
        $this->assertSame(1, $out['result']['times_earned']);
        $this->assertSame(25, gamipress_get_user_points($this->player, 'coins'));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, $this->state($this->player));
        $this->assertSame(0, gamipress_get_user_points($this->player, 'coins'));
    }

    public function test_a_draft_achievement_cannot_be_awarded(): void
    {
        $draft = $this->badge('Later', 5, 'draft');
        $out   = $this->write('gamipress-award-achievement', [ 'user_id' => $this->player, 'achievement_id' => $draft ]);
        $this->assertSame('achievement_not_published', $out['error']['code'] ?? null);
    }

    public function test_revoke_achievement_removes_the_earning_and_rollback_puts_the_same_row_back(): void
    {
        global $wpdb;
        $gold    = $this->badge('Gold', 25);
        $earning = wpmcp_test_gamipress_earning($this->player, $gold);
        $wpdb->insert($wpdb->prefix . 'gamipress_user_earnings_meta', [ 'user_earning_id' => $earning, 'meta_key' => '_gamipress_note', 'meta_value' => 'kept' ]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        update_user_meta($this->player, '_gamipress_coins_points', 25);

        $this->assertSame('not_earned', $this->write('gamipress-revoke-achievement', [ 'user_id' => $this->player, 'achievement_id' => $this->badge('Silver') ])['error']['code'] ?? null);

        $before = $this->state($this->player);
        $out    = $this->ok($this->write('gamipress-revoke-achievement', [ 'user_id' => $this->player, 'achievement_id' => $gold ]));
        $this->assertSame(0, $out['result']['times_earned']);
        $this->assertSame(0, gamipress_get_user_points($this->player, 'coins'));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, $this->state($this->player));
        $meta = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->prefix}gamipress_user_earnings_meta WHERE user_earning_id = %d", $earning)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $this->assertSame('kept', $meta);
    }

    public function test_set_user_rank_and_rollback(): void
    {
        $low    = $this->level('Novice', 1);
        $high   = $this->level('Master', 2);
        $before = $this->state($this->player);
        $out    = $this->ok($this->write('gamipress-set-user-rank', [ 'user_id' => $this->player, 'rank_id' => $high ]));
        $this->assertSame($low, $out['result']['previous_rank_id']);
        $this->assertSame($high, $out['result']['rank_id']);
        $this->assertTrue($out['result']['changed']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, $this->state($this->player));
        $this->assertSame($low, gamipress_get_user_rank_id($this->player, 'level'));
    }

    public function test_a_rank_must_be_a_published_rank(): void
    {
        $out = $this->write('gamipress-set-user-rank', [ 'user_id' => $this->player, 'rank_id' => $this->badge('Gold') ]);
        $this->assertSame('rank_not_found', $out['error']['code'] ?? null);
    }

    public function test_create_achievement_makes_an_admin_awarded_draft_and_rollback_trashes_it(): void
    {
        $out = $this->ok($this->write('gamipress-create-achievement', [ 'type' => 'badge', 'title' => 'Helper', 'points' => 15, 'points_type' => 'coins' ], 'gp-create'));
        $ach = $out['result']['achievement'];
        $this->assertSame('draft', $ach['status']);
        $this->assertSame('admin', $ach['earned_by']);
        $this->assertSame(15, $ach['points']);
        $this->assertSame(1, $ach['maximum_earnings']);

        (new Rollback_Session())->handle([ 'session_id' => 'gp-create' ]);
        $this->assertSame('trash', get_post_status((int) $ach['id']));
    }

    public function test_update_achievement_changes_only_what_is_given_and_rolls_back(): void
    {
        $gold = $this->badge('Gold', 10);
        $out  = $this->ok($this->write('gamipress-update-achievement', [ 'id' => $gold, 'points' => 40, 'hidden' => true, 'status' => 'draft' ]));
        $this->assertSame(40, $out['result']['achievement']['points']);
        $this->assertTrue($out['result']['achievement']['hidden']);
        $this->assertSame('Gold', $out['result']['achievement']['title']);
        $this->assertSame('draft', get_post_status($gold));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('10', get_post_meta($gold, '_gamipress_points', true));
        $this->assertSame('', get_post_meta($gold, '_gamipress_hidden', true));
        $this->assertSame('publish', get_post_status($gold));
    }

    public function test_a_session_of_several_writes_rolls_back_to_where_it_started(): void
    {
        $gold   = $this->badge('Gold', 5);
        $high   = $this->level('Master', 2);
        $this->level('Novice', 1);
        $before = $this->state($this->player);
        $this->ok($this->write('gamipress-award-points', [ 'user_id' => $this->player, 'points' => 10, 'points_type' => 'coins' ], 'gp-run'));
        $this->ok($this->write('gamipress-award-achievement', [ 'user_id' => $this->player, 'achievement_id' => $gold ], 'gp-run'));
        $this->ok($this->write('gamipress-set-user-rank', [ 'user_id' => $this->player, 'rank_id' => $high ], 'gp-run'));
        $this->ok($this->write('gamipress-deduct-points', [ 'user_id' => $this->player, 'points' => 2, 'points_type' => 'coins' ], 'gp-run'));

        (new Rollback_Session())->handle([ 'session_id' => 'gp-run' ]);
        $this->assertSame($before, $this->state($this->player));
    }

    public function test_restoring_a_gamipress_snapshot_takes_manage_options(): void
    {
        $out = $this->ok($this->write('gamipress-award-points', [ 'user_id' => $this->player, 'points' => 10, 'points_type' => 'coins' ]));
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        try {
            Rollback_Service::restore_operation((string) $out['operation_id']);
        } catch (\Throwable $e) {
            unset($e); // A refusal may be thrown or reported; either way nothing is restored.
        }
        $this->assertSame(10, gamipress_get_user_points($this->player, 'coins'));
    }
}
