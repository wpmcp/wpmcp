<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The gateways.health read (issue #661): the connection health of the
 * WooCommerce Stripe, WooPayments and WooCommerce PayPal Payments gateways.
 *
 * Each row says whether the gateway is enabled, whether it runs in test
 * mode, whether it is connected (its credentials for the current mode are
 * all set, or its account is linked), how its webhooks are doing and the
 * last error the plugin recorded. Everything comes from the options each
 * plugin itself stores, read directly, so the read never calls a payment
 * provider, never runs the plugin's own code paths (some of which write,
 * such as Stripe's webhook monitor start time) and works the same whichever
 * plugin version is loaded.
 *
 * It is a read only. No key, secret, token, account id, merchant id or
 * merchant email is ever returned: credentials only ever show as "set" or
 * "not set" through the connected flag and signing_secret_set.
 *
 * Detection is by gateway id in WooCommerce's gateway registry (WC()->
 * payment_gateways()): a plugin that is not active registers no gateway, so
 * its row is absent and, when none of the three is present, the op is left
 * out of woo-ops and refused with plugin_missing or plugin_inactive.
 */
final class Gateway_Health
{
    /** Gateway id => provider name in the response. */
    public const GATEWAYS = [
        'stripe'               => 'stripe',
        'woocommerce_payments' => 'woopayments',
        'ppcp-gateway'         => 'paypal',
    ];

    private const KEYS = [ 'gateway' ];

    /** Stripe's webhook monitor options, by mode. */
    private const STRIPE_WEBHOOK = [
        'live' => [ 'wc_stripe_wh_last_success_at', 'wc_stripe_wh_last_failure_at', 'wc_stripe_wh_last_error' ],
        'test' => [ 'wc_stripe_wh_test_last_success_at', 'wc_stripe_wh_test_last_failure_at', 'wc_stripe_wh_test_last_error' ],
    ];

    /** What Stripe stores as the last error when the last webhook validated. */
    private const STRIPE_NO_ERROR = 'validation_succeeded';

    /** Whether at least one of the supported gateways is registered. */
    public static function is_available(): bool
    {
        return [] !== self::detected();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function read(array $params): array
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($params)), self::KEYS));
        if ([] !== $unknown) {
            return Op_Guard::error(
                'invalid_params',
                'Unknown field(s): ' . implode(', ', $unknown) . '. Accepted: ' . implode(', ', self::KEYS) . '.',
                [ 'unknown' => $unknown ]
            );
        }

        $gateways = self::detected();
        if (array_key_exists('gateway', $params)) {
            $id = $params['gateway'];
            if (! is_string($id) || ! isset($gateways[ $id ])) {
                return Op_Guard::error(
                    'unknown_gateway',
                    'gateway must be the id of an active Stripe, WooPayments or PayPal Payments gateway: ' . implode(', ', array_keys($gateways)) . '.',
                    [ 'gateways' => array_keys($gateways) ]
                );
            }
            $gateways = [ $id => $gateways[ $id ] ];
        }

        $rows = [];
        foreach ($gateways as $id => $gateway) {
            $provider = self::GATEWAYS[ $id ];
            // Re-read the stored settings: the in-memory copy can be stale.
            $gateway->init_settings();
            $row      = [
                'id'       => (string) $id,
                'provider' => $provider,
                'title'    => wp_strip_all_tags((string) $gateway->get_method_title()),
                'enabled'  => 'yes' === $gateway->enabled,
            ];
            $rows[] = $row + match ($provider) {
                'stripe'      => self::stripe(),
                'woopayments' => self::woopayments(),
                default       => self::paypal(),
            };
        }

        return [ 'status' => 200, 'body' => [ 'gateways' => $rows ] ];
    }

    /**
     * Stripe: test mode and keys from woocommerce_stripe_settings, webhook
     * state from the plugin's webhook monitor for the current mode.
     *
     * @return array<string, mixed>
     */
    private static function stripe(): array
    {
        $settings = self::option_array('woocommerce_stripe_settings');
        $test     = 'yes' === ($settings['testmode'] ?? 'no');
        $prefix   = $test ? 'test_' : '';

        [ $success_option, $failure_option, $error_option ] = self::STRIPE_WEBHOOK[ $test ? 'test' : 'live' ];
        $success = self::timestamp(get_option($success_option));
        $failure = self::timestamp(get_option($failure_option));
        $error   = get_option($error_option);

        // The error is current only when the last failure is the latest event.
        $failing    = null !== $failure && (null === $success || $failure > $success);
        $last_error = $failing && is_string($error) && '' !== $error && self::STRIPE_NO_ERROR !== $error ? sanitize_key($error) : null;

        return [
            'test_mode'  => $test,
            'connected'  => self::filled($settings[ $prefix . 'publishable_key' ] ?? null) && self::filled($settings[ $prefix . 'secret_key' ] ?? null),
            'webhook'    => [
                'status'             => $failing ? 'failing' : (null !== $success ? 'ok' : 'none_received'),
                'signing_secret_set' => self::filled($settings[ $prefix . 'webhook_secret' ] ?? null),
                'last_success_at'    => self::utc($success),
                'last_failure_at'    => self::utc($failure),
            ],
            'last_error' => $last_error,
        ];
    }

    /**
     * WooPayments: test mode from the gateway settings, the account from the
     * plugin's own account cache. Its webhooks arrive through WooPayments'
     * servers, which the site cannot inspect, so their status is "managed".
     *
     * @return array<string, mixed>
     */
    private static function woopayments(): array
    {
        $settings = self::option_array('woocommerce_woocommerce_payments_settings');
        $cache    = self::option_array('wcpay_account_data');
        $data     = $cache['data'] ?? null;
        $errored  = ! empty($cache['errored']);

        // An empty array means no account is connected; a failed fetch leaves it unknown.
        $connected = is_array($data) ? [] !== $data : ($errored ? null : false);
        $account   = null;
        if (is_array($data) && [] !== $data) {
            $account = [
                'status'            => isset($data['status']) && is_string($data['status']) ? sanitize_key($data['status']) : null,
                'payments_enabled'  => isset($data['payments_enabled']) ? (bool) $data['payments_enabled'] : null,
                'details_submitted' => isset($data['details_submitted']) ? (bool) $data['details_submitted'] : null,
                'past_due'          => isset($data['has_overdue_requirements']) ? (bool) $data['has_overdue_requirements'] : null,
                'checked_at'        => self::utc(self::timestamp($cache['fetched'] ?? null)),
            ];
        }

        return [
            'test_mode'  => 'yes' === ($settings['test_mode'] ?? 'no'),
            'connected'  => $connected,
            'account'    => $account,
            'webhook'    => [ 'status' => 'managed' ],
            'last_error' => $errored ? 'account_fetch_failed' : null,
        ];
    }

    /**
     * PayPal Payments: sandbox and merchant credentials from its settings
     * (woocommerce-ppcp-data-common, or the pre-3.0 woocommerce-ppcp-settings),
     * the registered webhook from ppcp-webhook and the last received event
     * from ppcp-last-webhook.
     *
     * @return array<string, mixed>
     */
    private static function paypal(): array
    {
        $common = self::option_array('woocommerce-ppcp-data-common');
        if ([] !== $common) {
            $test = ! empty($common['use_sandbox']);
            $set  = $common;
        } else {
            $set  = self::option_array('woocommerce-ppcp-settings');
            $test = ! empty($set['sandbox_on']);
        }
        $connected = self::filled($set['merchant_id'] ?? null) && self::filled($set['client_id'] ?? null) && self::filled($set['client_secret'] ?? null);

        $webhook = self::option_array('ppcp-webhook');
        $last    = self::option_array('ppcp-last-webhook');

        return [
            'test_mode'  => $test,
            'connected'  => $connected,
            'webhook'    => [
                'status'           => self::filled($webhook['id'] ?? null) ? 'registered' : 'not_registered',
                'last_received_at' => self::utc(self::timestamp($last['received_time'] ?? null)),
            ],
            'last_error' => null,
        ];
    }

    /** @return array<string, \WC_Payment_Gateway> the supported gateways that are registered, by id */
    private static function detected(): array
    {
        if (! function_exists('WC')) {
            return [];
        }
        $registry = WC()->payment_gateways();
        $all      = is_object($registry) && method_exists($registry, 'payment_gateways') ? (array) $registry->payment_gateways() : [];
        $out      = [];
        foreach ($all as $id => $gateway) {
            if (isset(self::GATEWAYS[ $id ]) && $gateway instanceof \WC_Payment_Gateway) {
                $out[ (string) $id ] = $gateway;
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function option_array(string $option): array
    {
        $value = get_option($option, []);
        return is_array($value) ? $value : [];
    }

    private static function filled($value): bool
    {
        return is_string($value) ? '' !== trim($value) : ! empty($value);
    }

    private static function timestamp($value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function utc(?int $timestamp): ?string
    {
        return null === $timestamp ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }
}
