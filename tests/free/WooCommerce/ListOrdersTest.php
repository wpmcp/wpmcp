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
}
