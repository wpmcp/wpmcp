<?php

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Bookings snapshots (issue #659), and the one place wpmcp
 * reaches the Bookings API.
 *
 * A booking is a 'wc_booking' post: its status is the post status, its
 * order the post parent, its customer the post author, and everything else
 * lives in the post meta keys WC_Booking_Data_Store maps to its props
 * (META below, as Bookings 3.14.0 names them; start and end are local
 * wall-clock times stored as 'YmdHis'). Reads use that storage directly;
 * writes go through the plugin, so its own status rules, emails, order sync
 * and scheduled reminders run as they do from wp-admin.
 *
 * Three snapshot types:
 *
 *  - 'wc_booking': one booking's post fields and every mapped meta key,
 *    plus the status of the order it belongs to. A status change can move
 *    that order (Bookings cancels a single-booking order with its booking,
 *    and marks it processing or pending on paid or unpaid), so the restore
 *    puts the order status back first, then writes the booking's own fields
 *    back directly (no status transition, so no emails on undo), then asks
 *    Bookings to reschedule its reminder and completion events and flush its
 *    availability caches;
 *  - 'wc_booking_create': the creation row for a booking a tool created;
 *    undoing it deletes that booking (when its creation time still matches);
 *  - 'wc_booking_rules': a bookable product's own availability rules (the
 *    _wc_booking_availability meta), restored through the product so
 *    Bookings clears its cached availability.
 */
final class Wc_Booking_Snapshot
{
    public const TYPE        = 'wc_booking';
    public const CREATE_TYPE = 'wc_booking_create';
    public const RULES_TYPE  = 'wc_booking_rules';

    /** The post type Bookings stores bookings in. */
    public const POST_TYPE = 'wc_booking';

    /** Booking meta keys and the WC_Booking prop each holds (WC_Booking_Data_Store, Bookings 3.14.0). */
    public const META = [
        '_booking_all_day'                => 'all_day',
        '_booking_cost'                   => 'cost',
        '_booking_customer_id'            => 'customer_id',
        '_booking_woo_customer_id'        => 'woo_customer_id',
        '_booking_order_item_id'          => 'order_item_id',
        '_booking_parent_id'              => 'parent_id',
        '_booking_persons'                => 'person_counts',
        '_booking_product_id'             => 'product_id',
        '_booking_resource_id'            => 'resource_id',
        '_booking_start'                  => 'start',
        '_booking_end'                    => 'end',
        '_wc_bookings_gcalendar_event_id' => 'google_calendar_event_id',
        '_local_timezone'                 => 'local_timezone',
        '_booking_date_cancelled'         => 'date_cancelled',
        '_booking_note'                   => 'note',
        '_booking_attendance_status'      => 'attendance_status',
    ];

    /** The product meta key holding a bookable product's own availability rules. */
    public const RULES_META = '_wc_booking_availability';

    /**
     * Booking statuses (get_wc_booking_statuses() in Bookings 3.14.0: the
     * user-facing ones plus the cart states), used when the plugin cannot
     * be asked.
     */
    private const STATUSES = ['unpaid', 'pending-confirmation', 'confirmed', 'paid', 'cancelled', 'complete', 'in-cart', 'was-in-cart'];

    /** @var object|null Test seam: stands in for the Bookings API. */
    private static $double = null;

    /**
     * Stand in for the Bookings API in the stub-backed suite: an object with
     * product(int): ?WC_Product, resource_ids(WC_Product): int[],
     * plan(WC_Product, array): array|WP_Error, make(array, string): int,
     * set_status(int, string): bool, set_rules(int, array): void,
     * slots(array): array, remove(int): void and refresh(int): void.
     * Ignored outside the test suite.
     *
     * @param object|null $double
     */
    public static function set_double_for_tests($double): void
    {
        if (! defined('WPMCP_TESTING') || ! WPMCP_TESTING) {
            return;
        }
        self::$double = $double;
    }

    /** Whether WooCommerce Bookings is loaded (its main class, and its functions once WooCommerce is there). */
    public static function is_active(): bool
    {
        if (null !== self::$double) {
            return true;
        }
        return class_exists('WC_Bookings') && function_exists('get_wc_booking');
    }

    // ------------------------------------------------------------ storage

    /**
     * One booking as stored: post fields and every mapped meta key (null when
     * the key is absent), or null when the id names no booking.
     *
     * @return array{id: int, status: string, order_id: int, author: int, date_created: string, meta: array<string, mixed>}|null
     */
    public static function row(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        clean_post_cache($id);
        $post = get_post($id);
        if (! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type) {
            return null;
        }
        $meta = [];
        foreach (array_keys(self::META) as $key) {
            $meta[ $key ] = metadata_exists('post', $id, $key) ? get_post_meta($id, $key, true) : null;
        }
        return [
            'id'           => $id,
            'status'       => (string) $post->post_status,
            'order_id'     => (int) $post->post_parent,
            'author'       => (int) $post->post_author,
            'date_created' => (string) $post->post_date_gmt,
            'meta'         => $meta,
        ];
    }

    /**
     * Booking statuses this site knows.
     *
     * @return string[]
     */
    public static function statuses(): array
    {
        if (null === self::$double && function_exists('get_wc_booking_statuses')) {
            $known = array_merge(
                (array) get_wc_booking_statuses(null),
                (array) get_wc_booking_statuses('user'),
                (array) get_wc_booking_statuses('cancel'),
                ['was-in-cart']
            );
            return array_values(array_unique(array_map('strval', $known)));
        }
        return self::STATUSES;
    }

    /** The bookable product an id names, or null (any other product included). */
    public static function product(int $id): ?\WC_Product
    {
        if ($id <= 0) {
            return null;
        }
        if (null !== self::$double) {
            $found = self::$double->product($id);
        } elseif (self::is_active() && function_exists('is_wc_booking_product')) {
            $found = wc_get_product($id);
            $found = is_wc_booking_product($found) ? $found : null;
        } else {
            return null;
        }
        return $found instanceof \WC_Product ? $found : null;
    }

    /**
     * The product types Bookings adds (wc_bookings_get_product_types()).
     *
     * @return string[]
     */
    public static function product_types(): array
    {
        if (null === self::$double && function_exists('wc_bookings_get_product_types')) {
            return array_values(array_map('strval', (array) wc_bookings_get_product_types()));
        }
        return ['booking'];
    }

    /**
     * The resource ids a bookable product offers.
     *
     * @return int[]
     */
    public static function resource_ids(\WC_Product $product): array
    {
        if (null !== self::$double) {
            $ids = (array) self::$double->resource_ids($product);
        } else {
            $ids = method_exists($product, 'get_resource_ids') ? (array) $product->get_resource_ids() : [];
        }
        return array_values(array_map('intval', $ids));
    }

    // -------------------------------------------------------------- calls

    /**
     * Validate and price a new booking the way the plugin's own booking
     * form does: wc_bookings_get_posted_data() turns the booking form fields
     * into its booking data, and calculate_booking_cost() checks the block
     * is bookable (is_bookable(): duration, persons, resources and
     * availability) before pricing it.
     *
     * @param array<string, mixed> $posted wc_bookings_field_* form fields.
     * @return array{data: array<string, mixed>, cost: float|int|string}|\WP_Error
     */
    public static function plan(\WC_Product $product, array $posted)
    {
        if (null !== self::$double) {
            return self::$double->plan($product, $posted);
        }
        if (! function_exists('wc_bookings_get_posted_data') || ! class_exists('WC_Bookings_Cost_Calculation')) {
            return new \WP_Error('bookings_inactive', 'WooCommerce Bookings is not loaded.');
        }
        try {
            $data = wc_bookings_get_posted_data($posted, $product);
            $cost = \WC_Bookings_Cost_Calculation::calculate_booking_cost($data, $product);
        } catch (\Throwable $e) {
            return new \WP_Error('booking_refused', $e->getMessage());
        }
        if (is_wp_error($cost)) {
            return $cost;
        }
        return [ 'data' => (array) $data, 'cost' => $cost ];
    }

    /**
     * Create a booking from WC_Booking props with a status, as the plugin's
     * manual booking screen does. Returns its id, or 0.
     *
     * @param array<string, mixed> $props
     */
    public static function make(array $props, string $status): int
    {
        if (null !== self::$double) {
            return (int) self::$double->make($props, $status);
        }
        if (! class_exists('WC_Booking')) {
            return 0;
        }
        $booking = new \WC_Booking($props);
        $booking->set_status($status);
        return (int) $booking->save();
    }

    /** Move a booking to a status through WC_Booking::update_status(), so its hooks and emails run. */
    public static function set_status(int $id, string $status): bool
    {
        if (null !== self::$double) {
            return (bool) self::$double->set_status($id, $status);
        }
        $booking = function_exists('get_wc_booking') ? get_wc_booking($id) : false;
        if (! $booking) {
            return false;
        }
        return (bool) $booking->update_status($status);
    }

    /**
     * Replace a bookable product's own availability rules through the
     * product, so Bookings saves them its way and clears cached availability.
     *
     * @param array<int, array<string, mixed>> $rules
     */
    public static function set_rules(int $product_id, array $rules): void
    {
        if (null !== self::$double) {
            self::$double->set_rules($product_id, $rules);
            return;
        }
        $product = function_exists('get_wc_product_booking') ? get_wc_product_booking($product_id) : false;
        if (! $product) {
            return;
        }
        $product->set_availability($rules);
        $product->save();
    }

    /**
     * Bookable slots, from the plugin's own slots endpoint
     * (wc-bookings/v1/products/slots), dispatched in-process.
     *
     * @param array<string, mixed> $query product_ids, min_date, max_date, resource_ids, hide_unavailable, page, limit.
     * @return array{records: array<int, array<string, mixed>>, count: int}|\WP_Error
     */
    public static function slots(array $query)
    {
        if (null !== self::$double) {
            return self::$double->slots($query);
        }
        $request = new \WP_REST_Request('GET', '/wc-bookings/v1/products/slots');
        foreach ($query as $key => $value) {
            $request->set_param($key, $value);
        }
        $response = rest_do_request($request);
        if ($response->is_error()) {
            return $response->as_error();
        }
        $data = (array) $response->get_data();
        return [
            'records' => array_values((array) ($data['records'] ?? [])),
            'count'   => (int) ($data['count'] ?? 0),
        ];
    }

    /** Delete a booking for good, through the plugin (its delete hooks and cache flush run). */
    public static function remove(int $id): void
    {
        if (null !== self::$double) {
            self::$double->remove($id);
            return;
        }
        $booking = function_exists('get_wc_booking') ? get_wc_booking($id) : false;
        if ($booking) {
            $booking->delete(true);
        } else {
            wp_delete_post($id, true);
        }
        wp_clear_scheduled_hook('wc-booking-reminder', [ $id ]);
        wp_clear_scheduled_hook('wc-booking-complete', [ $id ]);
    }

    /**
     * After a booking's fields were written back directly: let Bookings
     * schedule (or clear) its reminder and completion events from them, and
     * flush the availability it cached.
     */
    public static function refresh(int $id): void
    {
        if (null !== self::$double) {
            self::$double->refresh($id);
            return;
        }
        $booking = function_exists('get_wc_booking') ? get_wc_booking($id) : false;
        if (! $booking) {
            return;
        }
        $booking->schedule_events();
        if (class_exists('WC_Cache_Helper')) {
            \WC_Cache_Helper::get_transient_version('bookings', true);
        }
        if (class_exists('WC_Bookings_Cache')) {
            \WC_Bookings_Cache::flush_all_booking_connected_transients($booking);
        }
    }

    // ---------------------------------------------------------- snapshots

    public static function capture(int $id): array
    {
        $row = self::row($id);
        if (null === $row) {
            return [
                'object_type' => self::TYPE,
                'object_id'   => $id,
                'data'        => [ 'exists' => false ],
            ];
        }
        $order = $row['order_id'] > 0 && function_exists('wc_get_order') ? wc_get_order($row['order_id']) : null;

        return [
            'object_type' => self::TYPE,
            'object_id'   => $id,
            'data'        => [
                'exists'       => true,
                'status'       => $row['status'],
                'order_id'     => $row['order_id'],
                'author'       => $row['author'],
                'meta'         => $row['meta'],
                'order_status' => $order instanceof \WC_Order ? (string) $order->get_status('edit') : null,
            ],
        ];
    }

    /**
     * Put a booking back to a 'wc_booking' snapshot. Returns a warning when
     * part of it could not be restored, else null.
     */
    public static function restore(array $snapshot): ?string
    {
        global $wpdb;

        $data = (array) ($snapshot['data'] ?? []);
        $id   = (int) ($snapshot['object_id'] ?? 0);
        if (empty($data['exists'])) {
            return null;
        }
        if (null === self::row($id)) {
            return "Booking {$id} no longer exists, so its snapshot could not be restored.";
        }

        $warnings = [];

        // The order first: its status hooks may move the booking, which is
        // written back right after.
        $order_id = (int) ($data['order_id'] ?? 0);
        $wanted   = $data['order_status'] ?? null;
        if ($order_id > 0 && is_string($wanted) && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order instanceof \WC_Order && $order->get_status('edit') !== $wanted) {
                try {
                    $order->update_status($wanted, 'Restored by a WP MCP rollback of booking #' . $id . '.');
                } catch (\Throwable $e) {
                    $warnings[] = "Order {$order_id}'s status could not be put back: " . $e->getMessage();
                }
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a restore writes the stored fields without a status transition (no emails on undo); the post cache is cleaned below.
        $written = $wpdb->update(
            $wpdb->posts,
            [
                'post_status' => (string) $data['status'],
                'post_parent' => $order_id,
                'post_author' => (int) ($data['author'] ?? 0),
            ],
            [ 'ID' => $id ]
        );
        if (false === $written) {
            $warnings[] = "Booking {$id}'s status could not be put back.";
        }
        clean_post_cache($id);

        foreach ((array) ($data['meta'] ?? []) as $key => $value) {
            if (! array_key_exists((string) $key, self::META)) {
                continue;
            }
            if (null === $value) {
                delete_post_meta($id, (string) $key);
            } else {
                update_post_meta($id, (string) $key, wp_slash($value));
            }
        }
        clean_post_cache($id);

        self::refresh($id);

        return [] === $warnings ? null : implode(' ', $warnings);
    }

    /**
     * Write the creation row for a booking a tool just created. If the row
     * cannot be written the booking is deleted again and the error rethrown.
     *
     * @param array<string, mixed> $args
     */
    public static function record_creation(int $id, string $tool_name, array $args, string $session_id): string
    {
        $row          = self::row($id);
        $operation_id = wp_generate_uuid4();
        try {
            Snapshot_Store::save(
                $operation_id,
                '' === $session_id ? 'default' : $session_id,
                [
                    'object_type' => self::CREATE_TYPE,
                    'object_id'   => $id,
                    'data'        => [ 'date_created' => (string) ($row['date_created'] ?? '') ],
                ],
                $tool_name,
                hash('sha256', (string) wp_json_encode($args))
            );
        } catch (\Throwable $e) {
            self::remove($id);
            throw $e;
        }
        Operation_Context::note($operation_id);
        Snapshot_Store::prune();

        return $operation_id;
    }

    /** Undo a 'wc_booking_create' row: delete the booking it made. Returns a warning, or null. */
    public static function undo_creation(array $snapshot): ?string
    {
        $id  = (int) ($snapshot['object_id'] ?? 0);
        $row = self::row($id);
        if (null === $row) {
            return null; // Already gone: nothing to undo.
        }
        if ((string) ($snapshot['data']['date_created'] ?? '') !== $row['date_created']) {
            return "Booking {$id} is not the booking this operation created (the id was reclaimed); it was left untouched.";
        }
        self::remove($id);
        return null === self::row($id) ? null : "Booking {$id} created by this operation could not be deleted; it was left in place.";
    }

    /** A bookable product's own availability rules as a 'wc_booking_rules' snapshot. */
    public static function capture_rules(int $product_id): array
    {
        $exists = $product_id > 0 && metadata_exists('post', $product_id, self::RULES_META);
        return [
            'object_type' => self::RULES_TYPE,
            'object_id'   => $product_id,
            'data'        => [
                'exists' => $exists,
                'rules'  => $exists ? get_post_meta($product_id, self::RULES_META, true) : null,
            ],
        ];
    }

    /** Put a bookable product's availability rules back. Returns a warning, or null. */
    public static function restore_rules(array $snapshot): ?string
    {
        $id   = (int) ($snapshot['object_id'] ?? 0);
        $data = (array) ($snapshot['data'] ?? []);
        if (null === self::product($id)) {
            return "Product {$id} is no longer a bookable product (or WooCommerce Bookings is inactive), so its availability rules could not be restored.";
        }
        $rules = $data['rules'] ?? null;
        self::set_rules($id, is_array($rules) ? $rules : []);
        if (empty($data['exists'])) {
            delete_post_meta($id, self::RULES_META);
        }
        return null;
    }
}
