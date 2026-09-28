<?php

namespace WPMCP\Tools\WooCommerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * plan-product-import: the read-only first step of a product import. See
 * Product_Import_Plan for the row format, the per-row outcomes and what the
 * plan hash covers. Nothing is written and no image is fetched.
 */
class Plan_Product_Import
{
    public function handle(array $args): array
    {
        $plan = Product_Import_Plan::build($args);

        return array_merge($plan['public'], [
            'max_rows'          => Product_Import_Plan::max_rows(),
            'confirm_threshold' => Product_Import_Plan::confirm_threshold(),
            'next'              => 'Review the rows, then call apply-product-import with the same rows, mode and this plan_hash.',
        ]);
    }
}
