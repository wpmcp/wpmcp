<?php

namespace WPMCP\Tools\WooCommerce;

use WPMCP\Safety\Post_Creation_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create a coupon through WC_Coupon's setters and save().
 *
 * Creation has no prior state to capture, so like create-product it records
 * a creation row once the coupon exists (issue #761, Post_Creation_Snapshot;
 * a coupon is a shop_coupon post) and returns its operation_id:
 * rollback-operation and rollback-session move the coupon to the trash,
 * where it stops working at checkout, frees its code for a new coupon, and
 * restore-post can bring it back. The input is validated first (Coupon_Input), so a
 * duplicate code or a percent discount over 100 is refused before anything
 * is written. A published coupon is live at checkout the moment it is
 * saved, so status defaults to draft; pass status:publish, or publish later
 * with update-coupon, once the user has agreed to the discount.
 */
class Create_Coupon
{
    public function handle(array $args): array
    {
        if (! isset($args['code']) || '' === wc_format_coupon_code((string) $args['code'])) {
            throw new \InvalidArgumentException('A coupon code is required.');
        }
        if (! array_key_exists('status', $args)) {
            $args['status'] = 'draft';
        }

        Coupon_Input::validate($args, 0, 'fixed_cart');

        $coupon = new \WC_Coupon();
        Coupon_Input::apply($coupon, $args);
        $id = $coupon->save();
        if (! $id) {
            throw new \RuntimeException('Could not create the coupon.');
        }

        $operation_id = Post_Creation_Snapshot::record('create-coupon', [(int) $id], $args, (string) ($args['session_id'] ?? 'default'));

        return ['operation_id' => $operation_id] + Coupon_View::detail(new \WC_Coupon($id));
    }
}
