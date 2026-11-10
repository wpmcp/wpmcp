<?php

namespace WPMCP\Tests\Pro\WooCommerce;

use WPMCP\Governance\Governance;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Safety\Wc_User_Membership_Snapshot;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\WooCommerce\Catalog\Op_Catalog;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Ops;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Read;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Write;

require_once __DIR__ . '/../../support/wc-memberships-double.php';

/**
 * WooCommerce Memberships (PR #573) as the memberships domain of the
 * existing woo-read and woo-write dispatchers, against the stand-in in
 * tests/support/wc-memberships-double.php. The plugin is commercial, so
 * there is no live leg; the double is unverified against the real plugin.
 *
 * Reads return a curated view; every write is snapshotted and undone here
 * exactly; grant and resume need confirm:true; cancel is destructive (off
 * by default, confirm:true) and reported not recoverable. Without the
 * plugin the ops are left out of woo-ops and refused with plugin_missing.
 */
class WooMembershipsTest extends \WP_UnitTestCase
{
    private \WPMCP_Test_Memberships_Double $double;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce is not active in this environment.');
        }
        if (class_exists('WC_Memberships')) {
            $this->markTestSkipped('The real WooCommerce Memberships is loaded; this suite runs against its stand-in.');
        }

        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        Governance::reset_for_tests();

        \WC_Install::create_roles();
        $GLOBALS['wp_roles'] = null;
        wp_roles();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->double = wpmcp_test_wc_memberships_double();
    }

    protected function tearDown(): void
    {
        wpmcp_test_wc_memberships_reset();
        remove_all_filters('wpmcp_woo_op_enabled');
        delete_option('wc_memberships_rules');
        Governance::reset_for_tests();
        wp_set_current_user(0);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    // ------------------------------------------------------------ fixtures

    private function read(string $op, array $params = []): array
    {
        return (new Woo_Read())->handle(['op' => $op, 'params' => $params]);
    }

    private function write(string $op, array $params, array $extra = []): array
    {
        return (new Woo_Write())->handle(['op' => $op, 'params' => $params] + $extra);
    }

    private function member(): int
    {
        return self::factory()->user->create(['role' => 'customer']);
    }

    /** A membership of a fresh plan and member. */
    private function membership(string $status = 'active', array $args = []): int
    {
        return wpmcp_test_user_membership($args + ['plan_id' => wpmcp_test_membership_plan(), 'user_id' => $this->member(), 'status' => $status]);
    }

    /** Everything a membership write can change, read back fresh. */
    private function state(int $id): array
    {
        $row = Wc_User_Membership_Snapshot::row($id);
        $this->assertNotNull($row);
        return $row + ['post_status' => get_post_status($id)];
    }

    private function snapshot_count(): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- counts ledger rows to prove a refusal wrote nothing.
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpmcp_snapshots");
    }

    private function membership_count(): int
    {
        return count(get_posts(['post_type' => 'wc_user_membership', 'post_status' => Wc_User_Membership_Snapshot::post_statuses(), 'numberposts' => -1, 'fields' => 'ids']));
    }

    private function rollback(array $out): void
    {
        $this->assertArrayHasKey('operation_id', $out, wp_json_encode($out));
        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored'], wp_json_encode($rolled));
    }

    private function enable_cancel(): void
    {
        add_filter('wpmcp_woo_op_enabled', static fn ($on, $op) => 'memberships.cancel' === $op ? true : $on, 10, 2);
    }

    // ------------------------------------------------------------- catalog

    public function test_the_catalog_carries_membership_ops_on_the_existing_pair(): void
    {
        $ops = Op_Catalog::ops();

        foreach (['memberships.plans', 'memberships.members', 'memberships.user'] as $op) {
            $this->assertSame('read', $ops[ $op ]['mode'], $op);
            $this->assertSame('woocommerce-memberships', $ops[ $op ]['plugin'], $op);
        }
        foreach (['memberships.grant' => 'wc_user_membership_create', 'memberships.pause' => 'wc_user_membership', 'memberships.resume' => 'wc_user_membership', 'memberships.extend' => 'wc_user_membership'] as $op => $type) {
            $this->assertSame('write', $ops[ $op ]['mode'], $op);
            $this->assertSame($type, $ops[ $op ]['snapshot']['type'], $op);
            $this->assertTrue($ops[ $op ]['recoverable'], $op);
            $this->assertSame('manage_woocommerce', $ops[ $op ]['capability'], $op);
            $this->assertSame('woocommerce-memberships', $ops[ $op ]['plugin'], $op);
        }
        $this->assertSame('destructive', $ops['memberships.cancel']['mode']);
        $this->assertSame('wc_user_membership', $ops['memberships.cancel']['snapshot']['type']);
        $this->assertFalse($ops['memberships.cancel']['recoverable']);
        foreach ([Wc_User_Membership_Snapshot::TYPE, Wc_User_Membership_Snapshot::CREATE_TYPE] as $type) {
            $this->assertContains($type, Rollback_Service::restorable_object_types());
        }
    }

    public function test_without_the_plugin_the_ops_are_hidden_and_refused_with_plugin_missing(): void
    {
        $listed = (new Woo_Ops())->handle([]);
        $this->assertArrayHasKey('memberships', $listed['domains']);
        $this->assertSame('WooCommerce Memberships', $listed['domains']['memberships'][0]['requires']);

        $id     = $this->membership();
        $before = $this->state($id);
        wpmcp_test_wc_memberships_reset();

        $listed = (new Woo_Ops())->handle([]);
        $this->assertArrayNotHasKey('memberships', $listed['domains']);
        $this->assertArrayHasKey('orders', $listed['domains']);

        $snapshots = $this->snapshot_count();
        foreach ([[ 'read', 'memberships.plans', [] ], [ 'write', 'memberships.pause', ['id' => $id] ], [ 'write', 'memberships.grant', ['user_id' => 1, 'plan_id' => 2] ]] as [$kind, $op, $params]) {
            $out = 'read' === $kind ? $this->read($op, $params) : $this->write($op, $params, ['confirm' => true]);
            $this->assertSame('plugin_missing', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('Install and activate WooCommerce Memberships', $out['error']['message']);
            $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url']);
        }
        $this->assertSame($snapshots, $this->snapshot_count());
        $this->assertSame($before, $this->state($id));
    }

    // --------------------------------------------------------------- reads

    public function test_plans_list_access_rules_and_member_counts(): void
    {
        $gold   = wpmcp_test_membership_plan('Gold', ['access_method' => 'purchase', 'access_length' => '1 years', 'product_ids' => [12, '13']], [
            ['rule_type' => 'content_restriction', 'content_type' => 'post_type', 'content_type_name' => 'post', 'object_ids' => [5], 'access_schedule' => 'immediate', 'active' => 'yes'],
            ['rule_type' => 'purchasing_discount', 'content_type' => 'post_type', 'content_type_name' => 'product', 'object_ids' => [], 'discount_type' => 'percentage', 'discount_amount' => '10', 'active' => 'yes'],
        ]);
        $silver = wpmcp_test_membership_plan('Silver');
        wpmcp_test_user_membership(['plan_id' => $gold, 'user_id' => $this->member()]);
        wpmcp_test_user_membership(['plan_id' => $gold, 'user_id' => $this->member()]);
        wpmcp_test_user_membership(['plan_id' => $gold, 'user_id' => $this->member(), 'status' => 'paused']);
        wp_insert_post(['post_type' => 'wc_membership_plan', 'post_status' => 'draft', 'post_title' => 'Draft plan']);

        $out = $this->read('memberships.plans');
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertSame([$gold, $silver], array_column($out['body']['plans'], 'id'), 'Published plans only');
        $plan = $out['body']['plans'][0];
        $this->assertSame('Gold', $plan['name']);
        $this->assertSame('purchase', $plan['access_method']);
        $this->assertSame('1 years', $plan['access_length']);
        $this->assertSame([12, 13], $plan['product_ids']);
        $this->assertEquals((object) ['active' => 2, 'paused' => 1], $plan['members']);
        $this->assertCount(2, $plan['rules']);
        $this->assertSame('content_restriction', $plan['rules'][0]['rule_type']);
        $this->assertSame('10', $plan['rules'][1]['discount_amount']);
        $this->assertArrayNotHasKey('membership_plan_id', $plan['rules'][0]);
        $this->assertSame([], $out['body']['plans'][1]['rules']);
    }

    public function test_members_by_plan_and_status_and_a_users_memberships(): void
    {
        $gold   = wpmcp_test_membership_plan('Gold');
        $silver = wpmcp_test_membership_plan('Silver');
        $ann    = $this->member();
        $bob    = $this->member();
        $a      = wpmcp_test_user_membership(['plan_id' => $gold, 'user_id' => $ann, 'start' => '2026-01-01 00:00:00', 'end' => '2099-01-01 00:00:00']);
        $b      = wpmcp_test_user_membership(['plan_id' => $gold, 'user_id' => $bob, 'status' => 'cancelled']);
        $c      = wpmcp_test_user_membership(['plan_id' => $silver, 'user_id' => $ann, 'end' => null]);

        $all = $this->read('memberships.members', ['plan_id' => $gold]);
        $this->assertSame(200, $all['status'], wp_json_encode($all));
        $this->assertSame([$b, $a], array_column($all['body']['members'], 'id'));
        $this->assertSame(get_userdata($ann)->user_email, $all['body']['members'][1]['user_email']);

        $active = $this->read('memberships.members', ['plan_id' => $gold, 'status' => ['active']]);
        $this->assertSame([$a], array_column($active['body']['members'], 'id'));

        $this->assertSame([$b], array_column($this->read('memberships.members', ['status' => 'cancelled'])['body']['members'], 'id'));
        $this->assertSame('invalid_params', $this->read('memberships.members', ['status' => 'wcm-active'])['error']['code'] ?? null);
        $this->assertSame('unknown_plan', $this->read('memberships.members', ['plan_id' => $a])['error']['code'] ?? null, 'A membership is not a plan');

        $mine = $this->read('memberships.user', ['user_id' => $ann]);
        $this->assertSame(200, $mine['status'], wp_json_encode($mine));
        $this->assertSame([$a, $c], array_column($mine['body']['memberships'], 'id'));
        $first = $mine['body']['memberships'][0];
        $this->assertSame('active', $first['status']);
        $this->assertSame('Gold', $first['plan_name']);
        $this->assertSame('2026-01-01 00:00:00', $first['start_date']);
        $this->assertSame('2099-01-01 00:00:00', $first['end_date']);
        $this->assertNull($mine['body']['memberships'][1]['end_date'], 'No end date is unlimited');

        $this->assertSame('invalid_params', $this->read('memberships.user', ['user_id' => 999999])['error']['code'] ?? null);
        $this->assertSame('invalid_params', $this->read('memberships.user', ['user_id' => $ann, 'plan' => 1])['error']['code'] ?? null);
    }

    // --------------------------------------------------------------- grant

    public function test_grant_needs_confirm_goes_through_the_plugin_and_rolls_back_by_deleting(): void
    {
        $plan      = wpmcp_test_membership_plan('Gold', ['access_length' => '1 months']);
        $user      = $this->member();
        $count     = $this->membership_count();
        $snapshots = $this->snapshot_count();

        $unconfirmed = $this->write('memberships.grant', ['user_id' => $user, 'plan_id' => $plan]);
        $this->assertSame('confirmation_required', $unconfirmed['error']['code'] ?? null, wp_json_encode($unconfirmed));
        $this->assertStringContainsString('email', $unconfirmed['error']['message']);
        $this->assertSame($count, $this->membership_count());
        $this->assertSame($snapshots, $this->snapshot_count());

        $created = did_action('wc_memberships_user_membership_created');
        $out     = $this->write('memberships.grant', ['user_id' => $user, 'plan_id' => $plan], ['confirm' => true]);
        $this->assertSame(201, $out['status'], wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertArrayHasKey('side_effects', $out);
        $this->assertSame(1 + $created, did_action('wc_memberships_user_membership_created'));
        $id = $out['body']['id'];
        $this->assertSame('active', $out['body']['status']);
        $this->assertSame($plan, $out['body']['plan_id']);
        $this->assertSame($user, $out['body']['user_id']);
        $this->assertNotNull($out['body']['end_date'], 'The plan\'s access length sets the end');

        $again = $this->write('memberships.grant', ['user_id' => $user, 'plan_id' => $plan], ['confirm' => true]);
        $this->assertSame('already_member', $again['error']['code'] ?? null);
        $this->assertSame($id, $again['error']['data']['membership_id']);

        $this->rollback($out);
        $this->assertNull(Wc_User_Membership_Snapshot::row($id));
        $this->assertSame($count, $this->membership_count());
    }

    public function test_grant_refuses_bad_input_and_writes_nothing(): void
    {
        $plan  = wpmcp_test_membership_plan();
        $draft = (int) wp_insert_post(['post_type' => 'wc_membership_plan', 'post_status' => 'draft', 'post_title' => 'Draft']);
        $user  = $this->member();
        $count = $this->membership_count();
        $snaps = $this->snapshot_count();

        foreach ([
            'no such user'  => [['user_id' => 999999, 'plan_id' => $plan], 'invalid_params'],
            'no such plan'  => [['user_id' => $user, 'plan_id' => 999999], 'unknown_plan'],
            'a draft plan'  => [['user_id' => $user, 'plan_id' => $draft], 'unknown_plan'],
            'a post'        => [['user_id' => $user, 'plan_id' => self::factory()->post->create()], 'unknown_plan'],
            'unknown field' => [['user_id' => $user, 'plan_id' => $plan, 'end_date' => '2099-01-01'], 'invalid_params'],
        ] as $label => [$params, $code]) {
            $out = $this->write('memberships.grant', $params, ['confirm' => true]);
            $this->assertSame($code, $out['error']['code'] ?? null, $label . ': ' . wp_json_encode($out));
        }
        $this->assertSame($count, $this->membership_count());
        $this->assertSame($snaps, $this->snapshot_count());
    }

    // ------------------------------------------------------ pause / resume

    public function test_pause_rolls_back_exactly(): void
    {
        $id     = $this->membership('active');
        $before = $this->state($id);

        $out = $this->write('memberships.pause', ['id' => $id]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertStringContainsString('access', $out['side_effects']);
        $this->assertSame('paused', $out['body']['status']);
        $this->assertNotNull($out['body']['paused_date']);

        $this->rollback($out);
        $this->assertSame($before, $this->state($id));
        $this->assertContains($id, $this->double->refreshed, 'Memberships reschedules its events after a restore');
    }

    public function test_resume_needs_confirm_and_rolls_back_exactly(): void
    {
        $id     = $this->membership('paused');
        $before = $this->state($id);

        $unconfirmed = $this->write('memberships.resume', ['id' => $id]);
        $this->assertSame('confirmation_required', $unconfirmed['error']['code'] ?? null);
        $this->assertSame($before, $this->state($id));

        $changed = did_action('wc_memberships_user_membership_status_changed');
        $out     = $this->write('memberships.resume', ['id' => $id], ['confirm' => true]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertSame(1 + $changed, did_action('wc_memberships_user_membership_status_changed'));
        $this->assertSame('active', $out['body']['status']);
        $this->assertNull($out['body']['paused_date']);
        $this->assertNotEmpty(get_post_meta($id, '_paused_intervals', true));

        $this->rollback($out);
        $this->assertSame($before, $this->state($id));
        $this->assertFalse(metadata_exists('post', $id, '_paused_intervals'), 'A key the write added is removed again');
    }

    public function test_transition_refusals_write_nothing(): void
    {
        $active    = $this->membership('active');
        $expired   = $this->membership('expired');
        $before    = $this->state($active);
        $snapshots = $this->snapshot_count();

        foreach ([
            'resume an active one'  => ['memberships.resume', ['id' => $active], 'status_not_allowed'],
            'pause an expired one'  => ['memberships.pause', ['id' => $expired], 'status_not_allowed'],
            'no membership'         => ['memberships.pause', ['id' => 999999], 'unknown_membership'],
            'a plan'                => ['memberships.pause', ['id' => wpmcp_test_membership_plan()], 'unknown_membership'],
            'unknown field'         => ['memberships.pause', ['id' => $active, 'note' => 'x'], 'invalid_params'],
        ] as $label => [$op, $params, $code]) {
            $out = $this->write($op, $params, ['confirm' => true]);
            $this->assertSame($code, $out['error']['code'] ?? null, $label . ': ' . wp_json_encode($out));
        }

        $this->assertSame($before, $this->state($active));
        $this->assertSame($snapshots, $this->snapshot_count());
    }

    // -------------------------------------------------------------- extend

    public function test_extend_moves_the_end_date_and_rolls_back_exactly(): void
    {
        $id     = $this->membership('active', ['end' => '2098-06-30 12:00:00']);
        $before = $this->state($id);

        $out = $this->write('memberships.extend', ['id' => $id, 'end_date' => '2099-06-30']);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('2098-06-30 12:00:00', $out['previous_end_date']);
        $this->assertSame('2099-06-30 23:59:59', $out['body']['end_date']);
        $this->assertSame('2099-06-30 23:59:59', $this->double->scheduled[ $id ], 'The expiry is rescheduled through the plugin');

        $this->rollback($out);
        $this->assertSame($before, $this->state($id));
        $this->assertSame('2098-06-30 12:00:00', $this->double->scheduled[ $id ], 'The restored end date is rescheduled');

        $unlimited = $this->write('memberships.extend', ['id' => $id, 'end_date' => null]);
        $this->assertSame(200, $unlimited['status'], wp_json_encode($unlimited));
        $this->assertNull($unlimited['body']['end_date']);
        $this->rollback($unlimited);
        $this->assertSame($before, $this->state($id));
    }

    public function test_extend_refusals_write_nothing(): void
    {
        $id        = $this->membership('active', ['end' => '2098-06-30 12:00:00']);
        $open      = $this->membership('active', ['end' => null]);
        $cancelled = $this->membership('cancelled');
        $before    = $this->state($id);
        $snapshots = $this->snapshot_count();

        foreach ([
            'earlier'          => [['id' => $id, 'end_date' => '2098-01-01'], 'invalid_params'],
            'in the past'      => [['id' => $open, 'end_date' => '2001-01-01'], 'invalid_params'],
            'not a date'       => [['id' => $id, 'end_date' => 'next year'], 'invalid_params'],
            'impossible date'  => [['id' => $id, 'end_date' => '2099-02-30'], 'invalid_params'],
            'missing end_date' => [['id' => $id], 'invalid_params'],
            'already open'     => [['id' => $open, 'end_date' => null], 'invalid_params'],
            'cancelled'        => [['id' => $cancelled, 'end_date' => '2099-01-01'], 'status_not_allowed'],
        ] as $label => [$params, $code]) {
            $out = $this->write('memberships.extend', $params);
            $this->assertSame($code, $out['error']['code'] ?? null, $label . ': ' . wp_json_encode($out));
        }
        $this->assertSame($before, $this->state($id));
        $this->assertSame($snapshots, $this->snapshot_count());
    }

    // -------------------------------------------------------------- cancel

    public function test_cancel_is_off_by_default_and_needs_confirm(): void
    {
        $id     = $this->membership('active');
        $before = $this->state($id);

        $off = $this->write('memberships.cancel', ['id' => $id], ['confirm' => true]);
        $this->assertSame('operation_disabled', $off['error']['code'] ?? null);

        $this->enable_cancel();
        $unconfirmed = $this->write('memberships.cancel', ['id' => $id]);
        $this->assertSame('confirmation_required', $unconfirmed['error']['code'] ?? null);

        $done = $this->write('memberships.cancel', ['id' => $this->membership('cancelled')], ['confirm' => true]);
        $this->assertSame('status_not_allowed', $done['error']['code'] ?? null, 'A cancelled membership is not cancelled again');

        $this->assertSame($before, $this->state($id));
    }

    public function test_cancel_is_not_recoverable_but_rolls_back_the_membership_exactly(): void
    {
        $this->enable_cancel();
        $id     = $this->membership('paused');
        $before = $this->state($id);

        $out = $this->write('memberships.cancel', ['id' => $id], ['confirm' => true]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertFalse($out['recoverable']);
        $this->assertStringContainsString('email', $out['side_effects']);
        $this->assertSame('cancelled', $out['body']['status']);
        $this->assertNotNull($out['body']['cancelled_date']);

        $this->rollback($out);
        $this->assertSame($before, $this->state($id));
    }

    // ------------------------------------------------------------- access

    public function test_a_membership_snapshot_is_restorable_only_by_a_store_manager(): void
    {
        $id     = $this->membership('active');
        $before = $this->state($id);
        $out    = $this->write('memberships.pause', ['id' => $id]);
        $this->assertSame(200, $out['status']);

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $refused = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertFalse($refused['restored']);
        $this->assertSame('paused', $this->state($id)['status']);

        wp_set_current_user(self::factory()->user->create(['role' => 'shop_manager']));
        $this->rollback($out);
        $this->assertSame($before, $this->state($id));
    }

    public function test_writes_take_the_store_capability(): void
    {
        $id = $this->membership('active');
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $out = $this->write('memberships.pause', ['id' => $id]);
        $this->assertSame('operation_denied', $out['error']['code'] ?? null, wp_json_encode($out));
        $this->assertSame('active', $this->state($id)['status']);
    }
}
