<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

use WPMCP\Safety\Wc_Shipping_Zone_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shipping zone and method reads and writes behind the shipping.* ops
 * (issue #292). Everything runs in-process through WC_Shipping_Zone and the
 * method classes WooCommerce registers, not through the wc/v3 shipping
 * routes, so every param is validated here before anything is written and
 * unknown params are refused rather than ignored.
 *
 * Method writes are limited to the three core methods (flat_rate,
 * free_shipping, local_pickup). Their settings are validated field by field
 * against the method's own instance form fields, through the same
 * sanitizers WooCommerce's settings screen uses (a flat rate cost that would
 * not evaluate is refused). Reads show settings only for those three core
 * methods: a third-party method's settings can hold carrier credentials.
 *
 * Woo_Write wraps every write except a zone create in Safe_Mutation with a
 * whole-zone snapshot (Wc_Shipping_Zone_Snapshot::TYPE), so adding or
 * removing a method is undone by putting the zone back; a zone create
 * records a creation row itself once the zone exists, and its rollback
 * deletes the zone.
 */
final class Shipping_Ops
{
    /** Method types these ops may add and configure. */
    public const METHODS = ['flat_rate', 'free_shipping', 'local_pickup'];

    private const LOCATION_TYPES = ['postcode', 'state', 'country', 'continent'];

    private const MAX_LOCATIONS = 500;

    private const KEYS = [
        'shipping_zone_create'   => ['name', 'order', 'locations'],
        'shipping_zone_update'   => ['id', 'name', 'order', 'locations'],
        'shipping_zone_delete'   => ['id'],
        'shipping_method_add'    => ['zone_id', 'method_id', 'enabled', 'order', 'settings'],
        'shipping_method_update' => ['zone_id', 'instance_id', 'enabled', 'order', 'settings'],
        'shipping_method_remove' => ['zone_id', 'instance_id'],
    ];

    // ----------------------------------------------------------------- reads

    /**
     * shipping.zones and shipping.zone.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed> ['status', 'body'] or a structured error
     */
    public static function read(string $handler, array $params): array
    {
        if ('shipping_zone' === $handler) {
            $id = self::int_param($params['id'] ?? null);
            if (null === $id || ! Wc_Shipping_Zone_Snapshot::exists($id)) {
                return Op_Guard::error('unknown_zone', 'No shipping zone has that id. List zones with shipping.zones.');
            }
            return [ 'status' => 200, 'body' => self::zone_view($id) ];
        }

        $zones = [];
        foreach (\WC_Data_Store::load('shipping-zone')->get_zones() as $row) {
            $zones[] = self::zone_view((int) $row->zone_id);
        }
        $zones[] = self::zone_view(0);
        return [ 'status' => 200, 'body' => $zones ];
    }

    /** @return array<string, mixed> */
    public static function zone_view(int $zone_id): array
    {
        $zone      = new \WC_Shipping_Zone($zone_id);
        $locations = [];
        foreach ($zone->get_zone_locations('edit') as $location) {
            $locations[] = [ 'code' => (string) $location->code, 'type' => (string) $location->type ];
        }

        $methods = [];
        foreach (Wc_Shipping_Zone_Snapshot::method_rows($zone_id) as $row) {
            $methods[] = self::method_view($row);
        }
        usort($methods, static fn ($a, $b) => [$a['order'], $a['instance_id']] <=> [$b['order'], $b['instance_id']]);

        return [
            'id'        => $zone_id,
            'name'      => $zone->get_zone_name('edit'),
            'order'     => (int) $zone->get_zone_order('edit'),
            'locations' => $locations,
            'methods'   => $methods,
        ];
    }

    /**
     * @param array<string, mixed> $row a woocommerce_shipping_zone_methods row
     * @return array<string, mixed>
     */
    private static function method_view(array $row): array
    {
        $method_id = (string) $row['method_id'];
        $instance  = (int) $row['instance_id'];
        $method    = self::method_instance($method_id, $instance);
        $core      = in_array($method_id, self::METHODS, true);

        $view = [
            'instance_id' => $instance,
            'method_id'   => $method_id,
            'title'       => $method ? (string) $method->get_title() : $method_id,
            'enabled'     => (bool) (int) $row['is_enabled'],
            'order'       => (int) $row['method_order'],
        ];
        if ($core && $method) {
            $method->init_instance_settings();
            $view['settings'] = (array) $method->instance_settings;
        }
        return $view;
    }

    // ---------------------------------------------------------------- writes

    /**
     * Validate and normalize the params of one shipping write. Returns a
     * structured error, or ['body' => normalized plan]. Writes nothing.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function prepare(string $handler, array $params): array
    {
        try {
            $body = self::validate($handler, $params);
        } catch (Order_Op_Refused $e) {
            return Op_Guard::error($e->code_name, $e->getMessage(), $e->data);
        }
        return [ 'body' => $body ];
    }

    /**
     * Create a zone and record its creation row.
     *
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public static function create(array $plan, string $session_id, string $op): array
    {
        $zone = new \WC_Shipping_Zone();
        $zone->set_zone_name($plan['name']);
        $zone->set_zone_order($plan['order'] ?? 0);
        $zone->set_locations($plan['locations'] ?? []);
        $zone_id = (int) $zone->save();
        if ($zone_id <= 0) {
            return Op_Guard::error('zone_create_failed', 'WooCommerce did not create the shipping zone.');
        }

        $operation_id = Wc_Shipping_Zone_Snapshot::record_creation($zone_id, 'woo-write', [ 'op' => $op, 'params' => $plan ], $session_id);

        return [ 'status' => 201, 'body' => self::zone_view($zone_id), 'operation_id' => $operation_id ];
    }

    /**
     * Apply a validated write. Runs inside Safe_Mutation, after the whole
     * zone snapshot is written.
     *
     * @param array<string, mixed> $plan
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function apply(string $handler, int $zone_id, array $plan): array
    {
        if ('shipping_zone_update' === $handler) {
            $zone = new \WC_Shipping_Zone($zone_id);
            if (array_key_exists('name', $plan)) {
                $zone->set_zone_name($plan['name']);
            }
            if (array_key_exists('order', $plan)) {
                $zone->set_zone_order($plan['order']);
            }
            if (array_key_exists('locations', $plan)) {
                $zone->set_locations($plan['locations']);
            }
            $zone->save();
            return [ 'status' => 200, 'body' => self::zone_view($zone_id) ];
        }

        if ('shipping_zone_delete' === $handler) {
            $view = self::zone_view($zone_id);
            (new \WC_Shipping_Zone($zone_id))->delete(true);
            return [ 'status' => 200, 'body' => [ 'deleted' => true, 'previous' => $view ] ];
        }

        $zone = new \WC_Shipping_Zone($zone_id);

        if ('shipping_method_remove' === $handler) {
            $row = self::method_row($zone_id, $plan['instance_id']);
            $zone->delete_shipping_method($plan['instance_id']);
            return [ 'status' => 200, 'body' => [ 'deleted' => true, 'previous' => null !== $row ? self::method_view($row) : null ] ];
        }

        $status   = 200;
        $instance = (int) ($plan['instance_id'] ?? 0);
        if ('shipping_method_add' === $handler) {
            $instance = (int) $zone->add_shipping_method($plan['method_id']);
            if ($instance <= 0) {
                throw new \RuntimeException('WooCommerce did not add the shipping method.');
            }
            $status = 201;
        }

        self::apply_method_changes($zone_id, $instance, $plan);

        $row = self::method_row($zone_id, $instance);
        return [ 'status' => $status, 'body' => null !== $row ? self::method_view($row) : [] ];
    }

    /** @param array<string, mixed> $plan */
    private static function apply_method_changes(int $zone_id, int $instance, array $plan): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'woocommerce_shipping_zone_methods';

        $columns = [];
        if (array_key_exists('enabled', $plan)) {
            $columns['is_enabled'] = $plan['enabled'] ? 1 : 0;
        }
        if (array_key_exists('order', $plan)) {
            $columns['method_order'] = $plan['order'];
        }
        if ([] !== $columns) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the same column writes the wc/v3 shipping method controller makes; WooCommerce has no setter for them.
            $wpdb->update($table, $columns, [ 'instance_id' => $instance, 'zone_id' => $zone_id ], array_fill(0, count($columns), '%d'), [ '%d', '%d' ]);
        }

        if (array_key_exists('settings', $plan)) {
            $row    = self::method_row($zone_id, $instance);
            $method = null !== $row ? self::method_instance((string) $row['method_id'], $instance) : null;
            if (null !== $method) {
                $method->init_instance_settings();
                $settings = array_merge((array) $method->instance_settings, $plan['settings']);
                update_option($method->get_instance_option_key(), $settings, true);
            }
        }

        if (class_exists('WC_Cache_Helper')) {
            \WC_Cache_Helper::get_transient_version('shipping', true);
        }
    }

    // ------------------------------------------------------------ validation

    /** @return array<string, mixed> */
    private static function validate(string $handler, array $params): array
    {
        if (! isset(self::KEYS[ $handler ])) {
            self::refuse('invalid_params', 'Unknown shipping handler.');
        }
        self::refuse_unknown($params, self::KEYS[ $handler ], $handler);

        if ('shipping_zone_create' === $handler) {
            if (! array_key_exists('name', $params)) {
                self::refuse('invalid_params', 'A new shipping zone needs a name.');
            }
            return self::zone_fields($params);
        }

        if (in_array($handler, ['shipping_zone_update', 'shipping_zone_delete'], true)) {
            $id = self::int_param($params['id'] ?? null);
            if (null === $id || 0 === $id) {
                self::refuse('invalid_params', 'id must be the positive id of a shipping zone. The "locations not covered" zone (0) has no name, order or locations to change, and cannot be deleted.');
            }
            if (! Wc_Shipping_Zone_Snapshot::exists($id)) {
                self::refuse('unknown_zone', "No shipping zone has id {$id}. List zones with shipping.zones.");
            }
            if ('shipping_zone_delete' === $handler) {
                return [ 'zone_id' => $id ];
            }
            $fields = self::zone_fields($params);
            if ([] === $fields) {
                self::refuse('invalid_params', 'shipping.update-zone needs at least one change: name, order or locations.');
            }
            return [ 'zone_id' => $id ] + $fields;
        }

        $zone_id = self::int_param($params['zone_id'] ?? null);
        if (null === $zone_id) {
            self::refuse('invalid_params', 'zone_id must be a shipping zone id (0 for "locations not covered").');
        }
        if (! Wc_Shipping_Zone_Snapshot::exists($zone_id)) {
            self::refuse('unknown_zone', "No shipping zone has id {$zone_id}. List zones with shipping.zones.");
        }

        if ('shipping_method_add' === $handler) {
            $method_id = $params['method_id'] ?? null;
            if (! is_string($method_id) || ! in_array($method_id, self::METHODS, true) || ! self::method_registered($method_id)) {
                self::refuse('invalid_params', 'method_id must be one of: ' . implode(', ', self::METHODS) . '.', [ 'allowed' => self::METHODS ]);
            }
            return [ 'zone_id' => $zone_id, 'method_id' => $method_id ] + self::method_fields($method_id, $params);
        }

        $instance = self::int_param($params['instance_id'] ?? null);
        $row      = null !== $instance && $instance > 0 ? self::method_row($zone_id, $instance) : null;
        if (null === $row) {
            self::refuse('invalid_params', "Shipping zone {$zone_id} has no method instance with that instance_id.");
        }
        $method_id = (string) $row['method_id'];
        if (! in_array($method_id, self::METHODS, true)) {
            self::refuse('invalid_params', "Instance {$instance} is a \"{$method_id}\" method; these ops change only " . implode(', ', self::METHODS) . '.');
        }

        if ('shipping_method_remove' === $handler) {
            return [ 'zone_id' => $zone_id, 'instance_id' => $instance ];
        }

        $fields = self::method_fields($method_id, $params);
        if ([] === $fields) {
            self::refuse('invalid_params', 'shipping.update-method needs at least one change: enabled, order or settings.');
        }
        return [ 'zone_id' => $zone_id, 'instance_id' => $instance ] + $fields;
    }

    /** @return array<string, mixed> */
    private static function zone_fields(array $params): array
    {
        $out = [];
        if (array_key_exists('name', $params)) {
            $name = is_string($params['name']) ? sanitize_text_field($params['name']) : '';
            if ('' === $name || strlen($name) > 200) {
                self::refuse('invalid_params', 'name must be a non-empty string of up to 200 characters.');
            }
            $out['name'] = $name;
        }
        if (array_key_exists('order', $params)) {
            $out['order'] = self::order($params['order']);
        }
        if (array_key_exists('locations', $params)) {
            $out['locations'] = self::locations($params['locations']);
        }
        return $out;
    }

    /** @return array<int, array{code: string, type: string}> */
    private static function locations($value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            self::refuse('invalid_params', 'locations must be a list of {code, type} objects.');
        }
        if (count($value) > self::MAX_LOCATIONS) {
            self::refuse('invalid_params', 'A zone takes at most ' . self::MAX_LOCATIONS . ' locations.');
        }

        $countries  = WC()->countries;
        $out        = [];
        foreach ($value as $location) {
            if (! is_array($location)) {
                self::refuse('invalid_params', 'Every location must be an object {code, type}.');
            }
            self::refuse_unknown($location, ['code', 'type'], 'a location');
            $type = $location['type'] ?? null;
            $code = is_string($location['code'] ?? null) ? trim((string) $location['code']) : '';
            if (! in_array($type, self::LOCATION_TYPES, true)) {
                self::refuse('invalid_params', 'A location type is one of: ' . implode(', ', self::LOCATION_TYPES) . '.');
            }

            $valid = false;
            if ('country' === $type) {
                $code  = strtoupper($code);
                $valid = isset($countries->get_countries()[ $code ]);
            } elseif ('continent' === $type) {
                $code  = strtoupper($code);
                $valid = isset($countries->get_continents()[ $code ]);
            } elseif ('state' === $type) {
                $code  = strtoupper($code);
                $parts = explode(':', $code, 2);
                $states = 2 === count($parts) ? $countries->get_states($parts[0]) : false;
                $valid  = is_array($states) && isset($states[ $parts[1] ]);
            } else {
                $code  = sanitize_text_field($code);
                $valid = '' !== $code && strlen($code) <= 200;
            }
            if (! $valid) {
                self::refuse('invalid_params', "\"{$code}\" is not a known {$type} location code.", [ 'code' => $code, 'type' => $type ]);
            }
            $out[] = [ 'code' => $code, 'type' => $type ];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function method_fields(string $method_id, array $params): array
    {
        $out = [];
        if (array_key_exists('enabled', $params)) {
            if (! is_bool($params['enabled'])) {
                self::refuse('invalid_params', 'enabled must be true or false.');
            }
            $out['enabled'] = $params['enabled'];
        }
        if (array_key_exists('order', $params)) {
            $out['order'] = self::order($params['order']);
        }
        if (array_key_exists('settings', $params)) {
            $out['settings'] = self::settings($method_id, $params['settings']);
        }
        return $out;
    }

    /**
     * Validate settings against the method's own instance form fields and
     * return the values WooCommerce would store.
     *
     * @return array<string, mixed>
     */
    private static function settings(string $method_id, $value): array
    {
        if (! is_array($value) || (array_is_list($value) && [] !== $value)) {
            self::refuse('invalid_params', 'settings must be an object of setting keys and values.');
        }
        $method = self::method_instance($method_id, 0);
        if (null === $method) {
            self::refuse('invalid_params', "The {$method_id} method is not available.");
        }

        $fields = $method->get_instance_form_fields();
        $keys   = [];
        foreach ($fields as $key => $field) {
            if ('title' !== $method->get_field_type($field)) {
                $keys[] = (string) $key;
            }
        }
        self::refuse_unknown($value, $keys, "the {$method_id} settings");

        $out = [];
        foreach ($value as $key => $raw) {
            $field = $fields[ $key ];
            $type  = $method->get_field_type($field);

            if ('checkbox' === $type) {
                if (! is_bool($raw) && ! in_array($raw, ['yes', 'no'], true)) {
                    self::refuse('invalid_params', "Setting \"{$key}\" is a checkbox: pass true or false.");
                }
                $out[ $key ] = (true === $raw || 'yes' === $raw) ? 'yes' : 'no';
                continue;
            }
            if (! is_scalar($raw) || is_bool($raw)) {
                self::refuse('invalid_params', "Setting \"{$key}\" must be a string or a number.");
            }
            $raw = (string) $raw;
            if (in_array($type, ['select', 'radio'], true) && ! array_key_exists($raw, (array) ($field['options'] ?? []))) {
                $allowed = array_map('strval', array_keys((array) ($field['options'] ?? [])));
                self::refuse('invalid_params', "Setting \"{$key}\" must be one of: " . implode(', ', $allowed) . '.', [ 'allowed' => $allowed ]);
            }

            $value_out = null;
            $error     = self::sanitize_setting($method, $key, $field, $raw, $value_out);
            if (null !== $error) {
                self::refuse('invalid_params', "Setting \"{$key}\" was refused: {$error}", [ 'setting' => $key ]);
            }
            $out[ $key ] = $value_out;
        }
        return $out;
    }

    /**
     * Run one value through the method's own sanitizer, as WooCommerce's
     * settings screen does. A flat rate cost is evaluated there, and under
     * WP_DEBUG WooCommerce's cost evaluator echoes and raises a warning on a
     * bad expression; both are caught here so nothing reaches the JSON
     * response. Returns the refusal reason, or null with $value set.
     *
     * @param array<string, mixed> $field
     * @param mixed                $value set to the sanitized value
     */
    private static function sanitize_setting(\WC_Shipping_Method $method, string $key, array $field, string $raw, &$value): ?string
    {
        $warning = null;
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- scoped to one sanitizer call and restored right after; it turns the evaluator's debug warning into a refusal.
        set_error_handler(static function (int $errno, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        }, E_WARNING | E_USER_WARNING | E_NOTICE | E_USER_NOTICE);
        ob_start();
        try {
            $value = $method->get_field_value($key, $field, [ $method->get_field_key($key) => $raw ]);
        } catch (\Throwable $e) {
            $warning = $e->getMessage();
        } finally {
            ob_end_clean();
            restore_error_handler();
        }

        if (null !== $warning) {
            return '' === trim($warning) ? 'WooCommerce could not evaluate it.' : wp_strip_all_tags($warning);
        }
        return null;
    }

    /** A method instance of a registered type, or null. */
    private static function method_instance(string $method_id, int $instance_id): ?\WC_Shipping_Method
    {
        $classes = WC()->shipping()->get_shipping_method_class_names();
        $class   = $classes[ $method_id ] ?? null;
        if (is_object($class)) {
            $class = get_class($class);
        }
        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }
        $method = new $class($instance_id);
        return $method instanceof \WC_Shipping_Method ? $method : null;
    }

    private static function method_registered(string $method_id): bool
    {
        return array_key_exists($method_id, WC()->shipping()->get_shipping_method_class_names());
    }

    /** @return array<string, mixed>|null */
    private static function method_row(int $zone_id, int $instance): ?array
    {
        foreach (Wc_Shipping_Zone_Snapshot::method_rows($zone_id) as $row) {
            if ((int) $row['instance_id'] === $instance) {
                return $row;
            }
        }
        return null;
    }

    private static function order($value): int
    {
        $order = self::int_param($value);
        if (null === $order || $order > 100000) {
            self::refuse('invalid_params', 'order must be a whole number from 0 to 100000.');
        }
        return $order;
    }

    /** A non-negative integer param, or null. */
    private static function int_param($value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && '' !== $value && ctype_digit($value)) {
            return (int) $value;
        }
        return null;
    }

    /**
     * Stop validation with a structured refusal, caught in prepare().
     *
     * @param array<string, mixed> $data
     */
    private static function refuse(string $code, string $message, array $data = []): never
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught in prepare() and returned as a JSON error, never rendered.
        throw new Order_Op_Refused($code, $message, $data);
    }

    private static function refuse_unknown(array $given, array $allowed, string $where): void
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($given)), $allowed));
        if ([] !== $unknown) {
            self::refuse(
                'invalid_params',
                'Unknown field(s) for ' . $where . ': ' . implode(', ', $unknown) . '. Accepted: ' . implode(', ', $allowed) . '.',
                [ 'unknown' => $unknown ]
            );
        }
    }
}
