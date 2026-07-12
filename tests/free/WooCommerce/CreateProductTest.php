<?php

namespace WPMCP\Tests\Free\WooCommerce;

use WPMCP\Tools\WooCommerce\Create_Product;

class CreateProductTest extends \WP_UnitTestCase
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

    public function test_creates_a_simple_product(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }

        $out = (new Create_Product())->handle([
            'name'          => 'Copper Kettle',
            'regular_price' => '30.00',
            'description'   => 'A shiny copper kettle.',
            'sku'           => 'KETTLE-1',
        ]);
        $this->created[] = $out['id'];

        $this->assertGreaterThan(0, $out['id']);

        $product = wc_get_product($out['id']);
        $this->assertSame('Copper Kettle', $product->get_name());
        $this->assertSame('30.00', $product->get_regular_price());
        $this->assertSame('KETTLE-1', $product->get_sku());
        $this->assertSame('publish', $product->get_status());
    }

    public function test_requires_a_name(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }

        $this->expectException(\InvalidArgumentException::class);
        (new Create_Product())->handle(['regular_price' => '5.00']);
    }
}
