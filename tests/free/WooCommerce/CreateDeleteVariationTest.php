<?php

namespace WPMCP\Tests\Free\WooCommerce;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\WooCommerce\Create_Variation;
use WPMCP\Tools\WooCommerce\Delete_Variation;

/**
 * create-variation and delete-variation (issue #195, phase 1). The rollback
 * test is the one the issue's definition of done names: a deleted variation
 * restored by rollback must still be attached to its parent product, and the
 * parent's derived data (children list, price range) must follow.
 */
class CreateDeleteVariationTest extends \WP_UnitTestCase
{
    use VariableProductFixture;

    protected function setUp(): void
    {
        parent::setUp();
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }
        Snapshot_Store::install();
        // The test install does not run WooCommerce's role setup, so the
        // administrator role lacks the store capability the tools require.
        $user = self::factory()->user->create_and_get(['role' => 'administrator']);
        $user->add_cap('manage_woocommerce');
        wp_set_current_user($user->ID);
        add_filter('wpmcp_enable_delete_variation', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_enable_delete_variation', '__return_true');
        parent::tearDown();
    }

    /** A variable product whose size attribute also offers "medium", unused so far. */
    private function parent_with_free_option(): array
    {
        $ids = $this->variable_product();

        // A fresh attribute object: mutating the loaded one in place leaves
        // WooCommerce's change tracking seeing no change, so save() skips it.
        $attr = new \WC_Product_Attribute();
        $attr->set_name('size');
        $attr->set_options(['small', 'medium', 'large']);
        $attr->set_visible(true);
        $attr->set_variation(true);

        $parent = wc_get_product($ids['parent']);
        $parent->set_attributes([$attr]);
        $parent->save();
        return $ids;
    }

    public function test_create_adds_a_variation_and_resyncs_the_parent(): void
    {
        $ids = $this->parent_with_free_option();

        $out = (new Create_Variation())->handle([
            'product_id'     => $ids['parent'],
            'attributes'     => ['Size' => 'Medium'],
            'regular_price'  => '15.00',
            'manage_stock'   => true,
            'stock_quantity' => 4,
        ]);

        $this->assertSame($ids['parent'], $out['parent_id']);
        $this->assertSame(['size' => 'medium'], $out['attributes']);
        $this->assertSame('15.00', $out['regular_price']);
        $this->assertSame(4, $out['stock_quantity']);
        $this->assertSame('delete-variation', $out['undo']);

        $parent = wc_get_product($ids['parent']);
        $this->assertContains($out['id'], $parent->get_children());
    }

    public function test_create_refuses_unknown_attributes_options_and_non_variable_parents(): void
    {
        $ids = $this->variable_product();

        foreach ([['colour' => 'red'], ['size' => 'huge'], 'size=small'] as $bad) {
            try {
                (new Create_Variation())->handle(['product_id' => $ids['parent'], 'attributes' => $bad]);
                $this->fail('Expected a refusal for ' . wp_json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        try {
            (new Create_Variation())->handle(['product_id' => $ids['parent'], 'attributes' => ['size' => 'small'], 'stock_quantity' => null]);
            $this->fail('update-variation rules must apply to create too.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('stock_quantity', $e->getMessage());
        }

        $simple = new \WC_Product_Simple();
        $simple->set_name('Plain');
        $simple_id = $simple->save();
        $this->expectException(\InvalidArgumentException::class);
        (new Create_Variation())->handle(['product_id' => $simple_id, 'attributes' => []]);
    }

    public function test_delete_is_gated_and_needs_confirm(): void
    {
        $ids = $this->variable_product();

        remove_filter('wpmcp_enable_delete_variation', '__return_true');
        try {
            (new Delete_Variation())->handle(['id' => $ids['small'], 'confirm' => true]);
            $this->fail('delete-variation must be disabled by default.');
        } catch (\RuntimeException $e) {
            $this->assertInstanceOf(\WC_Product_Variation::class, wc_get_product($ids['small']));
        }
        add_filter('wpmcp_enable_delete_variation', '__return_true');

        $this->expectException(\InvalidArgumentException::class);
        (new Delete_Variation())->handle(['id' => $ids['small']]);
    }

    public function test_delete_refuses_a_non_variation(): void
    {
        $ids = $this->variable_product();

        $this->expectException(\InvalidArgumentException::class);
        (new Delete_Variation())->handle(['id' => $ids['parent'], 'confirm' => true]);
    }

    public function test_rollback_of_a_delete_restores_the_variation_attached_to_its_parent(): void
    {
        $ids = $this->variable_product();

        $out = (new Delete_Variation())->handle(['id' => $ids['small'], 'confirm' => true]);
        \WC_Post_Data::do_deferred_product_sync();

        $this->assertTrue($out['recoverable']);
        $this->assertSame($ids['parent'], $out['parent_id']);
        $this->assertNull(get_post($ids['small']));
        $this->assertNotContains($ids['small'], wc_get_product($ids['parent'])->get_children());
        $this->assertSame('20.00', wc_get_product($ids['parent'])->get_variation_price('min'));

        $rolled_back = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled_back['restored']);

        $restored = wc_get_product($ids['small']);
        $this->assertInstanceOf(\WC_Product_Variation::class, $restored);
        $this->assertSame($ids['parent'], $restored->get_parent_id());
        $this->assertSame($ids['parent'], (int) get_post($ids['small'])->post_parent);
        $this->assertSame(['size' => 'small'], $restored->get_attributes());
        $this->assertSame('10.00', $restored->get_regular_price());
        $this->assertSame(5, $restored->get_stock_quantity());

        $parent = wc_get_product($ids['parent']);
        $this->assertContains($ids['small'], $parent->get_children());
        $this->assertSame('10.00', $parent->get_variation_price('min'));
        $this->assertSame('20.00', $parent->get_variation_price('max'));
    }
}
