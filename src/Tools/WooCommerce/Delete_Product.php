<?php

namespace WPMCP\Tools\WooCommerce;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delete a WooCommerce product.
 *
 * Destructive and disabled by default: sites must opt in with
 * add_filter('wpmcp_enable_delete_product', '__return_true') before this tool
 * will run at all, in addition to the caller passing confirm:true.
 *
 * A product is a 'product' post, so both paths route through Safe_Mutation
 * with object_type 'post' and the product post id, reusing the existing post
 * snapshot/rollback engine:
 *  - Default (trash): reversible via WordPress's own trash; a rollback simply
 *    sets the status back.
 *  - force:true: permanently deletes the row; a rollback resurrects the post
 *    at its original ID (import_id), restoring the product, its postmeta
 *    (price, stock) and terms. Unlike media, a product has no physical file,
 *    so the restore is complete.
 */
class Delete_Product
{
    public static function is_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_delete_product', false);
    }

    public function handle(array $args): array
    {
        if (! self::is_enabled()) {
            throw new \RuntimeException('The delete-product tool is disabled. Enable it with the wpmcp_enable_delete_product filter.');
        }

        $id      = (int) ($args['id'] ?? 0);
        $product = $id ? wc_get_product($id) : null;
        if (! $product) {
            throw new \InvalidArgumentException('Product not found.');
        }

        if (true !== ($args['confirm'] ?? null)) {
            throw new \WPMCP\MCP\Confirmation_Required('Deleting a product is permanent. Pass confirm:true to proceed.');
        }

        $force = ! empty($args['force']);

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'delete-product',
                'args'        => $args,
            ],
            function () use ($product, $force): void {
                // WC_Product::delete($force) trashes when $force is false and
                // permanently deletes when true, keeping WooCommerce's own
                // data-store bookkeeping correct.
                if (! $product->delete($force)) {
                    throw new \RuntimeException('Could not delete the product.');
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
