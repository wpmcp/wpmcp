<?php

namespace WPMCP\Tests\Pro\WooCommerce;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WPMCP\Governance\Governance;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tests\Free\WooCommerce\VariableProductFixture;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\WooCommerce\Catalog\Op_Catalog;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Write;

/**
 * Full order create and edit (issue #292, first slice) as ops on the
 * existing woo-write dispatcher. Orders are written through WooCommerce's own
 * CRUD, so HPOS and the legacy post store both work; every edit takes a full
 * order snapshot (order props, addresses, totals, every item and its meta) so
 * rollback restores it exactly; a create records a creation row whose
 * rollback moves the order to the trash. No gateway is ever charged and no
 * payment secret is ever returned.
 */
class WooOrderWritesTest extends \WP_UnitTestCase
{
    use VariableProductFixture;

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
        update_option('woocommerce_manage_stock', 'yes');
        update_option('woocommerce_calc_taxes', 'no');

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

    private function product(string $price = '10.00', ?int $stock = null, string $name = 'Mug'): int
    {
        $product = new \WC_Product_Simple();
        $product->set_name($name);
        $product->set_regular_price($price);
        if (null !== $stock) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity($stock);
        }
        return (int) $product->save();
    }

    private function coupon(string $code, string $amount = '5'): int
    {
        $coupon = new \WC_Coupon();
        $coupon->set_code($code);
        $coupon->set_discount_type('fixed_cart');
        $coupon->set_amount($amount);
        return (int) $coupon->save();
    }

    /** An order with two products, a shipping line and a fee, as a store would hold it. */
    private function existing_order(): \WC_Order
    {
        $order = wc_create_order();
        $order->add_product(wc_get_product($this->product('10.00', null, 'Mug')), 2);
        $order->add_product(wc_get_product($this->product('4.00', null, 'Coaster')), 1);
        $order->set_address(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'city' => 'London', 'email' => 'ada@example.com'], 'billing');
        $order->set_address(['first_name' => 'Ada', 'city' => 'London'], 'shipping');

        $shipping = new \WC_Order_Item_Shipping();
        $shipping->set_method_title('Flat rate');
        $shipping->set_method_id('flat_rate');
        $shipping->set_total('5.00');
        $order->add_item($shipping);

        $fee = new \WC_Order_Item_Fee();
        $fee->set_name('Gift wrap');
        $fee->set_total('2.00');
        $fee->set_tax_status('none');
        $order->add_item($fee);

        $order->set_customer_note('Leave at the door');
        $order->set_payment_method('bacs');
        $order->set_payment_method_title('Direct bank transfer');
        $order->calculate_totals();
        $order->save();

        // Item meta a store extension would have written, to prove item
        // meta round-trips through the snapshot.
        foreach ($order->get_items() as $item) {
            wc_add_order_item_meta($item->get_id(), '_custom_engraving', 'AL');
            break;
        }

        return wc_get_order($order->get_id());
    }

    private function write(string $op, array $params, array $extra = []): array
    {
        return (new Woo_Write())->handle(['op' => $op, 'params' => $params] + $extra);
    }

    /**
     * The whole observable state of an order, read back through a fresh
     * WC_Order (so stale caches would show) plus the raw item and item meta
     * rows, for exact before/after comparison.
     */
    private function state(int $order_id): array
    {
        global $wpdb;

        $order = wc_get_order($order_id);
        $this->assertInstanceOf(\WC_Order::class, $order);

        $items = [];
        foreach ($order->get_items(['line_item', 'shipping', 'fee', 'coupon', 'tax']) as $item_id => $item) {
            $items[ $item_id ] = [
                'type'     => $item->get_type(),
                'name'     => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'total'    => method_exists($item, 'get_total') ? (string) $item->get_total() : null,
            ];
        }
        ksort($items);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test oracle reads the raw item meta rows.
        $meta = $wpdb->get_results($wpdb->prepare(
            "SELECT m.meta_id, m.order_item_id, m.meta_key, m.meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta m
             JOIN {$wpdb->prefix}woocommerce_order_items i ON i.order_item_id = m.order_item_id
             WHERE i.order_id = %d ORDER BY m.meta_id",
            $order_id
        ), ARRAY_A);

        return [
            'status'               => $order->get_status(),
            'customer_id'          => $order->get_customer_id(),
            'billing'              => $order->get_address('billing'),
            'shipping'             => $order->get_address('shipping'),
            'customer_note'        => $order->get_customer_note(),
            'payment_method'       => $order->get_payment_method(),
            'payment_method_title' => $order->get_payment_method_title(),
            'discount_total'       => $order->get_discount_total(),
            'shipping_total'       => $order->get_shipping_total(),
            'cart_tax'             => $order->get_cart_tax(),
            'total_tax'            => $order->get_total_tax(),
            'total'                => $order->get_total(),
            'items'                => $items,
            'item_meta'            => $meta,
        ];
    }

    private function order_count(): int
    {
        return count(wc_get_orders(['limit' => -1, 'return' => 'ids', 'status' => array_merge(array_keys(wc_get_order_statuses()), ['trash'])]));
    }

    private function snapshot_count(): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- counts ledger rows to prove a refusal wrote nothing.
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpmcp_snapshots");
    }

    // ------------------------------------------------------------- catalog

    public function test_the_catalog_carries_order_create_and_edit_on_the_existing_dispatcher(): void
    {
        $ops = Op_Catalog::ops();

        $this->assertArrayHasKey('orders.create', $ops);
        $this->assertArrayHasKey('orders.update', $ops);

        $this->assertSame('write', $ops['orders.create']['mode']);
        $this->assertSame('POST', $ops['orders.create']['method']);
        $this->assertSame('wc_order_create', $ops['orders.create']['snapshot']['type'] ?? null);
        $this->assertTrue($ops['orders.create']['recoverable']);

        $this->assertSame('write', $ops['orders.update']['mode']);
        $this->assertSame('wc_order_full', $ops['orders.update']['snapshot']['type'] ?? null);
        $this->assertTrue($ops['orders.update']['recoverable']);

        foreach (['orders.create', 'orders.update'] as $op) {
            $this->assertSame('orders', $ops[ $op ]['domain']);
            $this->assertSame('manage_woocommerce', $ops[ $op ]['capability']);
        }
    }

    // -------------------------------------------------------------- create

    public function test_orders_create_builds_a_full_order_through_the_crud(): void
    {
        $mug      = $this->product('10.00');
        $shirt    = $this->variable_product();
        $customer = self::factory()->user->create(['role' => 'customer']);
        $this->coupon('save5', '5');

        $out = $this->write('orders.create', [
            'customer_id'          => $customer,
            'line_items'           => [
                ['product_id' => $mug, 'quantity' => 2],
                ['product_id' => $shirt['parent'], 'variation_id' => $shirt['large'], 'quantity' => 1],
            ],
            'billing'              => ['first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'grace@example.com', 'city' => 'Arlington', 'country' => 'US'],
            'shipping'             => ['first_name' => 'Grace', 'city' => 'Arlington', 'country' => 'US'],
            'shipping_lines'       => [['method_id' => 'flat_rate', 'method_title' => 'Flat rate', 'total' => '7.50']],
            'fee_lines'            => [['name' => 'Handling', 'total' => '1.50']],
            'coupon_codes'         => ['save5'],
            'payment_method'       => 'bacs',
            'payment_method_title' => 'Direct bank transfer',
            'customer_note'        => 'Please gift wrap',
        ]);

        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertSame(201, $out['status']);
        $this->assertTrue($out['applied']);
        $this->assertTrue($out['recoverable']);
        $this->assertArrayHasKey('operation_id', $out);

        $id    = (int) $out['body']['id'];
        $order = wc_get_order($id);
        $this->assertInstanceOf(\WC_Order::class, $order);
        $this->assertSame('pending', $order->get_status());
        $this->assertSame($customer, $order->get_customer_id());
        $this->assertSame('Arlington', $order->get_billing_city());
        $this->assertSame('grace@example.com', $order->get_billing_email());
        $this->assertSame('Please gift wrap', $order->get_customer_note());
        $this->assertSame('Direct bank transfer', $order->get_payment_method_title());
        $this->assertSame(['save5'], $order->get_coupon_codes());

        $variations = [];
        foreach ($order->get_items() as $item) {
            $variations[ $item->get_product_id() ] = [$item->get_variation_id(), $item->get_quantity()];
        }
        $this->assertSame([0, 2], $variations[ $mug ]);
        $this->assertSame([$shirt['large'], 1], $variations[ $shirt['parent'] ]);

        // 2 x 10 + 20 = 40 of products, 5 off, 7.50 shipping, 1.50 fee.
        $this->assertEqualsWithDelta(44.0, (float) $order->get_total(), 0.001);
        $this->assertEqualsWithDelta(44.0, (float) $out['body']['totals']['total'], 0.001);
        $this->assertCount(2, $out['body']['line_items']);
        $this->assertCount(1, $out['body']['shipping_lines']);
        $this->assertCount(1, $out['body']['fee_lines']);
    }

    public function test_orders_create_never_charges_a_gateway_and_refuses_payment_params(): void
    {
        $mug = $this->product();

        $out = $this->write('orders.create', [
            'line_items'           => [['product_id' => $mug, 'quantity' => 1]],
            'payment_method'       => 'bacs',
            'payment_method_title' => 'Direct bank transfer',
            'status'               => 'processing',
        ]);
        $this->assertSame(201, $out['status'], wp_json_encode($out));
        $order = wc_get_order((int) $out['body']['id']);
        $this->assertNull($order->get_date_paid(), 'Creating an order must never run payment_complete');
        $this->assertSame('', $order->get_transaction_id());

        foreach (['set_paid' => true, 'transaction_id' => 'ch_123', 'meta_data' => [['key' => 'x', 'value' => 'y']]] as $key => $value) {
            $before = $this->order_count();
            $out    = $this->write('orders.create', [
                'line_items' => [['product_id' => $mug, 'quantity' => 1]],
                $key         => $value,
            ]);
            $this->assertSame('forbidden_param', $out['error']['code'] ?? null, "{$key} must be refused");
            $this->assertSame($before, $this->order_count(), "A refused {$key} must create nothing");
        }
    }

    public function test_orders_create_validates_everything_before_writing_anything(): void
    {
        $mug   = $this->product();
        $shirt = $this->variable_product();

        $cases = [
            'no line items'         => [],
            'unknown product'       => ['line_items' => [['product_id' => 999999, 'quantity' => 1]]],
            'variable w/o variation' => ['line_items' => [['product_id' => $shirt['parent'], 'quantity' => 1]]],
            'foreign variation'     => ['line_items' => [['product_id' => $mug, 'variation_id' => $shirt['small'], 'quantity' => 1]]],
            'bad quantity'          => ['line_items' => [['product_id' => $mug, 'quantity' => 0]]],
            'unknown coupon'        => ['line_items' => [['product_id' => $mug, 'quantity' => 1]], 'coupon_codes' => ['nope-not-a-coupon']],
            'unknown status'        => ['line_items' => [['product_id' => $mug, 'quantity' => 1]], 'status' => 'shipped-to-mars'],
            'unknown customer'      => ['line_items' => [['product_id' => $mug, 'quantity' => 1]], 'customer_id' => 999999],
            'unknown param'         => ['line_items' => [['product_id' => $mug, 'quantity' => 1]], 'currency_symbol' => 'X'],
            'bad address key'       => ['line_items' => [['product_id' => $mug, 'quantity' => 1]], 'billing' => ['password' => 'x']],
        ];

        foreach ($cases as $label => $params) {
            $orders    = $this->order_count();
            $snapshots = $this->snapshot_count();
            $out       = $this->write('orders.create', $params);

            $this->assertArrayHasKey('error', $out, "{$label} must be refused");
            $this->assertSame($orders, $this->order_count(), "{$label} must create no order");
            $this->assertSame($snapshots, $this->snapshot_count(), "{$label} must write no ledger row");
        }
    }

    public function test_a_coupon_the_order_cannot_take_leaves_no_half_built_order(): void
    {
        $mug    = $this->product('10.00');
        $coupon = new \WC_Coupon();
        $coupon->set_code('bigspend');
        $coupon->set_discount_type('fixed_cart');
        $coupon->set_amount('5');
        $coupon->set_minimum_amount('500');
        $coupon->save();

        $orders = $this->order_count();
        $out    = $this->write('orders.create', [
            'line_items'   => [['product_id' => $mug, 'quantity' => 1]],
            'coupon_codes' => ['bigspend'],
        ]);

        $this->assertSame('coupon_rejected', $out['error']['code'] ?? null, wp_json_encode($out));
        $this->assertSame($orders, $this->order_count());
    }

    public function test_rolling_back_a_create_trashes_the_order_and_puts_stock_back(): void
    {
        $mug = $this->product('10.00', 5);

        $out = $this->write('orders.create', [
            'line_items' => [['product_id' => $mug, 'quantity' => 2]],
            'status'     => 'processing',
        ]);
        $this->assertSame(201, $out['status'], wp_json_encode($out));
        $id = (int) $out['body']['id'];
        $this->assertSame(3, wc_get_product($mug)->get_stock_quantity(), 'A processing order reduces stock, as the store would');

        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored']);

        $order = wc_get_order($id);
        $this->assertInstanceOf(\WC_Order::class, $order, 'The order is trashed, never hard-deleted');
        $this->assertSame('trash', $order->get_status());
        $this->assertSame(5, wc_get_product($mug)->get_stock_quantity(), 'Rollback returns the stock the order took');
    }

    // ---------------------------------------------------------------- edit

    public function test_orders_update_edits_items_addresses_lines_and_note_and_recalculates(): void
    {
        $order  = $this->existing_order();
        $id     = $order->get_id();
        $lines  = array_values($order->get_items());
        $mug    = $lines[0];
        $coast  = $lines[1];
        $ship   = array_values($order->get_items('shipping'))[0];
        $fee    = array_values($order->get_items('fee'))[0];
        $plate  = $this->product('30.00', null, 'Plate');

        $out = $this->write('orders.update', [
            'id'             => $id,
            'line_items'     => [
                ['id' => $mug->get_id(), 'quantity' => 3],
                ['id' => $coast->get_id(), 'remove' => true],
                ['product_id' => $plate, 'quantity' => 1],
            ],
            'shipping_lines' => [['id' => $ship->get_id(), 'total' => '9.00']],
            'fee_lines'      => [['id' => $fee->get_id(), 'remove' => true], ['name' => 'Rush', 'total' => '3.00']],
            'billing'        => ['city' => 'Paris'],
            'customer_note'  => 'Ring twice',
        ]);

        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertSame(200, $out['status']);
        $this->assertTrue($out['recoverable']);
        $this->assertArrayHasKey('operation_id', $out);

        $after = wc_get_order($id);
        $qty   = [];
        foreach ($after->get_items() as $item) {
            $qty[ $item->get_name() ] = $item->get_quantity();
        }
        $this->assertSame(['Mug' => 3, 'Plate' => 1], $qty);
        $this->assertSame('Paris', $after->get_billing_city());
        $this->assertSame('Lovelace', $after->get_billing_last_name(), 'A partial address edit keeps the other fields');
        $this->assertSame('Ring twice', $after->get_customer_note());
        $this->assertSame(['Rush'], array_values(array_map(static fn ($f) => $f->get_name(), $after->get_items('fee'))));
        // 3 x 10 + 30 = 60, 9 shipping, 3 fee.
        $this->assertEqualsWithDelta(72.0, (float) $after->get_total(), 0.001);
    }

    public function test_rolling_back_an_edit_restores_the_order_exactly(): void
    {
        $order  = $this->existing_order();
        $id     = $order->get_id();
        $before = $this->state($id);
        $lines  = array_values($order->get_items());

        $out = $this->write('orders.update', [
            'id'             => $id,
            'line_items'     => [
                ['id' => $lines[0]->get_id(), 'quantity' => 7],
                ['id' => $lines[1]->get_id(), 'remove' => true],
                ['product_id' => $this->product('1.00', null, 'Spoon'), 'quantity' => 4],
            ],
            'shipping_lines' => [['id' => array_values($order->get_items('shipping'))[0]->get_id(), 'remove' => true]],
            'fee_lines'      => [['name' => 'Extra', 'total' => '8.00']],
            'billing'        => ['city' => 'Rome', 'email' => 'other@example.com'],
            'shipping'       => ['city' => 'Rome'],
            'customer_note'  => 'changed',
            'payment_method_title' => 'Cash',
        ]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertNotEquals($before, $this->state($id));

        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored']);

        $this->assertSame($before, $this->state($id));
    }

    public function test_rolling_back_an_edit_restores_exactly_on_the_legacy_post_store_too(): void
    {
        if (! class_exists(OrderUtil::class)) {
            $this->markTestSkipped('This WooCommerce predates the order storage switch.');
        }
        $hpos = OrderUtil::custom_orders_table_usage_is_enabled();
        update_option('woocommerce_custom_orders_table_enabled', $hpos ? 'no' : 'yes');
        if ($hpos === OrderUtil::custom_orders_table_usage_is_enabled()) {
            $this->markTestSkipped('The order storage mode could not be switched in this environment.');
        }

        try {
            $this->test_rolling_back_an_edit_restores_the_order_exactly();
        } finally {
            update_option('woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no');
        }
    }

    public function test_orders_update_refuses_status_meta_and_unknown_items_and_writes_nothing(): void
    {
        $order  = $this->existing_order();
        $id     = $order->get_id();
        $before = $this->state($id);
        $other  = $this->existing_order();
        $foreign_item = array_values($other->get_items())[0]->get_id();

        $cases = [
            'status'         => [['status' => 'completed'], 'forbidden_param'],
            'meta_data'      => [['meta_data' => [['key' => 'k', 'value' => 'v']]], 'forbidden_param'],
            'transaction_id' => [['transaction_id' => 'ch_1'], 'forbidden_param'],
            'foreign item'   => [['line_items' => [['id' => $foreign_item, 'quantity' => 2]]], 'invalid_params'],
            'wrong type'     => [['line_items' => [['id' => array_values($order->get_items('shipping'))[0]->get_id(), 'quantity' => 2]]], 'invalid_params'],
            'unknown param'  => [['currency' => 'EUR'], 'invalid_params'],
            'no changes'     => [[], 'invalid_params'],
        ];

        foreach ($cases as $label => [$params, $code]) {
            $snapshots = $this->snapshot_count();
            $out       = $this->write('orders.update', ['id' => $id] + $params);
            $this->assertSame($code, $out['error']['code'] ?? null, "{$label}: " . wp_json_encode($out));
            $this->assertSame($snapshots, $this->snapshot_count(), "{$label} must write no snapshot");
        }
        $this->assertSame($before, $this->state($id));
    }

    public function test_orders_update_refuses_a_missing_order_or_a_refund(): void
    {
        $order  = $this->existing_order();
        $refund = wc_create_refund(['order_id' => $order->get_id(), 'amount' => '1.00']);
        $this->assertInstanceOf(\WC_Order_Refund::class, $refund);

        foreach ([999999, $refund->get_id()] as $id) {
            $out = $this->write('orders.update', ['id' => $id, 'customer_note' => 'x']);
            $this->assertSame('unknown_order', $out['error']['code'] ?? null, wp_json_encode($out));
        }
    }

    // -------------------------------------------------------------- safety

    public function test_responses_never_carry_payment_secrets_or_order_meta(): void
    {
        $order = $this->existing_order();
        $order->set_transaction_id('ch_live_secret_txn');
        $order->update_meta_data('_stripe_customer_id', 'cus_secret_value');
        $order->update_meta_data('_payment_tokens', 'tok_secret_value');
        $order->save();

        $out  = $this->write('orders.update', ['id' => $order->get_id(), 'customer_note' => 'safe']);
        $json = (string) wp_json_encode($out);

        $this->assertSame(200, $out['status'], $json);
        $this->assertStringNotContainsString('ch_live_secret_txn', $json);
        $this->assertStringNotContainsString('cus_secret_value', $json);
        $this->assertStringNotContainsString('tok_secret_value', $json);
        $this->assertArrayNotHasKey('meta_data', $out['body']);
        $this->assertArrayNotHasKey('transaction_id', $out['body']);
    }

    public function test_order_writes_need_manage_woocommerce(): void
    {
        $order = $this->existing_order();
        $role  = add_role('order_clerk', 'Order clerk', ['read' => true, 'edit_shop_orders' => true]);
        $this->assertNotNull($role);
        wp_set_current_user(self::factory()->user->create(['role' => 'order_clerk']));

        $create = $this->write('orders.create', ['line_items' => [['product_id' => $this->product(), 'quantity' => 1]]]);
        $update = $this->write('orders.update', ['id' => $order->get_id(), 'customer_note' => 'x']);

        remove_role('order_clerk');
        foreach ([$create, $update] as $out) {
            $this->assertSame('operation_denied', $out['error']['code'] ?? null);
            $this->assertSame('capability', $out['error']['data']['reason'] ?? null);
        }
    }

    public function test_an_order_snapshot_is_restorable_only_by_a_store_manager(): void
    {
        $order  = $this->existing_order();
        $before = $this->state($order->get_id());
        $out    = $this->write('orders.update', ['id' => $order->get_id(), 'customer_note' => 'edited']);
        $this->assertSame(200, $out['status']);

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $refused = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertFalse($refused['restored']);
        $this->assertSame('edited', wc_get_order($order->get_id())->get_customer_note());

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertSame($before, $this->state($order->get_id()));
    }

    public function test_a_session_that_created_then_edited_an_order_rolls_back_to_the_trash(): void
    {
        $session = wp_generate_uuid4();
        $mug     = $this->product();

        $made = $this->write('orders.create', ['line_items' => [['product_id' => $mug, 'quantity' => 1]]], ['session_id' => $session]);
        $this->assertSame(201, $made['status'], wp_json_encode($made));
        $id = (int) $made['body']['id'];

        $edit = $this->write('orders.update', ['id' => $id, 'line_items' => [['product_id' => $mug, 'quantity' => 2]]], ['session_id' => $session]);
        $this->assertSame(200, $edit['status'], wp_json_encode($edit));

        (new Rollback_Session())->handle(['session_id' => $session]);

        $this->assertSame('trash', wc_get_order($id)->get_status());
    }

    public function test_order_writes_batch_under_one_session(): void
    {
        $order = $this->existing_order();
        $out   = (new Woo_Write())->handle([
            'batch' => [
                ['op' => 'orders.create', 'params' => ['line_items' => [['product_id' => $this->product(), 'quantity' => 1]]]],
                ['op' => 'orders.update', 'params' => ['id' => $order->get_id(), 'customer_note' => 'batched']],
            ],
        ]);

        $this->assertSame(2, $out['applied'], wp_json_encode($out));
        $this->assertSame('batched', wc_get_order($order->get_id())->get_customer_note());
    }
}
