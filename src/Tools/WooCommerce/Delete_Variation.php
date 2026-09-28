<?php

namespace WPMCP\Tools\WooCommerce;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Permanently delete one variation of a variable product.
 *
 * Destructive and disabled by default, like delete-product: a site opts in
 * with add_filter('wpmcp_enable_delete_variation', '__return_true'), and the
 * caller must pass confirm:true. WooCommerce has no trash for variations
 * (its own REST API refuses a variation delete without force), so this is
 * always a permanent delete.
 *
 * It is still fully undoable. A variation is a product_variation post, so it
 * snapshots through the 'post' type, which captures the full row (post_parent
 * included) and every postmeta row (prices, stock, the attribute_* selections
 * that define it). rollback-operation resurrects it at its original id,
 * attached to the same parent, and Rollback_Service then re-syncs the
 * parent's price range and children list.
 */
class Delete_Variation
{
    public static function is_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_delete_variation', false);
    }

    public function handle(array $args): array
    {
        if (! self::is_enabled()) {
            throw new \RuntimeException('The delete-variation tool is disabled. Enable it with the wpmcp_enable_delete_variation filter.');
        }

        $id        = (int) ($args['id'] ?? 0);
        $variation = $id > 0 ? wc_get_product($id) : null;
        if (! $variation instanceof \WC_Product_Variation) {
            throw new \InvalidArgumentException('Variation not found.');
        }
        if (true !== ($args['confirm'] ?? null)) {
            throw new \InvalidArgumentException('Deleting a variation removes it from the store. Pass confirm:true to proceed.');
        }

        $parent_id = $variation->get_parent_id();

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'delete-variation',
                'args'        => $args,
            ],
            static function () use ($variation): void {
                if (! $variation->delete(true)) {
                    throw new \RuntimeException('Could not delete the variation.');
                }
            }
        );

        if ($parent_id > 0) {
            wc_delete_product_transients($parent_id);
            \WC_Product_Variable::sync($parent_id);
        }

        return [
            'operation_id' => $out['operation_id'],
            'id'           => $id,
            'parent_id'    => $parent_id,
            'deleted'      => 'deleted',
            'recoverable'  => true,
        ];
    }
}
