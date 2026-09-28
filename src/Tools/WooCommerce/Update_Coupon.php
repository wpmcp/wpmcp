<?php

namespace WPMCP\Tools\WooCommerce;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Update a coupon's fields through WC_Coupon's setters and save().
 *
 * A coupon is a shop_coupon post whose settings are postmeta, so the write
 * rides the existing 'post' snapshot type: the full row plus ALL postmeta
 * (amount, limits, restrictions, usage count and the used-by list) is
 * captured, and rollback-operation restores it exactly. Rollback_Service
 * then drops WooCommerce's code-to-id cache, so a rolled-back code change
 * resolves to the right coupon at checkout straight away.
 */
class Update_Coupon
{
    public function handle(array $args): array
    {
        $coupon = Coupon_View::load((int) ($args['id'] ?? 0));
        $id     = $coupon->get_id();

        Coupon_Input::validate($args, $id, $coupon->get_discount_type());

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-coupon',
                'args'        => $args,
            ],
            static function () use ($coupon, $args): void {
                Coupon_Input::apply($coupon, $args);
                if (! $coupon->save()) {
                    throw new \RuntimeException('Could not update the coupon.');
                }
            }
        );

        return array_merge(Coupon_View::detail(new \WC_Coupon($id)), ['operation_id' => $out['operation_id']]);
    }
}
