<?php

namespace WPMCP\Tests\Free\Functional;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\WooCommerce\Create_Product;
use WPMCP\Tools\WooCommerce\Update_Product;
use WPMCP\Tools\List_Operations;
use WPMCP\Tools\Rollback_Operation;

/**
 * End-to-end agent-realistic flow: create a WooCommerce product, update its
 * price, confirm list-operations surfaces the price change, then roll back
 * that single operation and confirm the original price is restored.
 */
class WooCommerceFlowTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();

        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }
    }

    public function test_create_update_price_list_and_rollback_operation_round_trips(): void
    {
        $session_id = 'wc-flow-session-' . uniqid();

        $created = (new Create_Product())->handle([
            'name'          => 'Rollback Widget',
            'regular_price' => '19.99',
        ]);
        $product_id = $created['id'];
        $this->assertSame('19.99', $created['regular_price']);

        $updated = (new Update_Product())->handle([
            'id'            => $product_id,
            'session_id'    => $session_id,
            'regular_price' => '49.99',
        ]);
        $this->assertSame('49.99', $updated['regular_price']);
        $this->assertSame('49.99', wc_get_product($product_id)->get_regular_price());

        $ops = (new List_Operations())->handle(['session_id' => $session_id]);
        $this->assertSame(1, $ops['total_count']);
        $this->assertSame('update-product', $ops['operations'][0]['tool_name']);
        $this->assertSame($product_id, $ops['operations'][0]['object_id']);
        $this->assertTrue($ops['operations'][0]['rollback_available']);

        $result = (new Rollback_Operation())->handle(['operation_id' => $updated['operation_id']]);
        $this->assertTrue($result['restored']);

        $restored_product = wc_get_product($product_id);
        $this->assertSame('19.99', $restored_product->get_regular_price());

        $restored_product->delete(true);
    }
}
