<?php

namespace WPMCP\Tools\WooCommerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: list products and variations whose managed stock is at or below
 * a threshold, plus anything explicitly marked out of stock. Reads have
 * nothing to roll back, so this never touches Safe_Mutation.
 *
 * The threshold defaults to the store's own woocommerce_notify_low_stock_amount
 * setting so the tool agrees with what the shop owner already considers "low".
 * Rows are summary shapes from Product_View/Variation_View, so ids feed
 * directly into update-product and update-variation for restocking.
 */
class List_Low_Stock_Products
{
    public function handle(array $args): array
    {
        $default   = (int) get_option('woocommerce_notify_low_stock_amount', 2);
        $threshold = array_key_exists('threshold', $args) ? (int) $args['threshold'] : $default;
        $per_page  = max(1, min(100, (int) ($args['per_page'] ?? 50)));
        $page      = max(1, (int) ($args['page'] ?? 1));

        $results = wc_get_products([
            'type'         => ['simple', 'variation'],
            'manage_stock' => true,
            'stock_status' => ['instock', 'outofstock', 'onbackorder'],
            'limit'        => $per_page,
            'page'         => $page,
            'paginate'     => true,
            'orderby'      => 'ID',
            'order'        => 'ASC',
        ]);

        $rows = [];
        foreach ($results->products as $product) {
            $quantity = $product->get_stock_quantity();
            $low      = (null !== $quantity && $quantity <= $threshold)
                || 'outofstock' === $product->get_stock_status();
            if (! $low) {
                continue;
            }
            $rows[] = $product instanceof \WC_Product_Variation
                ? Variation_View::summary($product)
                : Product_View::summary($product);
        }

        return [
            'threshold' => $threshold,
            'products'  => $rows,
            // Total counts stock-managed candidates scanned on this page's
            // query, not low-stock matches; page through until empty.
            'total'     => (int) $results->total,
            'page'      => $page,
        ];
    }
}
