<?php

namespace WPMCP\Tests\Pro\WooCommerce;

use WPMCP\Governance\Governance;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Safety\Wc_Booking_Snapshot;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\WooCommerce\Catalog\Op_Catalog;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Ops;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Read;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Write;

require_once __DIR__ . '/../../support/wc-bookings-double.php';

/**
 * WooCommerce Bookings (issue #659) as ops on the existing woo-read and
 * woo-write dispatchers, against the stand-in in
 * tests/support/wc-bookings-double.php (the live leg, WooBookingsLiveTest,
 * runs them on the real plugin).
 *
 * Reads return a curated view; every write is snapshotted and undone here;
 * create and update-status need confirm:true; cancel is destructive (off by
 * default, confirm:true) and reported not recoverable. Without the plugin
 * the ops are left out of woo-ops and refused with plugin_missing.
 */
class WooBookingsTest extends \WP_UnitTestCase
{
    private \WPMCP_Test_Bookings_Double $double;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce is not active in this environment.');
        }
        if (class_exists('WC_Bookings')) {
            $this->markTestSkipped('The real WooCommerce Bookings is loaded; WooBookingsLiveTest covers it.');
        }

        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        Governance::reset_for_tests();

        \WC_Install::create_roles();
        $GLOBALS['wp_roles'] = null;
        wp_roles();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->double = wpmcp_test_wc_bookings_double();
    }

    protected function tearDown(): void
    {
        wpmcp_test_wc_bookings_reset();
        remove_all_filters('wpmcp_woo_op_enabled');
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

    /** A booking of a product, in an order that holds only it. */
    private function booking_in_order(string $status = 'confirmed', string $order_status = 'processing'): array
    {
        $product = wpmcp_test_bookable_product();
        $order   = wc_create_order();
        $item    = new \WC_Order_Item_Product();
        $item->set_product(wc_get_product($product));
        $item->set_quantity(1);
        $order->add_item($item);
        $order->calculate_totals();
        $order->set_status($order_status);
        $order->save();

        $id = wpmcp_test_booking(['product_id' => $product, 'status' => $status, 'order_id' => $order->get_id(), 'order_item_id' => $item->get_id()]);
        return [$id, $order->get_id()];
    }

    /** Everything a booking write can change, read back fresh. */
    private function state(int $id): array
    {
        $row = Wc_Booking_Snapshot::row($id);
        $this->assertNotNull($row);
        $order = $row['order_id'] ? wc_get_order($row['order_id']) : null;
        return $row + ['order_status' => $order ? $order->get_status() : null];
    }

    private function snapshot_count(): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- counts ledger rows to prove a refusal wrote nothing.
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpmcp_snapshots");
    }

    private function booking_count(): int
    {
        return count(get_posts(['post_type' => 'wc_booking', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']));
    }

    private function rollback(array $out): void
    {
        $this->assertArrayHasKey('operation_id', $out, wp_json_encode($out));
        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored'], wp_json_encode($rolled));
    }

    private function enable_cancel(): void
    {
        add_filter('wpmcp_woo_op_enabled', static fn ($on, $op) => 'bookings.cancel' === $op ? true : $on, 10, 2);
    }

    private function future_day(int $days = 5): string
    {
        return gmdate('Y-m-d', time() + $days * DAY_IN_SECONDS);
    }

    // ------------------------------------------------------------- catalog

    public function test_the_catalog_carries_booking_ops_on_the_existing_pair(): void
    {
        $ops = Op_Catalog::ops();

        foreach (['bookings.products', 'bookings.list', 'bookings.get', 'bookings.availability'] as $op) {
            $this->assertSame('read', $ops[ $op ]['mode'], $op);
            $this->assertSame('woocommerce-bookings', $ops[ $op ]['plugin'], $op);
        }
        foreach (['bookings.create' => 'wc_booking_create', 'bookings.update-status' => 'wc_booking', 'bookings.set-availability-rules' => 'wc_booking_rules'] as $op => $type) {
            $this->assertSame('write', $ops[ $op ]['mode'], $op);
            $this->assertSame($type, $ops[ $op ]['snapshot']['type'], $op);
            $this->assertTrue($ops[ $op ]['recoverable'], $op);
            $this->assertSame('manage_woocommerce', $ops[ $op ]['capability'], $op);
        }
        $this->assertSame('destructive', $ops['bookings.cancel']['mode']);
        $this->assertSame('wc_booking', $ops['bookings.cancel']['snapshot']['type']);
        $this->assertFalse($ops['bookings.cancel']['recoverable']);
        $this->assertSame('edit_shop_orders', $ops['bookings.get']['capability']);
        foreach ([Wc_Booking_Snapshot::TYPE, Wc_Booking_Snapshot::CREATE_TYPE, Wc_Booking_Snapshot::RULES_TYPE] as $type) {
            $this->assertContains($type, Rollback_Service::restorable_object_types());
        }
    }

    public function test_without_the_plugin_the_ops_are_hidden_and_refused_with_plugin_missing(): void
    {
        $listed = (new Woo_Ops())->handle([]);
        $this->assertArrayHasKey('bookings', $listed['domains']);
        $this->assertSame('WooCommerce Bookings', $listed['domains']['bookings'][0]['requires']);

        wpmcp_test_wc_bookings_reset();

        $listed = (new Woo_Ops())->handle([]);
        $this->assertArrayNotHasKey('bookings', $listed['domains']);
        $this->assertArrayHasKey('orders', $listed['domains']);

        $before = $this->snapshot_count();
        foreach ([[ 'read', 'bookings.list', [] ], [ 'write', 'bookings.update-status', ['id' => 5, 'status' => 'paid'] ]] as [$kind, $op, $params]) {
            $out = 'read' === $kind ? $this->read($op, $params) : $this->write($op, $params, ['confirm' => true]);
            $this->assertSame('plugin_missing', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('Install and activate WooCommerce Bookings', $out['error']['message']);
            $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url']);
        }
        $this->assertSame($before, $this->snapshot_count());
    }

    // --------------------------------------------------------------- reads

    public function test_products_lists_bookable_products_with_their_settings(): void
    {
        $bookable = wpmcp_test_bookable_product(['qty' => '3', 'requires_confirmation' => 'yes']);
        update_post_meta($bookable, '_test_resource_ids', [41, 42]);
        $plain = new \WC_Product_Simple();
        $plain->set_name('Paddle');
        $plain->save();

        $out = $this->read('bookings.products');
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertSame([$bookable], array_column($out['body']['products'], 'id'));
        $row = $out['body']['products'][0];
        $this->assertSame(1, $row['duration']);
        $this->assertSame('hour', $row['duration_unit']);
        $this->assertSame(3, $row['qty']);
        $this->assertTrue($row['requires_confirmation']);
        $this->assertFalse($row['has_persons']);
        $this->assertSame([41, 42], $row['resource_ids']);
        $this->assertSame('available', $row['default_date_availability']);
    }

    public function test_list_and_get_return_a_curated_view(): void
    {
        $customer = self::factory()->user->create(['role' => 'customer']);
        $product  = wpmcp_test_bookable_product();
        $early    = wpmcp_test_booking(['product_id' => $product, 'customer_id' => $customer, 'start' => '20991001100000', 'end' => '20991001110000']);
        $late     = wpmcp_test_booking(['product_id' => $product, 'status' => 'paid', 'start' => '20991005100000', 'end' => '20991005110000']);
        wpmcp_test_booking(['product_id' => $product, 'status' => 'in-cart', 'start' => '20991003100000', 'end' => '20991003110000']);

        $all = $this->read('bookings.list');
        $this->assertSame(200, $all['status'], wp_json_encode($all));
        $this->assertSame([$late, $early], array_column($all['body']['bookings'], 'id'), 'Cart bookings are left out; newest start first');

        $asc = $this->read('bookings.list', ['order' => 'asc', 'from' => '2099-10-02']);
        $this->assertSame([$late], array_column($asc['body']['bookings'], 'id'));

        $mine = $this->read('bookings.list', ['customer_id' => $customer]);
        $this->assertSame([$early], array_column($mine['body']['bookings'], 'id'));

        $paid = $this->read('bookings.list', ['status' => ['paid']]);
        $this->assertSame([$late], array_column($paid['body']['bookings'], 'id'));

        $bad = $this->read('bookings.list', ['status' => 'shipped']);
        $this->assertSame('invalid_params', $bad['error']['code'] ?? null);

        $one = $this->read('bookings.get', ['id' => $early]);
        $this->assertSame(200, $one['status'], wp_json_encode($one));
        $this->assertSame('confirmed', $one['body']['status']);
        $this->assertSame('2099-10-01 10:00:00', $one['body']['start']);
        $this->assertSame('2099-10-01 11:00:00', $one['body']['end']);
        $this->assertSame($customer, $one['body']['customer_id']);
        $this->assertSame('Kayak hire', $one['body']['product_name']);
        $this->assertArrayNotHasKey('meta_data', $one['body']);

        $missing = $this->read('bookings.get', ['id' => $product]);
        $this->assertSame('unknown_booking', $missing['error']['code'] ?? null, 'A product is not a booking');
    }

    public function test_availability_returns_the_rules_and_the_slots(): void
    {
        $product = wpmcp_test_bookable_product(['qty' => '2']);
        update_post_meta($product, '_wc_booking_availability', [['type' => 'days', 'bookable' => 'no', 'priority' => 10, 'from' => '6', 'to' => '7']]);
        $day = $this->future_day(3);
        wpmcp_test_booking(['product_id' => $product, 'start' => str_replace('-', '', $day) . '100000', 'end' => str_replace('-', '', $day) . '110000']);

        $out = $this->read('bookings.availability', ['product_id' => $product, 'from' => $this->future_day(2), 'to' => $this->future_day(5)]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertSame('days', $out['body']['rules'][0]['type']);
        $this->assertSame(3, $out['body']['total']);
        $slot = $out['body']['slots'][1];
        $this->assertSame(1, $slot['booked']);
        $this->assertSame(1, $slot['available']);

        foreach ([
            'too long'        => ['product_id' => $product, 'from' => $this->future_day(1), 'to' => $this->future_day(60)],
            'backwards'       => ['product_id' => $product, 'from' => $this->future_day(5), 'to' => $this->future_day(1)],
            'not a date'      => ['product_id' => $product, 'from' => 'tomorrow'],
        ] as $label => $params) {
            $this->assertSame('invalid_params', $this->read('bookings.availability', $params)['error']['code'] ?? null, $label);
        }
        $plain = new \WC_Product_Simple();
        $plain->save();
        $this->assertSame('not_a_bookable_product', $this->read('bookings.availability', ['product_id' => $plain->get_id()])['error']['code'] ?? null);
    }

    // -------------------------------------------------------------- create

    public function test_create_needs_confirm_books_through_the_plugin_and_rolls_back_by_deleting(): void
    {
        $product   = wpmcp_test_bookable_product();
        $customer  = self::factory()->user->create(['role' => 'customer']);
        $params    = ['product_id' => $product, 'start' => $this->future_day() . ' 09:00', 'customer_id' => $customer, 'note' => 'Window seat'];
        $count     = $this->booking_count();
        $snapshots = $this->snapshot_count();

        $unconfirmed = $this->write('bookings.create', $params);
        $this->assertSame('confirmation_required', $unconfirmed['error']['code'] ?? null, wp_json_encode($unconfirmed));
        $this->assertStringContainsString('email', $unconfirmed['error']['message']);
        $this->assertSame('25.00', $unconfirmed['error']['data']['cost']);
        $this->assertSame($count, $this->booking_count());
        $this->assertSame($snapshots, $this->snapshot_count());

        $confirmed = did_action('woocommerce_booking_confirmed');
        $out       = $this->write('bookings.create', $params, ['confirm' => true]);
        $this->assertSame(201, $out['status'], wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertArrayHasKey('side_effects', $out);
        $this->assertSame(1 + $confirmed, did_action('woocommerce_booking_confirmed'));
        $id = $out['body']['id'];
        $this->assertSame('confirmed', $out['body']['status']);
        $this->assertSame($this->future_day() . ' 09:00:00', $out['body']['start']);
        $this->assertSame($this->future_day() . ' 10:00:00', $out['body']['end']);
        $this->assertSame('25.00', $out['body']['cost']);
        $this->assertSame('Window seat', $out['body']['note']);
        $this->assertSame($customer, $out['body']['customer_id']);

        $taken = $this->write('bookings.create', $params, ['confirm' => true]);
        $this->assertSame('not_bookable', $taken['error']['code'] ?? null, 'The block is full now');

        $this->rollback($out);
        $this->assertNull(Wc_Booking_Snapshot::row($id));
        $this->assertSame($count, $this->booking_count());
    }

    public function test_create_refuses_bad_input_and_writes_nothing(): void
    {
        $product = wpmcp_test_bookable_product(['duration_type' => 'customer']);
        $plain   = new \WC_Product_Simple();
        $plain->save();
        $count     = $this->booking_count();
        $snapshots = $this->snapshot_count();

        foreach ([
            'not bookable'     => [['product_id' => $plain->get_id(), 'start' => $this->future_day() . ' 09:00'], 'not_a_bookable_product'],
            'bad start'        => [['product_id' => $product, 'start' => 'next friday'], 'invalid_params'],
            'impossible date'  => [['product_id' => $product, 'start' => '2099-02-30 09:00'], 'invalid_params'],
            'no duration'      => [['product_id' => $product, 'start' => $this->future_day() . ' 09:00'], 'not_bookable'],
            'unknown resource' => [['product_id' => $product, 'start' => $this->future_day() . ' 09:00', 'duration' => 2, 'resource_id' => 77], 'invalid_params'],
            'bad status'       => [['product_id' => $product, 'start' => $this->future_day() . ' 09:00', 'duration' => 2, 'status' => 'cancelled'], 'invalid_params'],
            'no such customer' => [['product_id' => $product, 'start' => $this->future_day() . ' 09:00', 'duration' => 2, 'customer_id' => 999999], 'invalid_params'],
            'unknown field'    => [['product_id' => $product, 'start' => $this->future_day() . ' 09:00', 'duration' => 2, 'order_id' => 3], 'invalid_params'],
        ] as $label => [$params, $code]) {
            $out = $this->write('bookings.create', $params, ['confirm' => true]);
            $this->assertSame($code, $out['error']['code'] ?? null, $label . ': ' . wp_json_encode($out));
        }

        $this->assertSame($count, $this->booking_count());
        $this->assertSame($snapshots, $this->snapshot_count());

        $ok = $this->write('bookings.create', ['product_id' => $product, 'start' => $this->future_day() . ' 09:00', 'duration' => 3, 'persons' => 2, 'status' => 'unpaid'], ['confirm' => true]);
        $this->assertSame(201, $ok['status'], wp_json_encode($ok));
        $this->assertSame('35.00', $ok['body']['cost']);
        $this->assertSame($this->future_day() . ' 12:00:00', $ok['body']['end']);
        $this->assertSame('unpaid', $ok['body']['status']);
    }

    // -------------------------------------------------------------- status

    public function test_a_status_change_needs_confirm_and_rolls_back_with_its_order(): void
    {
        [$id, $order_id] = $this->booking_in_order('unpaid', 'pending');
        $before          = $this->state($id);

        $unconfirmed = $this->write('bookings.update-status', ['id' => $id, 'status' => 'paid']);
        $this->assertSame('confirmation_required', $unconfirmed['error']['code'] ?? null);
        $this->assertSame($before, $this->state($id));

        $out = $this->write('bookings.update-status', ['id' => $id, 'status' => 'paid'], ['confirm' => true]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertStringContainsString("order {$order_id}", $out['side_effects']);
        $this->assertSame('paid', $out['body']['status']);
        $this->assertSame('processing', wc_get_order($order_id)->get_status(), 'Bookings moved the order with it');

        $this->rollback($out);
        $this->assertSame($before, $this->state($id));
        $this->assertContains($id, $this->double->refreshed, 'Bookings reschedules its events after a restore');
    }

    public function test_status_refusals_write_nothing(): void
    {
        $product   = wpmcp_test_bookable_product();
        $id        = wpmcp_test_booking(['product_id' => $product]);
        $cart      = wpmcp_test_booking(['product_id' => $product, 'status' => 'in-cart']);
        $before    = $this->state($id);
        $snapshots = $this->snapshot_count();

        foreach ([
            'cancel is its own op' => [['id' => $id, 'status' => 'cancelled'], 'invalid_params'],
            'already confirmed'    => [['id' => $id, 'status' => 'confirmed'], 'invalid_params'],
            'unknown field'        => [['id' => $id, 'status' => 'paid', 'note' => 'x'], 'invalid_params'],
            'no booking'           => [['id' => 999999, 'status' => 'paid'], 'unknown_booking'],
            'a product'            => [['id' => $product, 'status' => 'paid'], 'unknown_booking'],
            'a cart booking'       => [['id' => $cart, 'status' => 'paid'], 'status_not_allowed'],
        ] as $label => [$params, $code]) {
            $out = $this->write('bookings.update-status', $params, ['confirm' => true]);
            $this->assertSame($code, $out['error']['code'] ?? null, $label);
        }

        $this->assertSame($before, $this->state($id));
        $this->assertSame($snapshots, $this->snapshot_count());
    }

    // -------------------------------------------------------------- cancel

    public function test_cancel_is_off_by_default_and_needs_confirm(): void
    {
        $id     = wpmcp_test_booking(['product_id' => wpmcp_test_bookable_product()]);
        $before = $this->state($id);

        $off = $this->write('bookings.cancel', ['id' => $id], ['confirm' => true]);
        $this->assertSame('opt_in_required', $off['error']['code'] ?? null);

        $this->enable_cancel();
        $unconfirmed = $this->write('bookings.cancel', ['id' => $id]);
        $this->assertSame('confirmation_required', $unconfirmed['error']['code'] ?? null);

        $done = $this->write('bookings.cancel', ['id' => wpmcp_test_booking(['product_id' => wpmcp_test_bookable_product(), 'status' => 'complete'])], ['confirm' => true]);
        $this->assertSame('status_not_allowed', $done['error']['code'] ?? null, 'A complete booking is not cancelled');

        $this->assertSame($before, $this->state($id));
    }

    public function test_cancel_is_not_recoverable_but_rolls_back_the_booking_and_its_order(): void
    {
        $this->enable_cancel();
        [$id, $order_id] = $this->booking_in_order('paid', 'processing');
        $before          = $this->state($id);

        $out = $this->write('bookings.cancel', ['id' => $id], ['confirm' => true]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertFalse($out['recoverable']);
        $this->assertStringContainsString('calendar', $out['side_effects']);
        $after = $this->state($id);
        $this->assertSame('cancelled', $after['status']);
        $this->assertSame('unattended', $after['meta']['_booking_attendance_status']);
        $this->assertNotNull($after['meta']['_booking_date_cancelled']);
        $this->assertSame('cancelled', $after['order_status']);

        $this->rollback($out);
        $this->assertSame($before, $this->state($id));
        $this->assertSame('processing', wc_get_order($order_id)->get_status());
    }

    // --------------------------------------------------------------- rules

    public function test_set_availability_rules_validates_and_rolls_back_exactly(): void
    {
        $product  = wpmcp_test_bookable_product();
        $existing = [['type' => 'custom', 'bookable' => 'no', 'priority' => 10, 'from' => '2099-12-24', 'to' => '2099-12-26']];
        update_post_meta($product, '_wc_booking_availability', $existing);
        $rules = [
            ['type' => 'days', 'bookable' => 'yes', 'priority' => 5, 'from' => 1, 'to' => '5'],
            ['type' => 'time:range', 'bookable' => false, 'from' => '12:00', 'to' => '13:00', 'from_date' => '2099-01-01', 'to_date' => '2099-01-31'],
        ];

        $out = $this->write('bookings.set-availability-rules', ['product_id' => $product, 'rules' => $rules]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertSame($existing, $out['previous_rules']);
        $this->assertSame(
            [
                ['type' => 'days', 'bookable' => 'yes', 'priority' => 5, 'from' => '1', 'to' => '5'],
                ['type' => 'time:range', 'bookable' => 'no', 'priority' => 10, 'from' => '12:00', 'to' => '13:00', 'from_date' => '2099-01-01', 'to_date' => '2099-01-31'],
            ],
            get_post_meta($product, '_wc_booking_availability', true)
        );

        $this->rollback($out);
        $this->assertSame($existing, get_post_meta($product, '_wc_booking_availability', true));

        // A product that had no rules of its own goes back to none.
        $fresh = wpmcp_test_bookable_product();
        $out   = $this->write('bookings.set-availability-rules', ['product_id' => $fresh, 'rules' => $rules]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->rollback($out);
        $this->assertFalse(metadata_exists('post', $fresh, '_wc_booking_availability'));
    }

    public function test_bad_rules_are_refused_and_write_nothing(): void
    {
        $product   = wpmcp_test_bookable_product();
        $snapshots = $this->snapshot_count();

        foreach ([
            'not a list'       => ['type' => 'days'],
            'unknown type'     => [['type' => 'rrule', 'bookable' => 'yes', 'from' => '1', 'to' => '2']],
            'bad bookable'     => [['type' => 'days', 'bookable' => 'maybe', 'from' => '1', 'to' => '2']],
            'day out of range' => [['type' => 'days', 'bookable' => 'yes', 'from' => '0', 'to' => '8']],
            'bad time'         => [['type' => 'time', 'bookable' => 'yes', 'from' => '9am', 'to' => '17:00']],
            'dates backwards'  => [['type' => 'custom', 'bookable' => 'no', 'from' => '2099-02-02', 'to' => '2099-02-01']],
            'missing dates'    => [['type' => 'custom:daterange', 'bookable' => 'no', 'from' => '09:00', 'to' => '10:00']],
            'stray dates'      => [['type' => 'days', 'bookable' => 'no', 'from' => '1', 'to' => '2', 'from_date' => '2099-01-01']],
            'unknown key'      => [['type' => 'days', 'bookable' => 'no', 'from' => '1', 'to' => '2', 'colour' => 'red']],
        ] as $label => $rules) {
            $out = $this->write('bookings.set-availability-rules', ['product_id' => $product, 'rules' => $rules]);
            $this->assertSame('invalid_params', $out['error']['code'] ?? null, $label);
        }
        $plain = new \WC_Product_Simple();
        $plain->save();
        $out = $this->write('bookings.set-availability-rules', ['product_id' => $plain->get_id(), 'rules' => []]);
        $this->assertSame('not_a_bookable_product', $out['error']['code'] ?? null);

        $this->assertFalse(metadata_exists('post', $product, '_wc_booking_availability'));
        $this->assertSame($snapshots, $this->snapshot_count());
    }

    // ------------------------------------------------------------- access

    public function test_reads_take_the_order_capability_and_writes_the_store_one(): void
    {
        $id   = wpmcp_test_booking(['product_id' => wpmcp_test_bookable_product()]);
        $role = add_role('order_clerk', 'Order clerk', ['read' => true, 'edit_shop_orders' => true, 'edit_others_shop_orders' => true]);
        $this->assertNotNull($role);
        wp_set_current_user(self::factory()->user->create(['role' => 'order_clerk']));

        $read  = $this->read('bookings.get', ['id' => $id]);
        $write = $this->write('bookings.update-status', ['id' => $id, 'status' => 'paid'], ['confirm' => true]);
        remove_role('order_clerk');

        $this->assertSame(200, $read['status'] ?? null, wp_json_encode($read));
        $this->assertSame('operation_denied', $write['error']['code'] ?? null);
        $this->assertSame('capability', $write['error']['data']['reason'] ?? null);
    }

    public function test_a_booking_snapshot_is_restorable_only_by_a_store_manager(): void
    {
        $id     = wpmcp_test_booking(['product_id' => wpmcp_test_bookable_product()]);
        $before = $this->state($id);
        $out    = $this->write('bookings.update-status', ['id' => $id, 'status' => 'paid'], ['confirm' => true]);
        $this->assertSame(200, $out['status']);

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $refused = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertFalse($refused['restored']);

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->rollback($out);
        $this->assertSame($before, $this->state($id));
    }
}
