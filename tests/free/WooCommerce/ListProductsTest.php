<?php

namespace WPMCP\Tests\Free\WooCommerce;

use WPMCP\Tools\WooCommerce\List_Products;

class ListProductsTest extends \WP_UnitTestCase
{
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            wp_delete_post($id, true);
        }
        $this->created = [];
        parent::tearDown();
    }

    private function product(string $name, string $price = '9.99'): int
    {
        $product = new \WC_Product_Simple();
        $product->set_name($name);
        $product->set_regular_price($price);
        $id = $product->save();
        $this->created[] = $id;
        return $id;
    }

    public function test_lists_products_as_summary_rows(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }

        $id  = $this->product('Handloom Scarf', '12.50');
        $out = (new List_Products())->handle([]);

        $this->assertArrayHasKey('products', $out);
        $this->assertArrayHasKey('total', $out);
        $this->assertArrayHasKey('page', $out);

        $ids = array_column($out['products'], 'id');
        $this->assertContains($id, $ids);

        $row = $out['products'][array_search($id, $ids, true)];
        $this->assertSame('Handloom Scarf', $row['name']);
        $this->assertSame('12.50', $row['regular_price']);
        $this->assertArrayHasKey('sku', $row);
        $this->assertArrayHasKey('stock_status', $row);
    }

    public function test_search_filters_products(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }

        $match = $this->product('Ceramic Teapot');
        $this->product('Wool Blanket');

        $out = (new List_Products())->handle(['search' => 'Teapot']);
        $ids = array_column($out['products'], 'id');

        $this->assertContains($match, $ids);
        $this->assertCount(1, $out['products']);
    }
}
