<?php

namespace WPMCP\Tests\Pro\WooCommerce;

use WPMCP\Governance\Governance;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\WooCommerce\Catalog\Op_Catalog;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Read;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Write;

/**
 * Shipping zone and method writes (issue #292, second slice) as ops on the
 * existing woo-read / woo-write dispatchers. Zones and methods are written
 * through WC_Shipping_Zone; every change is covered by a snapshot of the
 * whole zone (zone row, location rows, method rows and each method's
 * settings option), so rollback restores it exactly; a created zone records
 * a creation row whose rollback deletes it.
 */
class WooShippingWritesTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce is not active in this environment.');
        }

        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        Governance::reset_for_tests();

        \WC_Install::create_roles();
        $GLOBALS['wp_roles'] = null;
        wp_roles();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        global $wp_rest_server;
        $wp_rest_server = null;
        rest_get_server();
    }

    protected function tearDown(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;

        remove_all_filters('wpmcp_woo_op_enabled');
        Governance::reset_for_tests();
        wp_set_current_user(0);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    // ------------------------------------------------------------ fixtures

    /** A zone with two locations, a configured flat rate and free shipping. */
    private function zone(string $name = 'Europe'): \WC_Shipping_Zone
    {
        $zone = new \WC_Shipping_Zone();
        $zone->set_zone_name($name);
        $zone->set_zone_order(3);
        $zone->add_location('DE', 'country');
        $zone->add_location('FR', 'country');
        $zone->save();

        $flat = $zone->add_shipping_method('flat_rate');
        update_option("woocommerce_flat_rate_{$flat}_settings", ['title' => 'Courier', 'tax_status' => 'taxable', 'cost' => '7.50']);
        $zone->add_shipping_method('free_shipping');

        return new \WC_Shipping_Zone($zone->get_id());
    }

    private function instance_ids(int $zone_id): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test oracle reads the raw method rows.
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT instance_id FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE zone_id = %d ORDER BY instance_id",
            $zone_id
        )));
    }

    private function write(string $op, array $params, array $extra = []): array
    {
        return (new Woo_Write())->handle(['op' => $op, 'params' => $params] + $extra);
    }

    private function enable_destructive(): void
    {
        add_filter('wpmcp_woo_op_enabled', static fn ($enabled, $op) => in_array($op, ['shipping.delete-zone', 'shipping.remove-method'], true) ? true : $enabled, 10, 2);
    }

    /**
     * The whole stored state of one zone: its row, its location rows, its
     * method rows and every method's settings option (value and autoload),
     * read raw so a stale cache or a lost id would show.
     */
    private function state(int $zone_id): array
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- test oracle reads the raw rows.
        $zone      = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}woocommerce_shipping_zones WHERE zone_id = %d", $zone_id), ARRAY_A);
        $locations = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}woocommerce_shipping_zone_locations WHERE zone_id = %d ORDER BY location_id", $zone_id), ARRAY_A);
        $methods   = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE zone_id = %d ORDER BY instance_id", $zone_id), ARRAY_A);
        $options   = [];
        foreach ($methods as $method) {
            $name             = "woocommerce_{$method['method_id']}_{$method['instance_id']}_settings";
            $options[ $name ] = $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name), ARRAY_A);
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery
        $gone = null === $zone && 0 !== $zone_id;

        return [
            'zone'      => $zone,
            'locations' => $locations,
            'methods'   => $methods,
            'options'   => $options,
            // Read back through WooCommerce as well, so a stale cache shows
            // (a deleted zone cannot be loaded at all).
            'wc_name'   => $gone ? null : (new \WC_Shipping_Zone($zone_id))->get_zone_name('edit'),
            'wc_count'  => $gone ? null : count((new \WC_Shipping_Zone($zone_id))->get_shipping_methods()),
        ];
    }

    private function snapshot_count(): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- counts ledger rows to prove a refusal wrote nothing.
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpmcp_snapshots");
    }

    private function zone_count(): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test oracle.
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_shipping_zones");
    }

    // ------------------------------------------------------------- catalog

    public function test_the_catalog_carries_shipping_reads_and_writes_on_the_existing_dispatchers(): void
    {
        $ops = Op_Catalog::ops();

        foreach (['shipping.zones', 'shipping.zone'] as $read) {
            $this->assertArrayHasKey($read, $ops);
            $this->assertSame('read', $ops[ $read ]['mode']);
        }

        $expect = [
            'shipping.create-zone'   => ['write', 'POST', 'wc_shipping_zone_create'],
            'shipping.update-zone'   => ['write', 'PUT', 'wc_shipping_zone'],
            'shipping.delete-zone'   => ['destructive', 'DELETE', 'wc_shipping_zone'],
            'shipping.add-method'    => ['write', 'POST', 'wc_shipping_zone'],
            'shipping.update-method' => ['write', 'PUT', 'wc_shipping_zone'],
            'shipping.remove-method' => ['destructive', 'DELETE', 'wc_shipping_zone'],
        ];
        foreach ($expect as $op => [$mode, $method, $snapshot]) {
            $this->assertArrayHasKey($op, $ops);
            $this->assertSame('shipping', $ops[ $op ]['domain']);
            $this->assertSame($mode, $ops[ $op ]['mode'], $op);
            $this->assertSame($method, $ops[ $op ]['method'], $op);
            $this->assertSame($snapshot, $ops[ $op ]['snapshot']['type'] ?? null, $op);
            $this->assertSame('manage_woocommerce', $ops[ $op ]['capability'], $op);
            $this->assertTrue($ops[ $op ]['recoverable'], $op);
        }

        foreach (['wc_shipping_zone', 'wc_shipping_zone_create'] as $type) {
            $this->assertContains($type, Rollback_Service::restorable_object_types());
        }
    }

    // --------------------------------------------------------------- reads

    public function test_zone_reads_carry_locations_and_methods_with_their_settings(): void
    {
        $zone = $this->zone();
        $id   = $zone->get_id();

        $one = (new Woo_Read())->handle(['op' => 'shipping.zone', 'params' => ['id' => $id]]);
        $this->assertSame(200, $one['status'], wp_json_encode($one));
        $this->assertSame($id, $one['body']['id']);
        $this->assertSame('Europe', $one['body']['name']);
        $this->assertSame(3, $one['body']['order']);
        $this->assertEqualsCanonicalizing(
            [['code' => 'DE', 'type' => 'country'], ['code' => 'FR', 'type' => 'country']],
            $one['body']['locations']
        );
        $methods = array_column($one['body']['methods'], null, 'method_id');
        $this->assertSame(['flat_rate', 'free_shipping'], array_keys($methods));
        $this->assertSame('7.50', $methods['flat_rate']['settings']['cost']);
        $this->assertSame('Courier', $methods['flat_rate']['title']);
        $this->assertTrue($methods['flat_rate']['enabled']);

        $list = (new Woo_Read())->handle(['op' => 'shipping.zones']);
        $this->assertSame(200, $list['status']);
        $ids = array_column($list['body'], 'id');
        $this->assertContains($id, $ids);
        $this->assertContains(0, $ids, 'The "locations not covered" zone is listed too');
        $listed = array_values(array_filter($list['body'], static fn ($z) => $z['id'] === $id))[0];
        $this->assertCount(2, $listed['locations']);
        $this->assertCount(2, $listed['methods']);

        $missing = (new Woo_Read())->handle(['op' => 'shipping.zone', 'params' => ['id' => 999999]]);
        $this->assertSame('unknown_zone', $missing['error']['code'] ?? null);
    }

    // -------------------------------------------------------------- create

    public function test_create_zone_writes_name_order_and_locations_and_rollback_deletes_it(): void
    {
        $zones = $this->zone_count();

        $out = $this->write('shipping.create-zone', [
            'name'      => 'North America',
            'order'     => 2,
            'locations' => [
                ['code' => 'US', 'type' => 'country'],
                ['code' => 'CA:ON', 'type' => 'state'],
                ['code' => 'NA', 'type' => 'continent'],
                ['code' => '90210...90299', 'type' => 'postcode'],
            ],
        ]);
        $this->assertSame(201, $out['status'], wp_json_encode($out));
        $this->assertTrue($out['applied']);
        $this->assertTrue($out['recoverable']);
        $this->assertNotEmpty($out['operation_id']);

        $id   = (int) $out['body']['id'];
        $zone = new \WC_Shipping_Zone($id);
        $this->assertSame('North America', $zone->get_zone_name());
        $this->assertSame(2, $zone->get_zone_order());
        $this->assertCount(4, $zone->get_zone_locations());
        $this->assertSame($zones + 1, $this->zone_count());

        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored'], wp_json_encode($rolled));
        $this->assertSame($zones, $this->zone_count(), 'Rolling back a create deletes the zone: it is configuration, not content');
        $this->assertNull($this->state($id)['zone']);
        $this->assertSame([], $this->state($id)['locations']);
    }

    public function test_zone_writes_validate_everything_before_writing_anything(): void
    {
        $zone     = $this->zone();
        $id       = $zone->get_id();
        $before   = $this->state($id);
        $ledger   = $this->snapshot_count();
        $zones    = $this->zone_count();

        $cases = [
            ['shipping.create-zone', ['locations' => []], 'invalid_params'],
            ['shipping.create-zone', ['name' => ''], 'invalid_params'],
            ['shipping.create-zone', ['name' => 'X', 'locations' => [['code' => 'ZZ', 'type' => 'country']]], 'invalid_params'],
            ['shipping.create-zone', ['name' => 'X', 'locations' => [['code' => 'US:XX', 'type' => 'state']]], 'invalid_params'],
            ['shipping.create-zone', ['name' => 'X', 'locations' => [['code' => 'US', 'type' => 'planet']]], 'invalid_params'],
            ['shipping.create-zone', ['name' => 'X', 'order' => -1], 'invalid_params'],
            ['shipping.create-zone', ['name' => 'X', 'color' => 'red'], 'invalid_params'],
            ['shipping.update-zone', ['id' => $id], 'invalid_params'],
            ['shipping.update-zone', ['id' => 999999, 'name' => 'Y'], 'unknown_zone'],
            ['shipping.update-zone', ['id' => 0, 'name' => 'Y'], 'invalid_params'],
            ['shipping.update-zone', ['id' => $id, 'locations' => 'DE'], 'invalid_params'],
        ];
        foreach ($cases as [$op, $params, $code]) {
            $out = $this->write($op, $params);
            $this->assertSame($code, $out['error']['code'] ?? null, $op . ' ' . wp_json_encode($params) . ' => ' . wp_json_encode($out));
        }

        $this->assertSame($before, $this->state($id));
        $this->assertSame($ledger, $this->snapshot_count(), 'A refusal writes no snapshot');
        $this->assertSame($zones, $this->zone_count());
    }

    // -------------------------------------------------------------- update

    public function test_update_zone_rolls_back_exactly(): void
    {
        $zone   = $this->zone();
        $id     = $zone->get_id();
        $before = $this->state($id);

        $out = $this->write('shipping.update-zone', [
            'id'        => $id,
            'name'      => 'EU and UK',
            'order'     => 9,
            'locations' => [['code' => 'GB', 'type' => 'country'], ['code' => 'EU', 'type' => 'continent']],
        ]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertSame('EU and UK', $out['body']['name']);
        $this->assertSame(9, $out['body']['order']);
        $this->assertNotEquals($before, $this->state($id));

        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored']);
        $this->assertSame($before, $this->state($id));
    }

    // -------------------------------------------------------------- delete

    public function test_delete_zone_is_destructive_and_rollback_resurrects_it_at_the_same_ids(): void
    {
        $zone   = $this->zone();
        $id     = $zone->get_id();
        $before = $this->state($id);

        $off = $this->write('shipping.delete-zone', ['id' => $id], ['confirm' => true]);
        $this->assertSame('operation_disabled', $off['error']['code'] ?? null);

        $this->enable_destructive();
        $unconfirmed = $this->write('shipping.delete-zone', ['id' => $id]);
        $this->assertSame('confirmation_required', $unconfirmed['error']['code'] ?? null);
        $this->assertSame($before, $this->state($id));

        $out = $this->write('shipping.delete-zone', ['id' => $id], ['confirm' => true]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $gone = $this->state($id);
        $this->assertNull($gone['zone']);
        $this->assertSame([], $gone['methods']);
        foreach (array_keys($before['options']) as $option) {
            $this->assertFalse(get_option($option), 'Deleting a zone removes its method settings');
        }

        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored']);
        $this->assertSame($before, $this->state($id));
    }

    // ------------------------------------------------------------- methods

    public function test_add_method_writes_its_settings_and_rollback_removes_it(): void
    {
        $zone   = $this->zone();
        $id     = $zone->get_id();
        $before = $this->state($id);

        $out = $this->write('shipping.add-method', [
            'zone_id'   => $id,
            'method_id' => 'local_pickup',
            'enabled'   => false,
            'settings'  => ['title' => 'Collect in store', 'cost' => '1.25', 'tax_status' => 'none'],
        ]);
        $this->assertSame(201, $out['status'], wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $instance = (int) $out['body']['instance_id'];
        $this->assertSame('local_pickup', $out['body']['method_id']);
        $this->assertFalse($out['body']['enabled']);
        $this->assertSame('Collect in store', $out['body']['settings']['title']);
        $this->assertSame('1.25', get_option("woocommerce_local_pickup_{$instance}_settings")['cost'] ?? null);
        $this->assertContains($instance, $this->instance_ids($id));

        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored']);
        $this->assertNotContains($instance, $this->instance_ids($id));
        $this->assertFalse(get_option("woocommerce_local_pickup_{$instance}_settings"), 'Rollback removes the created method and its settings');
        $this->assertSame($before, $this->state($id));
    }

    public function test_update_method_rolls_back_settings_order_and_enabled_exactly(): void
    {
        $zone     = $this->zone();
        $id       = $zone->get_id();
        $flat     = $this->instance_ids($id)[0];
        $before   = $this->state($id);

        $out = $this->write('shipping.update-method', [
            'zone_id'     => $id,
            'instance_id' => $flat,
            'enabled'     => false,
            'order'       => 5,
            'settings'    => ['cost' => '12.00', 'title' => 'Express', 'tax_status' => 'none'],
        ]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $settings = get_option("woocommerce_flat_rate_{$flat}_settings");
        $this->assertSame('12.00', $settings['cost']);
        $this->assertSame('Express', $settings['title']);
        $this->assertFalse($out['body']['enabled']);
        $this->assertSame(5, $out['body']['order']);

        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored']);
        $this->assertSame($before, $this->state($id));
    }

    public function test_setting_a_method_that_had_no_settings_option_rolls_back_to_no_option(): void
    {
        $zone   = $this->zone();
        $id     = $zone->get_id();
        $free   = $this->instance_ids($id)[1];
        $this->assertFalse(get_option("woocommerce_free_shipping_{$free}_settings"));
        $before = $this->state($id);

        $out = $this->write('shipping.update-method', ['zone_id' => $id, 'instance_id' => $free, 'settings' => ['requires' => 'min_amount', 'min_amount' => '50']]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertSame('min_amount', get_option("woocommerce_free_shipping_{$free}_settings")['requires'] ?? null);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertFalse(get_option("woocommerce_free_shipping_{$free}_settings"));
        $this->assertSame($before, $this->state($id));
    }

    public function test_remove_method_is_destructive_and_rollback_restores_the_same_instance(): void
    {
        $zone   = $this->zone();
        $id     = $zone->get_id();
        $flat   = $this->instance_ids($id)[0];
        $before = $this->state($id);
        $this->enable_destructive();

        $unconfirmed = $this->write('shipping.remove-method', ['zone_id' => $id, 'instance_id' => $flat]);
        $this->assertSame('confirmation_required', $unconfirmed['error']['code'] ?? null);

        $out = $this->write('shipping.remove-method', ['zone_id' => $id, 'instance_id' => $flat], ['confirm' => true]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertNotContains($flat, $this->instance_ids($id));
        $this->assertFalse(get_option("woocommerce_flat_rate_{$flat}_settings"));

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertSame($before, $this->state($id));
    }

    public function test_method_writes_refuse_bad_types_settings_and_foreign_instances(): void
    {
        $zone   = $this->zone();
        $id     = $zone->get_id();
        $other  = $this->zone('Asia');
        $flat   = $this->instance_ids($id)[0];
        $alien  = $this->instance_ids($other->get_id())[0];
        $before = $this->state($id);
        $ledger = $this->snapshot_count();

        $cases = [
            ['shipping.add-method', ['zone_id' => $id, 'method_id' => 'table_rate'], 'invalid_params'],
            ['shipping.add-method', ['zone_id' => $id], 'invalid_params'],
            ['shipping.add-method', ['zone_id' => 999999, 'method_id' => 'flat_rate'], 'unknown_zone'],
            ['shipping.add-method', ['zone_id' => $id, 'method_id' => 'flat_rate', 'settings' => ['api_key' => 'x']], 'invalid_params'],
            ['shipping.add-method', ['zone_id' => $id, 'method_id' => 'flat_rate', 'settings' => ['cost' => '(2 * [qty]']], 'invalid_params'],
            ['shipping.add-method', ['zone_id' => $id, 'method_id' => 'flat_rate', 'settings' => ['tax_status' => 'sometimes']], 'invalid_params'],
            ['shipping.add-method', ['zone_id' => $id, 'method_id' => 'flat_rate', 'enabled' => 'maybe'], 'invalid_params'],
            ['shipping.update-method', ['zone_id' => $id, 'instance_id' => $alien, 'enabled' => false], 'invalid_params'],
            ['shipping.update-method', ['zone_id' => $id, 'instance_id' => $flat], 'invalid_params'],
            ['shipping.update-method', ['zone_id' => $id, 'instance_id' => $flat, 'method_id' => 'free_shipping'], 'invalid_params'],
        ];
        foreach ($cases as [$op, $params, $code]) {
            $out = $this->write($op, $params);
            $this->assertSame($code, $out['error']['code'] ?? null, $op . ' ' . wp_json_encode($params) . ' => ' . wp_json_encode($out));
        }

        $this->assertSame($before, $this->state($id));
        $this->assertSame($ledger, $this->snapshot_count(), 'A refusal writes no snapshot');
    }

    public function test_methods_on_the_locations_not_covered_zone_roll_back_too(): void
    {
        $before = $this->state(0);

        $out = $this->write('shipping.add-method', ['zone_id' => 0, 'method_id' => 'flat_rate', 'settings' => ['cost' => '20']]);
        $this->assertSame(201, $out['status'], wp_json_encode($out));
        $this->assertNotEquals($before, $this->state(0));

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertSame($before, $this->state(0));
    }

    // ------------------------------------------------------ gates, sessions

    public function test_shipping_writes_need_manage_woocommerce(): void
    {
        $zone = $this->zone();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $outs = [
            $this->write('shipping.create-zone', ['name' => 'X']),
            $this->write('shipping.update-zone', ['id' => $zone->get_id(), 'name' => 'X']),
            $this->write('shipping.add-method', ['zone_id' => $zone->get_id(), 'method_id' => 'flat_rate']),
            (new Woo_Read())->handle(['op' => 'shipping.zone', 'params' => ['id' => $zone->get_id()]]),
        ];
        foreach ($outs as $out) {
            $this->assertSame('operation_denied', $out['error']['code'] ?? null, wp_json_encode($out));
            $this->assertSame('capability', $out['error']['data']['reason'] ?? null);
        }
    }

    public function test_a_shipping_snapshot_is_restorable_only_by_a_store_manager(): void
    {
        $zone   = $this->zone();
        $before = $this->state($zone->get_id());
        $out    = $this->write('shipping.update-zone', ['id' => $zone->get_id(), 'name' => 'Renamed']);
        $this->assertSame(200, $out['status']);

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $refused = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertFalse($refused['restored']);
        $this->assertSame('Renamed', (new \WC_Shipping_Zone($zone->get_id()))->get_zone_name());

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertSame($before, $this->state($zone->get_id()));
    }

    public function test_a_session_that_created_a_zone_and_added_methods_unwinds_to_nothing(): void
    {
        $session = wp_generate_uuid4();
        $zones   = $this->zone_count();

        $made = $this->write('shipping.create-zone', ['name' => 'Oceania', 'locations' => [['code' => 'AU', 'type' => 'country']]], ['session_id' => $session]);
        $this->assertSame(201, $made['status'], wp_json_encode($made));
        $id = (int) $made['body']['id'];

        $method = $this->write('shipping.add-method', ['zone_id' => $id, 'method_id' => 'flat_rate', 'settings' => ['cost' => '3']], ['session_id' => $session]);
        $this->assertSame(201, $method['status'], wp_json_encode($method));
        $instance = (int) $method['body']['instance_id'];

        $rename = $this->write('shipping.update-zone', ['id' => $id, 'name' => 'Australia'], ['session_id' => $session]);
        $this->assertSame(200, $rename['status']);

        (new Rollback_Session())->handle(['session_id' => $session]);

        $this->assertSame($zones, $this->zone_count());
        $this->assertSame([], $this->instance_ids($id));
        $this->assertFalse(get_option("woocommerce_flat_rate_{$instance}_settings"));
    }

    public function test_rollback_of_a_zone_create_whose_id_now_holds_another_zone_leaves_it(): void
    {
        $out = $this->write('shipping.create-zone', ['name' => 'Mine', 'locations' => [['code' => 'DE', 'type' => 'country']]]);
        $this->assertSame(201, $out['status'], wp_json_encode($out));
        $id = (int) $out['body']['id'];

        // The created zone is deleted in the store admin, and a different
        // zone later lands on the same id.
        (new \WC_Shipping_Zone($id))->delete(true);
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture: a foreign zone at a reused id.
        $wpdb->insert($wpdb->prefix . 'woocommerce_shipping_zones', ['zone_id' => $id, 'zone_name' => 'Theirs', 'zone_order' => 0], ['%d', '%s', '%d']);
        \WC_Cache_Helper::invalidate_cache_group('shipping_zones');
        $theirs = $this->state($id);

        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertSame($theirs, $this->state($id), 'someone else\'s zone must survive the undo of ours');
        $this->assertSame('Theirs', $this->state($id)['wc_name']);
        $this->assertNotEmpty($rolled['warnings'], 'the skipped restore is reported');
        $this->assertStringContainsString((string) $id, implode(' ', $rolled['warnings']));
    }

    public function test_a_session_that_created_edited_and_deleted_a_zone_rolls_back_fully(): void
    {
        $this->enable_destructive();
        $session = wp_generate_uuid4();
        $zones   = $this->zone_count();

        $made = $this->write('shipping.create-zone', ['name' => 'Asia', 'locations' => [['code' => 'JP', 'type' => 'country']]], ['session_id' => $session]);
        $this->assertSame(201, $made['status'], wp_json_encode($made));
        $id = (int) $made['body']['id'];

        $method = $this->write('shipping.add-method', ['zone_id' => $id, 'method_id' => 'flat_rate', 'settings' => ['cost' => '4']], ['session_id' => $session]);
        $this->assertSame(201, $method['status'], wp_json_encode($method));
        $instance = (int) $method['body']['instance_id'];

        $rename = $this->write('shipping.update-zone', ['id' => $id, 'name' => 'Japan'], ['session_id' => $session]);
        $this->assertSame(200, $rename['status'], wp_json_encode($rename));

        $deleted = $this->write('shipping.delete-zone', ['id' => $id], ['session_id' => $session, 'confirm' => true]);
        $this->assertSame(200, $deleted['status'], wp_json_encode($deleted));

        $rolled = (new Rollback_Session())->handle(['session_id' => $session]);

        $this->assertSame([], $rolled['warnings'], wp_json_encode($rolled));
        $this->assertSame($zones, $this->zone_count(), 'the created zone is gone again');
        $this->assertNull($this->state($id)['zone']);
        $this->assertSame([], $this->instance_ids($id));
        $this->assertFalse(get_option("woocommerce_flat_rate_{$instance}_settings"));
    }

    public function test_a_session_that_created_then_renamed_a_zone_rolls_back_fully(): void
    {
        $session = wp_generate_uuid4();
        $zones   = $this->zone_count();

        $made = $this->write('shipping.create-zone', ['name' => 'Africa'], ['session_id' => $session]);
        $this->assertSame(201, $made['status'], wp_json_encode($made));
        $id = (int) $made['body']['id'];

        $rename = $this->write('shipping.update-zone', ['id' => $id, 'name' => 'Kenya', 'locations' => [['code' => 'KE', 'type' => 'country']]], ['session_id' => $session]);
        $this->assertSame(200, $rename['status'], wp_json_encode($rename));

        $rolled = (new Rollback_Session())->handle(['session_id' => $session]);

        $this->assertSame([], $rolled['warnings'], wp_json_encode($rolled));
        $this->assertSame($zones, $this->zone_count());
        $this->assertNull($this->state($id)['zone']);
        $this->assertSame([], $this->state($id)['locations']);
    }

    public function test_shipping_writes_batch_under_one_session(): void
    {
        $zone = $this->zone();
        $out  = (new Woo_Write())->handle([
            'batch' => [
                ['op' => 'shipping.update-zone', 'params' => ['id' => $zone->get_id(), 'name' => 'Batched']],
                ['op' => 'shipping.add-method', 'params' => ['zone_id' => $zone->get_id(), 'method_id' => 'free_shipping']],
            ],
        ]);

        $this->assertSame(2, $out['applied'], wp_json_encode($out));
        $this->assertSame('Batched', (new \WC_Shipping_Zone($zone->get_id()))->get_zone_name());
        $this->assertCount(3, $this->instance_ids($zone->get_id()));
    }
}
