<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The deep WooCommerce operations catalog (issue #68): a declarative map of
 * named store operations onto internal wc/v3 REST routes. The catalog is the
 * single source of truth the woo-read (and later woo-write) dispatchers
 * resolve against, so coverage grows by adding rows here, not by writing a
 * new tool class per endpoint, and the store REST API remains the actual
 * implementation (HPOS-safe, always current with the installed WooCommerce).
 *
 * Route templates use {param} placeholders. Path params are substituted from
 * the caller's params after rawurlencode(); every remaining param is passed
 * through as a query param, where the endpoint's own schema validates it.
 * Authorization is entirely inherited from the target endpoint's
 * permission_callback via rest_do_request() (see Call_Rest); the catalog
 * never widens access.
 *
 * This first slice is deliberately read-only: every op is a GET. That keeps
 * the slice free of confirm gates and snapshot questions while proving the
 * catalog + dispatcher architecture across all ten domains the issue names.
 *
 * TODO(#68): write ops (create/update) with per-op snapshot strategy where
 *            a snapshot type exists (products/orders are posts), and honest
 *            recoverable:false where none does yet.
 * TODO(#68): destructive ops (delete, refund creation) behind per-op confirm
 *            plus an opt-in filter, mirroring Call_Rest's write posture.
 * TODO(#68): batch ops (wc/v3 /batch endpoints) with per-item outcomes.
 * TODO(#68): variation ops. PR #203 ships dedicated free-tier variation
 *            tools; the catalog rows should land after that merges so the
 *            two surfaces reference one shared description of the shape.
 * TODO(#68): route-catalog integrity test in tests/pro asserting every row
 *            resolves to a registered wc/v3 route (acceptance criterion 1).
 */
class Op_Catalog
{
    /**
     * op name => [method, route template, domain, summary].
     * Keep ops kebab-case and route templates rooted at /wc/v3.
     */
    private const OPS = [
        // Products (catalog queries beyond the free list-products tool:
        // full endpoint filter surface, attributes, reviews).
        'list-products'          => [ 'GET', '/wc/v3/products', 'products', 'Query the product catalog with the full wc/v3 filter surface (sku, tag, attribute, min/max_price, on_sale, featured, stock_status, orderby...)' ],
        'get-product'            => [ 'GET', '/wc/v3/products/{id}', 'products', 'Full wc/v3 representation of one product' ],
        'list-product-attributes' => [ 'GET', '/wc/v3/products/attributes', 'products', 'Global product attributes (pa_* taxonomies)' ],
        'list-product-reviews'   => [ 'GET', '/wc/v3/products/reviews', 'products', 'Product reviews, filterable by product and status' ],

        // Orders.
        'list-orders'            => [ 'GET', '/wc/v3/orders', 'orders', 'Query orders with the full wc/v3 filter surface (status, customer, product, date ranges, orderby...)' ],
        'get-order'              => [ 'GET', '/wc/v3/orders/{id}', 'orders', 'Full wc/v3 representation of one order, line items included' ],
        'list-order-notes'       => [ 'GET', '/wc/v3/orders/{order_id}/notes', 'orders', 'Notes on one order, including customer-facing ones' ],

        // Refunds (read-only here; creating a refund is a destructive op,
        // see the class TODOs).
        'list-order-refunds'     => [ 'GET', '/wc/v3/orders/{order_id}/refunds', 'refunds', 'Refunds recorded against one order' ],
        'get-order-refund'       => [ 'GET', '/wc/v3/orders/{order_id}/refunds/{id}', 'refunds', 'One refund on one order' ],

        // Coupons.
        'list-coupons'           => [ 'GET', '/wc/v3/coupons', 'coupons', 'Query coupons (code search, paging)' ],
        'get-coupon'             => [ 'GET', '/wc/v3/coupons/{id}', 'coupons', 'Full wc/v3 representation of one coupon' ],

        // Customers.
        'list-customers'         => [ 'GET', '/wc/v3/customers', 'customers', 'Query store customers (email, role, search, paging)' ],
        'get-customer'           => [ 'GET', '/wc/v3/customers/{id}', 'customers', 'One customer with billing/shipping profile' ],

        // Shipping.
        'list-shipping-zones'    => [ 'GET', '/wc/v3/shipping/zones', 'shipping', 'Configured shipping zones' ],
        'list-shipping-zone-methods' => [ 'GET', '/wc/v3/shipping/zones/{zone_id}/methods', 'shipping', 'Shipping methods enabled in one zone' ],

        // Taxes.
        'list-tax-rates'         => [ 'GET', '/wc/v3/taxes', 'taxes', 'Tax rates, filterable by class' ],
        'list-tax-classes'       => [ 'GET', '/wc/v3/taxes/classes', 'taxes', 'Defined tax classes' ],

        // Webhooks.
        'list-webhooks'          => [ 'GET', '/wc/v3/webhooks', 'webhooks', 'Registered store webhooks and their delivery status' ],
        'get-webhook'            => [ 'GET', '/wc/v3/webhooks/{id}', 'webhooks', 'One webhook (topic, delivery URL, status)' ],

        // Settings.
        'list-settings-groups'   => [ 'GET', '/wc/v3/settings', 'settings', 'Store settings groups (general, products, tax, shipping, ...)' ],
        'list-settings-options'  => [ 'GET', '/wc/v3/settings/{group_id}', 'settings', 'All options in one settings group with current values' ],
    ];

    /**
     * @return array<string, array{method: string, route: string, domain: string, summary: string, path_params: string[]}>
     */
    public static function ops(): array
    {
        $out = [];
        foreach (self::OPS as $name => [$method, $route, $domain, $summary]) {
            $out[$name] = [
                'method'      => $method,
                'route'       => $route,
                'domain'      => $domain,
                'summary'     => $summary,
                'path_params' => self::path_params($route),
            ];
        }
        return $out;
    }

    /**
     * @return array{method: string, route: string, domain: string, summary: string, path_params: string[]}
     */
    public static function get(string $op): array
    {
        $ops = self::ops();
        if (! isset($ops[$op])) {
            throw new \InvalidArgumentException("Unknown WooCommerce op \"{$op}\". Call woo-ops for the catalog.");
        }
        return $ops[$op];
    }

    /**
     * Substitute the route template's {param} placeholders from $params and
     * return the concrete route plus the params that remain (to be sent as
     * query params). Every path param is required; missing ones are reported
     * together in one error.
     *
     * @param array<string, mixed> $params
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function resolve_route(string $op, array $params): array
    {
        $def     = self::get($op);
        $route   = $def['route'];
        $missing = [];

        foreach ($def['path_params'] as $name) {
            if (! isset($params[$name]) || '' === (string) $params[$name]) {
                $missing[] = $name;
                continue;
            }
            $route = str_replace('{' . $name . '}', rawurlencode((string) $params[$name]), $route);
            unset($params[$name]);
        }

        if ($missing) {
            throw new \InvalidArgumentException(
                "Op \"{$op}\" requires params: " . implode(', ', $missing) . '.'
            );
        }

        return [ $route, $params ];
    }

    /** @return string[] */
    private static function path_params(string $route): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $route, $m);
        return $m[1];
    }
}
