<?php

namespace WPMCP\Tools\WooCommerce;

use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * History row for a product or variation that apply-product-import CREATED.
 * Mirrors Media_Import_Snapshot: there is no prior state to capture, so the
 * row records what was created and
 * Rollback_Service::apply_wc_product_create_snapshot() undoes it by deleting
 * it again. post_type and post_date_gmt are kept for the identity check that
 * stops a rollback from deleting a different post that reclaimed the id, and
 * a variation's parent id is kept so the parent can be re-synced.
 */
class Product_Create_Snapshot
{
    public static function record(int $product_id, array $args, string $session_id): string
    {
        $operation_id = wp_generate_uuid4();
        $post         = get_post($product_id);

        Snapshot_Store::save(
            $operation_id,
            $session_id,
            [
                'object_type' => 'wc_product_create',
                'object_id'   => $product_id,
                'data'        => [
                    'post_type'     => $post ? $post->post_type : null,
                    'post_date_gmt' => $post ? $post->post_date_gmt : null,
                    'parent_id'     => $post ? (int) $post->post_parent : 0,
                ],
            ],
            'apply-product-import',
            hash('sha256', (string) wp_json_encode($args))
        );

        return $operation_id;
    }
}
