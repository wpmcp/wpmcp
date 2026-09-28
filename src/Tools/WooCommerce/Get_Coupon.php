<?php

namespace WPMCP\Tools\WooCommerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: one coupon's full field set, looked up by id or by code (a
 * code lookup also finds drafts, unlike the checkout's own lookup).
 */
class Get_Coupon
{
    public function handle(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0 && isset($args['code']) && '' !== trim((string) $args['code'])) {
            $id = Coupon_View::find_id_by_code(wc_format_coupon_code((string) $args['code']));
            if ($id <= 0) {
                throw new \RuntimeException('No coupon has the code "' . esc_html((string) $args['code']) . '".');
            }
        }
        if ($id <= 0) {
            throw new \InvalidArgumentException('Pass a coupon id or code.');
        }

        return Coupon_View::detail(Coupon_View::load($id));
    }
}
