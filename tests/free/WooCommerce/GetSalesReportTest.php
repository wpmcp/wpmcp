<?php

namespace WPMCP\Tests\Free\WooCommerce;

use WPMCP\Tools\WooCommerce\Get_Sales_Report;

class GetSalesReportTest extends \WP_UnitTestCase
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

    private function completed_order(int $product_id, int $qty, string $price): int
    {
        $order = wc_create_order();
        $order->add_product(wc_get_product($product_id), $qty);
        $order->calculate_totals();
        $order->set_status('completed');
        $id = $order->save();
        $this->created[] = $id;
        return $id;
    }

    private function product(string $price): int
    {
        $product = new \WC_Product_Simple();
        $product->set_name('Spice Box');
        $product->set_regular_price($price);
        $id = $product->save();
        $this->created[] = $id;
        return $id;
    }

    public function test_report_shape_over_a_date_range(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }

        $product_id = $this->product('10.00');
        $this->completed_order($product_id, 2, '10.00');

        $out = (new Get_Sales_Report())->handle([
            'date_from' => gmdate('Y-m-d', strtotime('-1 day')),
            'date_to'   => gmdate('Y-m-d', strtotime('+1 day')),
        ]);

        $this->assertArrayHasKey('date_from', $out);
        $this->assertArrayHasKey('date_to', $out);
        $this->assertArrayHasKey('order_count', $out);
        $this->assertArrayHasKey('gross_sales', $out);
        $this->assertArrayHasKey('items_sold', $out);
        $this->assertArrayHasKey('top_products', $out);

        $this->assertGreaterThanOrEqual(1, $out['order_count']);
        $this->assertEqualsWithDelta(20.00, (float) $out['gross_sales'], 0.001);
        $this->assertSame(2, $out['items_sold']);

        $this->assertNotEmpty($out['top_products']);
        $top = $out['top_products'][0];
        $this->assertArrayHasKey('product_id', $top);
        $this->assertArrayHasKey('quantity', $top);
        $this->assertArrayHasKey('name', $top);
    }

    public function test_empty_range_returns_zeroed_report(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }

        $out = (new Get_Sales_Report())->handle([
            'date_from' => '2000-01-01',
            'date_to'   => '2000-01-02',
        ]);

        $this->assertSame(0, $out['order_count']);
        $this->assertEqualsWithDelta(0.0, (float) $out['gross_sales'], 0.001);
        $this->assertSame([], $out['top_products']);
    }
}
