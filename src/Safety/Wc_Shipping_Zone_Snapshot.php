<?php

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Whole shipping zone snapshots and zone creation rows (issue #292).
 *
 * 'wc_shipping_zone' captures everything a zone or method write can change,
 * as raw rows so every id survives a restore:
 *  - the zone row (woocommerce_shipping_zones: name, order);
 *  - every location row (woocommerce_shipping_zone_locations);
 *  - every method instance row (woocommerce_shipping_zone_methods: method
 *    id, order, enabled flag);
 *  - each method instance's settings option
 *    (woocommerce_{method}_{instance}_settings), with whether it existed and
 *    its autoload flag.
 * Zone 0, "Locations not covered by your other zones", has no zone row and no
 * locations, only methods, and is snapshotted the same way.
 *
 * Restoring puts the zone back exactly: a deleted zone returns at its own id
 * with its locations, methods (at their own instance ids) and settings; a
 * method added since is removed along with its settings option; a settings
 * option that did not exist is deleted again. Method and location rows are
 * reused by id, because orders record the shipping instance id they were
 * charged under.
 *
 * 'wc_shipping_zone_create' is the creation row for a zone a tool created.
 * Shipping zones are store configuration, not content, so undoing a create
 * deletes the zone (with its methods and their settings) through
 * WooCommerce's own zone data store; there is no trash to move it to.
 */
final class Wc_Shipping_Zone_Snapshot
{
    public const TYPE        = 'wc_shipping_zone';
    public const CREATE_TYPE = 'wc_shipping_zone_create';

    /** The instance settings option WooCommerce keeps for one method instance. */
    public static function option_name(string $method_id, int $instance_id): string
    {
        return 'woocommerce_' . $method_id . '_' . $instance_id . '_settings';
    }

    /** Whether a zone id names an existing zone (zone 0 always exists). */
    public static function exists(int $zone_id): bool
    {
        return 0 === $zone_id || null !== self::zone_row($zone_id);
    }

    public static function capture(int $zone_id): array
    {
        $zone = $zone_id > 0 ? self::zone_row($zone_id) : null;
        if ($zone_id > 0 && null === $zone) {
            return [
                'object_type' => self::TYPE,
                'object_id'   => $zone_id,
                'data'        => [ 'exists' => false ],
            ];
        }

        $methods = self::method_rows($zone_id);
        $options = [];
        foreach ($methods as $method) {
            $name             = self::option_name((string) $method['method_id'], (int) $method['instance_id']);
            $options[ $name ] = self::option_state($name);
        }

        return [
            'object_type' => self::TYPE,
            'object_id'   => $zone_id,
            'data'        => [
                'exists'    => true,
                'zone'      => $zone,
                'locations' => self::location_rows($zone_id),
                'methods'   => $methods,
                'options'   => $options,
            ],
        ];
    }

    /**
     * Put a zone back to a 'wc_shipping_zone' snapshot. Returns a warning,
     * or null.
     */
    public static function restore(array $snapshot): ?string
    {
        global $wpdb;

        $data    = (array) ($snapshot['data'] ?? []);
        $zone_id = (int) ($snapshot['object_id'] ?? 0);
        if (empty($data['exists'])) {
            return null; // The zone did not exist when captured; nothing to put back.
        }

        $zones     = $wpdb->prefix . 'woocommerce_shipping_zones';
        $locations = $wpdb->prefix . 'woocommerce_shipping_zone_locations';
        $methods   = $wpdb->prefix . 'woocommerce_shipping_zone_methods';

        if ($zone_id > 0 && is_array($data['zone'] ?? null)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- restoring the raw zone row at its own id; WooCommerce's caches are invalidated below.
            $wpdb->replace(
                $zones,
                [
                    'zone_id'    => $zone_id,
                    'zone_name'  => (string) ($data['zone']['zone_name'] ?? ''),
                    'zone_order' => (int) ($data['zone']['zone_order'] ?? 0),
                ],
                [ '%d', '%s', '%d' ]
            );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
            $wpdb->delete($locations, [ 'zone_id' => $zone_id ], [ '%d' ]);
            foreach ((array) ($data['locations'] ?? []) as $row) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
                $wpdb->insert(
                    $locations,
                    [
                        'location_id'   => (int) ($row['location_id'] ?? 0),
                        'zone_id'       => $zone_id,
                        'location_code' => (string) ($row['location_code'] ?? ''),
                        'location_type' => (string) ($row['location_type'] ?? ''),
                    ],
                    [ '%d', '%d', '%s', '%s' ]
                );
            }
        }

        // Methods added since the capture go, with their settings.
        $captured = [];
        foreach ((array) ($data['methods'] ?? []) as $row) {
            $captured[ (int) ($row['instance_id'] ?? 0) ] = $row;
        }
        foreach (self::method_rows($zone_id) as $row) {
            $instance = (int) $row['instance_id'];
            if (isset($captured[ $instance ]) && (string) $captured[ $instance ]['method_id'] === (string) $row['method_id']) {
                continue;
            }
            delete_option(self::option_name((string) $row['method_id'], $instance));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
            $wpdb->delete($methods, [ 'instance_id' => $instance ], [ '%d' ]);
        }

        // Captured methods come back at their own instance ids.
        foreach ($captured as $instance => $row) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
            $wpdb->replace(
                $methods,
                [
                    'zone_id'      => $zone_id,
                    'instance_id'  => $instance,
                    'method_id'    => (string) ($row['method_id'] ?? ''),
                    'method_order' => (int) ($row['method_order'] ?? 0),
                    'is_enabled'   => (int) ($row['is_enabled'] ?? 1),
                ],
                [ '%d', '%d', '%s', '%d', '%d' ]
            );
        }

        foreach ((array) ($data['options'] ?? []) as $name => $state) {
            self::restore_option((string) $name, (array) $state);
        }

        self::flush_caches();
        return null;
    }

    /**
     * Write the creation row for a zone a tool just created. If the row
     * cannot be written the zone is deleted again and the error rethrown: a
     * creation is never left behind without its undo point.
     */
    public static function record_creation(int $zone_id, string $tool_name, array $args, string $session_id): string
    {
        $operation_id = wp_generate_uuid4();
        try {
            Snapshot_Store::save(
                $operation_id,
                '' === $session_id ? 'default' : $session_id,
                [
                    'object_type' => self::CREATE_TYPE,
                    'object_id'   => $zone_id,
                    'data'        => [],
                ],
                $tool_name,
                hash('sha256', (string) wp_json_encode($args))
            );
        } catch (\Throwable $e) {
            self::delete_zone($zone_id);
            throw $e;
        }
        Operation_Context::note($operation_id);
        Snapshot_Store::prune();

        return $operation_id;
    }

    /**
     * Undo a 'wc_shipping_zone_create' row: delete the zone, its methods and
     * their settings. Returns a warning, or null.
     */
    public static function undo_creation(array $snapshot): ?string
    {
        $zone_id = (int) ($snapshot['object_id'] ?? 0);
        if ($zone_id <= 0 || null === self::zone_row($zone_id)) {
            return null; // Already gone: nothing to undo.
        }
        if (! self::delete_zone($zone_id)) {
            return "Shipping zone {$zone_id} created by this operation could not be deleted; it was left in place.";
        }
        return null;
    }

    /** Delete a zone through WooCommerce's zone data store (methods and their settings go with it). */
    private static function delete_zone(int $zone_id): bool
    {
        if (! class_exists('WC_Shipping_Zone')) {
            return false;
        }
        try {
            $zone = new \WC_Shipping_Zone($zone_id);
            $zone->delete(true);
        } catch (\Throwable $e) {
            return false;
        }
        self::flush_caches();
        return null === self::zone_row($zone_id);
    }

    /** @return array<string, mixed>|null */
    private static function zone_row(int $zone_id): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- snapshot capture must read the live row, not a cache.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT zone_id, zone_name, zone_order FROM {$wpdb->prefix}woocommerce_shipping_zones WHERE zone_id = %d",
            $zone_id
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    private static function location_rows(int $zone_id): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT location_id, zone_id, location_code, location_type FROM {$wpdb->prefix}woocommerce_shipping_zone_locations WHERE zone_id = %d ORDER BY location_id",
            $zone_id
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /** @return array<int, array<string, mixed>> */
    public static function method_rows(int $zone_id): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT zone_id, instance_id, method_id, method_order, is_enabled FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE zone_id = %d ORDER BY instance_id",
            $zone_id
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /** @return array{existed: bool, value?: mixed, autoload?: string} */
    private static function option_state(string $name): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the autoload flag has no API getter; the value is read through get_option() below.
        $autoload = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name));
        if (null === $autoload) {
            return [ 'existed' => false ];
        }
        return [ 'existed' => true, 'value' => get_option($name), 'autoload' => (string) $autoload ];
    }

    /** @param array{existed?: bool, value?: mixed, autoload?: string} $state */
    private static function restore_option(string $name, array $state): void
    {
        delete_option($name);
        if (empty($state['existed'])) {
            return;
        }
        add_option($name, $state['value'] ?? '', '', self::autoload_flag((string) ($state['autoload'] ?? 'yes')));
        self::put_autoload($name, (string) ($state['autoload'] ?? ''));
    }

    /** add_option()'s autoload argument for a stored autoload value. */
    private static function autoload_flag(string $stored): bool
    {
        return in_array($stored, ['yes', 'on', 'auto-on', 'auto'], true);
    }

    /** Put the exact stored autoload value back (core maps its argument to a few spellings). */
    private static function put_autoload(string $name, string $stored): void
    {
        global $wpdb;
        if ('' === $stored) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- exact autoload restore; the options caches are flushed right after.
        $changed = $wpdb->update($wpdb->options, [ 'autoload' => $stored ], [ 'option_name' => $name ], [ '%s' ], [ '%s' ]);
        if ($changed) {
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete($name, 'options');
        }
    }

    private static function flush_caches(): void
    {
        if (class_exists('WC_Cache_Helper')) {
            \WC_Cache_Helper::invalidate_cache_group('shipping_zones');
            \WC_Cache_Helper::get_transient_version('shipping', true);
        }
    }
}
