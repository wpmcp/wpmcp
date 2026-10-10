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
     * registered gateway's settings array comes back untouched.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function mask_option(string $option, $value)
    {
        $gateway = is_array($value) ? self::gateway_for_option($option) : null;
        if (null === $gateway) {
            return $value;
        }
        foreach (self::secret_keys($gateway, $value) as $key) {
            if (array_key_exists($key, $value)) {
                $value[ $key ] = self::mask($value[ $key ]);
            }
        }
        return $value;
    }

    /**
     * A new value for a gateway settings option with every secret that still
     * reads as the mask put back to what is stored, so writing back a masked
     * read keeps the live credential.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function unmask_option(string $option, $value)
    {
        $gateway = is_array($value) ? self::gateway_for_option($option) : null;
        if (null === $gateway) {
            return $value;
        }
        $stored = get_option($option);
        $stored = is_array($stored) ? $stored : [];
        foreach (self::secret_keys($gateway, $value) as $key) {
            if (! array_key_exists($key, $value) || ! self::is_masked($value[ $key ])) {
                continue;
            }
            if (array_key_exists($key, $stored)) {
                $value[ $key ] = $stored[ $key ];
            } else {
                unset($value[ $key ]);
            }
        }
        return $value;
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
