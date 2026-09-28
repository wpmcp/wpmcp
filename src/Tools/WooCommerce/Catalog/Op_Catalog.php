<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The deep WooCommerce operations catalog (issue #68): a declarative map of
 * named store operations onto internal wc/v3 REST routes. The catalog is the
 * single source of truth the woo-read and woo-write dispatchers resolve
 * against, so coverage grows by adding rows here, not by writing a new tool
 * class per endpoint, and the store REST API remains the actual
 * implementation (HPOS-safe, always current with the installed WooCommerce).
 *
 * Op names are domain-namespaced with a dot (products.list, orders.get) so
 * they can never be confused with the free tools' ability names
 * (wpmcp/list-products, wpmcp/get-order), which return a different, smaller
 * shape.
 *
 * Route templates use {param} placeholders. Path params are substituted from
 * the caller's params after rawurlencode(); every remaining param is passed
 * through as a query param (reads, deletes) or body param (creates, updates),
 * where the endpoint's own schema validates it. Authorization is inherited
 * from the target endpoint's permission_callback via rest_do_request(); the
 * catalog never widens access. Each row also carries the wpmcp-layer
 * capability the dispatcher checks BEFORE dispatch, so this surface is never
 * looser than the free tool covering the same data (orders, notes and
 * refunds keep edit_shop_orders; customer reads keep list_users and customer
 * writes need edit_users / create_users; everything else is
 * manage_woocommerce).
 *
 * Every row has a mode:
 *  - read:        a GET, dispatched only by woo-read;
 *  - write:       a create or an update, dispatched only by woo-write;
 *  - destructive: a delete or a refund, dispatched only by woo-write, off
 *                 by default (the wpmcp_woo_op_enabled filter opts a site
 *                 in) and refused without confirm:true.
 *
 * Every write or destructive row that changes or removes EXISTING state
 * names a snapshot strategy, and woo-write routes it through Safe_Mutation
 * before dispatch so rollback-operation can undo it:
 *  - ['type' => 'post', 'param' => P]: products, variations and coupons are
 *    posts in every storage mode (HPOS moves orders only), so the full post
 *    row, meta and terms are captured and a force-delete is resurrectable;
 *  - ['type' => 'user', 'param' => P]: customers are users; the snapshot
 *    covers profile fields and usermeta (billing and shipping included) but
 *    never the password, so a password change is refused outright;
 *  - ['type' => 'wc_setting']: the wp_options row backing the setting,
 *    resolved from WooCommerce's own settings registry;
 *  - ['type' => 'wc_order', 'param' => P]: only the order status is
 *    captured, so rows using it are flagged recoverable:false;
 *  - ['type' => 'term', 'param' => P] or ['type' => 'term', 'create' => true]:
 *    one term of the row's taxonomy, keyed by (taxonomy, slug) with its meta
 *    and object assignments, so an update, a delete or a create (whose slug
 *    Brand_Ops derives before the write) rolls back exactly.
 * A row with no snapshot must be a POST that creates a new object: there is
 * no prior state to capture, the same exemption create-post and
 * create-product carry, and the response names the op that undoes it.
 *
 * Deliberately NOT in the catalog yet (each needs a snapshot type that does
 * not exist today, and a write with no honest undo does not belong on this
 * surface): order updates and deletes (the wc_order snapshot captures status
 * only, and HPOS orders are not posts), customer deletion (the user snapshot
 * cannot resurrect a deleted user), and writes to shipping zones, tax rates
 * and webhooks (custom tables with their own caches).
 *
 * The wc/v3 /batch endpoints are deliberately not rows either: woo-write
 * implements batching itself, one guarded and snapshotted op per item, so a
 * batch can never reach a mutating route around the per-op gates.
 */
class Op_Catalog
{
    /** Capability bands, matching the free WooCommerce tools' own split. */
    private const CAP_STORE          = 'manage_woocommerce';
    private const CAP_ORDERS         = 'edit_shop_orders';
    private const CAP_CUSTOMERS      = 'list_users';
    private const CAP_EDIT_CUSTOMERS = 'edit_users';
    private const CAP_ADD_CUSTOMERS  = 'create_users';

    private const MODES = ['read', 'write', 'destructive'];

    /**
     * meta_data keys a write may never set: role and capability storage
     * under any table prefix, and the login session store.
     */
    private const PRIVILEGE_META = '/(capabilities|user_level|session_tokens)$/i';

    /** WooCommerce's native brands taxonomy (issue #293). */
    private const BRAND_TAXONOMY = 'product_brand';

    /**
     * op name => [method, route template, domain, capability, summary, mode?, snapshot?, extra?].
     * mode defaults to 'read' and snapshot to null. extra carries per-op
     * posture: 'recoverable' (false when the snapshot cannot undo the whole
     * effect), 'forbidden_params' (refused before dispatch), 'forbidden_meta'
     * (a regex; a meta_data entry whose key matches is refused),
     * 'guard_variations' (refuse a delete, or a type change, of a product
     * that still has variations, which its snapshot would not cover), 'defaults'
     * (injected unless the caller sets them), 'undo_op' (for creates),
     * 'redact' (top-level keys of each returned record that are masked),
     * 'taxonomy' (the op is unavailable unless it is registered, and a term
     * snapshot targets it) and 'handler' (the op runs in-process through
     * Brand_Ops instead of dispatching its route).
     * Keep op names domain.kebab-case and route templates rooted at /wc/v3.
     */
    private const OPS = [
        // Products (catalog queries beyond the free list-products tool:
        // full endpoint filter surface, attributes, reviews).
        'products.list'       => [ 'GET', '/wc/v3/products', 'products', self::CAP_STORE, 'Query the product catalog with the full wc/v3 filter surface (sku, tag, attribute, min/max_price, on_sale, featured, stock_status, orderby...)' ],
        'products.get'        => [ 'GET', '/wc/v3/products/{id}', 'products', self::CAP_STORE, 'Full wc/v3 representation of one product' ],
        'products.attributes' => [ 'GET', '/wc/v3/products/attributes', 'products', self::CAP_STORE, 'Global product attributes (pa_* taxonomies)' ],
        'products.reviews'    => [ 'GET', '/wc/v3/products/reviews', 'products', self::CAP_STORE, 'Product reviews, filterable by product and status' ],
        'products.create'     => [ 'POST', '/wc/v3/products', 'products', self::CAP_STORE, 'Create a product of any type with the full wc/v3 field set', 'write', null, [ 'undo_op' => 'products.delete' ] ],
        // A product snapshot covers the product post only, while WooCommerce
        // trashes or deletes a variable product's variations along with it
        // and deletes them when its type changes away from variable. Both
        // are refused while variations exist ('guard_variations'): remove or
        // snapshot the variations first with the variations.* ops.
        'products.update'     => [ 'PUT', '/wc/v3/products/{id}', 'products', self::CAP_STORE, 'Update any wc/v3 product field (prices, stock, categories, attributes, images, meta...). Changing the type of a product that has variations is refused', 'write', [ 'type' => 'post', 'param' => 'id' ], [ 'guard_variations' => true ] ],
        'products.delete'     => [ 'DELETE', '/wc/v3/products/{id}', 'products', self::CAP_STORE, 'Trash a product, or delete it permanently with force:true. Refused while the product has variations (delete those first with variations.delete)', 'destructive', [ 'type' => 'post', 'param' => 'id' ], [ 'guard_variations' => true ] ],

        // Variations of a variable product. The free list-variations and
        // update-variation tools return curated rows; these are the raw
        // wc/v3 records and the full writable field set.
        'variations.list'     => [ 'GET', '/wc/v3/products/{product_id}/variations', 'variations', self::CAP_STORE, 'Variations of one variable product with the full wc/v3 filter surface' ],
        'variations.get'      => [ 'GET', '/wc/v3/products/{product_id}/variations/{id}', 'variations', self::CAP_STORE, 'Full wc/v3 representation of one variation' ],
        'variations.create'   => [ 'POST', '/wc/v3/products/{product_id}/variations', 'variations', self::CAP_STORE, 'Add a variation to a variable product', 'write', null, [ 'undo_op' => 'variations.delete' ] ],
        'variations.update'   => [ 'PUT', '/wc/v3/products/{product_id}/variations/{id}', 'variations', self::CAP_STORE, 'Update any wc/v3 variation field', 'write', [ 'type' => 'post', 'param' => 'id' ] ],
        'variations.delete'   => [ 'DELETE', '/wc/v3/products/{product_id}/variations/{id}', 'variations', self::CAP_STORE, 'Delete a variation (WooCommerce requires force:true for variations)', 'destructive', [ 'type' => 'post', 'param' => 'id' ] ],

        // Orders. Order data is PII-bearing, so these keep the narrower
        // edit_shop_orders the free order tools already require.
        'orders.list'         => [ 'GET', '/wc/v3/orders', 'orders', self::CAP_ORDERS, 'Query orders with the full wc/v3 filter surface (status, customer, product, date ranges, orderby...)' ],
        'orders.get'          => [ 'GET', '/wc/v3/orders/{id}', 'orders', self::CAP_ORDERS, 'Full wc/v3 representation of one order, line items included' ],
        'orders.notes'        => [ 'GET', '/wc/v3/orders/{order_id}/notes', 'orders', self::CAP_ORDERS, 'Notes on one order, including customer-facing ones' ],
        'orders.add-note'     => [ 'POST', '/wc/v3/orders/{order_id}/notes', 'orders', self::CAP_ORDERS, 'Add an internal or customer-facing (customer_note:true) note to an order. Additive', 'write', null, [ 'undo_op' => null ] ],

        // Refunds. Creating a refund moves money when api_refund is true
        // (the gateway is called), so it is destructive, and it is flagged
        // unrecoverable: the snapshot can restore the order's status but
        // cannot un-issue a refund. api_refund defaults to false here, the
        // opposite of the raw endpoint, so a gateway refund is always an
        // explicit choice.
        'refunds.list'        => [ 'GET', '/wc/v3/orders/{order_id}/refunds', 'refunds', self::CAP_ORDERS, 'Refunds recorded against one order' ],
        'refunds.get'         => [ 'GET', '/wc/v3/orders/{order_id}/refunds/{id}', 'refunds', self::CAP_ORDERS, 'One refund on one order' ],
        'refunds.create'      => [ 'POST', '/wc/v3/orders/{order_id}/refunds', 'refunds', self::CAP_ORDERS, 'Record a refund against an order (amount, reason, line_items). api_refund defaults to false: pass api_refund:true to also refund through the payment gateway', 'destructive', [ 'type' => 'wc_order', 'param' => 'order_id' ], [ 'recoverable' => false, 'defaults' => [ 'api_refund' => false ] ] ],

        // Coupons.
        'coupons.list'        => [ 'GET', '/wc/v3/coupons', 'coupons', self::CAP_STORE, 'Query coupons (code search, paging)' ],
        'coupons.get'         => [ 'GET', '/wc/v3/coupons/{id}', 'coupons', self::CAP_STORE, 'Full wc/v3 representation of one coupon' ],
        'coupons.create'      => [ 'POST', '/wc/v3/coupons', 'coupons', self::CAP_STORE, 'Create a coupon (code, discount_type, amount, limits, restrictions)', 'write', null, [ 'undo_op' => 'coupons.delete' ] ],
        'coupons.update'      => [ 'PUT', '/wc/v3/coupons/{id}', 'coupons', self::CAP_STORE, 'Update any wc/v3 coupon field', 'write', [ 'type' => 'post', 'param' => 'id' ] ],
        'coupons.delete'      => [ 'DELETE', '/wc/v3/coupons/{id}', 'coupons', self::CAP_STORE, 'Trash a coupon, or delete it permanently with force:true', 'destructive', [ 'type' => 'post', 'param' => 'id' ] ],

        // Customers. Customer records are user records, so reads take the
        // user-listing capability and writes the user-editing ones.
        'customers.list'      => [ 'GET', '/wc/v3/customers', 'customers', self::CAP_CUSTOMERS, 'Query store customers (email, role, search, paging)' ],
        'customers.get'       => [ 'GET', '/wc/v3/customers/{id}', 'customers', self::CAP_CUSTOMERS, 'One customer with billing/shipping profile' ],
        // meta_data keys that grant roles or capabilities are refused on
        // both: WooCommerce only strips underscore-prefixed keys, so a
        // "{prefix}capabilities" entry would otherwise let an identity that
        // may edit customers (a shop manager, say) promote one to
        // administrator.
        'customers.create'    => [ 'POST', '/wc/v3/customers', 'customers', self::CAP_ADD_CUSTOMERS, 'Create a customer account (email required; billing and shipping optional)', 'write', null, [ 'undo_op' => null, 'forbidden_meta' => self::PRIVILEGE_META ] ],
        'customers.update'    => [ 'PUT', '/wc/v3/customers/{id}', 'customers', self::CAP_EDIT_CUSTOMERS, 'Update a customer\'s name, email, billing and shipping profile. Password changes are refused: they could not be rolled back', 'write', [ 'type' => 'user', 'param' => 'id' ], [ 'forbidden_params' => [ 'password' ], 'forbidden_meta' => self::PRIVILEGE_META ] ],

        // Brands (issue #293): WooCommerce's product_brand taxonomy. Brand
        // writes are term-snapshotted; images must be media images or pass
        // the remote media guard. assign and unassign run in-process (the
        // products endpoint ignores an empty brands list, so it could not
        // remove a last brand) under a snapshot of the product.
        'brands.list'         => [ 'GET', '/wc/v3/products/brands', 'brands', self::CAP_STORE, 'Product brands', 'read', null, [ 'taxonomy' => self::BRAND_TAXONOMY ] ],
        'brands.get'          => [ 'GET', '/wc/v3/products/brands/{id}', 'brands', self::CAP_STORE, 'One product brand', 'read', null, [ 'taxonomy' => self::BRAND_TAXONOMY ] ],
        'brands.create'       => [ 'POST', '/wc/v3/products/brands', 'brands', self::CAP_STORE, 'Create a brand (name, slug, parent, description, image {id} or allowlisted {src})', 'write', [ 'type' => 'term', 'create' => true ], [ 'taxonomy' => self::BRAND_TAXONOMY, 'undo_op' => 'brands.delete' ] ],
        'brands.update'       => [ 'PUT', '/wc/v3/products/brands/{id}', 'brands', self::CAP_STORE, 'Update a brand, image included', 'write', [ 'type' => 'term', 'param' => 'id' ], [ 'taxonomy' => self::BRAND_TAXONOMY ] ],
        'brands.delete'       => [ 'DELETE', '/wc/v3/products/brands/{id}', 'brands', self::CAP_STORE, 'Delete a brand; the refusal without confirm says how many products use it', 'destructive', [ 'type' => 'term', 'param' => 'id' ], [ 'taxonomy' => self::BRAND_TAXONOMY, 'defaults' => [ 'force' => true ] ] ],
        'brands.assign'       => [ 'PUT', '/wc/v3/products/{product_id}', 'brands', self::CAP_STORE, 'Add brands (a list of brand ids) to a product', 'write', [ 'type' => 'post', 'param' => 'product_id' ], [ 'taxonomy' => self::BRAND_TAXONOMY, 'handler' => 'assign' ] ],
        'brands.unassign'     => [ 'PUT', '/wc/v3/products/{product_id}', 'brands', self::CAP_STORE, 'Remove brands (a list of brand ids) from a product', 'write', [ 'type' => 'post', 'param' => 'product_id' ], [ 'taxonomy' => self::BRAND_TAXONOMY, 'handler' => 'unassign' ] ],

        // Shipping.
        'shipping.zones'        => [ 'GET', '/wc/v3/shipping/zones', 'shipping', self::CAP_STORE, 'Configured shipping zones' ],
        'shipping.zone-methods' => [ 'GET', '/wc/v3/shipping/zones/{zone_id}/methods', 'shipping', self::CAP_STORE, 'Shipping methods enabled in one zone' ],

        // Taxes.
        'taxes.rates'         => [ 'GET', '/wc/v3/taxes', 'taxes', self::CAP_STORE, 'Tax rates, filterable by class' ],
        'taxes.classes'       => [ 'GET', '/wc/v3/taxes/classes', 'taxes', self::CAP_STORE, 'Defined tax classes' ],

        // Webhooks. The signing secret is redacted from the body: it
        // authenticates deliveries to a third party and has no use in a
        // model context.
        'webhooks.list'       => [ 'GET', '/wc/v3/webhooks', 'webhooks', self::CAP_STORE, 'Registered store webhooks and their delivery status (signing secret redacted)', 'read', null, [ 'redact' => [ 'secret' ] ] ],
        'webhooks.get'        => [ 'GET', '/wc/v3/webhooks/{id}', 'webhooks', self::CAP_STORE, 'One webhook: topic, delivery URL, status (signing secret redacted)', 'read', null, [ 'redact' => [ 'secret' ] ] ],

        // Settings.
        'settings.groups'     => [ 'GET', '/wc/v3/settings', 'settings', self::CAP_STORE, 'Store settings groups (general, products, tax, shipping, ...)' ],
        'settings.options'    => [ 'GET', '/wc/v3/settings/{group_id}', 'settings', self::CAP_STORE, 'All options in one settings group with current values' ],
        'settings.update'     => [ 'PUT', '/wc/v3/settings/{group_id}/{id}', 'settings', self::CAP_STORE, 'Set one store setting (pass value). Snapshotted as the option that backs it', 'write', [ 'type' => 'wc_setting' ] ],
    ];

    /**
     * @return array<string, array{method: string, route: string, domain: string, capability: string, summary: string, path_params: string[], mode: string, snapshot: ?array, recoverable: bool, forbidden_params: string[], defaults: array<string, mixed>, undo_op: ?string, redact: string[], forbidden_meta: ?string, guard_variations: bool, taxonomy: ?string, handler: ?string}>
     */
    public static function ops(): array
    {
        $out = [];
        foreach (self::OPS as $name => $row) {
            [$method, $route, $domain, $capability, $summary] = $row;
            $mode     = $row[5] ?? 'read';
            $snapshot = $row[6] ?? null;
            $extra    = $row[7] ?? [];

            $out[$name] = [
                'method'           => $method,
                'route'            => $route,
                'domain'           => $domain,
                'capability'       => $capability,
                'summary'          => $summary,
                'path_params'      => self::path_params($route),
                'mode'             => in_array($mode, self::MODES, true) ? $mode : 'read',
                'snapshot'         => $snapshot,
                // A write is recoverable when it has a snapshot target and
                // the row does not say the snapshot falls short. Reads have
                // nothing to recover.
                'recoverable'      => 'read' !== $mode && null !== $snapshot && ($extra['recoverable'] ?? true),
                'forbidden_params' => $extra['forbidden_params'] ?? [],
                'defaults'         => $extra['defaults'] ?? [],
                'undo_op'          => $extra['undo_op'] ?? null,
                'redact'           => $extra['redact'] ?? [],
                'forbidden_meta'   => $extra['forbidden_meta'] ?? null,
                'guard_variations' => (bool) ($extra['guard_variations'] ?? false),
                'taxonomy'         => $extra['taxonomy'] ?? null,
                'handler'          => $extra['handler'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * @return array{method: string, route: string, domain: string, capability: string, summary: string, path_params: string[], mode: string, snapshot: ?array, recoverable: bool, forbidden_params: string[], defaults: array<string, mixed>, undo_op: ?string, redact: string[], forbidden_meta: ?string, guard_variations: bool, taxonomy: ?string, handler: ?string}
     */
    public static function get(string $op): array
    {
        $ops = self::ops();
        if (! isset($ops[$op])) {
            throw new \InvalidArgumentException(esc_html("Unknown WooCommerce op \"{$op}\". Call woo-ops for the catalog."));
        }
        return $ops[$op];
    }

    /**
     * The Ability operation vocabulary an op maps onto, for governance:
     * reads are 'read', destructive ops 'delete', a POST that creates
     * 'create', and every other write 'update'.
     *
     * @param array{method: string, mode: string} $def
     */
    public static function governance_operation(array $def): string
    {
        if ('destructive' === $def['mode']) {
            return 'delete';
        }
        if ('write' === $def['mode']) {
            return 'POST' === $def['method'] ? 'create' : 'update';
        }
        return 'read';
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
                throw new \InvalidArgumentException(esc_html(
                    "Path param \"{$name}\" of op \"{$op}\" must be a scalar, "
                    . gettype($value) . ' given.'
                ));
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
            throw new \InvalidArgumentException(esc_html(
                "Op \"{$op}\" requires params: " . implode(', ', $missing) . '.'
            ));
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
