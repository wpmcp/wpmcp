<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;

/**
 * Paid Member Subscriptions (issue #666): read-only plan and member ops on
 * the plugin-data dispatcher pair, free tier, so they add no top-level tools.
 *
 * The plugin is not loaded in the shared test core. Presence is driven
 * through wpmcp_pms_active, the pms-subscription post type is registered
 * here, a plan is stored the way PMS_Subscription_Plan::init() reads it
 * (pms_subscription_plan_* post meta), and the member subscriptions table
 * is built from the plugin's own DDL (Paid_Member_Subscriptions::create_tables).
 */
class PaidMemberSubscriptionsPackTest extends \WP_UnitTestCase
{
    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'pms_member_subscriptions';
    }

    public static function wpSetUpBeforeClass(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL copied from the plugin.
        $wpdb->query('CREATE TABLE IF NOT EXISTS ' . self::table() . " (
          id bigint(20) AUTO_INCREMENT NOT NULL,
          user_id bigint(20) NOT NULL,
          subscription_plan_id bigint(20) NOT NULL,
          start_date datetime DEFAULT NULL,
          expiration_date datetime DEFAULT NULL,
          status varchar(32) NOT NULL,
          payment_profile_id varchar(32) NOT NULL,
          payment_gateway varchar(32) NOT NULL,
          billing_amount float(10) NOT NULL,
          billing_duration int(10) NOT NULL,
          billing_duration_unit varchar(32) NOT NULL,
          billing_cycles int(10) NOT NULL,
          billing_next_payment datetime DEFAULT NULL,
          billing_last_payment datetime DEFAULT NULL,
          trial_end datetime DEFAULT NULL,
          PRIMARY KEY  (id),
          KEY user_id (user_id),
          KEY subscription_plan_id (subscription_plan_id)
        )");
    }

    public static function wpTearDownAfterClass(): void
    {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS ' . self::table());
    }

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . self::table());
        register_post_type('pms-subscription', [ 'public' => false, 'show_ui' => true, 'capability_type' => 'post', 'hierarchical' => true ]);
        add_filter('wpmcp_pms_active', '__return_true');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_pms_active');
        unregister_post_type('pms-subscription');
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && ! isset($out['error']) ? $out['result'] : $out;
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function plan(array $o = []): int
    {
        $o += [ 'name' => 'Gold', 'price' => '29.99', 'status' => 'active', 'duration' => '1', 'unit' => 'month', 'meta' => [] ];
        $id = self::factory()->post->create([ 'post_type' => 'pms-subscription', 'post_title' => $o['name'], 'post_status' => 'publish' ]);
        update_post_meta($id, 'pms_subscription_plan_price', $o['price']);
        update_post_meta($id, 'pms_subscription_plan_status', $o['status']);
        update_post_meta($id, 'pms_subscription_plan_duration', $o['duration']);
        update_post_meta($id, 'pms_subscription_plan_duration_unit', $o['unit']);
        foreach ($o['meta'] as $key => $value) {
            update_post_meta($id, $key, $value);
        }
        return $id;
    }

    private function member(int $plan, array $o = []): int
    {
        global $wpdb;
        $o += [ 'status' => 'active', 'name' => 'Jane Doe', 'email' => 'jane.doe@example.com', 'start' => '2026-10-01 10:00:00' ];
        $user = self::factory()->user->create([ 'display_name' => $o['name'], 'user_email' => $o['email'] ]);
        $wpdb->insert(self::table(), [
            'user_id'               => $user,
            'subscription_plan_id'  => $plan,
            'start_date'            => $o['start'],
            'expiration_date'       => '2026-12-01 10:00:00',
            'status'                => $o['status'],
            'payment_profile_id'    => 'sub_abc',
            'payment_gateway'       => 'stripe_connect',
            'billing_amount'        => 29.99,
            'billing_duration'      => 1,
            'billing_duration_unit' => 'month',
            'billing_cycles'        => 0,
            'billing_next_payment'  => '2026-12-01 10:00:00',
        ]);
        return (int) $wpdb->insert_id;
    }

    public function test_the_catalog_lists_both_read_ops(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach ([ 'pms-list-plans', 'pms-list-members' ] as $name) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame('read', $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
        }
    }

    public function test_while_the_plugin_is_missing_a_call_answers_plugin_missing_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_pms_active');
        add_filter('wpmcp_pms_active', '__return_false');
        $out = $this->read('pms-list-plans');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Paid Member Subscriptions', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
    }

    public function test_an_editor_may_not_read_plans_or_members(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->read('pms-list-plans')['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->read('pms-list-members')['error']['code'] ?? null);
    }

    public function test_list_plans_reports_pricing_status_and_active_member_counts(): void
    {
        $gold = $this->plan([ 'meta' => [ 'pms_subscription_plan_sign_up_fee' => '5', 'pms_subscription_plan_trial_duration' => '7', 'pms_subscription_plan_trial_duration_unit' => 'day', 'pms_subscription_plan_recurring' => '1', 'pms_subscription_plan_user_role' => 'subscriber' ] ]);
        $free = $this->plan([ 'name' => 'Free', 'price' => '0', 'status' => 'inactive', 'duration' => '0', 'unit' => '' ]);
        $this->member($gold);
        $this->member($gold, [ 'email' => 'b@example.com' ]);
        $this->member($gold, [ 'status' => 'expired', 'email' => 'c@example.com' ]);

        $out  = $this->ok($this->read('pms-list-plans'));
        $this->assertSame(2, $out['total']);
        $rows = array_column($out['plans'], null, 'id');

        $this->assertSame('Gold', $rows[ $gold ]['name']);
        $this->assertSame(29.99, $rows[ $gold ]['price']);
        $this->assertSame('active', $rows[ $gold ]['status']);
        $this->assertSame('1 month', $rows[ $gold ]['duration']);
        $this->assertSame(5.0, $rows[ $gold ]['sign_up_fee']);
        $this->assertSame('7 day', $rows[ $gold ]['trial']);
        $this->assertTrue($rows[ $gold ]['recurring']);
        $this->assertSame('subscriber', $rows[ $gold ]['user_role']);
        $this->assertSame(2, $rows[ $gold ]['active_members'], 'the expired member is not counted');

        $this->assertSame('inactive', $rows[ $free ]['status']);
        $this->assertSame('unlimited', $rows[ $free ]['duration']);
        $this->assertSame(0, $rows[ $free ]['active_members']);
    }

    public function test_list_members_masks_names_and_emails_and_filters_by_plan_and_status(): void
    {
        $gold   = $this->plan();
        $silver = $this->plan([ 'name' => 'Silver' ]);
        $a      = $this->member($gold, [ 'start' => '2026-10-02 10:00:00' ]);
        $b      = $this->member($gold, [ 'status' => 'canceled', 'name' => 'Bob Smith', 'email' => 'bob@example.org', 'start' => '2026-10-01 10:00:00' ]);
        $c      = $this->member($silver, [ 'email' => 'cat@example.net', 'start' => '2026-09-01 10:00:00' ]);

        $out = $this->ok($this->read('pms-list-members', [ 'plan_id' => $gold ]));
        $this->assertTrue($out['masked']);
        $this->assertSame(2, $out['total']);
        $this->assertSame([ $a, $b ], array_column($out['members'], 'id'), 'newest start first');
        $row = $out['members'][0];
        $this->assertSame('j***@example.com', $row['email']);
        $this->assertSame('J***', $row['name']);
        $this->assertSame('Gold', $row['plan_name']);
        $this->assertSame('active', $row['status']);
        $this->assertSame('stripe_connect', $row['payment_gateway']);
        $this->assertSame('2026-12-01 10:00:00', $row['billing_next_payment']);
        $this->assertArrayNotHasKey('payment_profile_id', $row, 'the gateway profile id is not listed');

        $canceled = $this->ok($this->read('pms-list-members', [ 'status' => 'canceled' ]));
        $this->assertSame([ $b ], array_column($canceled['members'], 'id'));

        $all = $this->ok($this->read('pms-list-members', [ 'per_page' => 2 ]));
        $this->assertSame(3, $all['total']);
        $this->assertCount(2, $all['members']);
        $this->assertContains($c, array_column($this->ok($this->read('pms-list-members', [ 'per_page' => 2, 'page' => 2 ]))['members'], 'id'));
    }
}
