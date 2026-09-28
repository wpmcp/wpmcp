<?php

namespace WPMCP\Tests\Pro\WooCommerce;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WPMCP\Governance\Governance;
use WPMCP\Pro\Gate;
use WPMCP\Tools\WooCommerce\Catalog\Op_Catalog;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Read;

/**
 * Totals reports (issue #292, third slice) as read ops on the existing
 * woo-read dispatcher: sales totals by period with day, week or month
 * intervals, top sellers, orders by status, customer totals and coupon
 * totals. Each report reads WooCommerce's analytics lookup tables when they
 * are present and in step with the order store for the range, and otherwise
 * falls back to order queries through the order CRUD (HPOS-safe); both
 * sources give the same numbers. Responses carry aggregate counts and
 * amounts only, never a customer's name, email or address.
 */
class WooReportsTest extends \WP_UnitTestCase
{
    private const RANGE = ['period' => 'custom', 'date_from' => '2026-03-01', 'date_to' => '2026-03-31'];

    /** @var int[] every order and refund the fixture made */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce is not active in this environment.');
        }

        Gate::set_pro_for_tests(true);
        Governance::reset_for_tests();

        \WC_Install::create_roles();
        $GLOBALS['wp_roles'] = null;
        wp_roles();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option('woocommerce_calc_taxes', 'no');
        update_option('timezone_string', '');
        update_option('gmt_offset', 0);
        $this->made = [];

        global $wp_rest_server;
        $wp_rest_server = null;
        rest_get_server();
    }

    protected function tearDown(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;

        Governance::reset_for_tests();
        wp_set_current_user(0);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    // ------------------------------------------------------------ fixtures

    private function product(string $name, string $price): int
    {
        $product = new \WC_Product_Simple();
        $product->set_name($name);
        $product->set_regular_price($price);
        return (int) $product->save();
    }

    /**
     * @param array<int, int> $lines product id => quantity
     */
    private function order(array $lines, string $status, string $date, int $user = 0, string $email = '', float $shipping = 0.0, string $coupon = ''): \WC_Order
    {
        $order = wc_create_order(['customer_id' => $user]);
        foreach ($lines as $product => $qty) {
            $order->add_product(wc_get_product($product), $qty);
        }
        $order->set_billing_email('' !== $email ? $email : 'buyer' . count($this->made) . '@example.com');
        $order->set_billing_first_name('Private');
        $order->set_billing_last_name('Person');
        if ($shipping > 0) {
            $item = new \WC_Order_Item_Shipping();
            $item->set_method_title('Flat rate');
            $item->set_method_id('flat_rate');
            $item->set_total((string) $shipping);
            $order->add_item($item);
        }
        $order->calculate_totals(false);
        if ('' !== $coupon) {
            $order->apply_coupon($coupon);
        }
        $order->set_status($status);
        $order->set_date_created($date);
        $order->save();
        $this->made[] = $order->get_id();
        return $order;
    }

    /**
     * March 2026: three counted orders (A and F by one registered customer,
     * B by a guest), a pending and a cancelled order that do not count, an
     * April order outside the range, and a 10.00 refund on B inside it.
     *
     * @return array{mug: int, lamp: int, user: int}
     */
    private function fixture(): array
    {
        $mug  = $this->product('Mug', '10.00');
        $lamp = $this->product('Lamp', '30.00');

        $coupon = new \WC_Coupon();
        $coupon->set_code('save5');
        $coupon->set_discount_type('fixed_cart');
        $coupon->set_amount('5');
        $coupon->save();

        $user = self::factory()->user->create(['role' => 'customer', 'user_email' => 'reg@example.com', 'user_registered' => '2026-03-02 09:00:00']);
        self::factory()->user->create(['role' => 'customer', 'user_registered' => '2026-02-02 09:00:00']);

        $this->order([$mug => 2], 'completed', '2026-03-05 10:00:00', $user, 'reg@example.com', 5.0);
        $this->order([$lamp => 1], 'completed', '2026-03-15 10:00:00', $user, 'reg@example.com', 0.0, 'save5');
        $b = $this->order([$lamp => 1], 'processing', '2026-03-20 10:00:00', 0, 'guest@example.com');
        $this->order([$mug => 1], 'pending', '2026-03-10 10:00:00', 0, 'pending@example.com');
        $this->order([$mug => 1], 'cancelled', '2026-03-11 10:00:00', 0, 'cancel@example.com');
        $this->order([$lamp => 3], 'completed', '2026-04-02 10:00:00', $user, 'reg@example.com');

        $refund = wc_create_refund(['order_id' => $b->get_id(), 'amount' => '10.00', 'reason' => 'Dented']);
        $this->assertInstanceOf(\WC_Order_Refund::class, $refund);
        $refund->set_date_created('2026-03-25 10:00:00');
        $refund->save();
        $this->made[] = $refund->get_id();

        return ['mug' => $mug, 'lamp' => $lamp, 'user' => $user];
    }

    private function read(string $op, array $params = []): array
    {
        return (new Woo_Read())->handle(['op' => $op, 'params' => $params]);
    }

    /** Push every fixture order and refund into the analytics lookup tables. */
    private function sync_analytics(): void
    {
        $stats = 'Automattic\\WooCommerce\\Admin\\API\\Reports\\Orders\\Stats\\DataStore';
        if (! class_exists($stats)) {
            $this->markTestSkipped('This WooCommerce has no analytics data store.');
        }
        foreach ($this->made as $id) {
            $stats::sync_order($id);
            \Automattic\WooCommerce\Admin\API\Reports\Products\DataStore::sync_order_products($id);
            \Automattic\WooCommerce\Admin\API\Reports\Coupons\DataStore::sync_order_coupons($id);
        }
    }

    // ------------------------------------------------------------- catalog

    public function test_the_catalog_carries_read_only_reports_behind_manage_woocommerce(): void
    {
        $ops = Op_Catalog::ops();
        foreach (['reports.sales', 'reports.top-sellers', 'reports.orders', 'reports.customers', 'reports.coupons'] as $op) {
            $this->assertArrayHasKey($op, $ops);
            $this->assertSame('reports', $ops[ $op ]['domain']);
            $this->assertSame('read', $ops[ $op ]['mode'], $op);
            $this->assertSame('GET', $ops[ $op ]['method'], $op);
            $this->assertNull($ops[ $op ]['snapshot'], $op);
            $this->assertSame('manage_woocommerce', $ops[ $op ]['capability'], $op);
        }
    }

    // --------------------------------------------------------------- sales

    public function test_sales_totals_for_a_custom_range_from_order_queries(): void
    {
        $this->fixture();

        $out = $this->read('reports.sales', self::RANGE + ['source' => 'orders']);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $body = $out['body'];
        $this->assertSame('orders', $body['source']);
        $this->assertSame(['from' => '2026-03-01', 'to' => '2026-03-31', 'timezone' => 'UTC'], $body['range']);
        $this->assertSame(get_woocommerce_currency(), $body['currency']);
        $this->assertSame([
            'orders'              => 3,
            'items_sold'          => 4,
            'gross_sales'         => 80.0,
            'net_sales'           => 75.0,
            'shipping'            => 5.0,
            'taxes'               => 0.0,
            'refunds'             => 10.0,
            'average_order_value' => 26.67,
        ], $body['totals']);
        $this->assertArrayNotHasKey('intervals', $body, 'No breakdown unless an interval is asked for');
    }

    public function test_sales_intervals_by_day_week_and_month(): void
    {
        $this->fixture();

        $day = $this->read('reports.sales', self::RANGE + ['interval' => 'day', 'source' => 'orders'])['body']['intervals'];
        $this->assertSame(['2026-03-05', '2026-03-15', '2026-03-20'], array_column($day, 'interval'));
        $this->assertSame([25.0, 25.0, 30.0], array_column($day, 'gross_sales'));
        $this->assertSame([1, 1, 1], array_column($day, 'orders'));

        $week = $this->read('reports.sales', self::RANGE + ['interval' => 'week', 'source' => 'orders'])['body']['intervals'];
        $this->assertSame(['2026-W10', '2026-W11', '2026-W12'], array_column($week, 'interval'));

        $month = $this->read('reports.sales', ['period' => 'custom', 'date_from' => '2026-03-01', 'date_to' => '2026-04-30', 'interval' => 'month', 'source' => 'orders'])['body'];
        $this->assertSame(['2026-03', '2026-04'], array_column($month['intervals'], 'interval'));
        $this->assertSame([3, 1], array_column($month['intervals'], 'orders'));
        $this->assertSame(4, $month['totals']['orders']);
    }

    public function test_preset_periods_cover_today(): void
    {
        $mug   = $this->product('Mug', '10.00');
        $today = current_time('Y-m-d');
        $order = wc_create_order();
        $order->add_product(wc_get_product($mug), 1);
        $order->calculate_totals(false);
        $order->set_status('completed');
        $order->save();

        foreach (['day', 'week', 'month', 'year'] as $period) {
            $out = $this->read('reports.sales', ['period' => $period]);
            $this->assertSame(200, $out['status'], $period . ' ' . wp_json_encode($out));
            $this->assertSame($today, $out['body']['range']['to'], $period);
            $this->assertGreaterThanOrEqual(1, $out['body']['totals']['orders'], $period);
        }
        $this->assertSame($today, $this->read('reports.sales', ['period' => 'day'])['body']['range']['from']);
        $last = $this->read('reports.sales', ['period' => 'last_month'])['body'];
        $this->assertSame(0, $last['totals']['orders']);
        $this->assertLessThan($today, $last['range']['to']);
    }

    public function test_bad_report_params_are_refused(): void
    {
        $cases = [
            ['reports.sales', ['period' => 'fortnight']],
            ['reports.sales', ['period' => 'custom']],
            ['reports.sales', ['period' => 'custom', 'date_from' => '2026-03-31', 'date_to' => '2026-03-01']],
            ['reports.sales', ['period' => 'custom', 'date_from' => 'yesterday', 'date_to' => '2026-03-01']],
            ['reports.sales', ['period' => 'custom', 'date_from' => '2020-01-01', 'date_to' => '2026-03-01']],
            ['reports.sales', ['interval' => 'hour']],
            ['reports.sales', ['source' => 'magic']],
            ['reports.sales', ['customer' => 5]],
            ['reports.top-sellers', ['limit' => 0]],
            ['reports.top-sellers', ['limit' => 500]],
        ];
        foreach ($cases as [$op, $params]) {
            $out = $this->read($op, $params);
            $this->assertSame('invalid_params', $out['error']['code'] ?? null, $op . ' ' . wp_json_encode($params) . ' => ' . wp_json_encode($out));
        }
    }

    // ---------------------------------------------------------- the others

    public function test_top_sellers_orders_customers_and_coupons(): void
    {
        $ids = $this->fixture();

        $top = $this->read('reports.top-sellers', self::RANGE + ['source' => 'orders'])['body'];
        $this->assertSame([
            ['product_id' => $ids['lamp'], 'name' => 'Lamp', 'quantity' => 2, 'net_revenue' => 55.0],
            ['product_id' => $ids['mug'], 'name' => 'Mug', 'quantity' => 2, 'net_revenue' => 20.0],
        ], $top['products']);
        $this->assertCount(1, $this->read('reports.top-sellers', self::RANGE + ['limit' => 1, 'source' => 'orders'])['body']['products']);

        $orders = $this->read('reports.orders', self::RANGE)['body'];
        $this->assertSame(5, $orders['total']);
        $this->assertSame(['cancelled' => 1, 'completed' => 2, 'pending' => 1, 'processing' => 1], $orders['statuses']);

        $customers = $this->read('reports.customers', self::RANGE + ['source' => 'orders']);
        $this->assertSame([
            'customers'            => 2,
            'registered_customers' => 1,
            'guest_customers'      => 1,
            'repeat_customers'     => 1,
            'new_accounts'         => 1,
        ], $customers['body']['totals']);

        $coupons = $this->read('reports.coupons', self::RANGE + ['source' => 'orders'])['body'];
        $this->assertSame(['coupons_used' => 1, 'orders_with_coupons' => 1, 'discount_total' => 5.0], $coupons['totals']);
        $this->assertSame([['code' => 'save5', 'orders' => 1, 'discount' => 5.0]], $coupons['coupons']);

        $json = (string) wp_json_encode([$customers, $top, $orders, $coupons]);
        foreach (['reg@example.com', 'guest@example.com', 'Private', 'Person'] as $personal) {
            $this->assertStringNotContainsString($personal, $json, 'Reports carry aggregate counts only');
        }
    }

    // ------------------------------------------------------------ analytics

    public function test_analytics_tables_give_the_same_numbers_when_in_step(): void
    {
        $this->fixture();
        $this->sync_analytics();

        foreach (['reports.sales' => ['interval' => 'day'], 'reports.top-sellers' => [], 'reports.customers' => [], 'reports.coupons' => []] as $op => $extra) {
            $auto   = $this->read($op, self::RANGE + $extra);
            $orders = $this->read($op, self::RANGE + $extra + ['source' => 'orders']);
            $this->assertSame(200, $auto['status'], $op . ' ' . wp_json_encode($auto));
            $this->assertSame('analytics', $auto['body']['source'], $op . ' ' . wp_json_encode($auto['body']));
            unset($auto['body']['source'], $orders['body']['source'], $auto['body']['source_note'], $orders['body']['source_note']);
            $this->assertSame($orders['body'], $auto['body'], $op . ' gives the same numbers from either source');
        }
    }

    public function test_stale_analytics_fall_back_to_order_queries(): void
    {
        $this->fixture();
        $this->sync_analytics();

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- makes the lookup table fall behind the order store.
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wc_order_stats WHERE order_id = %d", $this->made[0]));

        $out = $this->read('reports.sales', self::RANGE);
        $this->assertSame('orders', $out['body']['source']);
        $this->assertNotEmpty($out['body']['source_note']);
        $this->assertSame(3, $out['body']['totals']['orders']);
    }

    public function test_order_queries_work_on_the_other_order_store_too(): void
    {
        if (! class_exists(OrderUtil::class)) {
            $this->markTestSkipped('This WooCommerce predates the order storage switch.');
        }
        $hpos   = OrderUtil::custom_orders_table_usage_is_enabled();
        $switch = static fn () => $hpos ? 'no' : 'yes';
        add_filter('pre_option_woocommerce_custom_orders_table_enabled', $switch);
        try {
            if ($hpos === OrderUtil::custom_orders_table_usage_is_enabled()) {
                $this->markTestSkipped('The order storage mode could not be switched in this environment.');
            }
            $this->test_sales_totals_for_a_custom_range_from_order_queries();
        } finally {
            remove_filter('pre_option_woocommerce_custom_orders_table_enabled', $switch);
        }
    }

    // ---------------------------------------------------------------- gates

    public function test_reports_need_manage_woocommerce(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        foreach (['reports.sales', 'reports.top-sellers', 'reports.orders', 'reports.customers', 'reports.coupons'] as $op) {
            $out = $this->read($op, self::RANGE);
            $this->assertSame('operation_denied', $out['error']['code'] ?? null, $op);
            $this->assertSame('capability', $out['error']['data']['reason'] ?? null, $op);
        }
    }
}
