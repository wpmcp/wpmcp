<?php

namespace WPMCP\Tools\WooCommerce;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Update a WooCommerce product variation's writable fields via the CRUD
 * layer (WC_Product_Variation setters + save()).
 *
 * A variation is a 'product_variation' post whose parent is the variable
 * product, and WooCommerce stores its price and stock as postmeta on that
 * post. So, exactly like Update_Product, this routes the write through
 * Safe_Mutation with object_type 'post' and the variation's own post id: the
 * existing post snapshot captures the full row (including post_parent, which
 * is what keeps the variation attached to its parent) plus ALL postmeta, and
 * rollback-operation restores the prior price and stock exactly through the
 * engine that already covers posts. Rollback_Service's post path finishes
 * with a WooCommerce refresh (transients, parent price sync, lookup table),
 * so the parent's derived price range follows the restored variation too.
 *
 * The mutation itself uses WC_Product_Variation setters (not raw
 * update_post_meta) so WooCommerce's derived fields (_price vs
 * _regular_price/_sale_price, _stock_status from quantity, the parent's
 * cached price range) stay correct: save() triggers the parent sync.
 *
 * Input rules, enforced before any snapshot is taken:
 *  - status: only 'publish' or 'private'. Those are the two statuses
 *    WooCommerce itself gives a variation; anything else either soft-deletes
 *    it ('trash') or hides it from the parent's children query entirely.
 *    Deleting a variation is a separate, gated tool.
 *  - stock_quantity: must be an integer. null is rejected rather than
 *    coerced to 0 (which WooCommerce would then turn into out-of-stock); to
 *    stop tracking a quantity, set manage_stock to false instead.
 *  - stock_status: must be one of wc_get_product_stock_status_options(),
 *    and is only accepted while stock is NOT managed on the variation,
 *    because WooCommerce re-derives it from stock_quantity on every save
 *    when stock is managed (on the variation or by its parent). Rejecting
 *    the call beats silently dropping the field.
 */
class Update_Variation
{
    private const ALLOWED_STATUSES = ['publish', 'private'];

    public function handle(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('A variation id is required.');
        }

        $variation = wc_get_product($id);
        if (! $variation instanceof \WC_Product_Variation) {
            throw new \RuntimeException('Variation not found (id ' . (int) $id . ' is not a product variation).');
        }

        $this->validate($variation, $args);

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-variation',
                'args'        => $args,
            ],
            function () use ($variation, $args): void {
                $this->apply_changes($variation, $args);
                if (! $variation->save()) {
                    throw new \RuntimeException('Could not update the variation.');
                }
            }
        );

        $fresh = wc_get_product($id);

        return array_merge(
            $fresh instanceof \WC_Product_Variation ? Variation_View::summary($fresh) : ['id' => $id],
            ['operation_id' => $out['operation_id']]
        );
    }

    /** Reject inputs WooCommerce would otherwise coerce or silently drop. */
    private function validate(\WC_Product_Variation $variation, array $args): void
    {
        if (array_key_exists('status', $args)) {
            $status = sanitize_key((string) $args['status']);
            if (! in_array($status, self::ALLOWED_STATUSES, true)) {
                throw new \InvalidArgumentException(
                    'status must be one of: ' . esc_html(implode(', ', self::ALLOWED_STATUSES)) . '.'
                );
            }
        }

        if (array_key_exists('stock_quantity', $args)) {
            $quantity = $args['stock_quantity'];
            if (! is_int($quantity) && ! (is_string($quantity) && preg_match('/^-?\d+$/', $quantity))) {
                throw new \InvalidArgumentException(
                    'stock_quantity must be an integer. To stop tracking a quantity, set manage_stock to false.'
                );
            }
        }

        if (array_key_exists('stock_status', $args)) {
            $status  = sanitize_key((string) $args['stock_status']);
            $options = function_exists('wc_get_product_stock_status_options')
                ? array_keys(wc_get_product_stock_status_options())
                : ['instock', 'outofstock', 'onbackorder'];
            if (! in_array($status, $options, true)) {
                throw new \InvalidArgumentException(
                    'stock_status must be one of: ' . esc_html(implode(', ', $options)) . '.'
                );
            }

            // get_manage_stock() is true, false or 'parent'; the last two
            // truthy values both mean WooCommerce derives stock_status itself.
            $managed = array_key_exists('manage_stock', $args)
                ? (bool) $args['manage_stock']
                : (bool) $variation->get_manage_stock();
            if ($managed) {
                throw new \InvalidArgumentException(
                    'stock_status is derived from stock_quantity while stock is managed (on the variation or by '
                    . 'its parent); set stock_quantity instead, or set manage_stock to false first.'
                );
            }
        }
    }

    /** Apply only the writable fields present in $args to the variation. */
    private function apply_changes(\WC_Product_Variation $variation, array $args): void
    {
        if (array_key_exists('regular_price', $args)) {
            $variation->set_regular_price((string) $args['regular_price']);
        }
        if (array_key_exists('sale_price', $args)) {
            $variation->set_sale_price((string) $args['sale_price']);
        }
        if (array_key_exists('sku', $args)) {
            $variation->set_sku(sanitize_text_field((string) $args['sku']));
        }
        if (array_key_exists('status', $args)) {
            $variation->set_status(sanitize_key((string) $args['status']));
        }
        if (array_key_exists('manage_stock', $args)) {
            $variation->set_manage_stock((bool) $args['manage_stock']);
        }
        if (array_key_exists('stock_quantity', $args)) {
            $variation->set_stock_quantity((int) $args['stock_quantity']);
        }
        if (array_key_exists('stock_status', $args)) {
            $variation->set_stock_status(sanitize_key((string) $args['stock_status']));
        }
    }
}
