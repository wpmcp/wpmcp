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
 *
 * A created zone carries a creation marker (issue #338): a random token kept
 * in the MARKER_OPTION map under the zone id, and recorded in the creation
 * row. WooCommerce zones have no meta table, so the marker lives in one
 * option; it is forgotten whenever WooCommerce deletes the zone
 * (woocommerce_delete_shipping_zone), so a different zone that later lands on
 * the same id carries no marker. Undoing the create deletes the zone only
 * while its marker still matches the row's; otherwise the zone is left in
 * place and the skipped restore is reported. Whole-zone snapshots record the
 * marker too, so a session that created, edited and deleted a zone puts it
 * back before the create is undone.
 */
final class Wc_Shipping_Zone_Snapshot
{
    public const TYPE        = 'wc_shipping_zone';
    public const CREATE_TYPE = 'wc_shipping_zone_create';

    /** Creation markers of zones a tool created: zone id => token. */
    public const MARKER_OPTION = 'wpmcp_shipping_zones_created';

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
                'marker'    => $zone_id > 0 ? self::marker($zone_id) : null,
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

            // The creation marker goes back with the zone (snapshots written
            // before issue #338 carry none and leave it alone).
            if (array_key_exists('marker', $data)) {
                self::put_marker($zone_id, is_string($data['marker']) ? $data['marker'] : null);
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
        $marker       = wp_generate_uuid4();
        try {
            self::put_marker($zone_id, $marker);
            Snapshot_Store::save(
                $operation_id,
                '' === $session_id ? 'default' : $session_id,
                [
                    'object_type' => self::CREATE_TYPE,
                    'object_id'   => $zone_id,
                    'data'        => [ 'marker' => $marker ],
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
     * their settings, but only while its creation marker proves it is still
     * the zone this operation created. Returns a warning, or null.
     */
    public static function undo_creation(array $snapshot): ?string
    {
        $zone_id = (int) ($snapshot['object_id'] ?? 0);
        $data    = (array) ($snapshot['data'] ?? []);
        if ($zone_id <= 0) {
            return null;
        }
        if (null === self::zone_row($zone_id)) {
            self::forget_creation($zone_id);
            return null; // Already gone: nothing to undo.
        }
        // Creation rows written before issue #338 carry no marker and keep
        // the old behavior.
        if (array_key_exists('marker', $data)) {
            $expected = (string) $data['marker'];
            if ('' === $expected || self::marker($zone_id) !== $expected) {
                return "Shipping zone {$zone_id} is no longer the zone this operation created; it was left in place.";
            }
        }
        if (! self::delete_zone($zone_id)) {
            return "Shipping zone {$zone_id} created by this operation could not be deleted; it was left in place.";
        }
        return null;
    }

    /**
     * Forget a zone's creation marker. Hooked on
     * woocommerce_delete_shipping_zone, so a zone deleted by any path that
     * goes through WooCommerce drops its marker with it.
     *
     * @param mixed $zone_id
     */
    public static function forget_creation($zone_id): void
    {
        self::put_marker((int) $zone_id, null);
    }

    /** The creation marker recorded for a zone, or null. */
    private static function marker(int $zone_id): ?string
    {
        $markers = get_option(self::MARKER_OPTION, []);
        $marker  = is_array($markers) ? ($markers[ $zone_id ] ?? null) : null;
        return is_string($marker) ? $marker : null;
    }

    /** Record (or, with null, forget) a zone's creation marker. */
    private static function put_marker(int $zone_id, ?string $marker): void
    {
        if ($zone_id <= 0) {
            return;
        }
        $markers = get_option(self::MARKER_OPTION, []);
        $markers = is_array($markers) ? $markers : [];
        if (null === $marker) {
            if (! array_key_exists($zone_id, $markers)) {
                return;
            }
            unset($markers[ $zone_id ]);
        } else {
            $markers[ $zone_id ] = $marker;
        }
        if ([] === $markers) {
            delete_option(self::MARKER_OPTION);
            return;
        }
        update_option(self::MARKER_OPTION, $markers, false);
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
