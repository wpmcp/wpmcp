<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Payment gateway reads and settings writes behind the gateways.* ops
 * (issue #292), in-process through WooCommerce's own gateway registry.
 *
 *  - Reads show each gateway's enabled state, title, description, order and
 *    form-field settings. Every secret-like field (a key, secret, password,
 *    token, signature or credential by name, or any field of type password)
 *    is masked in every response, so no gateway credential reaches a model
 *    context.
 *  - gateways.update changes the enabled flag, title, description and
 *    non-secret settings through the wc/v3 gateway endpoint (its own field
 *    validation), then answers with the masked view, never the endpoint's
 *    body. Secret fields are refused outright: there is no safe way to take
 *    a credential through a model context, and the store admin screen
 *    remains the place to set one.
 *  - Each write is snapshotted as the one option it changes: the gateway's
 *    settings option, or woocommerce_gateway_order for an order change. An
 *    order change therefore travels alone, so every undo point covers
 *    exactly what its write touched and a session unwinds exactly. The
 *    snapshot holds the gateway's secrets (never shown), so restoring it
 *    takes manage_woocommerce.
 */
final class Gateway_Ops
{
    /** WooCommerce's gateway display order option (gateway id => position). */
    public const ORDER_OPTION = 'woocommerce_gateway_order';

    /** Field names that mark a value as a credential. */
    public const SECRET_PATTERN = '/(key|secret|pass(word|wd|phrase)?|token|signature|credential|private)/i';

    /** Field types a write may set; anything else (headings, custom widgets) is refused. */
    private const WRITABLE_TYPES = [ 'text', 'textarea', 'checkbox', 'select', 'multiselect', 'radio', 'email', 'number', 'decimal', 'price', 'safe_text', 'tel', 'url' ];

    /** Field types that carry no value. */
    private const NO_VALUE_TYPES = [ 'title', 'sectionend' ];

    private const KEYS = [
        'gateway_list'   => [],
        'gateway_get'    => [ 'id' ],
        'gateway_update' => [ 'id', 'enabled', 'title', 'description', 'order', 'settings' ],
    ];

    /**
     * The gateways.list and gateways.get reads.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function read(string $handler, array $params): array
    {
        try {
            self::refuse_unknown($params, self::KEYS[ $handler ] ?? []);
            if ('gateway_get' === $handler) {
                return [ 'status' => 200, 'body' => self::view(self::gateway($params['id'] ?? null)) ];
            }
        } catch (Order_Op_Refused $e) {
            return Op_Guard::error($e->code_name, $e->getMessage(), $e->data);
        }

        $rows = [];
        foreach (self::registry() as $gateway) {
            $rows[] = self::view($gateway);
        }
        return [ 'status' => 200, 'body' => [ 'gateways' => $rows ] ];
    }

    /**
     * Validate one gateway write. Returns a structured error, or
     * ['body' => normalized plan]. Writes nothing.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function prepare(string $handler, array $params): array
    {
        try {
            if ('gateway_update' !== $handler) {
                self::refuse('invalid_params', 'Unknown gateway handler.');
            }
            self::refuse_unknown($params, self::KEYS[ $handler ]);
            $gateway = self::gateway($params['id'] ?? null);
            $plan    = [ 'id' => (string) $gateway->id, 'option_key' => (string) $gateway->get_option_key() ];

            if (array_key_exists('order', $params)) {
                if (count($params) > 2) {
                    self::refuse('invalid_params', 'Change order in a call of its own: it is stored apart from the other settings and is its own undo point.');
                }
                $order = $params['order'];
                if (! (is_int($order) || (is_string($order) && ctype_digit($order))) || (int) $order < 0) {
                    self::refuse('invalid_params', 'order must be a whole number, 0 or more.');
                }
                $plan['order'] = (int) $order;
                return [ 'body' => $plan ];
            }

            $changes = [];
            if (array_key_exists('enabled', $params)) {
                if (! is_bool($params['enabled'])) {
                    self::refuse('invalid_params', 'enabled must be true or false.');
                }
                $changes['enabled'] = $params['enabled'];
            }
            foreach ([ 'title', 'description' ] as $key) {
                if (array_key_exists($key, $params)) {
                    if (! is_string($params[ $key ])) {
                        self::refuse('invalid_params', "{$key} must be text.");
                    }
                    $changes[ $key ] = $params[ $key ];
                }
            }
            if (array_key_exists('settings', $params)) {
                $changes['settings'] = self::checked_settings($gateway, $params['settings']);
            }
            if ([] === $changes) {
                self::refuse('invalid_params', 'Nothing to change: pass enabled, title, description, settings or order.');
            }
            $plan['rest'] = $changes;
        } catch (Order_Op_Refused $e) {
            return Op_Guard::error($e->code_name, $e->getMessage(), $e->data);
        }

        return [ 'body' => $plan ];
    }

    /**
     * The snapshot target of a validated write: the one option it changes.
     * The snapshot carries the capability its restore needs, since it holds
     * the gateway's credentials.
     *
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public static function snapshot_target(array $plan): array
    {
        return [
            'object_type'        => 'option',
            'object_id'          => array_key_exists('order', $plan) ? self::ORDER_OPTION : (string) $plan['option_key'],
            'restore_capability' => 'manage_woocommerce',
        ];
    }

    /**
     * Apply a validated write. Runs inside Safe_Mutation, after the option
     * snapshot is written.
     *
     * @param array<string, mixed> $plan
     * @return array{status: int, body: mixed}
     */
    public static function apply(string $handler, int $unused, array $plan): array
    {
        $id = (string) $plan['id'];

        if (array_key_exists('order', $plan)) {
            $order = get_option(self::ORDER_OPTION);
            $order = is_array($order) ? $order : [];
            $order[ $id ] = (int) $plan['order'];
            update_option(self::ORDER_OPTION, $order);
        } else {
            $out = (new Wc_Rest_Write_Dispatch())->send('PUT', '/wc/v3/payment_gateways/' . rawurlencode($id), $plan['rest']);
            if ($out['status'] < 200 || $out['status'] >= 300) {
                // The endpoint's error body is a code and a message only.
                return $out;
            }
        }

        return [ 'status' => 200, 'body' => self::view(self::gateway($id)) ];
    }

    /**
     * Whether a form field holds a credential.
     *
     * @param array<string, mixed> $field
     */
    public static function is_secret(string $key, array $field): bool
    {
        return 'password' === ($field['type'] ?? '') || 1 === preg_match(self::SECRET_PATTERN, $key);
    }

    /**
     * One gateway as responses show it, secrets masked.
     *
     * @return array<string, mixed>
     */
    private static function view(\WC_Payment_Gateway $gateway): array
    {
        // Re-read the stored option: a write in this request (or its
        // rollback) leaves the in-memory settings stale.
        $gateway->init_settings();

        $settings = [];
        foreach ($gateway->get_form_fields() as $key => $field) {
            $field = is_array($field) ? $field : [];
            $type  = (string) ($field['type'] ?? 'text');
            if (in_array($type, self::NO_VALUE_TYPES, true)) {
                continue;
            }
            $secret = self::is_secret((string) $key, $field);
            $value  = $gateway->get_option((string) $key);
            if ($secret && '' !== $value && null !== $value && [] !== $value) {
                $value = '[redacted]';
            }
            $settings[ $key ] = [
                'label'  => wp_strip_all_tags((string) ($field['title'] ?? $key)),
                'type'   => $type,
                'value'  => $value,
                'secret' => $secret,
            ];
        }

        $order = get_option(self::ORDER_OPTION);
        $order = is_array($order) && isset($order[ $gateway->id ]) && is_numeric($order[ $gateway->id ]) ? (int) $order[ $gateway->id ] : null;

        return [
            'id'                 => (string) $gateway->id,
            'title'              => (string) $gateway->get_option('title'),
            'description'        => (string) $gateway->get_option('description'),
            'enabled'            => 'yes' === $gateway->enabled,
            'order'              => $order,
            'method_title'       => wp_strip_all_tags((string) $gateway->get_method_title()),
            'method_description' => wp_strip_all_tags((string) $gateway->get_method_description()),
            'settings'           => $settings,
        ];
    }

    /**
     * Validate a settings map against the gateway's own form fields.
     *
     * @param mixed $given
     * @return array<string, mixed>
     */
    private static function checked_settings(\WC_Payment_Gateway $gateway, $given): array
    {
        if (! is_array($given) || [] === $given || array_is_list($given)) {
            self::refuse('invalid_params', 'settings must be an object of field => value.');
        }

        $fields = $gateway->get_form_fields();
        $out    = [];
        foreach ($given as $key => $value) {
            $key   = (string) $key;
            $field = isset($fields[ $key ]) && is_array($fields[ $key ]) ? $fields[ $key ] : null;
            if (null === $field) {
                self::refuse('invalid_params', "This gateway has no setting \"{$key}\". Read it with gateways.get.", [ 'field' => $key ]);
            }
            if (self::is_secret($key, $field)) {
                self::refuse('secret_field', "\"{$key}\" holds a credential and cannot be set here; set it in the store's payment settings.", [ 'field' => $key ]);
            }
            $type = (string) ($field['type'] ?? 'text');
            if (! in_array($type, self::WRITABLE_TYPES, true)) {
                self::refuse('invalid_params', "\"{$key}\" is a {$type} field, which cannot be set here.", [ 'field' => $key ]);
            }
            $out[ $key ] = self::checked_value($key, $type, $field, $value);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $field
     * @param mixed                $value
     * @return mixed
     */
    private static function checked_value(string $key, string $type, array $field, $value)
    {
        if ('checkbox' === $type) {
            if (is_bool($value)) {
                return $value ? 'yes' : 'no';
            }
            if (! in_array($value, [ 'yes', 'no' ], true)) {
                self::refuse('invalid_params', "\"{$key}\" must be true or false.", [ 'field' => $key ]);
            }
            return $value;
        }

        $options = isset($field['options']) && is_array($field['options']) ? array_map('strval', array_keys($field['options'])) : null;
        if ('multiselect' === $type) {
            if (! is_array($value) || ! array_is_list($value)) {
                self::refuse('invalid_params', "\"{$key}\" takes a list of values.", [ 'field' => $key ]);
            }
            foreach ($value as $item) {
                if (! is_scalar($item) || (null !== $options && ! in_array((string) $item, $options, true))) {
                    self::refuse('invalid_params', "\"{$key}\" only takes: " . implode(', ', (array) $options) . '.', [ 'field' => $key ]);
                }
            }
            return array_map('strval', $value);
        }

        if (! is_scalar($value) || is_bool($value)) {
            self::refuse('invalid_params', "\"{$key}\" takes a single text or number value.", [ 'field' => $key ]);
        }
        $value = (string) $value;
        if (in_array($type, [ 'select', 'radio' ], true) && null !== $options && ! in_array($value, $options, true)) {
            self::refuse('invalid_params', "\"{$key}\" only takes: " . implode(', ', $options) . '.', [ 'field' => $key ]);
        }
        return $value;
    }

    /** @return \WC_Payment_Gateway[] keyed by id, in WooCommerce's display order */
    private static function registry(): array
    {
        $gateways = function_exists('WC') ? WC()->payment_gateways()->payment_gateways() : [];
        return array_filter($gateways, static fn ($gateway) => $gateway instanceof \WC_Payment_Gateway);
    }

    /** The gateway an op names, or a refusal. */
    private static function gateway($id): \WC_Payment_Gateway
    {
        $registry = self::registry();
        if (! is_string($id) || ! isset($registry[ $id ])) {
            self::refuse('unknown_gateway', 'No payment gateway has that id. List them with gateways.list.');
        }
        return $registry[ $id ];
    }

    /**
     * Stop validation with a structured refusal.
     *
     * @param array<string, mixed> $data
     */
    private static function refuse(string $code, string $message, array $data = []): never
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the caller and returned as a JSON error, never rendered.
        throw new Order_Op_Refused($code, $message, $data);
    }

    private static function refuse_unknown(array $given, array $allowed): void
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($given)), $allowed));
        if ([] !== $unknown) {
            self::refuse(
                'invalid_params',
                'Unknown field(s): ' . implode(', ', $unknown) . '. Accepted: ' . implode(', ', $allowed) . '.',
                [ 'unknown' => $unknown ]
            );
        }
    }
}
