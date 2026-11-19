<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Paid_Memberships_Pro_Integration;
use WPMCP\Integrations\Paid_Memberships_Pro_Pack;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/pmpro-stubs.php';

/**
 * Paid Memberships Pro members, orders, discount codes and writes (issue
 * #667) on the existing pmpro-read / pmpro-write pair, so no top-level tool
 * is added.
 *
 * PMPro is not loaded in the shared test core. Presence is driven through
 * wpmcp_pmpro_active and the storage is built from PMPro 3.2.2's own DDL
 * (tests/support/pmpro-stubs.php). Writes are off until the site opts in
 * (wpmcp_enable_pmpro_write) and each one is undone in a test.
 */
class PaidMembershipsProWritesTest extends \WP_UnitTestCase
{
    private const OPS = [
        'list-members'         => 'read',
        'get-member'           => 'read',
        'list-orders'          => 'read',
        'list-discount-codes'  => 'read',
        'create-level'         => 'write',
        'update-level'         => 'write',
        'change-member-level'  => 'destructive',
        'create-discount-code' => 'write',
    ];

    private int $admin;

    private int $gold;

    private int $silver;

    private int $member;

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_pmpro_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_pmpro_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        foreach (wpmcp_test_pmpro_tables() as $name) {
            $wpdb->query('DELETE FROM `' . $wpdb->prefix . $name . '`'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test fixture tables.
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        wp_set_current_user($this->admin);
        add_filter('wpmcp_pmpro_active', '__return_true');
        add_filter('wpmcp_enable_pmpro_write', '__return_true');

        $this->gold   = wpmcp_test_pmpro_level();
        $this->silver = wpmcp_test_pmpro_level([ 'name' => 'Silver', 'initial_payment' => '10.00', 'billing_amount' => '10.00', 'cycle_period' => 'Month' ]);
        $this->member = self::factory()->user->create([ 'user_login' => 'janedoe', 'user_email' => 'jane@example.com', 'display_name' => 'Jane Doe' ]);
        wpmcp_test_pmpro_membership($this->member, $this->gold);
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_pmpro_active');
        remove_all_filters('wpmcp_enable_pmpro_write');
        remove_all_filters('pmpro_after_change_membership_level');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Paid_Memberships_Pro_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args, bool $confirm = false): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if ($confirm) {
            $call['confirm'] = true;
        }
        return (new Paid_Memberships_Pro_Integration())->handle_write($call);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function rows(string $table, string $column, int $id): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE %i = %d ORDER BY 1', $wpdb->prefix . $table, $column, $id), ARRAY_A);
    }

    // ---------------------------------------------------------------
    // Catalog, availability, opt-in
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_new_op_with_its_mode_and_manage_options(): void
    {
        $ops = array_column((new Paid_Memberships_Pro_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops, $name);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertSame('manage_options', $ops[ $name ]['capability'], $name);
        }
        $this->assertArrayHasKey('list-levels', $ops, 'the existing reads stay');
    }

    public function test_the_new_ops_need_the_paid_license(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = array_column((new Paid_Memberships_Pro_Integration())->catalog()['operations'], null, 'name');
        foreach (array_keys(self::OPS) as $name) {
            $this->assertArrayNotHasKey($name, $ops, $name);
        }
        $this->assertArrayHasKey('list-levels', $ops);
    }

    public function test_presence_defaults_to_the_plugin_constant(): void
    {
        remove_all_filters('wpmcp_pmpro_active');
        $this->assertSame(defined('PMPRO_VERSION'), Paid_Memberships_Pro_Pack::active());
    }

    public function test_while_pmpro_is_not_active_a_call_names_the_plugin(): void
    {
        remove_all_filters('wpmcp_pmpro_active');
        add_filter('wpmcp_pmpro_active', '__return_false');
        $out = $this->read('list-members');
        $this->assertContains($out['error']['code'] ?? null, [ 'plugin_missing', 'plugin_inactive' ], (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Paid Memberships Pro', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url']);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters('wpmcp_enable_pmpro_write');
        $out = $this->write('create-level', [ 'name' => 'Bronze' ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('wpmcp_enable_pmpro_write', (string) wp_json_encode($out['error']));
    }

    public function test_an_editor_cannot_run_the_new_ops(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $out = $this->read('list-members');
        $this->assertArrayHasKey('error', $out);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_members_masks_personal_data_and_filters(): void
    {
        $other = self::factory()->user->create();
        wpmcp_test_pmpro_membership($other, $this->silver);
        wpmcp_test_pmpro_membership($other, $this->gold, 'cancelled');

        $out = $this->ok($this->read('list-members', [ 'level_id' => $this->gold ]))['result'];
        $this->assertTrue($out['masked']);
        $this->assertSame(1, $out['total'], 'active only by default');
        $row = $out['members'][0];
        $this->assertSame($this->member, $row['user_id']);
        $this->assertSame('j***@example.com', $row['user_email']);
        $this->assertSame('J***', $row['display_name']);
        $this->assertSame('j***', $row['user_login']);
        $this->assertSame('Gold', $row['level_name']);
        $this->assertSame('active', $row['status']);

        $any = $this->ok($this->read('list-members', [ 'level_id' => $this->gold, 'status' => 'any' ]))['result'];
        $this->assertSame(2, $any['total']);
    }

    public function test_get_member_returns_full_values_and_history(): void
    {
        wpmcp_test_pmpro_membership($this->member, $this->silver, 'expired', '2025-01-01 00:00:00');
        $out = $this->ok($this->read('get-member', [ 'user_id' => $this->member ]))['result']['member'];
        $this->assertSame('jane@example.com', $out['user_email']);
        $this->assertSame('Jane Doe', $out['display_name']);
        $this->assertSame([ $this->gold ], array_column($out['active_levels'], 'level_id'));
        $this->assertCount(2, $out['history']);

        $missing = $this->read('get-member', [ 'user_id' => 999999 ]);
        $this->assertArrayHasKey('error', $missing);
    }

    public function test_list_orders_masks_billing_and_leaves_card_fields_out(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'pmpro_membership_orders', [
            'code' => 'ABC123', 'user_id' => $this->member, 'membership_id' => $this->gold, 'billing_name' => 'Jane Doe',
            'billing_street' => '1 Main St', 'billing_country' => 'US', 'billing_phone' => '5551234', 'total' => '99.00',
            'subtotal' => '99.00', 'tax' => '0', 'status' => 'success', 'gateway' => 'stripe', 'gateway_environment' => 'sandbox',
            'payment_transaction_id' => 'ch_1', 'subscription_transaction_id' => 'sub_1', 'timestamp' => '2026-11-01 10:00:00',
            'affiliate_id' => '', 'affiliate_subid' => '', 'notes' => 'call Jane', 'cardtype' => 'Visa', 'accountnumber' => 'XXXX4242',
        ]);
        $out = $this->ok($this->read('list-orders', [ 'level_id' => $this->gold ]))['result'];
        $this->assertTrue($out['masked']);
        $this->assertSame(1, $out['total']);
        $order = $out['orders'][0];
        $this->assertSame('ABC123', $order['code']);
        $this->assertSame('99.00', $order['total']);
        $this->assertSame('J***', $order['billing_name']);
        $this->assertSame('US', $order['billing_country']);
        foreach ([ 'accountnumber', 'cardtype', 'billing_street', 'billing_phone', 'notes', 'expirationmonth' ] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $order, $hidden);
        }
        $this->assertSame(0, $this->ok($this->read('list-orders', [ 'status' => 'refunded' ]))['result']['total']);
    }

    public function test_list_discount_codes_with_their_levels_and_use_count(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'pmpro_discount_codes', [ 'code' => 'SAVE10', 'starts' => '2026-01-01', 'expires' => '2027-01-01', 'uses' => 5 ]);
        $code = (int) $wpdb->insert_id;
        $wpdb->insert($wpdb->prefix . 'pmpro_discount_codes_levels', [ 'code_id' => $code, 'level_id' => $this->gold, 'initial_payment' => '89', 'billing_amount' => '89', 'cycle_number' => 1, 'cycle_period' => 'Year', 'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'expiration_number' => 0, 'expiration_period' => '' ]);
        $wpdb->insert($wpdb->prefix . 'pmpro_discount_codes_uses', [ 'code_id' => $code, 'user_id' => $this->member, 'order_id' => 1 ]);

        $out = $this->ok($this->read('list-discount-codes'))['result'];
        $this->assertSame(1, $out['total']);
        $row = $out['codes'][0];
        $this->assertSame('SAVE10', $row['code']);
        $this->assertSame(5, $row['uses']);
        $this->assertSame(1, $row['times_used']);
        $this->assertSame($this->gold, $row['levels'][0]['level_id']);
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_create_level_writes_a_complete_row_and_rollback_removes_it(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'pmpro_groups', [ 'name' => 'Main Group', 'allow_multiple_selections' => 0, 'displayorder' => 0 ]);
        $group = (int) $wpdb->insert_id;

        $out   = $this->ok($this->write('create-level', [ 'name' => 'Bronze', 'initial_payment' => 5, 'billing_amount' => 5, 'cycle_number' => 1, 'cycle_period' => 'Month' ]));
        $level = $out['result']['level'];
        $this->assertTrue($out['recoverable']);
        $this->assertSame('Bronze', $level['name']);
        $this->assertSame('1 Month', $level['cycle']);
        $this->assertSame($group, $level['group_id'], 'put in the first group, as the level screen does');
        $this->assertCount(1, $this->rows('pmpro_membership_levels', 'id', $level['id']));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame([], $this->rows('pmpro_membership_levels', 'id', $level['id']));
        $this->assertSame([], $this->rows('pmpro_membership_levels_groups', 'level', $level['id']));
        $this->assertCount(1, $this->rows('pmpro_membership_levels', 'id', $this->gold), 'other levels stay');
    }

    public function test_create_level_refuses_an_unknown_group(): void
    {
        $out = $this->write('create-level', [ 'name' => 'Bronze', 'group_id' => 4242 ]);
        $this->assertSame('group_not_found', $out['error']['code'] ?? null, (string) wp_json_encode($out));
    }

    public function test_update_level_changes_only_the_given_fields_and_rollback_restores_the_row(): void
    {
        $before = $this->rows('pmpro_membership_levels', 'id', $this->gold);
        $out    = $this->ok($this->write('update-level', [ 'level_id' => $this->gold, 'name' => 'Gold Plus', 'allow_signups' => false ]));
        $after  = $this->rows('pmpro_membership_levels', 'id', $this->gold)[0];
        $this->assertSame('Gold Plus', $after['name']);
        $this->assertSame('0', (string) $after['allow_signups']);
        $this->assertSame($before[0]['initial_payment'], $after['initial_payment']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, $this->rows('pmpro_membership_levels', 'id', $this->gold));
    }

    public function test_rolling_back_a_level_write_needs_manage_options(): void
    {
        $out = $this->ok($this->write('update-level', [ 'level_id' => $this->gold, 'name' => 'Renamed' ]));
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        try {
            Rollback_Service::restore_operation($out['operation_id']);
            $this->fail('an editor restored a PMPro level');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('manage_options', $e->getMessage());
        }
        $this->assertSame('Renamed', $this->rows('pmpro_membership_levels', 'id', $this->gold)[0]['name']);
    }

    public function test_update_level_refuses_an_unknown_level(): void
    {
        $out = $this->write('update-level', [ 'level_id' => 9999, 'name' => 'X' ]);
        $this->assertSame('level_not_found', $out['error']['code'] ?? null);
    }

    public function test_change_member_level_asks_for_confirm_with_a_preview(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'pmpro_subscriptions', [ 'user_id' => $this->member, 'membership_level_id' => $this->gold, 'gateway' => 'stripe', 'gateway_environment' => 'live', 'subscription_transaction_id' => 'sub_9', 'status' => 'active' ]);

        $out = $this->write('change-member-level', [ 'user_id' => $this->member, 'level_id' => $this->silver ]);
        $this->assertSame('confirmation_required', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertSame([ $this->gold ], $out['error']['data']['current_level_ids']);
        $this->assertSame(1, $out['error']['data']['active_subscriptions']);
        $this->assertCount(1, $this->rows('pmpro_memberships_users', 'user_id', $this->member), 'nothing written');
    }

    public function test_change_member_level_runs_pmpro_and_rollback_puts_the_rows_back(): void
    {
        $before = $this->rows('pmpro_memberships_users', 'user_id', $this->member);
        $fired  = [];
        add_action('pmpro_after_change_membership_level', static function ($level, $user) use (&$fired): void {
            $fired[] = [ (int) $level, (int) $user ];
        }, 10, 2);

        $out = $this->ok($this->write('change-member-level', [ 'user_id' => $this->member, 'level_id' => $this->silver ], true));
        $this->assertFalse($out['recoverable'], 'a gateway cancel cannot be undone');
        $this->assertNotEmpty($out['operation_id']);
        $this->assertSame([ [ $this->silver, $this->member ] ], $fired);
        $this->assertSame([ $this->silver ], array_column($out['result']['member']['active_levels'], 'level_id'));
        $statuses = array_column($this->rows('pmpro_memberships_users', 'user_id', $this->member), 'status');
        $this->assertSame([ 'admin_changed', 'active' ], $statuses);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, $this->rows('pmpro_memberships_users', 'user_id', $this->member));
    }

    public function test_change_member_level_refuses_a_level_the_member_holds_or_that_does_not_exist(): void
    {
        $held = $this->write('change-member-level', [ 'user_id' => $this->member, 'level_id' => $this->gold ], true);
        $this->assertSame('already_member', $held['error']['code'] ?? null, (string) wp_json_encode($held));
        $gone = $this->write('change-member-level', [ 'user_id' => $this->member, 'level_id' => 9999 ], true);
        $this->assertSame('level_not_found', $gone['error']['code'] ?? null);
    }

    public function test_create_discount_code_writes_code_and_levels_and_rollback_removes_both(): void
    {
        $out = $this->ok($this->write('create-discount-code', [
            'code'    => 'WINTER25',
            'starts'  => '2026-12-01',
            'expires' => '2027-01-31',
            'uses'    => 50,
            'levels'  => [ [ 'level_id' => $this->gold, 'initial_payment' => 74.25, 'billing_amount' => 74.25, 'cycle_number' => 1, 'cycle_period' => 'Year' ] ],
        ]));
        $this->assertTrue($out['recoverable']);
        $code = $out['result']['code'];
        $this->assertSame('WINTER25', $code['code']);
        $this->assertSame('2027-01-31', $code['expires']);
        $this->assertCount(1, $this->rows('pmpro_discount_codes_levels', 'code_id', $code['id']));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame([], $this->rows('pmpro_discount_codes', 'id', $code['id']));
        $this->assertSame([], $this->rows('pmpro_discount_codes_levels', 'code_id', $code['id']));
    }

    public function test_create_discount_code_refuses_a_taken_or_malformed_code_and_unknown_levels(): void
    {
        $this->ok($this->write('create-discount-code', [ 'code' => 'TAKEN', 'levels' => [ [ 'level_id' => $this->gold ] ] ]));
        $this->assertSame('code_in_use', $this->write('create-discount-code', [ 'code' => 'TAKEN', 'levels' => [ [ 'level_id' => $this->gold ] ] ])['error']['code'] ?? null);
        $this->assertSame('invalid_code', $this->write('create-discount-code', [ 'code' => 'no spaces!', 'levels' => [ [ 'level_id' => $this->gold ] ] ])['error']['code'] ?? null);
        $this->assertSame('level_not_found', $this->write('create-discount-code', [ 'code' => 'OTHER', 'levels' => [ [ 'level_id' => 9999 ] ] ])['error']['code'] ?? null);
    }
}
