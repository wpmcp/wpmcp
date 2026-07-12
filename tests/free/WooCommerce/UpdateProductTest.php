<?php

namespace WPMCP\Tests\Free\WooCommerce;

use WPMCP\Tools\WooCommerce\Update_Product;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Safety\Snapshot_Store;

class UpdateProductTest extends \WP_UnitTestCase
{
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            wp_delete_post($id, true);
        }
        $this->created = [];
        parent::tearDown();
    }

    private function product(string $price = '20.00', int $stock = 10): int
    {
        $product = new \WC_Product_Simple();
        $product->set_name('Silk Shawl');
        $product->set_regular_price($price);
        $product->set_manage_stock(true);
        $product->set_stock_quantity($stock);
        $id = $product->save();
        $this->created[] = $id;
        return $id;
    }

    /** Read a product fresh, bypassing WC's runtime object cache. */
    private function fresh(int $id): \WC_Product
    {
        wc_delete_product_transients($id);
        return wc_get_product($id);
    }

    public function test_updates_price_and_stock(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }

        $id  = $this->product('20.00', 10);
        $out = (new Update_Product())->handle([
            'id'            => $id,
            'regular_price' => '25.00',
            'stock_quantity' => 3,
        ]);

        $this->assertArrayHasKey('operation_id', $out);
        $product = $this->fresh($id);
        $this->assertSame('25.00', $product->get_regular_price());
        $this->assertSame(3, $product->get_stock_quantity());
    }

    public function test_update_is_snapshotted_and_rollback_restores_prior_price_and_stock(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }

        $id  = $this->product('20.00', 10);
        $out = (new Update_Product())->handle([
            'id'            => $id,
            'regular_price' => '99.00',
            'stock_quantity' => 1,
        ]);

        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));
        $this->assertSame('99.00', $this->fresh($id)->get_regular_price());

        $rolled_back = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled_back['restored']);

        $restored = $this->fresh($id);
        $this->assertSame('20.00', $restored->get_regular_price());
        $this->assertSame(10, $restored->get_stock_quantity());
    }

    public function test_missing_product_throws(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }

        $this->expectException(\RuntimeException::class);
        (new Update_Product())->handle(['id' => 999999, 'regular_price' => '1.00']);
    }
}
