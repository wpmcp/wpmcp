<?php

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Full WooCommerce order snapshots and order creation rows (issue #292).
 *
 * 'wc_order_full' captures everything an order edit can change: the order's
 * own properties (status, customer, currency, payment method id and title,
 * customer note, every stored total), both addresses, and every order item
 * (line items, shipping, fees, coupons, taxes) with all of its item meta.
 * The existing 'wc_order' type keeps capturing the status only, for the
 * tools that only ever change the status.
 *
 * Storage split, so HPOS and the legacy post store both restore exactly:
 *  - order properties and addresses are read and written through WC_Order's
 *    CRUD getters and setters, which target whichever order store is
 *    active (and keep a synced store in step);
 *  - order items live in woocommerce_order_items and
 *    woocommerce_order_itemmeta in BOTH storage modes, so they are captured
 *    as raw rows and restored as raw rows, keeping every item id and meta id.
 *    Restoring through the item CRUD instead would give a deleted item a new
 *    id, and refunds and item-level integrations reference items by id.
 *
 * Order-level meta is deliberately not captured: no order write that takes
 * this snapshot sets order meta, and order meta is where payment gateways
 * keep customer and token references, which have no business sitting in the
 * snapshot ledger.
 *
 * 'wc_order_create' is the creation row for an order a tool created. Undoing
 * it is non-destructive, matching 'post_create': stock the order reduced is
 * returned, then the order is moved to the trash (its coupon usage is
 * released by WooCommerce's own trash handling). It is never hard-deleted.
 */
final class Wc_Order_Snapshot
{
    public const TYPE        = 'wc_order_full';
    public const CREATE_TYPE = 'wc_order_create';

    /** Scalar order properties captured and restored through the CRUD, in restore order. */
    private const PROPS = [
        'currency',
        'prices_include_tax',
        'customer_id',
        'payment_method',
        'payment_method_title',
        'customer_note',
        'discount_total',
        'discount_tax',
        'shipping_total',
        'shipping_tax',
        'cart_tax',
        'total',
    ];

    /** The order a WooCommerce id names, or null (refunds and other order types included). */
    public static function order(int $order_id): ?\WC_Order
    {
        if ($order_id <= 0 || ! function_exists('wc_get_order')) {
            return null;
        }
        $order = wc_get_order($order_id);
        return $order instanceof \WC_Order ? $order : null;
    }

    public static function capture(int $order_id): array
    {
        $order = self::order($order_id);
        if (null === $order) {
            return [
                'object_type' => self::TYPE,
                'object_id'   => $order_id,
                'data'        => [ 'exists' => false ],
            ];
        }

        $props = [];
        foreach (self::PROPS as $prop) {
            $props[ $prop ] = $order->{'get_' . $prop}('edit');
        }

        $data = $order->get_data();

        return [
            'object_type' => self::TYPE,
            'object_id'   => $order_id,
            'data'        => [
                'exists'    => true,
                'status'    => $order->get_status('edit'),
                'props'     => $props,
                'billing'   => (array) ($data['billing'] ?? []),
                'shipping'  => (array) ($data['shipping'] ?? []),
                'items'     => self::item_rows($order_id),
                'item_meta' => self::item_meta_rows($order_id),
            ],
        ];
    }

    /**
     * Put an order back to a 'wc_order_full' snapshot. Returns a warning
     * when nothing could be restored, else null.
     */
    public static function restore(array $snapshot): ?string
    {
        $data     = (array) ($snapshot['data'] ?? []);
        $order_id = (int) ($snapshot['object_id'] ?? 0);

        if (empty($data['exists'])) {
            return null; // Nothing existed to restore.
        }
        if (null === self::order($order_id)) {
            return "Order {$order_id} no longer exists (or WooCommerce is inactive), so its snapshot could not be restored.";
        }

        // Items first, as raw rows, then flush every cache that could hand
        // the CRUD a stale item list, then the order itself through the CRUD
        // (loaded fresh, with no items in memory, so its save cannot write
        // stale items back over the restored rows).
        $touched = self::restore_items($order_id, (array) ($data['items'] ?? []), (array) ($data['item_meta'] ?? []));
        self::flush_caches($order_id, $touched);

        $order = self::order($order_id);
        if (null === $order) {
            return "Order {$order_id} could not be reloaded after its items were restored.";
        }

        foreach ((array) ($data['props'] ?? []) as $prop => $value) {
            if (in_array($prop, self::PROPS, true) && is_callable([ $order, 'set_' . $prop ])) {
                $order->{'set_' . $prop}($value);
            }
        }
        foreach (['billing', 'shipping'] as $type) {
            foreach ((array) ($data[ $type ] ?? []) as $key => $value) {
                $setter = 'set_' . $type . '_' . $key;
                if (is_callable([ $order, $setter ])) {
                    $order->{$setter}($value);
                }
            }
        }
        $status = (string) ($data['status'] ?? '');
        if ('' !== $status && $order->get_status('edit') !== $status) {
            $order->set_status($status);
        }
        $order->save();
        self::flush_caches($order_id, $touched);

        return null;
    }

    /**
     * Write the creation row for an order a tool just created. If the row
     * cannot be written, the order is removed again and the error rethrown:
     * a creation is never left behind without its undo point.
     */
    public static function record_creation(int $order_id, string $tool_name, array $args, string $session_id): string
    {
        $order   = self::order($order_id);
        $created = $order && $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;

        $operation_id = wp_generate_uuid4();
        try {
            Snapshot_Store::save(
                $operation_id,
                '' === $session_id ? 'default' : $session_id,
                [
                    'object_type' => self::CREATE_TYPE,
                    'object_id'   => $order_id,
                    'data'        => [ 'date_created' => $created ],
                ],
                $tool_name,
                hash('sha256', (string) wp_json_encode($args))
            );
        } catch (\Throwable $e) {
            if ($order) {
                if (function_exists('wc_maybe_increase_stock_levels')) {
                    wc_maybe_increase_stock_levels($order_id);
                }
                $order->delete(true);
            }
            throw $e;
        }
        Operation_Context::note($operation_id);
        Snapshot_Store::prune();

        return $operation_id;
    }

    /**
     * Undo a 'wc_order_create' row: return any stock the order reduced, then
     * move it to the trash. date_created is fixed at creation, so a mismatch
     * means a different order holds the id; it is left untouched. Returns a
     * warning, or null.
     */
    public static function undo_creation(array $snapshot): ?string
    {
        $order_id = (int) ($snapshot['object_id'] ?? 0);
        $order    = self::order($order_id);
        if (null === $order) {
            return null; // Already gone (or WooCommerce inactive): nothing to undo here.
        }

        $created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
        if ((int) ($snapshot['data']['date_created'] ?? 0) !== $created) {
            return "Order {$order_id} is not the order this operation created (the id was reclaimed); it was left untouched.";
        }
        if ('trash' === $order->get_status('edit')) {
            return null;
        }

        if (function_exists('wc_maybe_increase_stock_levels')) {
            wc_maybe_increase_stock_levels($order_id);
        }

        $order = self::order($order_id);
        if (null !== $order) {
            $order->delete(false);
        }

        $after = self::order($order_id);
        if (null !== $after && 'trash' !== $after->get_status('edit')) {
            return "Order {$order_id} created by this operation could not be moved to the trash; it was left in place.";
        }
        return null;
    }

    /** @return array<int, array<string, mixed>> */
    private static function item_rows(int $order_id): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- snapshot capture must read the live rows, not a cache.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT order_item_id, order_item_name, order_item_type, order_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id = %d ORDER BY order_item_id",
            $order_id
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /** @return array<int, array<string, mixed>> */
    private static function item_meta_rows(int $order_id): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- snapshot capture must read the live rows, not a cache.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.meta_id, m.order_item_id, m.meta_key, m.meta_value
             FROM {$wpdb->prefix}woocommerce_order_itemmeta m
             INNER JOIN {$wpdb->prefix}woocommerce_order_items i ON i.order_item_id = m.order_item_id
             WHERE i.order_id = %d ORDER BY m.meta_id",
            $order_id
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Make the order's item rows exactly the captured ones: items added
     * since are deleted with their meta, captured items are re-written at
     * their own ids, and each captured item's meta is replaced by the
     * captured rows at their own meta ids.
     *
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array<string, mixed>> $meta
     * @return int[] every item id touched, for cache invalidation
     */
    private static function restore_items(int $order_id, array $items, array $meta): array
    {
        global $wpdb;
        $items_table = $wpdb->prefix . 'woocommerce_order_items';
        $meta_table  = $wpdb->prefix . 'woocommerce_order_itemmeta';

        $captured = [];
        foreach ($items as $row) {
            $id = (int) ($row['order_item_id'] ?? 0);
            if ($id > 0) {
                $captured[ $id ] = $row;
            }
        }
        $current = array_map('intval', array_column(self::item_rows($order_id), 'order_item_id'));

        foreach (array_diff($current, array_keys($captured)) as $stale_id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- restoring raw item rows; caches are flushed afterwards.
            $wpdb->delete($meta_table, [ 'order_item_id' => $stale_id ], [ '%d' ]);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
            $wpdb->delete($items_table, [ 'order_item_id' => $stale_id ], [ '%d' ]);
        }

        foreach ($captured as $id => $row) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
            $wpdb->replace(
                $items_table,
                [
                    'order_item_id'   => $id,
                    'order_item_name' => (string) ($row['order_item_name'] ?? ''),
                    'order_item_type' => (string) ($row['order_item_type'] ?? ''),
                    'order_id'        => $order_id,
                ],
                [ '%d', '%s', '%s', '%d' ]
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
            $wpdb->delete($meta_table, [ 'order_item_id' => $id ], [ '%d' ]);
        }

        foreach ($meta as $row) {
            $item_id = (int) ($row['order_item_id'] ?? 0);
            if (! isset($captured[ $item_id ])) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
            $wpdb->insert(
                $meta_table,
                [
                    'meta_id'       => (int) ($row['meta_id'] ?? 0),
                    'order_item_id' => $item_id,
                    'meta_key'      => (string) ($row['meta_key'] ?? ''), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- restoring a raw row, not querying by key.
                    'meta_value'    => $row['meta_value'] ?? null, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
                ],
                [ '%d', '%d', '%s', '%s' ]
            );
        }

        return array_values(array_unique(array_merge($current, array_keys($captured))));
    }

    /** @param int[] $item_ids */
    private static function flush_caches(int $order_id, array $item_ids): void
    {
        foreach ($item_ids as $item_id) {
            wp_cache_delete('item-' . $item_id, 'order-items');
            wp_cache_delete($item_id, 'order_item_meta');
        }
        wp_cache_delete('order-items-' . $order_id, 'orders');
        wp_cache_delete('order-needs-processing-' . $order_id, 'orders');

        $order_cache = '\\Automattic\\WooCommerce\\Caches\\OrderCache';
        if (function_exists('wc_get_container') && class_exists($order_cache)) {
            try {
                wc_get_container()->get($order_cache)->remove($order_id);
            } catch (\Throwable $e) {
                // A missing cache service only means there is nothing cached to drop.
            }
        }
        clean_post_cache($order_id);
    }
}
