<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Session;

/**
 * Issue #833: the GamiPress ops against the real plugin.
 *
 * Runs only when WPMCP_GAMIPRESS_SRC names a GamiPress copy (the free
 * plugin from wordpress.org works), which tests/bootstrap.php then loads,
 * with its tables built by GamiPress's own installer:
 *
 *   WPMCP_GAMIPRESS_SRC=/path/to/gamipress bin/test-local.sh -- --filter GamipressLiveTest
 *
 * Points, achievement and rank writes go through GamiPress's own functions,
 * so its logs, achievement points and rank earnings all fire; each write
 * must then roll back to the user's state before it. Everywhere else the
 * plugin is absent and the test is skipped. It has no leg in bin/legs.tsv
 * yet (a leg needs a runner in bin/test-local.sh), so its group does not
 * carry the -live suffix the leg table checks.
 *
 * @group gamipress-src
 */
class GamipressLiveTest extends \WP_UnitTestCase
{
    private int $player;

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('GAMIPRESS_VER') || ! function_exists('gamipress_award_points_to_user')) {
            $this->markTestSkipped('Needs the real GamiPress (WPMCP_GAMIPRESS_SRC).');
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $this->player = self::factory()->user->create([ 'role' => 'subscriber' ]);
        add_filter('wpmcp_enable_gamipress_write', '__return_true');

        // Types the way GamiPress registers them from its type posts.
        $coins = self::factory()->post->create([ 'post_type' => 'points-type', 'post_title' => 'Coin', 'post_name' => 'coins', 'post_status' => 'publish' ]);
        gamipress_register_points_type($coins, 'Coin', 'Coins', 'coins');
        $badge = self::factory()->post->create([ 'post_type' => 'achievement-type', 'post_title' => 'Badge', 'post_name' => 'badge', 'post_status' => 'publish' ]);
        gamipress_register_achievement_type($badge, 'Badge', 'Badges', 'badge');
        $level = self::factory()->post->create([ 'post_type' => 'rank-type', 'post_title' => 'Level', 'post_name' => 'level', 'post_status' => 'publish' ]);
        gamipress_register_rank_type($level, 'Level', 'Levels', 'level');
        register_post_type('badge', [ 'public' => true, 'hierarchical' => true ]);
        register_post_type('level', [ 'public' => true, 'hierarchical' => true ]);
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_gamipress_write');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function call(string $half, string $op, array $args, ?string $session = null): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if (null !== $session) {
            $call['session_id'] = $session;
        }
        $out = 'read' === $half ? (new Plugin_Data_Integration())->handle_read($call) : (new Plugin_Data_Integration())->handle_write($call);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function state(): array
    {
        global $wpdb;
        $logs = \WPMCP\Safety\Gamipress_User_Snapshot::table('gamipress_logs');
        $earn = \WPMCP\Safety\Gamipress_User_Snapshot::table('gamipress_user_earnings');
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return [
            'meta'     => $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s ORDER BY meta_key", $this->player, '%' . $wpdb->esc_like('_gamipress_') . '%'), ARRAY_A),
            'earnings' => $wpdb->get_results($wpdb->prepare("SELECT * FROM {$earn} WHERE user_id = %d ORDER BY user_earning_id", $this->player), ARRAY_A),
            'logs'     => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$logs} WHERE user_id = %d", $this->player)),
        ];
        // phpcs:enable
    }

    public function test_points_achievement_and_rank_writes_run_through_gamipress_and_roll_back(): void
    {
        $gold = self::factory()->post->create([ 'post_type' => 'badge', 'post_title' => 'Gold', 'post_status' => 'publish' ]);
        update_post_meta($gold, '_gamipress_points', '25');
        update_post_meta($gold, '_gamipress_points_type', 'coins');
        update_post_meta($gold, '_gamipress_earned_by', 'admin');
        $low  = self::factory()->post->create([ 'post_type' => 'level', 'post_title' => 'Novice', 'post_status' => 'publish', 'menu_order' => 1 ]);
        $high = self::factory()->post->create([ 'post_type' => 'level', 'post_title' => 'Master', 'post_status' => 'publish', 'menu_order' => 2 ]);

        $types = $this->call('read', 'gamipress-list-point-types', []);
        $this->assertContains('coins', array_column($types['result']['types'], 'slug'));

        $before = $this->state();

        $out = $this->call('write', 'gamipress-award-points', [ 'user_id' => $this->player, 'points' => 10, 'points_type' => 'coins' ]);
        $this->assertSame(10, gamipress_get_user_points($this->player, 'coins'));
        $this->assertGreaterThan($before['logs'], $this->state()['logs']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, $this->state());

        $out = $this->call('write', 'gamipress-award-achievement', [ 'user_id' => $this->player, 'achievement_id' => $gold ]);
        $this->assertSame(1, $out['result']['times_earned']);
        $this->assertSame(25, gamipress_get_user_points($this->player, 'coins'));
        $earned = $this->call('read', 'gamipress-get-user-achievements', [ 'user_id' => $this->player ]);
        $this->assertSame($gold, $earned['result']['achievements'][0]['achievement_id']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, $this->state());

        gamipress_award_achievement_to_user($gold, $this->player);
        $earned = $this->state();
        $out    = $this->call('write', 'gamipress-revoke-achievement', [ 'user_id' => $this->player, 'achievement_id' => $gold ]);
        $this->assertSame(0, $out['result']['times_earned']);
        $this->assertSame(0, gamipress_get_user_points($this->player, 'coins'));
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($earned, $this->state());
        $this->assertSame(25, gamipress_get_user_points($this->player, 'coins'));

        $rank_before = $this->state();
        $out = $this->call('write', 'gamipress-set-user-rank', [ 'user_id' => $this->player, 'rank_id' => $high ]);
        $this->assertSame($high, gamipress_get_user_rank_id($this->player, 'level'));
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($rank_before, $this->state());
        $this->assertSame($low, gamipress_get_user_rank_id($this->player, 'level'));
    }

    public function test_a_session_of_writes_rolls_back_whole(): void
    {
        $gold = self::factory()->post->create([ 'post_type' => 'badge', 'post_title' => 'Gold', 'post_status' => 'publish' ]);
        update_post_meta($gold, '_gamipress_points', '5');
        update_post_meta($gold, '_gamipress_points_type', 'coins');
        $before = $this->state();
        $this->call('write', 'gamipress-award-points', [ 'user_id' => $this->player, 'points' => 10, 'points_type' => 'coins' ], 'gp-live');
        $this->call('write', 'gamipress-award-achievement', [ 'user_id' => $this->player, 'achievement_id' => $gold ], 'gp-live');
        $this->call('write', 'gamipress-deduct-points', [ 'user_id' => $this->player, 'points' => 3, 'points_type' => 'coins' ], 'gp-live');
        $this->assertSame(12, gamipress_get_user_points($this->player, 'coins'));
        (new Rollback_Session())->handle([ 'session_id' => 'gp-live' ]);
        $this->assertSame($before, $this->state());
    }

    public function test_create_achievement_makes_a_draft_the_plugin_reads_back(): void
    {
        $out = $this->call('write', 'gamipress-create-achievement', [ 'type' => 'badge', 'title' => 'Helper', 'points' => 15, 'points_type' => 'coins' ], 'gp-create');
        $id  = (int) $out['result']['achievement']['id'];
        $this->assertTrue(gamipress_is_achievement($id));
        $this->assertSame('admin', get_post_meta($id, '_gamipress_earned_by', true));
        (new Rollback_Session())->handle([ 'session_id' => 'gp-create' ]);
        $this->assertSame('trash', get_post_status($id));
    }
}
