<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;
use WPMCP\Privacy\Masking;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Safety\SureCart_Api;
use WPMCP\Safety\SureCart_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * SureCart store data (issue #665), paid-tier ops on the plugin-data pair,
 * so they add no top-level tool. SureCart keeps products, orders, customers
 * and coupons in its own cloud and reaches them through its models with the
 * site's connection token; every op calls those models (SureCart_Api).
 *
 *  - surecart-list-products, surecart-get-product: products with their
 *    prices (amounts in the smallest currency unit), the description only
 *    on a single read.
 *  - surecart-list-orders, surecart-list-customers: customer names, emails
 *    and phones masked through Privacy\Masking.
 *  - surecart-list-coupons: discount, duration, redemptions and the
 *    promotion codes.
 *  - surecart-update-product: name, description, slug, status, archived or
 *    sku, only the changed fields sent. Snapshotted as a 'surecart_object'
 *    image read from the API, so rollback-operation puts back each field
 *    not changed since (SureCart_Snapshot).
 *  - surecart-create-coupon: a percent or fixed amount coupon with one
 *    promotion code (SureCart makes one when none is given). Undo deletes
 *    it, or archives it once an order has redeemed it.
 *
 * Writes are off until the site opts in (wpmcp_enable_surecart_write).
 * Every op takes manage_options. Without SureCart the ops answer
 * plugin_missing or plugin_inactive naming it; a site not connected to
 * SureCart gets surecart_not_connected. Presence is filterable
 * (wpmcp_surecart_active).
 */
final class SureCart_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const ENABLE_FILTER = 'wpmcp_enable_surecart_write';

    public const MAX_PER_PAGE = 50;

    private const PRODUCT_STATUSES = [ 'published', 'draft' ];

    private const CHECK_NOTE = 'SureCart re-syncs the product page on the site; open it to check, and undo with rollback-operation if needed.';

    public static function plugin_active(): bool
    {
        return (bool) apply_filters('wpmcp_surecart_active', defined('SURECART_PLUGIN_FILE') && SureCart_Api::loaded());
    }

    /** Whether SureCart is usable and this install may run the paid tier. */
    public static function active(): bool
    {
        return Registrar::tier_permitted('pro') && self::plugin_active();
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        // The literal name, not ENABLE_FILTER, so the hook stays greppable.
        $enabled = (bool) apply_filters('wpmcp_enable_surecart_write', false);
        $base    = [
            'tier'       => 'pro',
            'capability' => 'manage_options',
            'requires'   => static fn () => self::plugin_active() ? true : self::refusal(),
        ];
        $paging  = [
            'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
            'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
            'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
        ];
        $write   = $base + [
            'mode'               => 'write',
            'enabled_by_default' => $enabled,
            'opt_in_filter'      => self::ENABLE_FILTER,
        ];
        $id      = [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[A-Za-z0-9_-]+$' ];

        return [
            'surecart-list-products'  => $base + [
                'mode'         => 'read',
                'description'  => 'List SureCart products: id, name, slug, status, archived, sku, stock, and prices (amount in the smallest currency unit). Filter by archived, search. page, per_page (max 50)',
                'input_schema' => [ 'type' => 'object', 'properties' => $paging + [ 'archived' => [ 'type' => 'boolean' ] ] ],
                'handler'      => static fn (array $args): array => self::list_products($args),
            ],
            'surecart-get-product'    => $base + [
                'mode'         => 'read',
                'description'  => 'Read one SureCart product with its description and prices',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'product_id' => $id ], 'required' => [ 'product_id' ] ],
                'handler'      => static fn (array $args): array => self::product_view(self::find_product((string) $args['product_id']), true),
            ],
            'surecart-list-orders'    => $base + [
                'mode'         => 'read',
                'description'  => 'List SureCart orders: number, status, fulfillment, total, currency, customer id; names and emails masked. Filter by status (paid, processing, payment_failed, void, draft), search. page, per_page',
                'input_schema' => [ 'type' => 'object', 'properties' => $paging + [ 'status' => [ 'type' => 'string', 'enum' => [ 'paid', 'processing', 'payment_failed', 'void', 'draft' ] ] ] ],
                'handler'      => static fn (array $args): array => self::list_orders($args),
            ],
            'surecart-list-customers' => $base + [
                'mode'         => 'read',
                'description'  => 'List SureCart customers: id, live mode, created; names, emails and phones masked. search, page, per_page',
                'input_schema' => [ 'type' => 'object', 'properties' => $paging ],
                'handler'      => static fn (array $args): array => self::list_customers($args),
            ],
            'surecart-list-coupons'   => $base + [
                'mode'         => 'read',
                'description'  => 'List SureCart coupons: percent or amount off, duration, redemptions, archived, and promotion codes. Filter by archived, search. page, per_page',
                'input_schema' => [ 'type' => 'object', 'properties' => $paging + [ 'archived' => [ 'type' => 'boolean' ] ] ],
                'handler'      => static fn (array $args): array => self::list_coupons($args),
            ],
            'surecart-update-product' => $write + [
                'description'  => 'Change a SureCart product\'s name, description, slug, status (published, draft), archived or sku. Snapshotted; undo: rollback-operation. Disabled by default (wpmcp_enable_surecart_write filter)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'product_id'  => $id,
                        'name'        => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 255 ],
                        'description' => [ 'type' => 'string', 'maxLength' => 65535 ],
                        'slug'        => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
                        'status'      => [ 'type' => 'string', 'enum' => self::PRODUCT_STATUSES ],
                        'archived'    => [ 'type' => 'boolean' ],
                        'sku'         => [ 'type' => 'string', 'maxLength' => 100 ],
                    ],
                    'required'   => [ 'product_id' ],
                ],
                'validate'     => static fn (array $args): ?array => self::update_refusal($args),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => SureCart_Snapshot::TYPE, 'object_id' => SureCart_Snapshot::key('product', (string) $args['product_id']) ],
                'handler'      => static fn (array $args, array $context): array => self::update_product($args, (string) ($context['operation_id'] ?? '')),
            ],
            'surecart-create-coupon'  => $write + [
                'description'  => 'Create a SureCart coupon: name, percent_off (1-100) or amount_off (smallest currency unit) with currency, duration (once, repeating with duration_in_months, forever), max_redemptions, and a promotion code (made by SureCart when left out). Snapshotted; undo deletes it, or archives it once redeemed. Disabled by default (wpmcp_enable_surecart_write filter)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'name'               => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 255 ],
                        'percent_off'        => [ 'type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => 100 ],
                        'amount_off'         => [ 'type' => 'integer', 'minimum' => 1 ],
                        'currency'           => [ 'type' => 'string', 'pattern' => '^[A-Za-z]{3}$' ],
                        'duration'           => [ 'type' => 'string', 'enum' => [ 'once', 'repeating', 'forever' ] ],
                        'duration_in_months' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 120 ],
                        'max_redemptions'    => [ 'type' => 'integer', 'minimum' => 1 ],
                        'code'               => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'pattern' => '^[A-Za-z0-9_-]+$' ],
                    ],
                    'required'   => [ 'name' ],
                ],
                'validate'     => static fn (array $args): ?array => self::coupon_refusal($args),
                'snapshot'     => static fn (): array => [ 'object_type' => SureCart_Snapshot::TYPE, 'object_id' => SureCart_Snapshot::key('coupon', 'new-' . wp_generate_uuid4()) ],
                'handler'      => static fn (array $args, array $context): array => self::create_coupon($args, (string) ($context['operation_id'] ?? '')),
            ],
        ];
    }

    /**
     * plugin_missing or plugin_inactive naming SureCart and the plugins screen.
     *
     * @return array{code: string, message: string, data: array<string, string>}
     */
    private static function refusal(): array
    {
        $refusal         = Plugin_Refusal::make('plugin_missing', 'SureCart');
        $refusal['code'] = 'inactive' === $refusal['data']['state'] ? 'plugin_inactive' : 'plugin_missing';
        return $refusal;
    }

    // ---------------------------------------------------------------
    // API
    // ---------------------------------------------------------------

    /**
     * One SureCart call; a refusal becomes an Operation_Error.
     *
     * @return array<string, mixed>
     */
    private static function call(string $resource, string $action, array $params = []): array
    {
        $out = SureCart_Api::call($resource, $action, $params);
        if (is_wp_error($out)) {
            throw self::error($out);
        }
        return $out;
    }

    private static function error(\WP_Error $error): Operation_Error
    {
        $code = (string) $error->get_error_code();
        if (in_array($code, [ 'missing_token', 'unauthorized', 'invalid_token' ], true)) {
            return new Operation_Error('surecart_not_connected', 'This site is not connected to a SureCart store. Connect it under SureCart > Settings > Connection (' . admin_url('admin.php?page=sc-settings') . '), then try again.');
        }
        if ('not_found' === $code) {
            return new Operation_Error('not_found', 'SureCart has no such record.');
        }
        return new Operation_Error('surecart_refused', 'SureCart refused: ' . $error->get_error_message(), [ 'surecart_code' => $code ]);
    }

    /** @return array<string, mixed> */
    private static function find_product(string $id): array
    {
        return self::call('products', 'find', [ 'id' => $id, 'expand' => [ 'prices' ] ]);
    }

    /** @return array{page: int, per_page: int} */
    private static function paging(array $args): array
    {
        return [ 'page' => max(1, (int) ($args['page'] ?? 1)), 'per_page' => min(self::MAX_PER_PAGE, max(1, (int) ($args['per_page'] ?? 20))) ];
    }

    /**
     * A list call with paging, search and filters.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    private static function list_call(string $resource, array $args, array $query, array $expand = []): array
    {
        $paging = self::paging($args);
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $query['query'] = trim((string) $args['search']);
        }
        $out   = self::call($resource, 'list', [ 'query' => $query, 'expand' => $expand ] + $paging);
        $items = array_values(array_filter((array) ($out['data'] ?? []), 'is_array'));
        return [
            'items'    => $items,
            'total'    => (int) ($out['pagination']['count'] ?? count($items)),
            'page'     => $paging['page'],
            'per_page' => $paging['per_page'],
        ];
    }

    /** @return array<int, array<string, mixed>> the data of an expanded list, or [] */
    private static function expanded($value): array
    {
        $value = is_array($value) ? $value : [];
        $rows  = isset($value['data']) && is_array($value['data']) ? $value['data'] : (array_is_list($value) ? $value : []);
        return array_values(array_filter($rows, 'is_array'));
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function product_view(array $product, bool $full): array
    {
        $view = [
            'id'               => (string) ($product['id'] ?? ''),
            'name'             => (string) ($product['name'] ?? ''),
            'slug'             => (string) ($product['slug'] ?? ''),
            'status'           => (string) ($product['status'] ?? ''),
            'archived'         => (bool) ($product['archived'] ?? false),
            'sku'              => isset($product['sku']) ? (string) $product['sku'] : null,
            'recurring'        => (bool) ($product['recurring'] ?? false),
            'stock_enabled'    => (bool) ($product['stock_enabled'] ?? false),
            'stock'            => isset($product['stock']) ? (int) $product['stock'] : null,
            'available_stock'  => isset($product['available_stock']) ? (int) $product['available_stock'] : null,
            'tax_enabled'      => (bool) ($product['tax_enabled'] ?? false),
            'shipping_enabled' => (bool) ($product['shipping_enabled'] ?? false),
            'prices'           => array_map(static fn (array $price): array => [
                'id'                       => (string) ($price['id'] ?? ''),
                'name'                     => isset($price['name']) ? (string) $price['name'] : null,
                'amount'                   => isset($price['amount']) ? (int) $price['amount'] : null,
                'currency'                 => isset($price['currency']) ? (string) $price['currency'] : null,
                'recurring_interval'       => isset($price['recurring_interval']) ? (string) $price['recurring_interval'] : null,
                'recurring_interval_count' => isset($price['recurring_interval_count']) ? (int) $price['recurring_interval_count'] : null,
                'archived'                 => (bool) ($price['archived'] ?? false),
                'ad_hoc'                   => (bool) ($price['ad_hoc'] ?? false),
            ], self::expanded($product['prices'] ?? [])),
            'created_at'       => isset($product['created_at']) ? (int) $product['created_at'] : null,
            'updated_at'       => isset($product['updated_at']) ? (int) $product['updated_at'] : null,
        ];
        if ($full) {
            $view['description'] = (string) ($product['description'] ?? '');
        }
        return $view;
    }

    /** @return array<string, mixed> */
    private static function list_products(array $args): array
    {
        $query = isset($args['archived']) ? [ 'archived' => (bool) $args['archived'] ] : [];
        $list  = self::list_call('products', $args, $query, [ 'prices' ]);
        return [
            'products' => array_map(static fn (array $p): array => self::product_view($p, false), $list['items']),
        ] + self::page_meta($list);
    }

    /** @return array<string, mixed> */
    private static function list_orders(array $args): array
    {
        $query = isset($args['status']) ? [ 'status' => [ (string) $args['status'] ] ] : [];
        $list  = self::list_call('orders', $args, $query, [ 'checkout', 'checkout.customer' ]);
        $rows  = [];
        foreach ($list['items'] as $order) {
            $checkout = is_array($order['checkout'] ?? null) ? $order['checkout'] : [];
            $customer = is_array($checkout['customer'] ?? null) ? $checkout['customer'] : [];
            $rows[]   = Masking::keys([
                'id'                 => (string) ($order['id'] ?? ''),
                'number'             => isset($order['number']) ? (string) $order['number'] : null,
                'status'             => (string) ($order['status'] ?? ''),
                'fulfillment_status' => isset($order['fulfillment_status']) ? (string) $order['fulfillment_status'] : null,
                'shipment_status'    => isset($order['shipment_status']) ? (string) $order['shipment_status'] : null,
                'live_mode'          => (bool) ($order['live_mode'] ?? false),
                'total_amount'       => isset($checkout['total_amount']) ? (int) $checkout['total_amount'] : null,
                'currency'           => isset($checkout['currency']) ? (string) $checkout['currency'] : null,
                'customer_id'        => isset($customer['id']) ? (string) $customer['id'] : (is_string($checkout['customer'] ?? null) ? $checkout['customer'] : null),
                'email'              => (string) ($checkout['email'] ?? ($customer['email'] ?? '')),
                'name'               => (string) ($checkout['name'] ?? ($customer['name'] ?? '')),
                'created_at'         => isset($order['created_at']) ? (int) $order['created_at'] : null,
            ], [ 'email', 'name' ]);
        }
        return [ 'orders' => $rows ] + self::page_meta($list);
    }

    /** @return array<string, mixed> */
    private static function list_customers(array $args): array
    {
        $list = self::list_call('customers', $args, []);
        $rows = [];
        foreach ($list['items'] as $customer) {
            $rows[] = Masking::keys([
                'id'         => (string) ($customer['id'] ?? ''),
                'name'       => (string) ($customer['name'] ?? ''),
                'email'      => (string) ($customer['email'] ?? ''),
                'phone'      => (string) ($customer['phone'] ?? ''),
                'live_mode'  => (bool) ($customer['live_mode'] ?? false),
                'created_at' => isset($customer['created_at']) ? (int) $customer['created_at'] : null,
            ], [ 'name', 'email', 'phone' ]);
        }
        return [ 'customers' => $rows ] + self::page_meta($list);
    }

    /** @return array<string, mixed> */
    private static function coupon_view(array $coupon): array
    {
        return [
            'id'                 => (string) ($coupon['id'] ?? ''),
            'name'               => (string) ($coupon['name'] ?? ''),
            'percent_off'        => isset($coupon['percent_off']) ? $coupon['percent_off'] + 0 : null,
            'amount_off'         => isset($coupon['amount_off']) ? (int) $coupon['amount_off'] : null,
            'currency'           => isset($coupon['currency']) ? (string) $coupon['currency'] : null,
            'duration'           => (string) ($coupon['duration'] ?? ''),
            'duration_in_months' => isset($coupon['duration_in_months']) ? (int) $coupon['duration_in_months'] : null,
            'max_redemptions'    => isset($coupon['max_redemptions']) ? (int) $coupon['max_redemptions'] : null,
            'times_redeemed'     => (int) ($coupon['times_redeemed'] ?? 0),
            'redeem_by'          => isset($coupon['redeem_by']) ? (int) $coupon['redeem_by'] : null,
            'archived'           => (bool) ($coupon['archived'] ?? false),
            'valid'              => (bool) ($coupon['valid'] ?? true),
            'codes'              => array_values(array_map(static fn (array $p): string => (string) ($p['code'] ?? ''), self::expanded($coupon['promotions'] ?? []))),
            'created_at'         => isset($coupon['created_at']) ? (int) $coupon['created_at'] : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function list_coupons(array $args): array
    {
        $query = isset($args['archived']) ? [ 'archived' => (bool) $args['archived'] ] : [];
        $list  = self::list_call('coupons', $args, $query, [ 'promotions' ]);
        return [ 'coupons' => array_map([ self::class, 'coupon_view' ], $list['items']) ] + self::page_meta($list);
    }

    /** @return array{total: int, page: int, per_page: int} */
    private static function page_meta(array $list): array
    {
        return [ 'total' => $list['total'], 'page' => $list['page'], 'per_page' => $list['per_page'] ];
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    /** @return array<string, mixed> the fields the call names, as SureCart takes them */
    private static function requested(array $args): array
    {
        $set = [];
        foreach (SureCart_Snapshot::FIELDS as $field) {
            if (array_key_exists($field, $args)) {
                $set[ $field ] = 'archived' === $field ? (bool) $args[ $field ] : (string) $args[ $field ];
            }
        }
        return $set;
    }

    /** @return array<string, mixed> the requested fields that differ from the product */
    private static function changes(array $args, array $product): array
    {
        $current = SureCart_Snapshot::fields($product);
        return array_filter(
            self::requested($args),
            static fn ($value, string $field): bool => $value !== $current[ $field ] && ! ('' === $value && null === $current[ $field ]),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /** @return array{code: string, message: string, data?: array}|null */
    private static function update_refusal(array $args): ?array
    {
        if ([] === self::requested($args)) {
            return [ 'code' => 'invalid_args', 'message' => 'Name at least one of: ' . implode(', ', SureCart_Snapshot::FIELDS) . '.' ];
        }
        try {
            $product = self::call('products', 'find', [ 'id' => (string) $args['product_id'] ]);
        } catch (Operation_Error $e) {
            return [ 'code' => $e->error_code(), 'message' => 'not_found' === $e->error_code() ? sprintf('SureCart has no product %s.', (string) $args['product_id']) : $e->getMessage() ];
        }
        if ([] === self::changes($args, $product)) {
            return [ 'code' => 'no_change', 'message' => 'The product already has those values; nothing to change.' ];
        }
        return null;
    }

    /** @return array<string, mixed> */
    private static function update_product(array $args, string $operation_id): array
    {
        $id      = (string) $args['product_id'];
        $product = self::call('products', 'find', [ 'id' => $id ]);
        $set     = self::changes($args, $product);
        $updated = SureCart_Api::call('products', 'update', [ 'id' => $id, 'attributes' => $set ]);
        if (is_wp_error($updated)) {
            // Nothing changed in SureCart, so the undo point goes too.
            Snapshot_Store::delete_operation($operation_id);
            throw self::error($updated);
        }
        SureCart_Snapshot::record_after($operation_id, $updated);

        return self::product_view($updated, true) + [ 'changed' => array_keys($set), 'check' => self::CHECK_NOTE ];
    }

    /** @return array{code: string, message: string}|null */
    private static function coupon_refusal(array $args): ?array
    {
        $percent  = isset($args['percent_off']);
        $amount   = isset($args['amount_off']);
        $duration = (string) ($args['duration'] ?? 'once');
        if ($percent === $amount) {
            return [ 'code' => 'invalid_args', 'message' => 'Pass exactly one of percent_off or amount_off.' ];
        }
        if ($amount && ! isset($args['currency'])) {
            return [ 'code' => 'invalid_args', 'message' => 'amount_off needs a currency (a three-letter code such as usd).' ];
        }
        if ('repeating' === $duration && ! isset($args['duration_in_months'])) {
            return [ 'code' => 'invalid_args', 'message' => 'A repeating coupon needs duration_in_months.' ];
        }
        if ('repeating' !== $duration && isset($args['duration_in_months'])) {
            return [ 'code' => 'invalid_args', 'message' => 'duration_in_months only applies to a repeating coupon.' ];
        }
        return null;
    }

    /** @return array<string, mixed> */
    private static function create_coupon(array $args, string $operation_id): array
    {
        $attributes = [ 'name' => (string) $args['name'], 'duration' => (string) ($args['duration'] ?? 'once') ];
        if (isset($args['percent_off'])) {
            $attributes['percent_off'] = $args['percent_off'] + 0;
        } else {
            $attributes['amount_off'] = (int) $args['amount_off'];
            $attributes['currency']   = strtolower((string) $args['currency']);
        }
        foreach ([ 'duration_in_months', 'max_redemptions' ] as $key) {
            if (isset($args[ $key ])) {
                $attributes[ $key ] = (int) $args[ $key ];
            }
        }
        // One promotion code, as SureCart's coupon screen creates it; an
        // empty one lets SureCart make the code.
        $attributes['promotions'] = [ isset($args['code']) ? [ 'code' => (string) $args['code'] ] : [] ];

        $coupon = SureCart_Api::call('coupons', 'create', [ 'attributes' => $attributes ]);
        if (is_wp_error($coupon)) {
            Snapshot_Store::delete_operation($operation_id);
            throw self::error($coupon);
        }
        SureCart_Snapshot::record_created($operation_id, (string) ($coupon['id'] ?? ''));

        return self::coupon_view($coupon);
    }
}
