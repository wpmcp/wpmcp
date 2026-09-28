<?php

namespace WPMCP\Tools\WooCommerce;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Update a WooCommerce product's writable fields via the CRUD layer
 * (WC_Product setters + save()).
 *
 * A product is a 'product' post, and WooCommerce stores its price, stock, and
 * descriptive data as postmeta on that post. So this routes the write through
 * Safe_Mutation with object_type 'post' and the product's post id: the
 * existing post snapshot captures the full row plus ALL postmeta (including
 * _regular_price, _price, _stock, _stock_status), and a rollback-operation
 * restores the prior price and stock exactly, through the same engine that
 * already covers posts. No WooCommerce-specific snapshot logic is needed.
 *
 * The mutation itself uses WC_Product setters (not raw update_post_meta) so
 * WooCommerce's own derived fields (e.g. _price vs _regular_price/_sale_price,
 * _stock_status from quantity) stay correct.
 */
class Update_Product
{
    public function handle(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('A product id is required.');
        }

        $product = wc_get_product($id);
        if (! $product) {
            throw new \RuntimeException('Product not found.');
        }

        $this->validate($product, $args);

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-product',
                'args'        => $args,
            ],
            function () use ($product, $args): void {
                $this->apply_changes($product, $args);
                if (! $product->save()) {
                    throw new \RuntimeException('Could not update the product.');
                }
            }
        );

        return array_merge(
            Product_View::detail(wc_get_product($id)),
            ['operation_id' => $out['operation_id']]
        );
    }

    /**
     * Reject a stock write WooCommerce would silently overwrite (issue #195).
     *
     * stock_quantity must be an integer: null would be coerced to 0, which
     * WooCommerce then turns into out-of-stock. stock_status is only honoured
     * while stock is not managed on the product, and never on a variable
     * product, whose status WooCommerce derives from its variations on every
     * sync; accepting the field there would report success for a value that
     * is gone by the next page load.
     */
    private function validate(\WC_Product $product, array $args): void
    {
        if (array_key_exists('stock_quantity', $args)) {
            $quantity = $args['stock_quantity'];
            if (! is_int($quantity) && ! (is_string($quantity) && preg_match('/^-?\d+$/', $quantity))) {
                throw new \InvalidArgumentException(
                    'stock_quantity must be an integer. To stop tracking a quantity, set manage_stock to false.'
                );
            }
        }

        if (! array_key_exists('stock_status', $args)) {
            return;
        }

        $status  = sanitize_key((string) $args['stock_status']);
        $options = function_exists('wc_get_product_stock_status_options')
            ? array_keys(wc_get_product_stock_status_options())
            : ['instock', 'outofstock', 'onbackorder'];
        if (! in_array($status, $options, true)) {
            throw new \InvalidArgumentException('stock_status must be one of: ' . esc_html(implode(', ', $options)) . '.');
        }
        if ($product->is_type('variable')) {
            throw new \InvalidArgumentException(
                'A variable product\'s stock_status is derived from its variations; set it on the variations with update-variation.'
            );
        }
        $managed = array_key_exists('manage_stock', $args) ? (bool) $args['manage_stock'] : (bool) $product->get_manage_stock();
        if ($managed) {
            throw new \InvalidArgumentException(
                'stock_status is derived from stock_quantity while stock is managed; set stock_quantity instead, or set manage_stock to false first.'
            );
        }
    }

    /** Apply only the writable fields present in $args to the product object. */
    private function apply_changes(\WC_Product $product, array $args): void
    {
        if (array_key_exists('name', $args)) {
            $product->set_name((string) $args['name']);
        }
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
        if (array_key_exists('stock_status', $args)) {
            $product->set_stock_status(sanitize_key((string) $args['stock_status']));
        }
    }
}
