<?php

namespace WPMCP\Tests\Free\WooCommerce;

use WPMCP\Tools\WooCommerce\List_Variations;

class ListVariationsTest extends \WP_UnitTestCase
{
    use VariableProductFixture;

    protected function setUp(): void
    {
        parent::setUp();
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }
    }

    public function test_lists_variations_of_a_variable_product_as_summary_rows(): void
    {
        $ids = $this->variable_product();
        $out = (new List_Variations())->handle(['product_id' => $ids['parent']]);

        $this->assertSame($ids['parent'], $out['product_id']);
        $this->assertSame(2, $out['total']);
        $this->assertSame(1, $out['page']);
        $this->assertCount(2, $out['variations']);

        $by_id = array_column($out['variations'], null, 'id');
        $this->assertArrayHasKey($ids['small'], $by_id);
        $this->assertArrayHasKey($ids['large'], $by_id);

        $small = $by_id[ $ids['small'] ];
        $this->assertSame($ids['parent'], $small['parent_id']);
        $this->assertSame(['size' => 'small'], $small['attributes']);
        $this->assertSame('10.00', $small['regular_price']);
        $this->assertSame('10.00', $small['price']);
        $this->assertTrue($small['manage_stock']);
        $this->assertSame(5, $small['stock_quantity']);
        $this->assertSame('instock', $small['stock_status']);
    }

    public function test_pages_through_variations_in_id_order(): void
    {
        $ids  = $this->variable_product();
        $tool = new List_Variations();

        $first = $tool->handle(['product_id' => $ids['parent'], 'per_page' => 1, 'page' => 1]);
        $this->assertSame(2, $first['total']);
        $this->assertCount(1, $first['variations']);
        $this->assertSame($ids['small'], $first['variations'][0]['id']);

        $second = $tool->handle(['product_id' => $ids['parent'], 'per_page' => 1, 'page' => 2]);
        $this->assertSame(2, $second['page']);
        $this->assertCount(1, $second['variations']);
        $this->assertSame($ids['large'], $second['variations'][0]['id']);

        $third = $tool->handle(['product_id' => $ids['parent'], 'per_page' => 1, 'page' => 3]);
        $this->assertSame([], $third['variations']);
    }

    public function test_rejects_a_non_variable_product(): void
    {
        $simple = new \WC_Product_Simple();
        $simple->set_name('Plain Tee');
        $simple->set_regular_price('5.00');
        $id = $simple->save();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a variable product');
        (new List_Variations())->handle(['product_id' => $id]);
    }

    public function test_missing_product_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        (new List_Variations())->handle(['product_id' => 999999]);
    }

    public function test_requires_a_product_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new List_Variations())->handle([]);
    }
}
