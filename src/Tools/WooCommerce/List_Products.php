<?php

namespace WPMCP\Tools\WooCommerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: list WooCommerce products as safe summary rows, optionally
 * filtered by search, status, category, or type, with paging. Reads have
 * nothing to roll back, so this never touches Safe_Mutation.
 *
 * Uses wc_get_products() (the CRUD query layer) rather than a raw WP_Query so
 * the returned objects are real WC_Product instances and the row shape stays
 * consistent with get-product.
 */
class List_Products
{
    public function handle(array $args): array
    {
        $per_page = max(1, min(100, (int) ($args['per_page'] ?? 20)));
        $page     = max(1, (int) ($args['page'] ?? 1));

        $query_args = [
            'limit'    => $per_page,
            'page'     => $page,
            'paginate' => true,
            'orderby'  => 'date',
            'order'    => 'DESC',
        ];
        if (! empty($args['search'])) {
            $query_args['s'] = sanitize_text_field((string) $args['search']);
        }
        if (! empty($args['status'])) {
            $query_args['status'] = sanitize_key((string) $args['status']);
        }
        if (! empty($args['type'])) {
            $query_args['type'] = sanitize_key((string) $args['type']);
        }
        if (! empty($args['category'])) {
            $query_args['category'] = [ sanitize_title((string) $args['category']) ];
        }

        $results = wc_get_products($query_args);

        $rows = [];
        foreach ($results->products as $product) {
            $rows[] = Product_View::summary($product);
        }

        return [
            'products' => $rows,
            'total'    => (int) $results->total,
            'page'     => $page,
        ];
    }
}
