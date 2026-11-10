<?php

namespace WPMCP\Tests\Free\WooCommerce;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\WooCommerce\Create_Product;
use WPMCP\Tools\WooCommerce\Update_Product;

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

    /** Issue #761: create-product returns an undo point, and undoing it trashes the product. */
    public function test_create_returns_an_undo_point_that_trashes_the_product(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }
        Snapshot_Store::install();
        \WC_Install::create_roles();
        $GLOBALS['wp_roles'] = null;
        wp_roles();
        $user = self::factory()->user->create_and_get(['role' => 'administrator']);
        $user->add_cap('manage_woocommerce');
        wp_set_current_user($user->ID);

        $out             = (new Create_Product())->handle(['name' => 'H4 Product 2', 'regular_price' => '10', 'status' => 'draft']);
        $this->created[] = $out['id'];
        $this->assertNotEmpty($out['operation_id']);

        $rolled_back = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled_back['restored'], implode(' ', $rolled_back['warnings']));
        $this->assertSame('trash', get_post_status($out['id']));
    }

    /** Issue #761: rollback-session over a create then an edit trashes the product. */
    public function test_rollback_session_undoes_a_create_and_its_edit(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }
        Snapshot_Store::install();
        \WC_Install::create_roles();
        $GLOBALS['wp_roles'] = null;
        wp_roles();
        $user = self::factory()->user->create_and_get(['role' => 'administrator']);
        $user->add_cap('manage_woocommerce');
        wp_set_current_user($user->ID);

        $out             = (new Create_Product())->handle(['name' => 'Session Kettle', 'regular_price' => '10', 'status' => 'draft', 'session_id' => 'product-761']);
        $this->created[] = $out['id'];
        (new Update_Product())->handle(['id' => $out['id'], 'regular_price' => '12', 'session_id' => 'product-761']);

        $result = (new Rollback_Session())->handle(['session_id' => 'product-761']);
        $this->assertSame('trash', get_post_status($out['id']), implode(' ', (array) ($result['warnings'] ?? [])));
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
