<?php

namespace WPMCP\Integrations;

use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WP Simple Pay (issue #666) on the plugin-data pair, free tier and read
 * only, so it adds no top-level tools. Ops are prefixed simplepay- because
 * the pair is shared.
 *
 * WP Simple Pay (Lite and Pro, 4.x) keeps a payment form as a private
 * `simple-pay` post (capability_type post) with its settings in post meta,
 * read through simpay_get_saved_meta() in Abstracts\Form:
 *  - _form_display_type: stripe_checkout, embedded or overlay;
 *  - _livemode: '' to follow the global setting, otherwise 1 or 0;
 *  - _company_name, _item_description, _checkout_button_text;
 *  - _success_redirect_type (default, page, redirect), _success_redirect_page,
 *    _success_redirect_url;
 *  - the price options as an array per payment mode, _simpay_prices_live and
 *    _simpay_prices_test (Forms\PriceOptions), each price with id, label,
 *    default, currency, unit_amount (smallest currency unit), unit_amount_min
 *    for a custom amount, can_recur and recurring (interval, interval_count).
 * The global test mode is the test_mode key of the simpay_settings option
 * ('enabled' unless set otherwise). A form is in live mode when its own
 * _livemode says so, else when the global test mode is off; it charges the
 * prices of that mode.
 *
 * Reads: simplepay-list-forms (status, mode, display type and a price
 * summary per form) and simplepay-get-form (the full configuration). Forms
 * hold no customer data, so nothing is masked. Both take manage_options,
 * the capability WP Simple Pay's own form screens are behind. Writes are not
 * part of the first version (issue #666). Presence is filterable
 * (wpmcp_simple_pay_active, default: SIMPLE_PAY_VERSION is defined).
 */
final class Simple_Pay_Pack
{
    public const POST_TYPE = 'simple-pay';

    public const MAX_PER_PAGE = 100;

    public const PLUGIN_FILES = [ 'stripe/stripe-checkout.php' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_simple_pay_active', defined('SIMPLE_PAY_VERSION'));
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        if (self::active()) {
            return true;
        }
        $installed = false;
        foreach (self::PLUGIN_FILES as $file) {
            $installed = $installed || (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $file));
        }
        $url = admin_url('plugins.php');

        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf(
                'WP Simple Pay is %s on this site. Install and activate WP Simple Pay (%s), then try again.',
                $installed ? 'installed but not active' : 'not installed',
                $url
            ),
            'data'    => [ 'plugin' => 'stripe', 'plugins_url' => $url ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $base = [
            'mode'       => 'read',
            'capability' => 'manage_options',
            'requires'   => static fn () => self::presence(),
        ];

        return [
            'simplepay-list-forms' => $base + [
                'description'  => 'List WP Simple Pay payment forms: id, title, post status, display type (stripe_checkout, embedded, overlay), payment mode (test or live), and the prices of that mode (label, amount in the smallest currency unit, currency, recurring interval). global_mode is the site-wide test or live setting. Filter by status (publish, draft, any). page, per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status'   => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private', 'any' ] ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                    ],
                ],
                'handler'      => static fn (array $args): array => self::list_forms($args),
            ],
            'simplepay-get-form'   => $base + [
                'objects'      => [ 'id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'read' ] ],
                'description'  => 'Read one WP Simple Pay payment form: title, status, display type, payment mode, company name, item description, checkout button text, success redirect, and every price option of its mode with Stripe price ids and custom amount minimums',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'id' ],
                ],
                'validate'     => static fn (array $args): ?array => self::form_refusal((int) $args['id']),
                'handler'      => static fn (array $args): array => [ 'form' => self::view((int) $args['id'], true) ],
            ],
        ];
    }

    /** 'live' or 'test' for the site-wide setting. */
    public static function global_mode(): string
    {
        $settings = get_option('simpay_settings', []);
        $test     = is_array($settings) && isset($settings['test_mode']) ? (string) $settings['test_mode'] : 'enabled';
        return ('' === $test || 'enabled' === $test) ? 'test' : 'live';
    }

    private static function mode(int $id): string
    {
        $own = get_post_meta($id, '_livemode', true);
        if ('' !== $own && null !== $own && false !== $own) {
            return (bool) $own ? 'live' : 'test';
        }
        return self::global_mode();
    }

    private static function list_forms(array $args): array
    {
        $per_page = min(self::MAX_PER_PAGE, max(1, (int) ($args['per_page'] ?? 20)));
        $page     = max(1, (int) ($args['page'] ?? 1));
        $status   = (string) ($args['status'] ?? 'any');
        $q        = Readable_Posts::query([
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'any' === $status ? [ 'publish', 'draft', 'pending', 'private', 'future' ] : $status,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'ID',
            'order'          => 'DESC',
        ]);
        $forms = [];
        foreach ($q->posts as $post) {
            $forms[] = self::view((int) $post->ID, false);
        }
        return [ 'forms' => $forms, 'total' => (int) $q->found_posts, 'page' => $page, 'per_page' => $per_page, 'global_mode' => self::global_mode() ];
    }

    private static function form_refusal(int $id): ?array
    {
        $post = get_post($id);
        if (! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type) {
            return [ 'code' => 'form_not_found', 'message' => sprintf('%d is not a WP Simple Pay payment form.', $id), 'data' => [ 'id' => $id ] ];
        }
        return null;
    }

    /** @return array<string, mixed> */
    private static function view(int $id, bool $full): array
    {
        $post   = get_post($id);
        $mode   = self::mode($id);
        $prices = get_post_meta($id, 'live' === $mode ? '_simpay_prices_live' : '_simpay_prices_test', true);
        $prices = is_array($prices) ? array_values(array_filter($prices, 'is_array')) : [];
        $view   = [
            'id'           => $id,
            'title'        => $post instanceof \WP_Post ? (string) $post->post_title : '',
            'status'       => $post instanceof \WP_Post ? (string) $post->post_status : '',
            'display_type' => (string) (get_post_meta($id, '_form_display_type', true) ?: 'stripe_checkout'),
            'mode'         => $mode,
            'price_count'  => count($prices),
            'prices'       => array_map(static fn (array $p): array => self::price($p, $full), $prices),
        ];
        if (! $full) {
            return $view;
        }
        $redirect = (string) get_post_meta($id, '_success_redirect_type', true);
        return $view + [
            'company_name'         => (string) get_post_meta($id, '_company_name', true),
            'item_description'     => (string) get_post_meta($id, '_item_description', true),
            'checkout_button_text' => (string) get_post_meta($id, '_checkout_button_text', true),
            'success_redirect'     => [
                'type'    => '' === $redirect ? 'default' : $redirect,
                'page_id' => (int) get_post_meta($id, '_success_redirect_page', true),
                'url'     => (string) get_post_meta($id, '_success_redirect_url', true),
            ],
            'date_modified'        => $post instanceof \WP_Post ? (string) $post->post_modified_gmt : '',
        ];
    }

    /** @return array<string, mixed> */
    private static function price(array $p, bool $full): array
    {
        $recurring = isset($p['recurring']) && is_array($p['recurring']) && isset($p['recurring']['interval'])
            ? [ 'interval' => (string) $p['recurring']['interval'], 'interval_count' => (int) ($p['recurring']['interval_count'] ?? 1) ]
            : null;
        $view      = [
            'label'     => isset($p['label']) ? (string) $p['label'] : '',
            'amount'    => (int) ($p['unit_amount'] ?? 0),
            'currency'  => (string) ($p['currency'] ?? ''),
            'recurring' => $recurring,
            'default'   => isset($p['default']) && true === $p['default'],
        ];
        if (! $full) {
            return $view;
        }
        return $view + [
            'id'         => (string) ($p['id'] ?? ''),
            'amount_min' => isset($p['unit_amount_min']) ? (int) $p['unit_amount_min'] : null,
            'custom'     => isset($p['unit_amount_min']) || ! empty($p['custom']),
            'can_recur'  => isset($p['can_recur']) && true === $p['can_recur'],
        ];
    }
}
