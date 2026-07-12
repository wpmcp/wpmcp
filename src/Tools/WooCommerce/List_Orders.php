<?php

namespace WPMCP\Tools\WooCommerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: list WooCommerce orders as safe summary rows, optionally filtered
 * by status or customer, with paging. Uses wc_get_orders() so it works
 * uniformly under HPOS or the legacy CPT storage. Reads have nothing to roll
 * back, so this never touches Safe_Mutation.
 */
class List_Orders
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
        if (! empty($args['status'])) {
            $query_args['status'] = sanitize_key((string) $args['status']);
        }
        if (! empty($args['customer_id'])) {
            $query_args['customer_id'] = (int) $args['customer_id'];
        }

        $results = wc_get_orders($query_args);

        $rows = [];
        foreach ($results->orders as $order) {
            $rows[] = Order_View::summary($order);
        }

        return [
            'orders' => $rows,
            'total'  => (int) $results->total,
            'page'   => $page,
        ];
    }
}
