<?php

namespace WPMCP\Tests\Free\WooCommerce;

use WPMCP\Governance\Governance;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\WooCommerce\Bulk_Update_Products;

/**
 * bulk-update-products (issue #195): per-item outcomes, one rollback
 * session for the batch, and no way around the single-item tools' gates.
 */
class BulkUpdateProductsTest extends \WP_UnitTestCase
{
    use VariableProductFixture;

    protected function setUp(): void
    {
        parent::setUp();
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }
        Snapshot_Store::install();
        Governance::reset_for_tests();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Governance::reset_for_tests();
        parent::tearDown();
    }

    private function simple(string $price, int $stock): int
    {
        $product = new \WC_Product_Simple();
        $product->set_name('Mug ' . $price);
        $product->set_regular_price($price);
        $product->set_manage_stock(true);
        $product->set_stock_quantity($stock);
        return $product->save();
    }

    public function test_reports_a_separate_outcome_per_item_and_keeps_going_after_a_failure(): void
    {
        $ids  = $this->variable_product();
        $mug  = $this->simple('9.00', 3);

        $out = (new Bulk_Update_Products())->handle([
            'items' => [
                ['id' => $mug, 'regular_price' => '11.00', 'stock_quantity' => 30],
                ['id' => $ids['small'], 'stock_quantity' => 'lots'],
                ['id' => 987654, 'regular_price' => '1.00'],
                ['id' => $ids['large'], 'regular_price' => '22.00'],
            ],
        ]);

        $this->assertSame(2, $out['updated']);
        $this->assertSame(2, $out['failed']);
        $this->assertStringStartsWith('bulk-update-products-', $out['session_id']);

        [$first, $second, $third, $fourth] = $out['results'];
        $this->assertTrue($first['ok']);
        $this->assertSame('product', $first['kind']);
        $this->assertNotEmpty($first['operation_id']);
        $this->assertFalse($second['ok']);
        $this->assertStringContainsString('stock_quantity', $second['error']);
        $this->assertFalse($third['ok']);
        $this->assertTrue($fourth['ok']);
        $this->assertSame('variation', $fourth['kind']);

        $this->assertSame('11.00', wc_get_product($mug)->get_regular_price());
        $this->assertSame(30, wc_get_product($mug)->get_stock_quantity());
        $this->assertSame(5, wc_get_product($ids['small'])->get_stock_quantity());
        $this->assertSame('22.00', wc_get_product($ids['large'])->get_regular_price());
    }

    public function test_rollback_session_undoes_the_whole_batch(): void
    {
        $a = $this->simple('5.00', 1);
        $b = $this->simple('6.00', 2);

        $out = (new Bulk_Update_Products())->handle([
            'items' => [
                ['id' => $a, 'stock_quantity' => 50],
                ['id' => $b, 'stock_quantity' => 60, 'regular_price' => '7.00'],
            ],
        ]);
        $this->assertSame(2, $out['updated']);

        (new Rollback_Session())->handle(['session_id' => $out['session_id']]);

        $this->assertSame(1, wc_get_product($a)->get_stock_quantity());
        $this->assertSame(2, wc_get_product($b)->get_stock_quantity());
        $this->assertSame('6.00', wc_get_product($b)->get_regular_price());
    }

    public function test_an_item_is_skipped_when_its_single_item_tool_is_switched_off(): void
    {
        $ids = $this->variable_product();
        $mug = $this->simple('9.00', 3);

        $off = static function ($enabled, $name) {
            return 'wpmcp/update-variation' === $name ? false : $enabled;
        };
        add_filter('wpmcp_ability_enabled', $off, 10, 2);

        try {
            $out = (new Bulk_Update_Products())->handle([
                'items' => [
                    ['id' => $ids['small'], 'stock_quantity' => 1],
                    ['id' => $mug, 'stock_quantity' => 1],
                ],
            ]);
        } finally {
            remove_filter('wpmcp_ability_enabled', $off, 10);
        }

        $this->assertFalse($out['results'][0]['ok']);
        $this->assertStringContainsString('update-variation', $out['results'][0]['error']);
        $this->assertSame(5, wc_get_product($ids['small'])->get_stock_quantity());
        $this->assertTrue($out['results'][1]['ok']);
    }

    public function test_refuses_malformed_batches_before_writing_anything(): void
    {
        $mug = $this->simple('9.00', 3);

        foreach (
            [
            [],
            ['items' => []],
            ['items' => [['regular_price' => '1.00']]],
            ['items' => [['id' => $mug, 'stock_quantity' => 1], ['id' => $mug, 'stock_quantity' => 2]]],
            ['items' => array_fill(0, 51, ['id' => $mug])],
            ] as $bad
        ) {
            try {
                (new Bulk_Update_Products())->handle($bad);
                $this->fail('Expected a refusal.');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame(3, wc_get_product($mug)->get_stock_quantity());
    }
}
