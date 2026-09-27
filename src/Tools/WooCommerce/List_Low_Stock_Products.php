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
 *
 * The match is expressed as a postmeta query, not a PHP post-filter, so that
 * 'total' counts real matches and every page up to the last one is full:
 *
 *   (_manage_stock = 'yes' AND _stock <= threshold, numeric)
 *   OR _stock_status = 'outofstock'
 *
 * That covers every product type. A variable product that manages stock at
 * the parent level carries _manage_stock = 'yes' and _stock on the parent
 * post (its variations carry 'parent'), so it shows up as one parent row; a
 * variation managing its own stock shows up as a variation row; and a
 * product with stock management off still surfaces once it is marked out of
 * stock, which is the only signal WooCommerce keeps for it.
 */
class List_Low_Stock_Products
{
    public function handle(array $args): array
    {
        $default   = (int) get_option('woocommerce_notify_low_stock_amount', 2);
        $threshold = array_key_exists('threshold', $args) ? (int) $args['threshold'] : $default;
        $per_page  = max(1, min(100, (int) ($args['per_page'] ?? 50)));
        $page      = max(1, (int) ($args['page'] ?? 1));

        $query = new \WP_Query([
            'post_type'           => ['product', 'product_variation'],
            'post_status'         => ['publish', 'private', 'draft', 'pending'],
            'posts_per_page'      => $per_page,
            'paged'               => $page,
            'orderby'             => 'ID',
            'order'               => 'ASC',
            'fields'              => 'ids',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => false,
            'meta_query'          => [
                'relation' => 'OR',
                [
                    'relation' => 'AND',
                    [
                        'key'   => '_manage_stock',
                        'value' => 'yes',
                    ],
                    [
                        'key'     => '_stock',
                        'value'   => $threshold,
                        'compare' => '<=',
                        'type'    => 'NUMERIC',
                    ],
                ],
                [
                    'key'   => '_stock_status',
                    'value' => 'outofstock',
                ],
            ],
        ]);

        $rows = [];
        foreach ($query->posts as $id) {
            $product = wc_get_product((int) $id);
            if ($product instanceof \WC_Product_Variation) {
                $rows[] = Variation_View::summary($product);
            } elseif ($product instanceof \WC_Product) {
                $rows[] = Product_View::summary($product);
            }
        }

        $total = (int) $query->found_posts;

        return [
            'threshold' => $threshold,
            'products'  => $rows,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $per_page,
            'has_more'  => ($page * $per_page) < $total,
        ];
    }
}
