<?php

namespace WPMCP\Tests\Free\WooCommerce;

use WPMCP\Tools\WooCommerce\List_Orders;

class ListOrdersTest extends \WP_UnitTestCase
{
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            $order = wc_get_order($id);
            if ($order) {
                $order->delete(true);
            }
        }
        $this->created = [];
        parent::tearDown();
    }

    private function order(string $status = 'processing'): int
    {
        $order = wc_create_order();
        $order->set_status($status);
        $id = $order->save();
        $this->created[] = $id;
        return $id;
    }

    public function test_lists_orders_as_summary_rows(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }

        $id  = $this->order('processing');
        $out = (new List_Orders())->handle([]);

        $this->assertArrayHasKey('orders', $out);
        $this->assertArrayHasKey('total', $out);

        $ids = array_column($out['orders'], 'id');
        $this->assertContains($id, $ids);

        $row = $out['orders'][array_search($id, $ids, true)];
        $this->assertSame('processing', $row['status']);
        $this->assertArrayHasKey('total', $row);
        $this->assertArrayHasKey('currency', $row);
        $this->assertArrayHasKey('date_created', $row);
    }

    public function test_status_filter(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }

        $processing = $this->order('processing');
        $this->order('completed');

        $out = (new List_Orders())->handle(['status' => 'processing']);
        $ids = array_column($out['orders'], 'id');

        $this->assertContains($processing, $ids);
        foreach ($out['orders'] as $row) {
            $this->assertSame('processing', $row['status']);
        }
    }

    /** #942: a refund must not come back as a row, nor be counted in total. */
    public function test_store_with_a_refund_lists_only_orders(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }

        $product = new \WC_Product_Simple();
        $product->set_name('Refund Box');
        $product->set_regular_price('10.00');
        $product_id = $product->save();

        $order = wc_create_order();
        $order->add_product(wc_get_product($product_id), 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order_id        = $order->save();
        $this->created[] = $order_id;

        $refund = wc_create_refund([ 'order_id' => $order_id, 'amount' => '4.00' ]);
        $this->assertInstanceOf(\WC_Order_Refund::class, $refund);

        $out = (new List_Orders())->handle([ 'per_page' => 100 ]);

        $ids = array_column($out['orders'], 'id');
        $this->assertContains($order_id, $ids);
        $this->assertNotContains($refund->get_id(), $ids);

        $order_count = (int) wc_get_orders([ 'type' => 'shop_order', 'limit' => 1, 'paginate' => true, 'return' => 'ids' ])->total;
        $this->assertSame($order_count, $out['total']);

        wp_delete_post($product_id, true);
    }
}
