<?php

namespace WPMCP\Tools\WooCommerce;

use WPMCP\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Update up to MAX_ITEMS products and variations in one call, reporting a
 * separate outcome for every item instead of one pass/fail for the batch.
 *
 * Each item is dispatched to update-product or update-variation (chosen by
 * what the id turns out to be), so it gets exactly the validation, the
 * Safe_Mutation snapshot and the reply shape of the single-item tool. One
 * item failing does not stop the others: its error is recorded and the batch
 * moves on, because on a live store "the other 49 prices changed and this one
 * did not, here is why" is more useful than an all-or-nothing that leaves the
 * agent guessing which items were touched.
 *
 * The batch can never do more than the single-item tools would allow: each
 * item is re-checked against the target ability's own gates (capability,
 * governance toggles and filters, identity scope, memory blocks) through
 * Registrar::is_permitted(), so a site that switched off update-variation
 * has switched it off here too.
 *
 * Every item runs under one session_id (generated when the caller passes
 * none), and that id is returned, so rollback-session undoes the whole batch
 * and nothing else, while each item's own operation_id undoes just that
 * item.
 */
class Bulk_Update_Products
{
    public const MAX_ITEMS = 50;

    public function handle(array $args): array
    {
        $items = $args['items'] ?? null;
        if (! is_array($items) || [] === $items) {
            throw new \InvalidArgumentException('items must be a non-empty array of {id, ...fields}.');
        }
        if (count($items) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException('At most ' . (int) self::MAX_ITEMS . ' items per call.');
        }

        $seen = [];
        foreach ($items as $index => $item) {
            $id = is_array($item) ? (int) ($item['id'] ?? 0) : 0;
            if ($id <= 0) {
                throw new \InvalidArgumentException('Item ' . (int) $index . ' needs a positive integer id.');
            }
            if (isset($seen[ $id ])) {
                throw new \InvalidArgumentException('Id ' . (int) $id . ' appears more than once; merge its fields into one item.');
            }
            $seen[ $id ] = true;
        }

        $session_id = isset($args['session_id']) && '' !== (string) $args['session_id']
            ? (string) $args['session_id']
            : 'bulk-update-products-' . wp_generate_uuid4();

        $results = [];
        $updated = 0;
        foreach ($items as $item) {
            $item['session_id'] = $session_id;
            $id                 = (int) $item['id'];
            try {
                $product = wc_get_product($id);
                if (! $product) {
                    throw new \RuntimeException('Product not found.');
                }
                $is_variation = $product instanceof \WC_Product_Variation;
                $this->assert_permitted($is_variation ? 'wpmcp/update-variation' : 'wpmcp/update-product', $item);
                $handler = $is_variation ? new Update_Variation() : new Update_Product();
                $reply   = $handler->handle($item);

                $results[] = [
                    'id'           => $id,
                    'ok'           => true,
                    'kind'         => $is_variation ? 'variation' : 'product',
                    'operation_id' => $reply['operation_id'],
                    'price'        => $reply['price'] ?? null,
                    'stock_status' => $reply['stock_status'] ?? null,
                    'stock_quantity' => $reply['stock_quantity'] ?? null,
                ];
                $updated++;
            } catch (\Throwable $e) {
                $results[] = [
                    'id'    => $id,
                    'ok'    => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'session_id' => $session_id,
            'updated'    => $updated,
            'failed'     => count($items) - $updated,
            'results'    => $results,
        ];
    }

    /** Refuse an item the single-item ability would refuse. */
    private function assert_permitted(string $ability_name, array $item): void
    {
        $registrar = Plugin::instance()->registrar();
        $ability   = $registrar->get($ability_name);
        if (null === $ability || ! $registrar->is_permitted($ability, $item)) {
            throw new \RuntimeException(
                esc_html($ability_name) . ' is not permitted for this user on this site, so this item was skipped.'
            );
        }
    }
}
