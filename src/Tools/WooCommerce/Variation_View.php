<?php

namespace WPMCP\Tools\WooCommerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shape a WC_Product_Variation into a safe summary row.
 *
 * Mirrors Product_View: prices are the raw stored strings (never formatted
 * with currency symbols) so callers can parse them back losslessly and a
 * rollback comparison is exact. The attributes map is the variation's
 * defining attribute selections (e.g. { "pa_size": "large" }), which is what
 * an agent needs to tell sibling variations apart.
 */
class Variation_View
{
    /** A compact row for listings and write confirmations. */
    public static function summary(\WC_Product_Variation $variation): array
    {
        return [
            'id'             => $variation->get_id(),
            'parent_id'      => $variation->get_parent_id(),
            'sku'            => $variation->get_sku(),
            'status'         => $variation->get_status(),
            'attributes'     => $variation->get_attributes(),
            'price'          => $variation->get_price(),
            'regular_price'  => $variation->get_regular_price(),
            'sale_price'     => $variation->get_sale_price(),
            'manage_stock'   => $variation->get_manage_stock(),
            'stock_status'   => $variation->get_stock_status(),
            'stock_quantity' => $variation->get_stock_quantity(),
        ];
    }
}
