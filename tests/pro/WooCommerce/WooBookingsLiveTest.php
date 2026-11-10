<?php

namespace WPMCP\Tests\Pro\WooCommerce;

use WPMCP\Governance\Governance;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Safety\Wc_Booking_Snapshot;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Ops;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Read;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Write;

/**
 * Issue #659: the bookings.* ops against the real WooCommerce Bookings.
 *
 * It runs only in the local gate's live Bookings leg (bin/test-local.sh
 * --live-wc-bookings), which sets WPMCP_LIVE_WC_BOOKINGS=1, copies the
 * plugin from WPMCP_WC_BOOKINGS_SRC onto a separate WordPress install
 * beside WooCommerce (Bookings is a commercial plugin, not on
 * wordpress.org) and loads it in tests/bootstrap.php. Everywhere else it is
 * skipped; the leg runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * Products and bookings are made with the plugin's own classes, every write
 * goes through its API, and every rollback must put the booking back as
 * Bookings reads it, its order's status and scheduled completion event
 * included.
 *
 * @group wc-bookings-live
 */
class WooBookingsLiveTest extends \WP_UnitTestCase
{
    private const COMPLETE_HOOK = 'wc-booking-complete';

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists('WC_Bookings') || ! class_exists('WC_Product_Booking') || ! function_exists('get_wc_booking')) {
            $this->markTestSkipped('Needs the real WooCommerce Bookings (bin/test-local.sh --live-wc-bookings, WPMCP_WC_BOOKINGS_SRC).');
        }
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        Governance::reset_for_tests();
        \WC_Install::create_roles();
        $GLOBALS['wp_roles'] = null;
        wp_roles();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option('woocommerce_calc_taxes', 'no');
        \WC_Bookings_Install::maybe_install();
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_woo_op_enabled');
        Governance::reset_for_tests();
        wp_set_current_user(0);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    // ------------------------------------------------------------ fixtures

    /** A real bookable product: one-hour fixed blocks, base cost 20, block cost 5, one at a time. */
    private function product(): \WC_Product_Booking
    {
        $product = new \WC_Product_Booking();
        $product->set_name('Kayak hire');
        $product->set_status('publish');
        $product->set_duration_type('fixed');
        $product->set_duration(1);
        $product->set_duration_unit('hour');
        $product->set_cost(20);
        $product->set_block_cost(5);
        $product->set_qty(1);
        $product->set_default_date_availability('available');
        $product->set_min_date_value(0);
        $product->set_min_date_unit('day');
        $product->set_max_date_value(12);
        $product->set_max_date_unit('month');
        $product->save();
        return get_wc_product_booking($product->get_id());
    }

    /** A real booking, in an order holding only it. */
    private function booking(string $status, string $order_status): array
    {
        $product = $this->product();
        $order   = wc_create_order();
        $item    = new \WC_Order_Item_Product();
        $item->set_product($product);
        $item->set_quantity(1);
        $item->set_total('25');
        $order->add_item($item);
        $order->calculate_totals();
        $order->set_status($order_status);
        $order->save();

        $start   = strtotime($this->day(4) . ' 10:00 UTC');
        $booking = new \WC_Booking([
            'product_id'    => $product->get_id(),
            'start'         => $start,
            'end'           => $start + HOUR_IN_SECONDS,
            'cost'          => '25',
            'order_item_id' => $item->get_id(),
        ]);
        $booking->set_order_id($order->get_id());
        $booking->set_status($status);
        $booking->save();

        return [(int) $booking->get_id(), $order->get_id()];
    }

    private function day(int $ahead): string
    {
        return gmdate('Y-m-d', time() + $ahead * DAY_IN_SECONDS);
    }

    private function read(string $op, array $params = []): array
    {
        return (new Woo_Read())->handle(['op' => $op, 'params' => $params]);
    }

    private function write(string $op, array $params, array $extra = []): array
    {
        return (new Woo_Write())->handle(['op' => $op, 'params' => $params] + $extra);
    }

    /** The booking as Bookings reads it, its order's status and its scheduled completion. */
    private function state(int $id): array
    {
        $booking = get_wc_booking($id);
        $this->assertNotFalse($booking);
        $order = $booking->get_order();
        return [
            'row'       => Wc_Booking_Snapshot::row($id),
            'status'    => $booking->get_status(),
            'start'     => $booking->get_start(),
            'end'       => $booking->get_end(),
            'order'     => $order ? $order->get_status() : null,
            'completes' => (bool) wp_next_scheduled(self::COMPLETE_HOOK, [ $id ]),
        ];
    }

    private function rollback(array $out): void
    {
        $this->assertArrayHasKey('operation_id', $out, wp_json_encode($out));
        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored'], wp_json_encode($rolled));
    }

    // ----------------------------------------------------------- the tests

    public function test_the_ops_are_listed_and_read_the_real_plugin(): void
    {
        $product = $this->product();
        $product->set_availability([['type' => 'days', 'bookable' => 'no', 'priority' => 10, 'from' => '6', 'to' => '7']]);
        $product->save();

        $listed = (new Woo_Ops())->handle(['domain' => 'bookings']);
        $this->assertContains('bookings.create', array_column($listed['domains']['bookings'], 'op'));

        $products = $this->read('bookings.products');
        $this->assertSame(200, $products['status'], wp_json_encode($products));
        $row = array_values(array_filter($products['body']['products'], static fn ($p) => $p['id'] === $product->get_id()))[0] ?? null;
        $this->assertNotNull($row);
        $this->assertSame('hour', $row['duration_unit']);
        $this->assertSame(1, $row['duration']);
        $this->assertSame(1, $row['rules']);

        $slots = $this->read('bookings.availability', ['product_id' => $product->get_id(), 'from' => $this->day(1), 'to' => $this->day(3)]);
        $this->assertSame(200, $slots['status'], wp_json_encode($slots));
        $this->assertSame('days', $slots['body']['rules'][0]['type']);
        $this->assertGreaterThan(0, $slots['body']['total'], wp_json_encode($slots['body']));
        $this->assertArrayHasKey('available', $slots['body']['slots'][0]);
    }

    public function test_create_books_and_prices_through_the_plugin_and_rollback_deletes_it(): void
    {
        $product = $this->product();
        $start   = $this->day(6) . ' 09:00';

        $out = $this->write('bookings.create', ['product_id' => $product->get_id(), 'start' => $start], ['confirm' => true]);
        $this->assertSame(201, $out['status'], wp_json_encode($out));
        $id      = $out['body']['id'];
        $booking = get_wc_booking($id);
        $this->assertNotFalse($booking);
        $this->assertSame('confirmed', $booking->get_status());
        $this->assertSame($start . ':00', gmdate('Y-m-d H:i:s', $booking->get_start()));
        $this->assertSame($this->day(6) . ' 10:00:00', gmdate('Y-m-d H:i:s', $booking->get_end()));
        $this->assertEqualsWithDelta(25.0, (float) $booking->get_cost(), 0.001);
        $this->assertTrue((bool) wp_next_scheduled(self::COMPLETE_HOOK, [ $id ]), 'Bookings scheduled its completion');

        $taken = $this->write('bookings.create', ['product_id' => $product->get_id(), 'start' => $start], ['confirm' => true]);
        $this->assertSame('not_bookable', $taken['error']['code'] ?? null, 'Bookings refuses the full block: ' . wp_json_encode($taken));

        $this->rollback($out);
        $this->assertFalse(get_wc_booking($id));
        $this->assertFalse(wp_next_scheduled(self::COMPLETE_HOOK, [ $id ]));
    }

    public function test_a_status_change_moves_the_order_and_rolls_back_with_it(): void
    {
        [$id, $order_id] = $this->booking('unpaid', 'pending');
        $before          = $this->state($id);

        $out = $this->write('bookings.update-status', ['id' => $id, 'status' => 'paid'], ['confirm' => true]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertSame('paid', get_wc_booking($id)->get_status());
        $this->assertSame('processing', wc_get_order($order_id)->get_status(), 'Bookings moved its order');
        $this->assertTrue((bool) wp_next_scheduled(self::COMPLETE_HOOK, [ $id ]));

        $this->rollback($out);
        $this->assertSame($before, $this->state($id));
    }

    public function test_cancel_cancels_the_order_and_rolls_back_the_booking_its_order_and_its_events(): void
    {
        add_filter('wpmcp_woo_op_enabled', static fn ($on, $op) => 'bookings.cancel' === $op ? true : $on, 10, 2);
        [$id, $order_id] = $this->booking('confirmed', 'processing');
        $before          = $this->state($id);
        $this->assertTrue($before['completes']);

        $out = $this->write('bookings.cancel', ['id' => $id], ['confirm' => true]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertFalse($out['recoverable']);
        $this->assertSame('cancelled', get_wc_booking($id)->get_status());
        $this->assertSame('cancelled', wc_get_order($order_id)->get_status());
        $this->assertFalse(wp_next_scheduled(self::COMPLETE_HOOK, [ $id ]));

        $this->rollback($out);
        $this->assertSame($before, $this->state($id));
    }

    public function test_availability_rules_are_saved_by_the_product_and_rolled_back(): void
    {
        $product  = $this->product();
        $existing = [['type' => 'custom', 'bookable' => 'no', 'priority' => 10, 'from' => $this->day(10), 'to' => $this->day(11)]];
        $product->set_availability($existing);
        $product->save();
        $rules = [['type' => 'time', 'bookable' => 'yes', 'priority' => 1, 'from' => '09:00', 'to' => '17:00']];

        $out = $this->write('bookings.set-availability-rules', ['product_id' => $product->get_id(), 'rules' => $rules]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertSame($rules, get_wc_product_booking($product->get_id())->get_availability());

        $this->rollback($out);
        $this->assertSame($existing, get_wc_product_booking($product->get_id())->get_availability());
    }
}
