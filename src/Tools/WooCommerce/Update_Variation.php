<?php

namespace WPMCP\Tools\WooCommerce;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Update a WooCommerce product variation's writable fields via the CRUD
 * layer (WC_Product_Variation setters + save()).
 *
 * A variation is a 'product_variation' post whose parent is the variable
 * product, and WooCommerce stores its price and stock as postmeta on that
 * post. So, exactly like Update_Product, this routes the write through
 * Safe_Mutation with object_type 'post' and the variation's own post id: the
 * existing post snapshot captures the full row (including post_parent, which
 * is what keeps the variation attached to its parent) plus ALL postmeta, and
 * rollback-operation restores the prior price and stock exactly through the
 * engine that already covers posts.
 *
 * The mutation itself uses WC_Product_Variation setters (not raw
 * update_post_meta) so WooCommerce's derived fields (_price vs
 * _regular_price/_sale_price, _stock_status from quantity, the parent's
 * cached price range) stay correct: save() triggers the parent sync.
 */
class Update_Variation
{
    public function handle(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('A variation id is required.');
        }

        $variation = wc_get_product($id);
        if (! $variation instanceof \WC_Product_Variation) {
            throw new \RuntimeException('Variation not found (id ' . $id . ' is not a product variation).');
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-variation',
                'args'        => $args,
            ],
            function () use ($variation, $args): void {
                $this->apply_changes($variation, $args);
                if (! $variation->save()) {
                    throw new \RuntimeException('Could not update the variation.');
                }
            }
        );

        $fresh = wc_get_product($id);

        return array_merge(
            $fresh instanceof \WC_Product_Variation ? Variation_View::summary($fresh) : ['id' => $id],
            ['operation_id' => $out['operation_id']]
        );
    }

    /** Apply only the writable fields present in $args to the variation. */
    private function apply_changes(\WC_Product_Variation $variation, array $args): void
    {
        if (array_key_exists('regular_price', $args)) {
            $variation->set_regular_price((string) $args['regular_price']);
        }
        if (array_key_exists('sale_price', $args)) {
            $variation->set_sale_price((string) $args['sale_price']);
        }
        if (array_key_exists('sku', $args)) {
            $variation->set_sku(sanitize_text_field((string) $args['sku']));
        }
        if (array_key_exists('status', $args)) {
            $variation->set_status(sanitize_key((string) $args['status']));
        }
        if (array_key_exists('manage_stock', $args)) {
            $variation->set_manage_stock((bool) $args['manage_stock']);
        }
        if (array_key_exists('stock_quantity', $args)) {
            $variation->set_stock_quantity((int) $args['stock_quantity']);
        }
        if (array_key_exists('stock_status', $args)) {
            $variation->set_stock_status(sanitize_key((string) $args['stock_status']));
        }
    }
}
