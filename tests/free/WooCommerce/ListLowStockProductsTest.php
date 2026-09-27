<?php

namespace WPMCP\Tests\Free\WooCommerce;

use WPMCP\Tools\WooCommerce\List_Low_Stock_Products;

class ListLowStockProductsTest extends \WP_UnitTestCase
{
    use VariableProductFixture;

    protected function setUp(): void
    {
        parent::setUp();
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }
    }

    private function simple(string $name, ?int $stock, bool $manage = true, ?string $status = null): int
    {
        $product = new \WC_Product_Simple();
        $product->set_name($name);
        $product->set_regular_price('5.00');
        $product->set_manage_stock($manage);
        if ($manage) {
            $product->set_stock_quantity($stock);
        }
        if (null !== $status) {
            $product->set_stock_status($status);
        }
        return $product->save();
    }

    /** @return int[] */
    private function ids(array $out): array
    {
        return array_column($out['products'], 'id');
    }

    public function test_threshold_defaults_to_the_store_low_stock_setting(): void
    {
        update_option('woocommerce_notify_low_stock_amount', 3);
        $low  = $this->simple('Low', 3);
        $fine = $this->simple('Fine', 4);

        $out = (new List_Low_Stock_Products())->handle([]);

        $this->assertSame(3, $out['threshold']);
        $this->assertContains($low, $this->ids($out));
        $this->assertNotContains($fine, $this->ids($out));
    }

    public function test_includes_variable_parent_managing_stock_at_parent_level(): void
    {
        $attribute = new \WC_Product_Attribute();
        $attribute->set_name('size');
        $attribute->set_options(['small']);
        $attribute->set_variation(true);

        $parent = new \WC_Product_Variable();
        $parent->set_name('Parent-managed Shirt');
        $parent->set_attributes([$attribute]);
        $parent->set_manage_stock(true);
        $parent->set_stock_quantity(1);
        $parent_id = $parent->save();

        $variation = new \WC_Product_Variation();
        $variation->set_parent_id($parent_id);
        $variation->set_attributes(['size' => 'small']);
        $variation->set_regular_price('10.00');
        $variation->set_manage_stock(false);
        $variation_id = $variation->save();

        $this->assertSame('parent', wc_get_product($variation_id)->get_manage_stock());

        $out = (new List_Low_Stock_Products())->handle(['threshold' => 2]);

        $this->assertContains($parent_id, $this->ids($out));
        $by_id = array_column($out['products'], null, 'id');
        $this->assertSame('variable', $by_id[ $parent_id ]['type']);
        $this->assertSame(1, $by_id[ $parent_id ]['stock_quantity']);
    }

    public function test_includes_variations_managing_their_own_stock(): void
    {
        $ids = $this->variable_product(); // small: 5, large: 8

        $out = (new List_Low_Stock_Products())->handle(['threshold' => 5]);

        $this->assertContains($ids['small'], $this->ids($out));
        $this->assertNotContains($ids['large'], $this->ids($out));
        $this->assertNotContains($ids['parent'], $this->ids($out));

        $row = array_column($out['products'], null, 'id')[ $ids['small'] ];
        $this->assertSame($ids['parent'], $row['parent_id']);
        $this->assertSame(['size' => 'small'], $row['attributes']);
    }

    public function test_includes_unmanaged_products_marked_out_of_stock(): void
    {
        $unmanaged_out = $this->simple('Sold out', null, false, 'outofstock');
        $unmanaged_in  = $this->simple('Available', null, false, 'instock');

        $out = (new List_Low_Stock_Products())->handle(['threshold' => 0]);

        $this->assertContains($unmanaged_out, $this->ids($out));
        $this->assertNotContains($unmanaged_in, $this->ids($out));
    }

    public function test_total_and_paging_reflect_matches_only(): void
    {
        $low = [];
        for ($i = 0; $i < 3; $i++) {
            $low[] = $this->simple('Low ' . $i, 1);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->simple('Fine ' . $i, 50);
        }
        $tool = new List_Low_Stock_Products();

        $first = $tool->handle(['threshold' => 2, 'per_page' => 2, 'page' => 1]);
        $this->assertSame(3, $first['total']);
        $this->assertSame(2, $first['per_page']);
        $this->assertTrue($first['has_more']);
        $this->assertSame([$low[0], $low[1]], $this->ids($first));

        $second = $tool->handle(['threshold' => 2, 'per_page' => 2, 'page' => 2]);
        $this->assertSame(3, $second['total']);
        $this->assertFalse($second['has_more']);
        $this->assertSame([$low[2]], $this->ids($second));
    }
}
