<?php

namespace WPMCP\Tools\WooCommerce;

use WPMCP\Safety\Post_Creation_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create a simple WooCommerce product via the CRUD layer (WC_Product_Simple
 * setters + save()).
 *
 * Creation has no prior state to snapshot, so like create-post it records a
 * creation row under the caller's session_id once the product exists
 * (issue #761, Post_Creation_Snapshot) and returns its operation_id:
 * rollback-operation and rollback-session move the product to the trash,
 * where restore-post brings it back. Only a fixed set of writable fields is
 * honored; anything else in the args is ignored.
 */
class Create_Product
{
    public function handle(array $args): array
    {
        $name = trim((string) ($args['name'] ?? ''));
        if ('' === $name) {
            throw new \InvalidArgumentException('A product name is required.');
        }

        $product = new \WC_Product_Simple();
        $product->set_name($name);

        if (array_key_exists('regular_price', $args)) {
            $product->set_regular_price((string) $args['regular_price']);
        }
        if (array_key_exists('sale_price', $args)) {
            $product->set_sale_price((string) $args['sale_price']);
        }
        if (array_key_exists('sku', $args)) {
            $product->set_sku(sanitize_text_field((string) $args['sku']));
        }
        if (array_key_exists('description', $args)) {
            $product->set_description((string) $args['description']);
        }
        if (array_key_exists('short_description', $args)) {
            $product->set_short_description((string) $args['short_description']);
        }
        if (array_key_exists('status', $args)) {
            $product->set_status(sanitize_key((string) $args['status']));
        }
        if (array_key_exists('manage_stock', $args)) {
            $product->set_manage_stock((bool) $args['manage_stock']);
        }
        if (array_key_exists('stock_quantity', $args)) {
            $product->set_stock_quantity((int) $args['stock_quantity']);
        }

        // WooCommerce saves a product without a status as published.
        \WPMCP\Tools\Content\Post_Status_Guard::assert('product', $product->get_status() ? $product->get_status() : 'publish', true);

        $id = $product->save();
        if (! $id) {
            throw new \RuntimeException('Could not create the product.');
        }

        $operation_id = Post_Creation_Snapshot::record('create-product', [(int) $id], $args, (string) ($args['session_id'] ?? 'default'));

        return ['operation_id' => $operation_id] + Product_View::detail(wc_get_product($id));
    }
}
