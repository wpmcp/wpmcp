<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

use WPMCP\Safety\Wc_Order_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Order create and edit behind orders.create and orders.update (issue #292).
 *
 * Both run in-process through WooCommerce's order CRUD (wc_create_order,
 * WC_Order, its item classes) rather than through the wc/v3 orders route, so
 * HPOS and the legacy post store both work and nothing outside this class
 * decides what is written:
 *  - no payment gateway is ever called: payment_complete() and
 *    process_payment() are never run, set_paid and transaction_id are
 *    refused, and payment_method / payment_method_title are plain labels;
 *  - every param is validated before anything is written (Woo_Write calls
 *    prepare() in its guard chain), and unknown params are refused rather
 *    than ignored;
 *  - responses are a curated view: no order meta, no transaction id, no
 *    customer IP or user agent, so a gateway's customer or token references
 *    never reach a model context.
 *
 * Woo_Write wraps update() in Safe_Mutation with a full order snapshot
 * (Wc_Order_Snapshot::TYPE); create() records a creation row itself once the
 * order exists, and a create that fails half way (a coupon the order cannot
 * take) removes the order it started.
 */
final class Order_Ops
{
    private const BILLING_KEYS  = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone'];
    private const SHIPPING_KEYS = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone'];

    private const CREATE_KEYS = ['customer_id', 'status', 'line_items', 'billing', 'shipping', 'shipping_lines', 'fee_lines', 'coupon_codes', 'payment_method', 'payment_method_title', 'customer_note'];
    private const UPDATE_KEYS = ['id', 'line_items', 'billing', 'shipping', 'shipping_lines', 'fee_lines', 'payment_method', 'payment_method_title', 'customer_note', 'recalculate'];

    /** Statuses an order may be created in: refunded implies money moved, the rest are internal. */
    private const CREATE_REFUSED_STATUSES = ['refunded', 'checkout-draft', 'trash'];

    private const MAX_QUANTITY = 100000;

    /**
     * Validate and normalize the params of one order op. Returns a
     * structured error, or ['body' => normalized plan]. Writes nothing.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function prepare(string $handler, array $params): array
    {
        try {
            $body = 'order_create' === $handler ? self::prepare_create($params) : self::prepare_update($params);
        } catch (Order_Op_Refused $e) {
            return Op_Guard::error($e->code_name, $e->getMessage(), $e->data);
        }
        return [ 'body' => $body ];
    }

    /**
     * Create the order. Returns the result shape Woo_Write reports, or a
     * structured error when WooCommerce refused part of it (nothing is left
     * behind then).
     *
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public static function create(array $plan, string $session_id, string $op): array
    {
        $order = wc_create_order([
            'status'      => 'pending',
            'customer_id' => (int) $plan['customer_id'],
            'created_via' => 'wpmcp',
        ]);
        if (is_wp_error($order) || ! $order instanceof \WC_Order) {
            return Op_Guard::error('order_create_failed', is_wp_error($order) ? $order->get_error_message() : 'WooCommerce did not create the order.');
        }

        try {
            foreach ($plan['line_items'] as $line) {
                $order->add_product(wc_get_product($line['variation_id'] ?: $line['product_id']), $line['quantity']);
            }
            self::apply_addresses($order, $plan);
            foreach ($plan['shipping_lines'] as $line) {
                $order->add_item(self::fill_shipping(new \WC_Order_Item_Shipping(), $line));
            }
            foreach ($plan['fee_lines'] as $line) {
                $order->add_item(self::fill_fee(new \WC_Order_Item_Fee(), $line));
            }
            self::apply_scalars($order, $plan);
            $order->calculate_totals(true);
            $order->save();

            foreach ($plan['coupon_codes'] as $code) {
                $applied = $order->apply_coupon($code);
                if (is_wp_error($applied)) {
                    $order->delete(true);
                    return Op_Guard::error(
                        'coupon_rejected',
                        "Coupon \"{$code}\" cannot be applied to this order: " . wp_strip_all_tags($applied->get_error_message()) . ' Nothing was created.',
                        [ 'coupon' => $code ]
                    );
                }
            }
            $order->save();

            if ('pending' !== $plan['status']) {
                $order->update_status($plan['status']);
            }
        } catch (\Throwable $e) {
            $order->delete(true);
            return Op_Guard::error('order_create_failed', 'WooCommerce refused the order: ' . $e->getMessage() . ' Nothing was created.');
        }

        $order_id     = (int) $order->get_id();
        $operation_id = Wc_Order_Snapshot::record_creation($order_id, 'woo-write', [ 'op' => $op, 'params' => $plan ], $session_id);

        return [
            'status'       => 201,
            'body'         => self::view(wc_get_order($order_id)),
            'operation_id' => $operation_id,
        ];
    }

    /**
     * Apply a validated edit. Runs inside Safe_Mutation, after the full
     * order snapshot is written.
     *
     * @param array<string, mixed> $plan
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function update(int $order_id, array $plan): array
    {
        $order = wc_get_order($order_id);
        $items = $order->get_items(['line_item', 'shipping', 'fee']);

        foreach (['line_items', 'shipping_lines', 'fee_lines'] as $group) {
            foreach ($plan[ $group ] as $line) {
                $item = isset($line['id']) ? ($items[ $line['id'] ] ?? null) : null;

                if (null !== $item && ! empty($line['remove'])) {
                    $order->remove_item($line['id']);
                    continue;
                }

                if ('line_items' === $group) {
                    if (null === $item) {
                        $order->add_product(wc_get_product($line['variation_id'] ?: $line['product_id']), $line['quantity']);
                        continue;
                    }
                    self::requantify($item, $line['quantity']);
                    continue;
                }

                if ('shipping_lines' === $group) {
                    $shipping = self::fill_shipping($item ?? new \WC_Order_Item_Shipping(), $line);
                    if (null === $item) {
                        $order->add_item($shipping);
                    }
                    continue;
                }

                $fee = self::fill_fee($item ?? new \WC_Order_Item_Fee(), $line);
                if (null === $item) {
                    $order->add_item($fee);
                }
            }
        }

        self::apply_addresses($order, $plan);
        self::apply_scalars($order, $plan);
        $order->save();

        if ($plan['recalculate']) {
            if ([] !== $order->get_coupon_codes()) {
                $order->recalculate_coupons();
            } else {
                $order->calculate_totals(true);
            }
            $order->save();
        }

        return [ 'status' => 200, 'body' => self::view(wc_get_order($order_id)) ];
    }

    /**
     * The curated order view both ops return. Deliberately no meta_data,
     * transaction id, customer IP or user agent.
     *
     * @return array<string, mixed>
     */
    public static function view(\WC_Order $order): array
    {
        $lines = [];
        foreach ($order->get_items() as $item) {
            $lines[] = [
                'id'           => $item->get_id(),
                'product_id'   => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'name'         => $item->get_name(),
                'quantity'     => $item->get_quantity(),
                'subtotal'     => $item->get_subtotal(),
                'total'        => $item->get_total(),
                'total_tax'    => $item->get_total_tax(),
            ];
        }
        $shipping = [];
        foreach ($order->get_items('shipping') as $item) {
            $shipping[] = [
                'id'           => $item->get_id(),
                'method_id'    => $item->get_method_id(),
                'method_title' => $item->get_method_title(),
                'total'        => $item->get_total(),
            ];
        }
        $fees = [];
        foreach ($order->get_items('fee') as $item) {
            $fees[] = [
                'id'         => $item->get_id(),
                'name'       => $item->get_name(),
                'total'      => $item->get_total(),
                'tax_status' => $item->get_tax_status(),
            ];
        }
        $coupons = [];
        foreach ($order->get_items('coupon') as $item) {
            $coupons[] = [
                'id'       => $item->get_id(),
                'code'     => $item->get_code(),
                'discount' => $item->get_discount(),
            ];
        }

        return [
            'id'                   => $order->get_id(),
            'number'               => $order->get_order_number(),
            'status'               => $order->get_status(),
            'currency'             => $order->get_currency(),
            'customer_id'          => $order->get_customer_id(),
            'billing'              => $order->get_address('billing'),
            'shipping'             => $order->get_address('shipping'),
            'payment_method'       => $order->get_payment_method(),
            'payment_method_title' => $order->get_payment_method_title(),
            'is_paid'              => $order->is_paid(),
            'customer_note'        => $order->get_customer_note(),
            'line_items'           => $lines,
            'shipping_lines'       => $shipping,
            'fee_lines'            => $fees,
            'coupon_lines'         => $coupons,
            'totals'               => [
                'discount_total' => $order->get_discount_total(),
                'shipping_total' => $order->get_shipping_total(),
                'cart_tax'       => $order->get_cart_tax(),
                'total_tax'      => $order->get_total_tax(),
                'total'          => $order->get_total(),
            ],
        ];
    }

    // ------------------------------------------------------------ validation

    /** @return array<string, mixed> */
    private static function prepare_create(array $params): array
    {
        self::refuse_unknown($params, self::CREATE_KEYS, 'orders.create');

        $lines = $params['line_items'] ?? null;
        if (! is_array($lines) || [] === $lines || ! array_is_list($lines)) {
            self::refuse('invalid_params', 'orders.create needs line_items: a non-empty list of {product_id, variation_id?, quantity?}.');
        }

        $customer_id = 0;
        if (array_key_exists('customer_id', $params)) {
            $customer_id = self::id($params['customer_id'], 'customer_id', true);
            if ($customer_id > 0 && false === get_userdata($customer_id)) {
                self::refuse('invalid_params', "No user has id {$customer_id}; pass an existing customer_id or 0 for a guest order.");
            }
        }

        $status = 'pending';
        if (array_key_exists('status', $params)) {
            $status = self::status($params['status']);
        }

        $coupons = [];
        foreach (self::list_param($params, 'coupon_codes') as $code) {
            if (! is_string($code) || '' === trim($code)) {
                self::refuse('invalid_params', 'coupon_codes must be a list of coupon code strings.');
            }
            $code = wc_format_coupon_code($code);
            if (! wc_get_coupon_id_by_code($code)) {
                self::refuse('invalid_params', "No coupon has the code \"{$code}\".", [ 'coupon' => $code ]);
            }
            $coupons[] = $code;
        }

        return [
            'customer_id'    => $customer_id,
            'status'         => $status,
            'line_items'     => array_map(static fn ($line) => self::new_line_item($line), $lines),
            'shipping_lines' => array_map(static fn ($line) => self::shipping_line($line, false), self::list_param($params, 'shipping_lines')),
            'fee_lines'      => array_map(static fn ($line) => self::fee_line($line, false), self::list_param($params, 'fee_lines')),
            'coupon_codes'   => array_values(array_unique($coupons)),
        ] + self::addresses($params) + self::scalars($params);
    }

    /** @return array<string, mixed> */
    private static function prepare_update(array $params): array
    {
        self::refuse_unknown($params, self::UPDATE_KEYS, 'orders.update');

        $id    = self::id($params['id'] ?? null, 'id', false);
        $order = Wc_Order_Snapshot::order($id);
        if (null === $order) {
            self::refuse('unknown_order', "No order has id {$id} (refunds are not orders). List orders with orders.list.");
        }

        $changes = array_diff(array_keys($params), ['id', 'recalculate']);
        if ([] === $changes) {
            self::refuse('invalid_params', 'orders.update needs at least one change: line_items, shipping_lines, fee_lines, billing, shipping, customer_note or payment_method(_title).');
        }

        $existing = [];
        foreach ($order->get_items(['line_item', 'shipping', 'fee']) as $item_id => $item) {
            $existing[ (int) $item_id ] = $item->get_type();
        }

        $groups = [ 'line_items' => 'line_item', 'shipping_lines' => 'shipping', 'fee_lines' => 'fee' ];
        $plan   = [];
        foreach ($groups as $group => $type) {
            $plan[ $group ] = [];
            foreach (self::list_param($params, $group) as $line) {
                if (! is_array($line)) {
                    self::refuse('invalid_params', "Every {$group} entry must be an object.");
                }
                if (! array_key_exists('id', $line)) {
                    $plan[ $group ][] = 'line_item' === $type ? self::new_line_item($line) : ('shipping' === $type ? self::shipping_line($line, false) : self::fee_line($line, false));
                    continue;
                }

                $item_id = self::id($line['id'], "{$group} id", false);
                if (($existing[ $item_id ] ?? null) !== $type) {
                    self::refuse('invalid_params', "Order {$id} has no {$type} item with id {$item_id}.", [ 'item_id' => $item_id ]);
                }

                if (true === ($line['remove'] ?? null)) {
                    if ([] !== array_diff(array_keys($line), ['id', 'remove'])) {
                        self::refuse('invalid_params', "A {$group} removal takes only {id, remove:true}.");
                    }
                    $plan[ $group ][] = [ 'id' => $item_id, 'remove' => true ];
                    continue;
                }

                if ('line_item' === $type) {
                    self::refuse_unknown($line, ['id', 'quantity'], "a {$group} change");
                    $plan[ $group ][] = [ 'id' => $item_id, 'quantity' => self::quantity($line['quantity'] ?? null) ];
                    continue;
                }
                $plan[ $group ][] = [ 'id' => $item_id ] + ('shipping' === $type ? self::shipping_line($line, true) : self::fee_line($line, true));
            }
        }

        return [ 'id' => $id, 'recalculate' => false !== ($params['recalculate'] ?? true) ] + $plan + self::addresses($params) + self::scalars($params);
    }

    /** @return array{product_id: int, variation_id: int, quantity: int} */
    private static function new_line_item($line): array
    {
        if (! is_array($line)) {
            self::refuse('invalid_params', 'Every line_items entry must be an object.');
        }
        self::refuse_unknown($line, ['product_id', 'variation_id', 'quantity'], 'a new line item');

        $product_id = self::id($line['product_id'] ?? null, 'product_id', false);
        $product    = wc_get_product($product_id);
        if (! $product || 'trash' === $product->get_status()) {
            self::refuse('invalid_params', "No product has id {$product_id}.", [ 'product_id' => $product_id ]);
        }

        $variation_id = array_key_exists('variation_id', $line) ? self::id($line['variation_id'], 'variation_id', true) : 0;
        if ($product->is_type('variation')) {
            // A variation id passed as product_id names its variation directly.
            if (0 !== $variation_id && $variation_id !== $product_id) {
                self::refuse('invalid_params', "Product {$product_id} is a variation; pass its parent as product_id.");
            }
            $variation_id = $product_id;
            $product_id   = $product->get_parent_id();
        } elseif ($product->is_type('variable') && 0 === $variation_id) {
            self::refuse('invalid_params', "Product {$product_id} is variable: pass the variation_id to order.", [ 'product_id' => $product_id ]);
        }

        if (0 !== $variation_id) {
            $variation = wc_get_product($variation_id);
            if (! $variation || ! $variation->is_type('variation') || $variation->get_parent_id() !== $product_id || 'trash' === $variation->get_status()) {
                self::refuse('invalid_params', "{$variation_id} is not a variation of product {$product_id}.", [ 'variation_id' => $variation_id ]);
            }
        }

        return [
            'product_id'   => $product_id,
            'variation_id' => $variation_id,
            'quantity'     => array_key_exists('quantity', $line) ? self::quantity($line['quantity']) : 1,
        ];
    }

    /** @return array<string, mixed> */
    private static function shipping_line($line, bool $partial): array
    {
        if (! is_array($line)) {
            self::refuse('invalid_params', 'Every shipping_lines entry must be an object.');
        }
        self::refuse_unknown($line, $partial ? ['id', 'method_id', 'method_title', 'total'] : ['method_id', 'method_title', 'total'], 'a shipping line');

        $out = [];
        foreach (['method_id', 'method_title'] as $key) {
            if (array_key_exists($key, $line)) {
                $out[ $key ] = self::text($line[ $key ], $key);
            }
        }
        if (array_key_exists('total', $line)) {
            $out['total'] = self::money($line['total'], 'shipping total');
        }
        if (! $partial && '' === ($out['method_title'] ?? '')) {
            self::refuse('invalid_params', 'A new shipping line needs a method_title.');
        }
        if ($partial && [] === $out) {
            self::refuse('invalid_params', 'A shipping line change needs method_id, method_title or total (or remove:true).');
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function fee_line($line, bool $partial): array
    {
        if (! is_array($line)) {
            self::refuse('invalid_params', 'Every fee_lines entry must be an object.');
        }
        self::refuse_unknown($line, $partial ? ['id', 'name', 'total', 'tax_status'] : ['name', 'total', 'tax_status'], 'a fee line');

        $out = [];
        if (array_key_exists('name', $line)) {
            $out['name'] = self::text($line['name'], 'name');
        }
        if (array_key_exists('total', $line)) {
            $out['total'] = self::money($line['total'], 'fee total', true);
        }
        if (array_key_exists('tax_status', $line)) {
            if (! in_array($line['tax_status'], ['taxable', 'none'], true)) {
                self::refuse('invalid_params', 'A fee tax_status is "taxable" or "none".');
            }
            $out['tax_status'] = $line['tax_status'];
        }
        if (! $partial && ('' === ($out['name'] ?? '') || ! isset($out['total']))) {
            self::refuse('invalid_params', 'A new fee line needs a name and a total.');
        }
        if ($partial && [] === $out) {
            self::refuse('invalid_params', 'A fee line change needs name, total or tax_status (or remove:true).');
        }
        return $out;
    }

    /** @return array<string, array<string, string>> */
    private static function addresses(array $params): array
    {
        $out = [];
        foreach (['billing' => self::BILLING_KEYS, 'shipping' => self::SHIPPING_KEYS] as $type => $keys) {
            if (! array_key_exists($type, $params)) {
                continue;
            }
            if (! is_array($params[ $type ]) || array_is_list($params[ $type ]) && [] !== $params[ $type ]) {
                self::refuse('invalid_params', "{$type} must be an object of address fields.");
            }
            self::refuse_unknown($params[ $type ], $keys, "the {$type} address");
            $address = [];
            foreach ($params[ $type ] as $key => $value) {
                $address[ $key ] = self::text($value, "{$type}.{$key}");
            }
            if ('' !== ($address['email'] ?? '') && ! is_email($address['email'])) {
                self::refuse('invalid_params', "{$type}.email is not a valid email address.");
            }
            $out[ $type ] = $address;
        }
        return $out;
    }

    /** @return array<string, string> */
    private static function scalars(array $params): array
    {
        $out = [];
        foreach (['payment_method', 'payment_method_title', 'customer_note'] as $key) {
            if (array_key_exists($key, $params)) {
                $out[ $key ] = 'customer_note' === $key ? self::text($params[ $key ], $key, true) : self::text($params[ $key ], $key);
            }
        }
        return $out;
    }

    private static function status($value): string
    {
        $status   = is_string($value) ? (string) preg_replace('/^wc-/', '', trim($value)) : '';
        $statuses = array_map(static fn ($key) => (string) preg_replace('/^wc-/', '', $key), array_keys(wc_get_order_statuses()));
        if ('' === $status || ! in_array($status, $statuses, true) || in_array($status, self::CREATE_REFUSED_STATUSES, true)) {
            $allowed = array_values(array_diff($statuses, self::CREATE_REFUSED_STATUSES));
            self::refuse('invalid_params', 'status must be one of: ' . implode(', ', $allowed) . '.', [ 'allowed' => $allowed ]);
        }
        return $status;
    }

    private static function id($value, string $name, bool $zero_ok): int
    {
        $id = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : -1;
        if ($id < 0 || (0 === $id && ! $zero_ok)) {
            self::refuse('invalid_params', "{$name} must be a positive integer id.");
        }
        return $id;
    }

    private static function quantity($value): int
    {
        $qty = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;
        if ($qty < 1 || $qty > self::MAX_QUANTITY) {
            self::refuse('invalid_params', 'quantity must be a whole number from 1 to ' . self::MAX_QUANTITY . ' (remove an item with remove:true).');
        }
        return $qty;
    }

    private static function money($value, string $name, bool $negative_ok = false): string
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            self::refuse('invalid_params', "{$name} must be a number.");
        }
        if (! $negative_ok && (float) $value < 0) {
            self::refuse('invalid_params', "{$name} cannot be negative.");
        }
        return wc_format_decimal((string) $value);
    }

    private static function text($value, string $name, bool $multiline = false): string
    {
        if (! is_scalar($value) || is_bool($value)) {
            self::refuse('invalid_params', "{$name} must be a string.");
        }
        return $multiline ? sanitize_textarea_field((string) $value) : sanitize_text_field((string) $value);
    }

    /** @return array<int, mixed> */
    private static function list_param(array $params, string $key): array
    {
        if (! array_key_exists($key, $params)) {
            return [];
        }
        if (! is_array($params[ $key ]) || ! array_is_list($params[ $key ])) {
            self::refuse('invalid_params', "{$key} must be a list.");
        }
        return $params[ $key ];
    }

    /**
     * Stop validation with a structured refusal. The message is never
     * printed as HTML: prepare() returns it inside the dispatcher's JSON
     * error, so escaping it here would only corrupt the quotes it holds.
     *
     * @param array<string, mixed> $data
     */
    private static function refuse(string $code, string $message, array $data = []): never
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught in prepare() and returned as a JSON error, never rendered.
        throw new Order_Op_Refused($code, $message, $data);
    }

    private static function refuse_unknown(array $given, array $allowed, string $where): void
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($given)), $allowed));
        if ([] !== $unknown) {
            self::refuse(
                'invalid_params',
                'Unknown field(s) for ' . $where . ': ' . implode(', ', $unknown) . '. Accepted: ' . implode(', ', $allowed) . '.',
                [ 'unknown' => $unknown ]
            );
        }
    }

    // --------------------------------------------------------------- writers

    /** @param array<string, mixed> $plan */
    private static function apply_addresses(\WC_Order $order, array $plan): void
    {
        foreach (['billing', 'shipping'] as $type) {
            foreach ((array) ($plan[ $type ] ?? []) as $key => $value) {
                $setter = "set_{$type}_{$key}";
                if (is_callable([ $order, $setter ])) {
                    $order->{$setter}($value);
                }
            }
        }
    }

    /** @param array<string, mixed> $plan */
    private static function apply_scalars(\WC_Order $order, array $plan): void
    {
        foreach (['payment_method', 'payment_method_title', 'customer_note'] as $key) {
            if (array_key_exists($key, $plan)) {
                $order->{'set_' . $key}($plan[ $key ]);
            }
        }
    }

    /** @param array<string, mixed> $line */
    private static function fill_shipping(\WC_Order_Item_Shipping $item, array $line): \WC_Order_Item_Shipping
    {
        if (array_key_exists('method_title', $line)) {
            $item->set_method_title($line['method_title']);
        }
        if (array_key_exists('method_id', $line)) {
            $item->set_method_id($line['method_id']);
        }
        if (array_key_exists('total', $line)) {
            $item->set_total($line['total']);
        }
        return $item;
    }

    /** @param array<string, mixed> $line */
    private static function fill_fee(\WC_Order_Item_Fee $item, array $line): \WC_Order_Item_Fee
    {
        if (array_key_exists('name', $line)) {
            $item->set_name($line['name']);
        }
        if (array_key_exists('tax_status', $line)) {
            $item->set_tax_status($line['tax_status']);
        } elseif (0 === $item->get_id()) {
            $item->set_tax_status('none');
        }
        if (array_key_exists('total', $line)) {
            $item->set_amount($line['total']);
            $item->set_total($line['total']);
        }
        return $item;
    }

    /**
     * Change a line item's quantity at the unit price the order already
     * charges for it (not today's catalog price), as a store manager's
     * quantity edit does.
     */
    private static function requantify(\WC_Order_Item_Product $item, int $quantity): void
    {
        $old = max(1, (int) $item->get_quantity());
        $unit_subtotal = (float) $item->get_subtotal() / $old;
        $unit_total    = (float) $item->get_total() / $old;

        $item->set_quantity($quantity);
        $item->set_subtotal(wc_format_decimal($unit_subtotal * $quantity));
        $item->set_total(wc_format_decimal($unit_total * $quantity));
    }
}
