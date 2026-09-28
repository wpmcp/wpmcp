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
 *  - ['type' => 'wc_order_full', 'param' => P]: the whole order (props,
 *    addresses, totals, every item and its meta), restored exactly in
 *    either order store (Safety\Wc_Order_Snapshot);
 *  - ['type' => 'wc_order_create', 'create' => true]: the order does not
 *    exist yet, so Order_Ops records a creation row once it does, and
 *    rollback moves the order to the trash;
 *  - ['type' => 'wc_shipping_zone', 'param' => P]: the whole zone (zone
 *    row, location rows, method rows and each method's settings option),
 *    so zone edits and method adds, edits and removals all roll back
 *    exactly (Safety\Wc_Shipping_Zone_Snapshot);
 *  - ['type' => 'wc_shipping_zone_create', 'create' => true] and
 *    ['type' => 'wc_webhook_create', 'create' => true]: the zone or webhook
 *    does not exist yet, so its handler records a creation row once it does,
 *    and rollback deletes it (store configuration, not content);
 *  - ['type' => 'comment', 'param' => P]: a product review's full comment
 *    row and all its meta (rating, verified); ['type' => 'comment_create',
 *    'create' => true]: a reply does not exist yet, so Review_Ops records a
 *    creation row once it does, and rollback trashes the reply;
 *  - ['type' => 'wc_webhook', 'param' => P]: the raw webhook row, secret
 *    included but never shown (Safety\Wc_Webhook_Snapshot);
 *  - ['type' => 'wc_gateway', 'param' => P]: the one option a gateway write
 *    changes (its settings option, or the gateway order option for an
 *    order change), restorable only with manage_woocommerce;
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
 * surface): order deletes, customer deletion (the user snapshot cannot
 * resurrect a deleted user), and tax rate writes through this catalog (the
 * dedicated tax rate tools carry their own snapshot type).
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
    private const CAP_REVIEWS        = 'moderate_comments';

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
     * snapshot targets it) and 'handler' (the op runs in-process, through
     * Brand_Ops or, for an order_*, shipping_*, webhook_*, review_*,
     * report_*, gateway_* or status_* handler, the matching *_Ops class,
     * instead of dispatching its route; status_run_tool only validates, and
     * its route is dispatched).
     * Keep op names domain.kebab-case and route templates rooted at /wc/v3.
     */
    private const OPS = [
        // Products (catalog queries beyond the free list-products tool:
        // full endpoint filter surface, attributes, reviews).
        'products.list'       => [ 'GET', '/wc/v3/products', 'products', self::CAP_STORE, 'Query the product catalog with the full wc/v3 filter surface (sku, tag, attribute, min/max_price, on_sale, featured, stock_status, orderby...)' ],
        'products.get'        => [ 'GET', '/wc/v3/products/{id}', 'products', self::CAP_STORE, 'Full wc/v3 representation of one product' ],
        'products.attributes' => [ 'GET', '/wc/v3/products/attributes', 'products', self::CAP_STORE, 'Global product attributes (pa_* taxonomies)' ],
        'products.reviews'    => [ 'GET', '/wc/v3/products/reviews', 'products', self::CAP_STORE, 'Raw wc/v3 product reviews, filterable by product and status (reviews.list adds rating filters)' ],
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
        // Order create and edit (issue #292) run in-process through the
        // WooCommerce order CRUD (Order_Ops), so HPOS and the legacy store
        // both work and no gateway is ever called. An edit is covered by a
        // full order snapshot (props, addresses, totals, items, item meta);
        // a create writes a creation row whose rollback trashes the order.
        'orders.create'       => [ 'POST', '/wc/v3/orders', 'orders', self::CAP_STORE, 'Create an order: line_items [{product_id, variation_id, quantity}], customer_id, billing, shipping, shipping_lines, fee_lines, coupon_codes, status, payment_method(_title), customer_note. Never charges a gateway; rollback trashes it', 'write', [ 'type' => 'wc_order_create', 'create' => true ], [ 'handler' => 'order_create', 'forbidden_params' => [ 'set_paid', 'transaction_id', 'meta_data' ] ] ],
        'orders.update'       => [ 'PUT', '/wc/v3/orders/{id}', 'orders', self::CAP_STORE, 'Edit an order: line_items (add {product_id, variation_id, quantity}, change {id, quantity}, drop {id, remove:true}), shipping_lines and fee_lines (same shape), billing, shipping, customer_note, payment_method(_title); totals recalculated unless recalculate:false. Status changes go through update-order-status', 'write', [ 'type' => 'wc_order_full', 'param' => 'id' ], [ 'handler' => 'order_update', 'forbidden_params' => [ 'status', 'set_paid', 'transaction_id', 'meta_data', 'coupon_lines', 'customer_id' ] ] ],

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

        // Shipping. Zone reads and every zone and method write (issue #292)
        // run in-process through WC_Shipping_Zone (Shipping_Ops). Each write
        // to an existing zone, its methods included, is covered by a
        // whole-zone snapshot (zone row, locations, method rows and each
        // method's settings option); a zone create writes a creation row
        // whose rollback deletes the zone.
        'shipping.zones'         => [ 'GET', '/wc/v3/shipping/zones', 'shipping', self::CAP_STORE, 'Every shipping zone (the "locations not covered" zone 0 last) with its locations and methods', 'read', null, [ 'handler' => 'shipping_zones' ] ],
        'shipping.zone'          => [ 'GET', '/wc/v3/shipping/zones/{id}', 'shipping', self::CAP_STORE, 'One shipping zone with its locations and methods, core method settings included', 'read', null, [ 'handler' => 'shipping_zone' ] ],
        'shipping.zone-methods'  => [ 'GET', '/wc/v3/shipping/zones/{zone_id}/methods', 'shipping', self::CAP_STORE, 'Shipping methods enabled in one zone' ],
        'shipping.create-zone'   => [ 'POST', '/wc/v3/shipping/zones', 'shipping', self::CAP_STORE, 'Create a shipping zone: name, order, locations [{code, type: country|state|continent|postcode}]. Rollback deletes it', 'write', [ 'type' => 'wc_shipping_zone_create', 'create' => true ], [ 'handler' => 'shipping_zone_create' ] ],
        'shipping.update-zone'   => [ 'PUT', '/wc/v3/shipping/zones/{id}', 'shipping', self::CAP_STORE, 'Change a zone\'s name, order or locations (the list replaces the current one)', 'write', [ 'type' => 'wc_shipping_zone', 'param' => 'id' ], [ 'handler' => 'shipping_zone_update' ] ],
        'shipping.delete-zone'   => [ 'DELETE', '/wc/v3/shipping/zones/{id}', 'shipping', self::CAP_STORE, 'Delete a zone with its methods and their settings', 'destructive', [ 'type' => 'wc_shipping_zone', 'param' => 'id' ], [ 'handler' => 'shipping_zone_delete' ] ],
        'shipping.add-method'    => [ 'POST', '/wc/v3/shipping/zones/{zone_id}/methods', 'shipping', self::CAP_STORE, 'Add a flat_rate, free_shipping or local_pickup method to a zone (0 = locations not covered): method_id, enabled, order, settings. Rollback removes it', 'write', [ 'type' => 'wc_shipping_zone', 'param' => 'zone_id' ], [ 'handler' => 'shipping_method_add' ] ],
        'shipping.update-method' => [ 'PUT', '/wc/v3/shipping/zones/{zone_id}/methods/{instance_id}', 'shipping', self::CAP_STORE, 'Change a core method\'s enabled flag, order or settings (validated against its own fields)', 'write', [ 'type' => 'wc_shipping_zone', 'param' => 'zone_id' ], [ 'handler' => 'shipping_method_update' ] ],
        'shipping.remove-method' => [ 'DELETE', '/wc/v3/shipping/zones/{zone_id}/methods/{instance_id}', 'shipping', self::CAP_STORE, 'Remove a core method from a zone, with its settings', 'destructive', [ 'type' => 'wc_shipping_zone', 'param' => 'zone_id' ], [ 'handler' => 'shipping_method_remove' ] ],

        // Reviews (issue #292): product comments, run in-process through
        // the comments API (Review_Ops). Every op needs moderate_comments
        // plus edit_product for the review's product. Moderation and edits
        // snapshot the comment row and all its meta (rating, verified); a
        // reply is posted as the current user and records a comment_create
        // row whose rollback trashes it. Listing never shows reviewer emails
        // or IPs.
        'reviews.list'        => [ 'GET', '/wc/v3/products/reviews', 'reviews', self::CAP_REVIEWS, 'Product reviews and replies, filterable by product_id, rating (1-5), status (approved, hold, spam, trash, all) and type (review, reply, all); no reviewer emails', 'read', null, [ 'handler' => 'review_list' ] ],
        'reviews.approve'     => [ 'PUT', '/wc/v3/products/reviews/{id}', 'reviews', self::CAP_REVIEWS, 'Approve a review', 'write', [ 'type' => 'comment', 'param' => 'id' ], [ 'handler' => 'review_approve' ] ],
        'reviews.unapprove'   => [ 'PUT', '/wc/v3/products/reviews/{id}', 'reviews', self::CAP_REVIEWS, 'Hold a review for moderation', 'write', [ 'type' => 'comment', 'param' => 'id' ], [ 'handler' => 'review_unapprove' ] ],
        'reviews.spam'        => [ 'PUT', '/wc/v3/products/reviews/{id}', 'reviews', self::CAP_REVIEWS, 'Mark a review as spam', 'write', [ 'type' => 'comment', 'param' => 'id' ], [ 'handler' => 'review_spam' ] ],
        'reviews.trash'       => [ 'DELETE', '/wc/v3/products/reviews/{id}', 'reviews', self::CAP_REVIEWS, 'Move a review to the trash', 'destructive', [ 'type' => 'comment', 'param' => 'id' ], [ 'handler' => 'review_trash' ] ],
        'reviews.update'      => [ 'PUT', '/wc/v3/products/reviews/{id}', 'reviews', self::CAP_REVIEWS, 'Edit a review\'s text (content); rating and verified are kept', 'write', [ 'type' => 'comment', 'param' => 'id' ], [ 'handler' => 'review_update' ] ],
        'reviews.reply'       => [ 'POST', '/wc/v3/products/reviews', 'reviews', self::CAP_REVIEWS, 'Reply to a review (id, content) as the store, approved. Rollback trashes the reply', 'write', [ 'type' => 'comment_create', 'create' => true ], [ 'handler' => 'review_reply' ] ],

        // Reports (issue #292): read-only totals, computed in-process
        // (Report_Ops) from WooCommerce's analytics tables when they are in
        // step with the order store, else from HPOS-safe order queries.
        // Aggregates only, never customer details. Params: period (day,
        // week, month, last_month, year, custom with date_from and date_to).
        'reports.sales'       => [ 'GET', '/wc/v3/reports/sales', 'reports', self::CAP_STORE, 'Sales totals for a period (orders, items, gross, net, shipping, taxes, refunds, average order); interval day|week|month adds a breakdown', 'read', null, [ 'handler' => 'report_sales' ] ],
        'reports.top-sellers' => [ 'GET', '/wc/v3/reports/top_sellers', 'reports', self::CAP_STORE, 'Best-selling products for a period by quantity, with net revenue (limit, default 10)', 'read', null, [ 'handler' => 'report_top_sellers' ] ],
        'reports.orders'      => [ 'GET', '/wc/v3/reports/orders/totals', 'reports', self::CAP_STORE, 'Order counts by status for a period', 'read', null, [ 'handler' => 'report_orders' ] ],
        'reports.customers'   => [ 'GET', '/wc/v3/reports/customers/totals', 'reports', self::CAP_STORE, 'Customer counts for a period: buying, registered, guest, repeat, new accounts', 'read', null, [ 'handler' => 'report_customers' ] ],
        'reports.coupons'     => [ 'GET', '/wc/v3/reports/coupons/totals', 'reports', self::CAP_STORE, 'Coupon use for a period: codes used, orders with coupons, discount per code', 'read', null, [ 'handler' => 'report_coupons' ] ],

        // Payment gateways (issue #292), in-process through WooCommerce's
        // gateway registry (Gateway_Ops). Secret-like fields are masked in
        // every response and refused on write; each write snapshots the one
        // option it changes, the gateway's settings or the gateway order.
        'gateways.list'       => [ 'GET', '/wc/v3/payment_gateways', 'gateways', self::CAP_STORE, 'Payment gateways: enabled, title, description, order and settings (secrets masked)', 'read', null, [ 'handler' => 'gateway_list' ] ],
        'gateways.get'        => [ 'GET', '/wc/v3/payment_gateways/{id}', 'gateways', self::CAP_STORE, 'One payment gateway with its settings fields (secrets masked)', 'read', null, [ 'handler' => 'gateway_get' ] ],
        'gateways.update'     => [ 'PUT', '/wc/v3/payment_gateways/{id}', 'gateways', self::CAP_STORE, 'Set a gateway\'s enabled, title, description or non-secret settings; or its order, alone. Secret fields are refused', 'write', [ 'type' => 'wc_gateway', 'param' => 'id' ], [ 'handler' => 'gateway_update' ] ],

        // System status (issue #292), through the wc/v3 status endpoints
        // (Status_Ops). The report masks secret-like values. run-tool runs
        // allowlisted maintenance tools only, needs confirm:true for those
        // that delete rows, and is never recoverable: it is a POST with no
        // snapshot, and its response says why.
        'system-status.get'      => [ 'GET', '/wc/v3/system_status', 'system-status', self::CAP_STORE, 'The system status report; sections (default environment, database, active_plugins, theme, settings, security)', 'read', null, [ 'handler' => 'status_get' ] ],
        'system-status.tools'    => [ 'GET', '/wc/v3/system_status/tools', 'system-status', self::CAP_STORE, 'Status tools, with which ones run-tool accepts and which need confirm', 'read', null, [ 'handler' => 'status_tools' ] ],
        'system-status.run-tool' => [ 'POST', '/wc/v3/system_status/tools/{id}', 'system-status', self::CAP_STORE, 'Run an allowlisted status tool (clear transients, regenerate lookup tables...). Not recoverable', 'write', null, [ 'handler' => 'status_run_tool' ] ],

        // Taxes.
        'taxes.rates'         => [ 'GET', '/wc/v3/taxes', 'taxes', self::CAP_STORE, 'Tax rates, filterable by class' ],
        'taxes.classes'       => [ 'GET', '/wc/v3/taxes/classes', 'taxes', self::CAP_STORE, 'Defined tax classes' ],

        // Webhooks. The signing secret is redacted from the body: it
        // authenticates deliveries to a third party and has no use in a
        // model context.
        'webhooks.list'       => [ 'GET', '/wc/v3/webhooks', 'webhooks', self::CAP_STORE, 'Registered store webhooks and their delivery status (signing secret redacted)', 'read', null, [ 'redact' => [ 'secret' ] ] ],
        'webhooks.get'        => [ 'GET', '/wc/v3/webhooks/{id}', 'webhooks', self::CAP_STORE, 'One webhook: topic, delivery URL, status (signing secret redacted)', 'read', null, [ 'redact' => [ 'secret' ] ] ],
        // Webhook writes (issue #292) run in-process through WC_Webhook
        // (Webhook_Ops): the secret is write-only and masked in every
        // response, the delivery URL must be https and pass the SSRF guard,
        // and each change to an existing webhook is covered by a raw-row
        // snapshot (secret included, never shown); a create writes a
        // creation row whose rollback deletes the webhook.
        'webhooks.create'     => [ 'POST', '/wc/v3/webhooks', 'webhooks', self::CAP_STORE, 'Create a webhook: topic (resource.event), delivery_url (public https), name, status, secret (write-only, generated when omitted). Rollback deletes it', 'write', [ 'type' => 'wc_webhook_create', 'create' => true ], [ 'handler' => 'webhook_create' ] ],
        'webhooks.update'     => [ 'PUT', '/wc/v3/webhooks/{id}', 'webhooks', self::CAP_STORE, 'Change a webhook\'s name, topic, delivery_url, status or secret. The secret is never returned', 'write', [ 'type' => 'wc_webhook', 'param' => 'id' ], [ 'handler' => 'webhook_update' ] ],
        'webhooks.pause'      => [ 'PUT', '/wc/v3/webhooks/{id}', 'webhooks', self::CAP_STORE, 'Pause a webhook (status paused); deliveries stop until it is set active again', 'write', [ 'type' => 'wc_webhook', 'param' => 'id' ], [ 'handler' => 'webhook_pause' ] ],
        'webhooks.delete'     => [ 'DELETE', '/wc/v3/webhooks/{id}', 'webhooks', self::CAP_STORE, 'Delete a webhook', 'destructive', [ 'type' => 'wc_webhook', 'param' => 'id' ], [ 'handler' => 'webhook_delete' ] ],

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
