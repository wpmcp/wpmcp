<?php

namespace WPMCP\Tools\WooCommerce;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delete a coupon: trash by default, permanently with force:true.
 *
 * Destructive and disabled by default, like delete-product: a site opts in
 * with add_filter('wpmcp_enable_delete_coupon', '__return_true'), and the
 * caller must pass confirm:true. Both paths run through Safe_Mutation with
 * object_type 'post' (a coupon is a shop_coupon post), so a trash is undone
 * by restoring the status and a force-delete is undone by resurrecting the
 * coupon at its original id with every setting and its usage history.
 */
class Delete_Coupon
{
    public static function is_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_delete_coupon', false);
    }

    public function handle(array $args): array
    {
        if (! self::is_enabled()) {
            throw new \RuntimeException('The delete-coupon tool is disabled. Enable it with the wpmcp_enable_delete_coupon filter.');
        }

        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0 || 'shop_coupon' !== get_post_type($id)) {
            throw new \InvalidArgumentException('Coupon not found.');
        }
        if (true !== ($args['confirm'] ?? null)) {
            throw new \WPMCP\MCP\Confirmation_Required('Deleting a coupon stops it working at checkout. Pass confirm:true to proceed.');
        }

        $force  = ! empty($args['force']);
        $coupon = new \WC_Coupon($id);

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'delete-coupon',
                'args'        => $args,
            ],
            static function () use ($coupon, $force): void {
                if (! $coupon->delete($force)) {
                    throw new \RuntimeException('Could not delete the coupon.');
                }
            }
        );

        return [
            'operation_id' => $out['operation_id'],
            'id'           => $id,
            'deleted'      => $force ? 'deleted' : 'trashed',
            'recoverable'  => true,
        ];
    }
}
