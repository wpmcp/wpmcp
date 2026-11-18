<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Discount_Rules_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/discount-rules-stubs.php';

/**
 * Discount Rules for WooCommerce (issue #901): free-tier ops on the
 * plugin-data dispatcher pair, so they add no top-level tools.
 *
 * The plugin is not loaded in the shared test core. Presence is driven
 * through wpmcp_discount_rules_active, and the rules table is built the way
 * the plugin's 2.6.18 createTable() builds it
 * (tests/support/discount-rules-stubs.php).
 *
 * Price-affecting writes are off until the site opts in
 * (wpmcp_enable_discount_rules_write); a created rule is staged disabled,
 * an enabled rule changes only with confirm_live, and each write is undone
 * in a test.
 */
class DiscountRulesPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'discountrules-list-rules'   => 'read',
        'discountrules-get-rule'     => 'read',
        'discountrules-create-rule'  => 'write',
        'discountrules-update-rule'  => 'write',
        'discountrules-enable-rule'  => 'write',
        'discountrules-disable-rule' => 'write',
    ];

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_wdr_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_wdr_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $admin = self::factory()->user->create_and_get([ 'role' => 'administrator' ]);
        $admin->add_cap('manage_woocommerce'); // WooCommerce grants it to administrators on install.
        wp_set_current_user($admin->ID);
        add_filter('wpmcp_discount_rules_active', '__return_true');
        add_filter('wpmcp_enable_discount_rules_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_discount_rules_active');
        remove_all_filters('wpmcp_enable_discount_rules_write');
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && ! isset($out['error']) ? $out['result'] : $out;
    }

    private function write(string $op, array $args): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function code(array $out): ?string
    {
        return $out['error']['code'] ?? null;
    }

    // ---------------------------------------------------------------
    // Catalog and availability
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_op_with_its_mode_and_manage_woocommerce(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            $this->assertSame('manage_woocommerce', $ops[ $name ]['capability'], $name);
        }
    }

    public function test_presence_defaults_to_the_plugin_constant(): void
    {
        remove_all_filters('wpmcp_discount_rules_active');
        $this->assertSame(defined('WDR_VERSION'), Discount_Rules_Pack::active());
    }

    public function test_while_the_plugin_is_missing_a_call_answers_plugin_missing_naming_it(): void
    {
        remove_all_filters('wpmcp_discount_rules_active');
        add_filter('wpmcp_discount_rules_active', '__return_false');
        $out = $this->read('discountrules-list-rules');
        $this->assertSame('plugin_missing', $this->code($out), (string) wp_json_encode($out));
        $this->assertStringContainsString('Discount Rules', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $id = wpmcp_test_wdr_rule([ 'enabled' => 0 ]);
        remove_all_filters('wpmcp_enable_discount_rules_write');
        foreach ([
            [ 'discountrules-create-rule', [ 'title' => 'X', 'discount_type' => 'wdr_simple_discount', 'adjustment' => [ 'type' => 'percentage', 'value' => 5 ] ] ],
            [ 'discountrules-update-rule', [ 'rule_id' => $id, 'title' => 'X' ] ],
            [ 'discountrules-enable-rule', [ 'rule_id' => $id ] ],
            [ 'discountrules-disable-rule', [ 'rule_id' => $id ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $this->code($out), $op);
            $this->assertStringContainsString('wpmcp_enable_discount_rules_write', $out['error']['message']);
        }
        $this->assertSame('Ten percent off', wpmcp_test_wdr_row($id)['title']);
        $this->assertSame('0', wpmcp_test_wdr_row($id)['enabled']);
    }

    public function test_every_op_takes_manage_woocommerce(): void
    {
        $id = wpmcp_test_wdr_rule();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->code($this->read('discountrules-list-rules')));
        $this->assertSame('operation_denied', $this->code($this->read('discountrules-get-rule', [ 'rule_id' => $id ])));
        $this->assertSame('operation_denied', $this->code($this->write('discountrules-disable-rule', [ 'rule_id' => $id ])));
        $this->assertSame('1', wpmcp_test_wdr_row($id)['enabled']);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_rules_returns_live_rules_by_priority_with_types_dates_and_status(): void
    {
        $a = wpmcp_test_wdr_rule([ 'title' => 'Second', 'priority' => 2, 'date_from' => 1798761600, 'usage_limits' => 50, 'used_limits' => 3 ]);
        $b = wpmcp_test_wdr_rule([ 'title' => 'First bulk', 'priority' => 1, 'enabled' => 0, 'discount_type' => 'wdr_bulk_discount', 'product_adjustments' => '[]', 'bulk_adjustments' => '{"operator":"product_cumulative","ranges":{"1":{"from":"2","to":"","type":"percentage","value":"5","label":""}},"cart_label":""}' ]);
        $c = wpmcp_test_wdr_rule([ 'title' => 'Gone', 'priority' => 3, 'deleted' => 1 ]);

        $out = $this->ok($this->read('discountrules-list-rules'));
        $this->assertSame([ $b, $a ], array_column($out['rules'], 'id'));
        $this->assertSame(2, $out['total']);
        $second = $out['rules'][1];
        $this->assertSame('wdr_simple_discount', $second['discount_type']);
        $this->assertTrue($second['enabled']);
        $this->assertSame('2027-01-01 00:00', $second['date_from']);
        $this->assertNull($second['date_to']);
        $this->assertSame(50, $second['usage_limits']);
        $this->assertSame(3, $second['used_limits']);
        $this->assertSame([ 'all_products' ], $second['filter_types']);
        $this->assertSame([ 'cart_subtotal' ], $second['condition_types']);
        $this->assertFalse($out['rules'][0]['enabled']);

        $this->assertSame([ $b ], array_column($this->read('discountrules-list-rules', [ 'status' => 'disabled' ])['rules'], 'id'));
        $this->assertSame([ $c ], array_column($this->read('discountrules-list-rules', [ 'status' => 'deleted' ])['rules'], 'id'));
        $this->assertSame([ $b ], array_column($this->read('discountrules-list-rules', [ 'discount_type' => 'wdr_bulk_discount' ])['rules'], 'id'));
        $this->assertSame([ $b ], array_column($this->read('discountrules-list-rules', [ 'search' => 'bulk' ])['rules'], 'id'));
        $paged = $this->read('discountrules-list-rules', [ 'page_size' => 1 ]);
        $this->assertSame(1, $paged['next_offset']);
    }

    public function test_get_rule_returns_filters_conditions_and_the_adjustment_decoded(): void
    {
        $id   = wpmcp_test_wdr_rule();
        $rule = $this->ok($this->read('discountrules-get-rule', [ 'rule_id' => $id ]))['rule'];
        $this->assertSame('Ten percent off', $rule['title']);
        $this->assertSame([ [ 'type' => 'all_products' ] ], $rule['filters']);
        $this->assertSame('cart_subtotal', $rule['conditions'][0]['type']);
        $this->assertSame('500', $rule['conditions'][0]['options']['value']);
        $this->assertSame('and', $rule['condition_relationship']);
        $this->assertSame([ 'type' => 'percentage', 'value' => '10', 'cart_label' => '' ], $rule['adjustment']);
        $this->assertSame('rule_not_found', $this->code($this->read('discountrules-get-rule', [ 'rule_id' => 99999 ])));
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_create_rule_stages_it_disabled_at_the_end_of_the_queue_and_rollback_removes_it(): void
    {
        $existing = wpmcp_test_wdr_rule([ 'priority' => 4 ]);
        $out      = $this->ok($this->write('discountrules-create-rule', [
            'title'         => 'Hoodies 15 percent',
            'discount_type' => 'wdr_simple_discount',
            'adjustment'    => [ 'type' => 'percentage', 'value' => 15, 'cart_label' => 'Hoodie sale' ],
            'filters'       => [ [ 'type' => 'product_category', 'method' => 'in_list', 'value' => [ 12, 14 ] ] ],
            'date_from'     => '2026-12-01 00:00',
            'date_to'       => '2026-12-31 23:59',
        ]));
        $this->assertTrue($out['recoverable']);
        $rule = $out['result']['rule'];
        $this->assertGreaterThan($existing, $rule['id']);
        $this->assertFalse($rule['enabled'], 'a new rule is staged disabled');
        $this->assertSame('2026-12-01 00:00', $rule['date_from']);

        $row = wpmcp_test_wdr_row($rule['id']);
        $this->assertSame('0', $row['enabled']);
        $this->assertSame('5', $row['priority']);
        $this->assertSame('wdr_simple_discount', $row['discount_type']);
        $this->assertSame([ '1' => [ 'type' => 'product_category', 'method' => 'in_list', 'value' => [ '12', '14' ] ] ], json_decode($row['filters'], true));
        $this->assertSame([ 'type' => 'percentage', 'value' => '15', 'cart_label' => 'Hoodie sale', 'apply_as_cart_rule' => '0' ], json_decode($row['product_adjustments'], true));
        $this->assertSame('[]', $row['cart_adjustments']);
        $this->assertSame((string) gmmktime(0, 0, 0, 12, 1, 2026), $row['date_from']);
        $this->assertSame([ 'condition_relationship' => 'and' ], json_decode($row['additional'], true));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertNull(wpmcp_test_wdr_row($rule['id']));
        $this->assertNotNull(wpmcp_test_wdr_row($existing));
    }

    public function test_create_rule_defaults_to_all_products_and_builds_bulk_ranges(): void
    {
        $out = $this->ok($this->write('discountrules-create-rule', [
            'title'         => 'Buy more',
            'discount_type' => 'wdr_bulk_discount',
            'adjustment'    => [ 'ranges' => [ [ 'from' => 2, 'to' => 4, 'type' => 'percentage', 'value' => 5 ], [ 'from' => 5, 'type' => 'flat', 'value' => 3 ] ] ],
        ]));
        $row = wpmcp_test_wdr_row($out['result']['rule']['id']);
        $this->assertSame([ '1' => [ 'type' => 'all_products' ] ], json_decode($row['filters'], true));
        $bulk = json_decode($row['bulk_adjustments'], true);
        $this->assertSame('product_cumulative', $bulk['operator']);
        $this->assertSame([ 'from' => '2', 'to' => '4', 'type' => 'percentage', 'value' => '5', 'label' => '' ], $bulk['ranges']['1']);
        $this->assertSame([ 'from' => '5', 'to' => '', 'type' => 'flat', 'value' => '3', 'label' => '' ], $bulk['ranges']['2']);
        $this->assertSame('[]', $row['product_adjustments']);
    }

    public function test_a_rule_giving_more_than_100_percent_off_or_a_negative_amount_is_refused(): void
    {
        global $wpdb;
        $before = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->prefix . 'wdr_rules'));
        foreach ([
            [ 'discount_type' => 'wdr_simple_discount', 'adjustment' => [ 'type' => 'percentage', 'value' => 101 ] ],
            [ 'discount_type' => 'wdr_cart_discount', 'adjustment' => [ 'type' => 'percentage', 'value' => 150 ] ],
            [ 'discount_type' => 'wdr_cart_discount', 'adjustment' => [ 'type' => 'flat', 'value' => -5 ] ],
            [ 'discount_type' => 'wdr_bulk_discount', 'adjustment' => [ 'ranges' => [ [ 'from' => 1, 'type' => 'percentage', 'value' => 120 ] ] ] ],
        ] as $args) {
            $out = $this->write('discountrules-create-rule', [ 'title' => 'Too much' ] + $args);
            $this->assertSame('discount_out_of_range', $this->code($out), (string) wp_json_encode($args));
        }
        $this->assertSame($before, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->prefix . 'wdr_rules')));
    }

    public function test_create_rule_refuses_a_pro_type_a_bad_adjustment_type_and_bad_dates(): void
    {
        $this->assertSame('invalid_args', $this->code($this->write('discountrules-create-rule', [ 'title' => 'X', 'discount_type' => 'wdr_simple_discount', 'adjustment' => [ 'type' => 'flat_in_subtotal', 'value' => 1 ] ])));
        $this->assertSame('invalid_args', $this->code($this->write('discountrules-create-rule', [ 'title' => 'X', 'discount_type' => 'wdr_bulk_discount', 'adjustment' => [ 'type' => 'percentage', 'value' => 5 ] ])));
        $this->assertSame('invalid_dates', $this->code($this->write('discountrules-create-rule', [ 'title' => 'X', 'discount_type' => 'wdr_simple_discount', 'adjustment' => [ 'type' => 'percentage', 'value' => 5 ], 'date_from' => '2026-12-31 00:00', 'date_to' => '2026-12-01 00:00' ])));
        $out = $this->write('discountrules-create-rule', [ 'title' => 'X', 'discount_type' => 'wdr_free_shipping', 'adjustment' => [ 'type' => 'percentage', 'value' => 5 ] ]);
        $this->assertNotNull($this->code($out), 'only the free plugin\'s simple, cart and bulk types are created');
    }

    public function test_update_rule_changes_only_the_args_given_and_rolls_back_exactly(): void
    {
        $id     = wpmcp_test_wdr_rule([ 'enabled' => 0 ]);
        $before = wpmcp_test_wdr_row($id);
        $out    = $this->ok($this->write('discountrules-update-rule', [ 'rule_id' => $id, 'title' => 'Twelve percent', 'adjustment' => [ 'type' => 'percentage', 'value' => 12 ] ]));
        $row    = wpmcp_test_wdr_row($id);
        $this->assertSame('Twelve percent', $row['title']);
        $this->assertSame('12', json_decode($row['product_adjustments'], true)['value']);
        $this->assertSame($before['filters'], $row['filters']);
        $this->assertSame($before['conditions'], $row['conditions']);
        $this->assertSame('0', $row['enabled']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, wpmcp_test_wdr_row($id));
    }

    public function test_an_enabled_rule_changes_only_with_confirm_live(): void
    {
        $id  = wpmcp_test_wdr_rule();
        $out = $this->write('discountrules-update-rule', [ 'rule_id' => $id, 'adjustment' => [ 'type' => 'percentage', 'value' => 50 ] ]);
        $this->assertSame('rule_live', $this->code($out));
        $this->assertSame('10', json_decode(wpmcp_test_wdr_row($id)['product_adjustments'], true)['value']);

        $this->ok($this->write('discountrules-update-rule', [ 'rule_id' => $id, 'adjustment' => [ 'type' => 'percentage', 'value' => 50 ], 'confirm_live' => true ]));
        $this->assertSame('50', json_decode(wpmcp_test_wdr_row($id)['product_adjustments'], true)['value']);
        $this->assertSame('1', wpmcp_test_wdr_row($id)['enabled']);
    }

    public function test_update_rule_refuses_a_deleted_rule_and_over_100_percent(): void
    {
        $gone = wpmcp_test_wdr_rule([ 'deleted' => 1, 'enabled' => 0 ]);
        $this->assertSame('rule_not_found', $this->code($this->write('discountrules-update-rule', [ 'rule_id' => $gone, 'title' => 'X' ])));
        $id = wpmcp_test_wdr_rule([ 'enabled' => 0 ]);
        $this->assertSame('discount_out_of_range', $this->code($this->write('discountrules-update-rule', [ 'rule_id' => $id, 'adjustment' => [ 'type' => 'percentage', 'value' => 100.5 ] ])));
    }

    public function test_enable_and_disable_flip_the_flag_and_roll_back(): void
    {
        $id  = wpmcp_test_wdr_rule([ 'enabled' => 0 ]);
        $out = $this->ok($this->write('discountrules-enable-rule', [ 'rule_id' => $id ]));
        $this->assertTrue($out['result']['rule']['enabled']);
        $this->assertSame('1', wpmcp_test_wdr_row($id)['enabled']);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('0', wpmcp_test_wdr_row($id)['enabled']);

        $live = wpmcp_test_wdr_rule();
        $out  = $this->ok($this->write('discountrules-disable-rule', [ 'rule_id' => $live ]));
        $this->assertSame('0', wpmcp_test_wdr_row($live)['enabled']);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('1', wpmcp_test_wdr_row($live)['enabled']);
    }

    public function test_enable_refuses_a_stored_rule_over_100_percent(): void
    {
        $id = wpmcp_test_wdr_rule([ 'enabled' => 0, 'cart_adjustments' => '{"type":"percentage","value":"200","label":""}', 'discount_type' => 'wdr_cart_discount', 'product_adjustments' => '[]' ]);
        $this->assertSame('discount_out_of_range', $this->code($this->write('discountrules-enable-rule', [ 'rule_id' => $id ])));
        $this->assertSame('0', wpmcp_test_wdr_row($id)['enabled']);
    }

    public function test_a_rollback_needs_manage_woocommerce(): void
    {
        $id  = wpmcp_test_wdr_rule([ 'enabled' => 0 ]);
        $out = $this->ok($this->write('discountrules-enable-rule', [ 'rule_id' => $id ]));
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        try {
            $restored = Rollback_Service::restore_operation($out['operation_id']);
        } catch (\Throwable $e) {
            $restored = false;
        }
        $this->assertFalse($restored);
        $this->assertSame('1', wpmcp_test_wdr_row($id)['enabled']);
    }
}
