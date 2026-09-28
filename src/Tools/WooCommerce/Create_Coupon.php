<?php

namespace WPMCP\Tools\WooCommerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create a coupon through WC_Coupon's setters and save().
 *
 * Creation is exempt from Safe_Mutation, like create-product: there is no
 * prior state to capture. The input is validated first (Coupon_Input), so a
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

        return array_merge(
            Coupon_View::detail(new \WC_Coupon($id)),
            ['recoverable' => false, 'undo' => 'delete-coupon']
        );
    }
}
