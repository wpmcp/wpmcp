<?php

namespace WPMCP\Tools\WooCommerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: list coupons as summary rows, with a code search, a status
 * filter and paging. Coupons are shop_coupon posts; the query only selects
 * ids, and every row is built from a WC_Coupon so the shape matches
 * get-coupon and the write tools' replies.
 */
class List_Coupons
{
    private const STATUSES = ['publish', 'draft', 'pending', 'private', 'future', 'trash'];

    public function handle(array $args): array
    {
        $per_page = max(1, min(100, (int) ($args['per_page'] ?? 50)));
        $page     = max(1, (int) ($args['page'] ?? 1));

        $status = 'any';
        if (isset($args['status']) && '' !== (string) $args['status']) {
            $status = sanitize_key((string) $args['status']);
            if (! in_array($status, self::STATUSES, true)) {
                throw new \InvalidArgumentException('status must be one of: ' . esc_html(implode(', ', self::STATUSES)) . '.');
            }
        }

        $query_args = [
            'post_type'      => 'shop_coupon',
            'post_status'    => $status,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ];
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $query_args['s'] = sanitize_text_field((string) $args['search']);
        }

        $query = new \WP_Query($query_args);
        $rows  = [];
        foreach ($query->posts as $id) {
            $rows[] = Coupon_View::summary(new \WC_Coupon((int) $id));
        }

        return [
            'coupons'  => $rows,
            'total'    => (int) $query->found_posts,
            'page'     => $page,
            'has_more' => $page * $per_page < (int) $query->found_posts,
        ];
    }
}
