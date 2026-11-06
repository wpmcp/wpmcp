<?php

namespace WPMCP\Tools\WooCommerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The one masking rule for payment gateway credentials (issues #292, #507).
 *
 * A gateway setting is a secret when its form field is a password field or
 * its key looks like a credential (a key, secret, password, token,
 * signature, credential or private value). Every read path that can return
 * gateway settings masks with this class: the gateways.* ops, get-option on
 * a gateway's settings option, and call-rest on the WooCommerce payment
 * gateway routes. A new secret field is therefore masked everywhere at once.
 *
 * A masked value sent back unchanged (a read, edited, then written whole)
 * means "keep what is stored", so a masked round-trip never overwrites a
 * live credential with the mask.
 */
final class Gateway_Secrets
{
    /** What a non-empty secret value reads as. */
    public const MASK = '[redacted]';

    /** Setting keys that hold a credential, whatever the field type. */
    public const SECRET_PATTERN = '/(key|secret|pass(word|wd|phrase)?|token|signature|credential|private)/i';

    /** A gateway's settings option: woocommerce_{gateway id}_settings. */
    private const OPTION_PATTERN = '/^woocommerce_(.+)_settings$/';

    /** WooCommerce's own payment gateway REST routes, any version, item or collection. */
    private const REST_ROUTE_PATTERN = '#^/wc/v\d+/payment_gateways(?:/|$)#i';

    /** One gateway's REST route, capturing its id. */
    private const REST_ITEM_PATTERN = '#^/wc/v\d+/payment_gateways/([^/?]+)/?$#i';

    /**
     * @param array<string, mixed> $field The setting's form field, when known.
     */
    public static function is_secret(string $key, array $field = []): bool
    {
        return 'password' === ($field['type'] ?? '') || 1 === preg_match(self::SECRET_PATTERN, $key);
    }

    /**
     * A secret's value as responses show it: empty stays empty (so "not set"
     * is still visible), anything else is the mask.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function mask($value)
    {
        return '' === $value || null === $value || [] === $value ? $value : self::MASK;
    }

    /** True for a value that is exactly what mask() returns for a set secret. */
    public static function is_masked($value): bool
    {
        return self::MASK === $value;
    }

    /**
     * The gateway a settings option belongs to, or null when the option is
     * no registered gateway's settings.
     */
    public static function gateway_for_option(string $option): ?\WC_Payment_Gateway
    {
        if (1 !== preg_match(self::OPTION_PATTERN, $option, $m)) {
            return null;
        }
        $gateway = self::gateway($m[1]);
        return null !== $gateway && $option === $gateway->get_option_key() ? $gateway : null;
    }

    /** A registered gateway by id, or null. */
    public static function gateway(string $id): ?\WC_Payment_Gateway
    {
        if (! function_exists('WC') || ! class_exists('WC_Payment_Gateway')) {
            return null;
        }
        $gateways = WC()->payment_gateways();
        $all      = is_object($gateways) && method_exists($gateways, 'payment_gateways') ? (array) $gateways->payment_gateways() : [];
        foreach ($all as $gateway) {
            if ($gateway instanceof \WC_Payment_Gateway && $id === (string) $gateway->id) {
                return $gateway;
            }
        }
        return null;
    }

    /**
     * The keys of a gateway's settings that hold a credential: every form
     * field the rule calls secret, plus any stored key whose name does.
     *
     * @param array<string, mixed> $stored
     * @return array<int, string>
     */
    public static function secret_keys(\WC_Payment_Gateway $gateway, array $stored = []): array
    {
        $keys = [];
        foreach ((array) $gateway->get_form_fields() as $key => $field) {
            if (self::is_secret((string) $key, is_array($field) ? $field : [])) {
                $keys[] = (string) $key;
            }
        }
        foreach (array_keys($stored) as $key) {
            if (self::is_secret((string) $key)) {
                $keys[] = (string) $key;
            }
        }
        return array_values(array_unique($keys));
    }

    /**
     * An option's value with gateway secrets masked. Anything that is not a
     * registered gateway's settings option comes back untouched.
     *
     * The rule follows the option name, not the stored shape (issue #729):
     * a gateway's settings can end up stored as JSON text, a serialized
     * string or an object instead of the usual array, so each of those is
     * decoded, masked by key and encoded back in the same form. A non-empty
     * value that decodes to no settings array at all is masked whole, since
     * nothing tells which part of it is a credential.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function mask_option(string $option, $value)
    {
        if (self::is_unset($value)) {
            return $value;
        }
        $gateway = self::gateway_for_option($option);
        if (null === $gateway) {
            return $value;
        }
        $decoded = self::decode($value);
        if (null === $decoded) {
            return self::MASK;
        }
        [ $settings, $layers ] = $decoded;
        $masked = self::mask_settings($gateway, $settings);
        return $masked === $settings ? $value : self::encode($masked, $layers);
    }

    /**
     * A new value for a gateway settings option with every secret that still
     * reads as the mask put back to what is stored, so writing back a masked
     * read keeps the live credential. Covers every shape mask_option() reads
     * (issue #729): a whole value that is exactly the mask keeps the stored
     * value, and JSON text or a serialized string is decoded, its masked
     * keys restored and encoded back in the same form.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function unmask_option(string $option, $value)
    {
        $gateway = self::gateway_for_option($option);
        if (null === $gateway) {
            return $value;
        }
        $missing = new \stdClass();
        $stored  = get_option($option, $missing);
        if (self::is_masked($value)) {
            return $missing === $stored ? $value : $stored;
        }
        $decoded = self::decode($value);
        if (null === $decoded) {
            return $value;
        }
        [ $settings, $layers ] = $decoded;
        $current  = ($missing === $stored ? null : self::decode_settings($stored)) ?? [];
        $restored = $settings;
        foreach (self::secret_keys($gateway, $settings) as $key) {
            if (! array_key_exists($key, $restored) || ! self::is_masked($restored[ $key ])) {
                continue;
            }
            if (array_key_exists($key, $current)) {
                $restored[ $key ] = $current[ $key ];
            } else {
                unset($restored[ $key ]);
            }
        }
        return $restored === $settings ? $value : self::encode($restored, $layers);
    }

    /**
     * A gateway settings value decoded to its settings array, whatever form
     * it is stored in (array, object, serialized string, JSON text), or null
     * when it holds no settings array.
     *
     * @param mixed $value
     * @return array<mixed>|null
     */
    public static function decode_settings($value): ?array
    {
        $decoded = self::decode($value);
        return null === $decoded ? null : $decoded[0];
    }

    /** @param mixed $value */
    private static function is_unset($value): bool
    {
        return false === $value || null === $value || '' === $value || [] === $value;
    }

    /**
     * $value peeled down to an array: the array plus the layers taken off it
     * (outermost first), or null when no array is inside. Serialized data is
     * decoded without instantiating any class.
     *
     * @param mixed $value
     * @return array{0: array<mixed>, 1: array<int, string>}|null
     */
    private static function decode($value): ?array
    {
        $layers = [];
        for ($depth = 0; $depth < 4; $depth++) {
            if (is_array($value)) {
                return [ $value, $layers ];
            }
            if (is_object($value)) {
                $layers[] = 'object';
                $value    = get_object_vars($value);
                continue;
            }
            if (! is_string($value)) {
                return null;
            }
            if (is_serialized($value)) {
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- allowed_classes:false never instantiates an object; the value is only masked and re-encoded.
                $inner = @unserialize($value, [ 'allowed_classes' => false ]);
                if (false === $inner && 'b:0;' !== $value) {
                    return null;
                }
                $layers[] = 'serialized';
                $value    = $inner;
                continue;
            }
            $trimmed = ltrim($value);
            if ('' !== $trimmed && in_array($trimmed[0], [ '{', '[', '"' ], true)) {
                $inner = json_decode($value, true);
                if (JSON_ERROR_NONE === json_last_error() && (is_array($inner) || is_string($inner))) {
                    $layers[] = 'json';
                    $value    = $inner;
                    continue;
                }
            }
            return null;
        }
        return null;
    }

    /**
     * $settings wrapped back in the layers decode() took off.
     *
     * @param array<mixed>       $settings
     * @param array<int, string> $layers
     * @return mixed
     */
    private static function encode(array $settings, array $layers)
    {
        $value = $settings;
        foreach (array_reverse($layers) as $layer) {
            if ('object' === $layer) {
                $value = (object) $value;
            } elseif ('serialized' === $layer) {
                $value = serialize($value); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- re-encodes the value in the serialized form it was read in.
            } else {
                $value = (string) wp_json_encode($value);
            }
        }
        return $value;
    }

    /**
     * Settings with every secret masked, at any depth: a key is secret when
     * it is one of the gateway's secret fields or the rule calls it secret,
     * so a value stored in an unusual shape cannot keep a credential one
     * level down.
     *
     * @param array<mixed> $settings
     * @return array<mixed>
     */
    private static function mask_settings(\WC_Payment_Gateway $gateway, array $settings): array
    {
        return self::mask_keys($settings, self::secret_keys($gateway, $settings));
    }

    /**
     * @param array<mixed>       $data
     * @param array<int, string> $secret
     * @return array<mixed>
     */
    private static function mask_keys(array $data, array $secret): array
    {
        foreach ($data as $key => $item) {
            if (in_array((string) $key, $secret, true) || self::is_secret((string) $key)) {
                $data[ $key ] = self::mask($item);
            } elseif (is_array($item)) {
                $data[ $key ] = self::mask_keys($item, $secret);
            }
        }
        return $data;
    }

    /** True when a REST route is one of WooCommerce's payment gateway routes. */
    public static function is_gateway_route(string $route): bool
    {
        return 1 === preg_match(self::REST_ROUTE_PATTERN, $route);
    }

    /**
     * A WooCommerce payment gateway REST response body with every secret
     * setting masked: one gateway, or a list of them.
     *
     * @param mixed $body
     * @return mixed
     */
    public static function mask_rest_body($body)
    {
        if (! is_array($body)) {
            return $body;
        }
        if (array_is_list($body)) {
            return array_map(static fn ($item) => self::mask_rest_gateway($item), $body);
        }
        return self::mask_rest_gateway($body);
    }

    /**
     * @param mixed $gateway
     * @return mixed
     */
    private static function mask_rest_gateway($gateway)
    {
        if (! is_array($gateway) || ! is_array($gateway['settings'] ?? null)) {
            return $gateway;
        }
        foreach ($gateway['settings'] as $key => $setting) {
            if (is_array($setting) && self::is_secret((string) $key, $setting) && array_key_exists('value', $setting)) {
                $gateway['settings'][ $key ]['value'] = self::mask($setting['value']);
            }
        }
        return $gateway;
    }

    /**
     * A WooCommerce payment gateway REST write body with every secret that
     * still reads as the mask dropped, so a masked round-trip leaves the
     * stored credential in place.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function unmask_rest_params(string $route, array $params): array
    {
        if (! is_array($params['settings'] ?? null) || 1 !== preg_match(self::REST_ITEM_PATTERN, $route, $m)) {
            return $params;
        }
        $gateway = self::gateway(rawurldecode($m[1]));
        $secret  = null === $gateway ? [] : self::secret_keys($gateway);
        foreach ($params['settings'] as $key => $setting) {
            if (self::is_masked($setting) && (in_array((string) $key, $secret, true) || self::is_secret((string) $key))) {
                unset($params['settings'][ $key ]);
            }
        }
        return $params;
    }
}
