<?php

namespace WPMCP\Tools\WooCommerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create a simple WooCommerce product via the CRUD layer (WC_Product_Simple
 * setters + save()).
 *
 * Creation is exempt from Safe_Mutation: there is no prior state to snapshot,
 * and the created product can be removed with delete-product if it was a
 * mistake. Only a fixed set of writable fields is honored; anything else in
 * the args is ignored.
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

        $id = $product->save();
        if (! $id) {
            throw new \RuntimeException('Could not create the product.');
        }

        return Product_View::detail(wc_get_product($id));
    }
}
