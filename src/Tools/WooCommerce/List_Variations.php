<?php

namespace WPMCP\Tools\WooCommerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: list the variations of one variable product as safe summary
 * rows, with paging. Reads have nothing to roll back, so this never touches
 * Safe_Mutation.
 *
 * Uses wc_get_products() with type 'variation' and parent set, so the
 * returned objects are real WC_Product_Variation instances and the row shape
 * stays consistent with update-variation's reply.
 */
class List_Variations
{
    public function handle(array $args): array
    {
        $product_id = (int) ($args['product_id'] ?? 0);
        if ($product_id <= 0) {
            throw new \InvalidArgumentException('A product_id is required.');
        }

        $parent = wc_get_product($product_id);
        if (! $parent) {
            throw new \RuntimeException('Product not found.');
        }
        if (! $parent->is_type('variable')) {
            throw new \InvalidArgumentException(
                'Product ' . $product_id . ' is type "' . $parent->get_type() . '", not a variable product.'
            );
        }

        $per_page = max(1, min(100, (int) ($args['per_page'] ?? 50)));
        $page     = max(1, (int) ($args['page'] ?? 1));

        $results = wc_get_products([
            'type'     => 'variation',
            'parent'   => $product_id,
            'limit'    => $per_page,
            'page'     => $page,
            'paginate' => true,
            'orderby'  => 'ID',
            'order'    => 'ASC',
        ]);

        $rows = [];
        foreach ($results->products as $variation) {
            if ($variation instanceof \WC_Product_Variation) {
                $rows[] = Variation_View::summary($variation);
            }
        }

        return [
            'product_id' => $product_id,
            'variations' => $rows,
            'total'      => (int) $results->total,
            'page'       => $page,
        ];
    }
}
