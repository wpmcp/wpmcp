<?php

namespace WPMCP\Tools\WooCommerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shape a WC_Coupon into a safe row. Money values are the raw stored strings
 * (never formatted with a currency symbol), and the expiry date is an ISO
 * 8601 string or null, so a caller can read a value back and write it
 * unchanged.
 */
class Coupon_View
{
    /** A compact row for listings. */
    public static function summary(\WC_Coupon $coupon): array
    {
        $expires = $coupon->get_date_expires();

        return [
            'id'            => $coupon->get_id(),
            'code'          => $coupon->get_code(),
            'status'        => $coupon->get_status(),
            'discount_type' => $coupon->get_discount_type(),
            'amount'        => (string) $coupon->get_amount(),
            'usage_count'   => (int) $coupon->get_usage_count(),
            'usage_limit'   => (int) $coupon->get_usage_limit(),
            'date_expires'  => $expires ? $expires->date('c') : null,
        ];
    }

    /** The full writable field set, as update-coupon accepts it. */
    public static function detail(\WC_Coupon $coupon): array
    {
        return array_merge(self::summary($coupon), [
            'description'                 => $coupon->get_description(),
            'individual_use'              => (bool) $coupon->get_individual_use(),
            'free_shipping'               => (bool) $coupon->get_free_shipping(),
            'exclude_sale_items'          => (bool) $coupon->get_exclude_sale_items(),
            'minimum_amount'              => (string) $coupon->get_minimum_amount(),
            'maximum_amount'              => (string) $coupon->get_maximum_amount(),
            'usage_limit_per_user'        => (int) $coupon->get_usage_limit_per_user(),
            'limit_usage_to_x_items'      => null === $coupon->get_limit_usage_to_x_items() ? null : (int) $coupon->get_limit_usage_to_x_items(),
            'product_ids'                 => array_map('intval', $coupon->get_product_ids()),
            'excluded_product_ids'        => array_map('intval', $coupon->get_excluded_product_ids()),
            'product_categories'          => array_map('intval', $coupon->get_product_categories()),
            'excluded_product_categories' => array_map('intval', $coupon->get_excluded_product_categories()),
            'email_restrictions'          => array_values($coupon->get_email_restrictions()),
        ]);
    }

    /**
     * Id of a non-trashed coupon whose code is $code, in ANY status, or 0.
     *
     * wc_get_coupon_id_by_code() only sees published coupons (it mirrors what
     * checkout can apply), so it cannot answer "is this code taken": two
     * drafts could share a code and both go live later. post_title holds the
     * code and the title query compares it under the column's
     * case-insensitive collation, matching how checkout treats codes.
     */
    public static function find_id_by_code(string $code, int $exclude_id = 0): int
    {
        if ('' === $code) {
            return 0;
        }
        $ids = get_posts([
            'post_type'        => 'shop_coupon',
            'post_status'      => ['publish', 'draft', 'pending', 'private', 'future'],
            'title'            => $code,
            'fields'           => 'ids',
            'posts_per_page'   => 1,
            'post__not_in'     => $exclude_id > 0 ? [$exclude_id] : [],
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'no_found_rows'    => true,
        ]);
        return $ids ? (int) $ids[0] : 0;
    }

    /** Resolve a coupon by id, or throw. Trashed coupons still resolve (restore is an update). */
    public static function load(int $id): \WC_Coupon
    {
        if ($id <= 0 || 'shop_coupon' !== get_post_type($id)) {
            throw new \RuntimeException('Coupon not found (id ' . (int) $id . ' is not a coupon).');
        }
        return new \WC_Coupon($id);
    }
}
