<?php

namespace WPMCP\Tools\WooCommerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shape a WC_Product into a safe summary/detail row.
 *
 * Centralizes the mapping so list-products and get-product return a
 * consistent shape. Prices are returned as the raw stored strings (never
 * formatted with currency symbols), so callers can parse them back
 * losslessly and a rollback comparison is exact.
 */
class Product_View
{
    /** A compact row for listings. */
    public static function summary(\WC_Product $product): array
    {
        return [
            'id'            => $product->get_id(),
            'name'          => $product->get_name(),
            'sku'           => $product->get_sku(),
            'type'          => $product->get_type(),
            'status'        => $product->get_status(),
            'price'         => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price'    => $product->get_sale_price(),
            'stock_status'  => $product->get_stock_status(),
            'stock_quantity' => $product->get_stock_quantity(),
        ];
    }

    /** Full detail, extending the summary with descriptive fields. */
    public static function detail(\WC_Product $product): array
    {
        return array_merge(self::summary($product), [
            'description'       => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'manage_stock'      => $product->get_manage_stock(),
            'catalog_visibility' => $product->get_catalog_visibility(),
            'categories'        => self::term_names($product->get_id(), 'product_cat'),
            'tags'              => self::term_names($product->get_id(), 'product_tag'),
            'permalink'         => $product->get_permalink(),
        ]);
    }

    /** @return string[] */
    private static function term_names(int $product_id, string $taxonomy): array
    {
        $terms = wp_get_object_terms($product_id, $taxonomy, ['fields' => 'names']);
        return is_array($terms) ? array_values($terms) : [];
    }
}
