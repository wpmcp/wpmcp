<?php

namespace WPMCP\Tests\Free\WooCommerce;

use WPMCP\Tools\WooCommerce\Update_Variation;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Safety\Snapshot_Store;

/**
 * The reads here deliberately do NOT clear WooCommerce transients first:
 * the rollback promise is that the storefront-facing derived data (the
 * parent's variation price range, the meta lookup rows) follows the restore,
 * so the assertions read exactly what a shopper-facing query would.
 */
class UpdateVariationTest extends \WP_UnitTestCase
{
    use VariableProductFixture;

    protected function setUp(): void
    {
        parent::setUp();
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }
        Snapshot_Store::install();
    }

    /** @return array{min_price:string, max_price:string, stock_quantity:?int}|null */
    private function lookup_row(int $product_id): ?array
    {
        global $wpdb;
        wp_cache_delete('lookup_table', 'object_' . $product_id);
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT min_price, max_price, stock_quantity FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id = %d",
                $product_id
            ),
            ARRAY_A
        );
        if (! $row) {
            return null;
        }
        return [
            // The lookup columns are DECIMAL(19,4); normalise to the two
            // places the raw price strings use so comparisons stay exact.
            'min_price'      => number_format((float) $row['min_price'], 2, '.', ''),
            'max_price'      => number_format((float) $row['max_price'], 2, '.', ''),
            'stock_quantity' => null === $row['stock_quantity'] ? null : (int) $row['stock_quantity'],
        ];
    }

    public function test_updates_price_and_stock_and_syncs_the_parent(): void
    {
        $ids = $this->variable_product();
        $out = (new Update_Variation())->handle([
            'id'             => $ids['small'],
            'regular_price'  => '25.00',
            'stock_quantity' => 2,
        ]);

        $this->assertArrayHasKey('operation_id', $out);
        $this->assertSame('25.00', $out['regular_price']);
        $this->assertSame(2, $out['stock_quantity']);
        $this->assertSame($ids['parent'], $out['parent_id']);

        $variation = wc_get_product($ids['small']);
        $this->assertSame('25.00', $variation->get_regular_price());
        $this->assertSame(2, $variation->get_stock_quantity());

        $parent = wc_get_product($ids['parent']);
        $this->assertSame('20.00', $parent->get_variation_price('min'));
        $this->assertSame('25.00', $parent->get_variation_price('max'));
    }

    public function test_rollback_restores_price_stock_parent_attachment_and_parent_price_range(): void
    {
        $ids = $this->variable_product();
        $out = (new Update_Variation())->handle([
            'id'             => $ids['small'],
            'regular_price'  => '99.00',
            'sale_price'     => '90.00',
            'stock_quantity' => 1,
        ]);

        // A variation save() queues the parent sync for shutdown
        // (wc_deferred_product_sync); drain it the way the end of a real
        // request would, so the "before rollback" state below is what the
        // store actually ends up with.
        \WC_Post_Data::do_deferred_product_sync();

        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));
        $this->assertSame('90.00', wc_get_product($ids['small'])->get_price());
        $this->assertSame('90.00', wc_get_product($ids['parent'])->get_variation_price('max'));
        $this->assertSame('90.00', $this->lookup_row($ids['small'])['min_price']);
        $this->assertSame('90.00', $this->lookup_row($ids['parent'])['max_price']);

        $rolled_back = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled_back['restored']);

        $restored = wc_get_product($ids['small']);
        $this->assertInstanceOf(\WC_Product_Variation::class, $restored);
        $this->assertSame($ids['parent'], $restored->get_parent_id());
        $this->assertSame($ids['parent'], (int) get_post($ids['small'])->post_parent);
        $this->assertSame('10.00', $restored->get_regular_price());
        $this->assertSame('', $restored->get_sale_price());
        $this->assertSame('10.00', $restored->get_price());
        $this->assertSame(5, $restored->get_stock_quantity());

        $parent = wc_get_product($ids['parent']);
        $this->assertSame('10.00', $parent->get_variation_price('min'));
        $this->assertSame('20.00', $parent->get_variation_price('max'));
        $this->assertContains($ids['small'], $parent->get_children());

        $this->assertSame(
            ['min_price' => '10.00', 'max_price' => '10.00', 'stock_quantity' => 5],
            $this->lookup_row($ids['small'])
        );
        $this->assertSame('10.00', $this->lookup_row($ids['parent'])['min_price']);
        $this->assertSame('20.00', $this->lookup_row($ids['parent'])['max_price']);
    }

    public function test_status_only_accepts_publish_or_private(): void
    {
        $ids = $this->variable_product();

        try {
            (new Update_Variation())->handle(['id' => $ids['small'], 'status' => 'trash']);
            $this->fail('trash must be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('publish, private', $e->getMessage());
        }
        $this->assertSame('publish', wc_get_product($ids['small'])->get_status());

        $out = (new Update_Variation())->handle(['id' => $ids['small'], 'status' => 'private']);
        $this->assertSame('private', $out['status']);
        $this->assertSame('private', wc_get_product($ids['small'])->get_status());
    }

    public function test_null_stock_quantity_is_rejected_not_coerced_to_zero(): void
    {
        $ids = $this->variable_product();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stock_quantity must be an integer');
        (new Update_Variation())->handle(['id' => $ids['small'], 'stock_quantity' => null]);
    }

    public function test_stock_status_is_validated_and_refused_while_stock_is_managed(): void
    {
        $ids  = $this->variable_product();
        $tool = new Update_Variation();

        try {
            $tool->handle(['id' => $ids['small'], 'manage_stock' => false, 'stock_status' => 'bogus']);
            $this->fail('unknown stock_status must be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('stock_status must be one of', $e->getMessage());
        }

        try {
            $tool->handle(['id' => $ids['small'], 'stock_status' => 'outofstock']);
            $this->fail('stock_status must be refused while stock is managed');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('derived from stock_quantity', $e->getMessage());
        }
        $this->assertSame('instock', wc_get_product($ids['small'])->get_stock_status());

        $out = $tool->handle(['id' => $ids['small'], 'manage_stock' => false, 'stock_status' => 'outofstock']);
        $this->assertFalse($out['manage_stock']);
        $this->assertSame('outofstock', $out['stock_status']);
        $this->assertSame('outofstock', wc_get_product($ids['small'])->get_stock_status());
    }

    public function test_rejected_input_takes_no_snapshot(): void
    {
        $ids     = $this->variable_product();
        $session = 'reject-' . wp_generate_password(8, false);

        try {
            (new Update_Variation())->handle(['id' => $ids['small'], 'status' => 'draft', 'session_id' => $session]);
        } catch (\InvalidArgumentException $e) {
            // expected
        }

        $this->assertSame([], Snapshot_Store::list_by_session($session));
    }

    public function test_non_variation_and_missing_ids_throw(): void
    {
        $ids = $this->variable_product();

        $this->expectException(\RuntimeException::class);
        (new Update_Variation())->handle(['id' => $ids['parent'], 'regular_price' => '1.00']);
    }
}
