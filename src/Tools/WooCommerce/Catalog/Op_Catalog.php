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
 * Op names are domain-namespaced with a dot (products.list, orders.get) so
 * they can never be confused with the free tools' ability names
 * (wpmcp/list-products, wpmcp/get-order), which return a different, smaller
 * shape under a different tier.
 *
 * Route templates use {param} placeholders. Path params are substituted from
 * the caller's params after rawurlencode(); every remaining param is passed
 * through as a query param, where the endpoint's own schema validates it.
 * Authorization is inherited from the target endpoint's permission_callback
 * via rest_do_request(); the catalog never widens access. Each row also
 * carries the wpmcp-layer capability the dispatcher checks BEFORE dispatch,
 * so this surface is never looser than the free tool covering the same data
 * (orders, notes and refunds keep edit_shop_orders; customers keep
 * list_users; everything else is manage_woocommerce).
 *
 * This first slice is deliberately read-only: every op is a GET. That keeps
 * the slice free of confirm gates and snapshot questions while proving the
 * catalog + dispatcher architecture across nine of the ten domains the issue
 * names. Variations is the missing tenth: PR #203 ships dedicated free-tier
 * variation tools and the catalog rows land after it merges, so the two
 * surfaces share one description of the shape.
 *
 * TODO(#68): write ops (create/update) with per-op snapshot strategy where
 *            a snapshot type exists (products/orders are posts), and honest
 *            recoverable:false where none does yet.
 * TODO(#68): destructive ops (delete, refund creation) behind per-op confirm
 *            plus an opt-in filter. The confirm gate belongs in the write
 *            dispatcher itself, not in a caller, so no sibling can reach a
 *            mutating route without passing it (Wc_Rest_Dispatch is GET-only
 *            by construction for exactly that reason).
 * TODO(#68): batch ops (wc/v3 /batch endpoints) with per-item outcomes.
 * TODO(#68): variation ops. PR #203 ships dedicated free-tier variation
 *            tools; the catalog rows should land after that merges so the
 *            two surfaces reference one shared description of the shape.
 */
class Op_Catalog
{
    /** Capability bands, matching the free WooCommerce tools' own split. */
    private const CAP_STORE     = 'manage_woocommerce';
    private const CAP_ORDERS    = 'edit_shop_orders';
    private const CAP_CUSTOMERS = 'list_users';

    /**
     * op name => [method, route template, domain, capability, summary].
     * Keep op names domain.kebab-case and route templates rooted at /wc/v3.
     */
    private const OPS = [
        // Products (catalog queries beyond the free list-products tool:
        // full endpoint filter surface, attributes, reviews).
        'products.list'       => [ 'GET', '/wc/v3/products', 'products', self::CAP_STORE, 'Query the product catalog with the full wc/v3 filter surface (sku, tag, attribute, min/max_price, on_sale, featured, stock_status, orderby...)' ],
        'products.get'        => [ 'GET', '/wc/v3/products/{id}', 'products', self::CAP_STORE, 'Full wc/v3 representation of one product' ],
        'products.attributes' => [ 'GET', '/wc/v3/products/attributes', 'products', self::CAP_STORE, 'Global product attributes (pa_* taxonomies)' ],
        'products.reviews'    => [ 'GET', '/wc/v3/products/reviews', 'products', self::CAP_STORE, 'Product reviews, filterable by product and status' ],

        // Orders. Order data is PII-bearing, so these keep the narrower
        // edit_shop_orders the free order tools already require.
        'orders.list'         => [ 'GET', '/wc/v3/orders', 'orders', self::CAP_ORDERS, 'Query orders with the full wc/v3 filter surface (status, customer, product, date ranges, orderby...)' ],
        'orders.get'          => [ 'GET', '/wc/v3/orders/{id}', 'orders', self::CAP_ORDERS, 'Full wc/v3 representation of one order, line items included' ],
        'orders.notes'        => [ 'GET', '/wc/v3/orders/{order_id}/notes', 'orders', self::CAP_ORDERS, 'Notes on one order, including customer-facing ones' ],

        // Refunds (read-only here; creating a refund is a destructive op,
        // see the class TODOs).
        'refunds.list'        => [ 'GET', '/wc/v3/orders/{order_id}/refunds', 'refunds', self::CAP_ORDERS, 'Refunds recorded against one order' ],
        'refunds.get'         => [ 'GET', '/wc/v3/orders/{order_id}/refunds/{id}', 'refunds', self::CAP_ORDERS, 'One refund on one order' ],

        // Coupons.
        'coupons.list'        => [ 'GET', '/wc/v3/coupons', 'coupons', self::CAP_STORE, 'Query coupons (code search, paging)' ],
        'coupons.get'         => [ 'GET', '/wc/v3/coupons/{id}', 'coupons', self::CAP_STORE, 'Full wc/v3 representation of one coupon' ],

        // Customers. Customer records are user records, so they take the
        // user-listing capability on top of the store capability band.
        'customers.list'      => [ 'GET', '/wc/v3/customers', 'customers', self::CAP_CUSTOMERS, 'Query store customers (email, role, search, paging)' ],
        'customers.get'       => [ 'GET', '/wc/v3/customers/{id}', 'customers', self::CAP_CUSTOMERS, 'One customer with billing/shipping profile' ],

        // Shipping.
        'shipping.zones'        => [ 'GET', '/wc/v3/shipping/zones', 'shipping', self::CAP_STORE, 'Configured shipping zones' ],
        'shipping.zone-methods' => [ 'GET', '/wc/v3/shipping/zones/{zone_id}/methods', 'shipping', self::CAP_STORE, 'Shipping methods enabled in one zone' ],

        // Taxes.
        'taxes.rates'         => [ 'GET', '/wc/v3/taxes', 'taxes', self::CAP_STORE, 'Tax rates, filterable by class' ],
        'taxes.classes'       => [ 'GET', '/wc/v3/taxes/classes', 'taxes', self::CAP_STORE, 'Defined tax classes' ],

        // Webhooks.
        'webhooks.list'       => [ 'GET', '/wc/v3/webhooks', 'webhooks', self::CAP_STORE, 'Registered store webhooks and their delivery status' ],
        'webhooks.get'        => [ 'GET', '/wc/v3/webhooks/{id}', 'webhooks', self::CAP_STORE, 'One webhook (topic, delivery URL, status)' ],

        // Settings.
        'settings.groups'     => [ 'GET', '/wc/v3/settings', 'settings', self::CAP_STORE, 'Store settings groups (general, products, tax, shipping, ...)' ],
        'settings.options'    => [ 'GET', '/wc/v3/settings/{group_id}', 'settings', self::CAP_STORE, 'All options in one settings group with current values' ],
    ];

    /**
     * @return array<string, array{method: string, route: string, domain: string, capability: string, summary: string, path_params: string[]}>
     */
    public static function ops(): array
    {
        $out = [];
        foreach (self::OPS as $name => [$method, $route, $domain, $capability, $summary]) {
            $out[$name] = [
                'method'      => $method,
                'route'       => $route,
                'domain'      => $domain,
                'capability'  => $capability,
                'summary'     => $summary,
                'path_params' => self::path_params($route),
            ];
        }
        return $out;
    }

    /**
     * @return array{method: string, route: string, domain: string, capability: string, summary: string, path_params: string[]}
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
     * The ability schema types params as a bare object, so a caller can put
     * anything in a path param slot. Non-scalars are rejected outright: a
     * bare (string) cast on an array would emit an "Array to string
     * conversion" warning and silently dispatch /wc/v3/products/Array, and on
     * an object without __toString it would throw a raw \Error.
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
            if (! isset($params[$name])) {
                $missing[] = $name;
                continue;
            }

            $value = $params[$name];
            if (! is_scalar($value)) {
                throw new \InvalidArgumentException(
                    "Path param \"{$name}\" of op \"{$op}\" must be a scalar, "
                    . gettype($value) . ' given.'
                );
            }
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            $value = (string) $value;
            if ('' === $value) {
                $missing[] = $name;
                continue;
            }

            $route = str_replace('{' . $name . '}', rawurlencode($value), $route);
            unset($params[$name]);
        }

        if ($missing) {
            throw new \InvalidArgumentException(
                "Op \"{$op}\" requires params: " . implode(', ', $missing) . '.'
            );
        }

        return [ $route, $params ];
    }

    /**
     * Placeholder names in a route template. Deliberately wider than the
     * names currently in use so a future row with a digit or an uppercase
     * letter in its placeholder is substituted rather than silently
     * dispatched with a literal {...} still in the route.
     *
     * @return string[]
     */
    private static function path_params(string $route): array
    {
        preg_match_all('/\{([A-Za-z0-9_]+)\}/', $route, $m);
        return $m[1];
    }
}
