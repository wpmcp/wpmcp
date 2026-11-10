<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

use WPMCP\Safety\Wc_Subscription_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Subscriptions ops behind the subscriptions.* rows (issue
 * #658), run in-process through the Subscriptions API rather than its wc/v3
 * routes, so every response is a curated view (no order meta, so no payment
 * gateway customer or token reference reaches a model context) and every
 * write goes through the same calls the subscription edit screen makes:
 *
 *  - update-status moves a subscription between active and on-hold through
 *    WC_Subscription::update_status(), which applies Subscriptions' own
 *    transition rules (can_be_updated_to()) and date handling. A status
 *    change can send emails, so it needs confirm:true;
 *  - update-next-payment changes the next payment date through
 *    update_dates(), which validates it against the rest of the schedule
 *    and reschedules the renewal;
 *  - update-items edits line items, shipping and fees exactly as
 *    orders.update does (Order_Ops), refused when the payment method cannot
 *    take amount changes;
 *  - cancel (destructive: off by default and confirm:true) ends a
 *    subscription at the end of its prepaid term (pending-cancel) or, with
 *    immediately:true, now. It is not recoverable: rollback puts the
 *    subscription back, but a gateway that keeps its own recurring profile
 *    has cancelled it, and the cancellation emails are sent.
 *
 * Every write runs inside Safe_Mutation with a 'wc_subscription' snapshot
 * (Safety\Wc_Subscription_Snapshot): status, schedule, items, totals and
 * addresses, so rollback-operation restores the subscription.
 */
final class Subscription_Ops
{
    private const MAX_PER_PAGE     = 50;
    private const DEFAULT_PER_PAGE = 20;

    /** Statuses update-status moves a subscription to; ending one is cancel's job. */
    private const SETTABLE_STATUSES = ['active', 'on-hold'];

    /** Statuses after which a subscription's items and schedule are settled. */
    private const ENDED_STATUSES = ['cancelled', 'expired', 'switched', 'pending-cancel', 'trash'];

    /** Related order kinds Subscriptions records. */
    private const RELATIONS = ['parent', 'renewal', 'switch', 'resubscribe'];

    /** Product types Subscriptions adds. */
    private const PRODUCT_TYPES = ['subscription', 'variable-subscription'];

    /** Product meta Subscriptions keeps a subscription product's terms in, by the name shown. */
    private const PRODUCT_META = [
        'price'           => '_subscription_price',
        'period'          => '_subscription_period',
        'period_interval' => '_subscription_period_interval',
        'length'          => '_subscription_length',
        'trial_length'    => '_subscription_trial_length',
        'trial_period'    => '_subscription_trial_period',
        'sign_up_fee'     => '_subscription_sign_up_fee',
    ];

    /** Said on every status write: what a rollback cannot take back. */
    private const EMAIL_NOTE = 'Subscriptions may email the customer and the store about the change; a rollback restores the subscription but cannot unsend them.';

    // ------------------------------------------------------------- reads

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function read(string $handler, array $params): array
    {
        try {
            switch ($handler) {
                case 'subscription_list':
                    return [ 'status' => 200, 'body' => self::list($params) ];
                case 'subscription_get':
                    return [ 'status' => 200, 'body' => self::view(self::existing($params)) ];
                case 'subscription_products':
                    return [ 'status' => 200, 'body' => self::products($params) ];
                case 'subscription_orders':
                    return [ 'status' => 200, 'body' => self::related_orders($params) ];
            }
        } catch (Order_Op_Refused $e) {
            return Op_Guard::error($e->code_name, $e->getMessage(), $e->data);
        }
        return Op_Guard::error('unknown_op', "No subscription read named \"{$handler}\".");
    }

    /** @return array<string, mixed> */
    private static function list(array $params): array
    {
        self::refuse_unknown($params, ['status', 'customer_id', 'product_id', 'page', 'per_page'], 'subscriptions.list');

        $statuses = ['any'];
        if (array_key_exists('status', $params)) {
            $known    = self::known_statuses();
            $statuses = [];
            foreach ((array) $params['status'] as $status) {
                $status = is_string($status) ? (string) preg_replace('/^wc-/', '', trim($status)) : '';
                if ('any' !== $status && ! in_array($status, $known, true)) {
                    self::refuse('invalid_params', 'status must be any or one of: ' . implode(', ', $known) . '.', [ 'allowed' => $known ]);
                }
                $statuses[] = $status;
            }
        }

        $page     = self::positive($params['page'] ?? 1, 'page');
        $per_page = min(self::MAX_PER_PAGE, self::positive($params['per_page'] ?? self::DEFAULT_PER_PAGE, 'per_page'));

        $args = [
            'subscriptions_per_page' => $per_page,
            'paged'                  => $page,
            'offset'                 => ($page - 1) * $per_page,
            'subscription_status'    => $statuses,
        ];
        foreach (['customer_id', 'product_id'] as $key) {
            if (array_key_exists($key, $params)) {
                $args[ $key ] = self::positive($params[ $key ], $key);
            }
        }

        $rows = array_map([ self::class, 'summary' ], Wc_Subscription_Snapshot::query($args));

        return [ 'page' => $page, 'per_page' => $per_page, 'subscriptions' => $rows ];
    }

    /** @return array<string, mixed> */
    private static function products(array $params): array
    {
        self::refuse_unknown($params, ['page', 'per_page', 'search'], 'subscriptions.products');

        $page     = self::positive($params['page'] ?? 1, 'page');
        $per_page = min(self::MAX_PER_PAGE, self::positive($params['per_page'] ?? self::DEFAULT_PER_PAGE, 'per_page'));
        $query    = [
            'type'     => self::PRODUCT_TYPES,
            'status'   => ['publish', 'private', 'draft', 'pending', 'future'],
            'limit'    => $per_page,
            'page'     => $page,
            'orderby'  => 'ID',
            'order'    => 'ASC',
            'paginate' => true,
        ];
        if (isset($params['search']) && is_string($params['search']) && '' !== trim($params['search'])) {
            $query['s'] = sanitize_text_field($params['search']);
        }
        $found = wc_get_products($query);

        $rows = [];
        foreach ($found->products as $product) {
            $row = [
                'id'     => $product->get_id(),
                'name'   => $product->get_name(),
                'type'   => self::product_type($product),
                'status' => $product->get_status(),
                'sku'    => $product->get_sku(),
            ] + self::terms($product);
            if ('variable-subscription' === $row['type']) {
                $row['variations'] = [];
                foreach ($product->get_children() as $child_id) {
                    $child = wc_get_product($child_id);
                    if ($child) {
                        $row['variations'][] = [ 'id' => $child->get_id(), 'name' => $child->get_name(), 'sku' => $child->get_sku() ] + self::terms($child);
                    }
                }
            }
            $rows[] = $row;
        }

        return [ 'page' => $page, 'per_page' => $per_page, 'total' => (int) $found->total, 'products' => $rows ];
    }

    /** @return array<string, mixed> */
    private static function related_orders(array $params): array
    {
        self::refuse_unknown($params, ['id', 'relation'], 'subscriptions.renewal-orders');
        $subscription = self::existing($params);

        $relation = $params['relation'] ?? 'renewal';
        if (! is_string($relation) || ! in_array($relation, array_merge(self::RELATIONS, ['all']), true)) {
            self::refuse('invalid_params', 'relation must be one of: ' . implode(', ', self::RELATIONS) . ', all.');
        }
        $relations = 'all' === $relation ? self::RELATIONS : [ $relation ];

        $orders = [];
        foreach ($relations as $kind) {
            foreach ((array) $subscription->get_related_orders('ids', $kind) as $order_id) {
                $order = wc_get_order((int) $order_id);
                if (! $order instanceof \WC_Order) {
                    continue;
                }
                $created  = $order->get_date_created();
                $paid     = $order->get_date_paid();
                $orders[] = [
                    'id'           => $order->get_id(),
                    'relation'     => $kind,
                    'number'       => $order->get_order_number(),
                    'status'       => $order->get_status(),
                    'total'        => $order->get_total(),
                    'currency'     => $order->get_currency(),
                    'date_created' => $created ? gmdate('Y-m-d H:i:s', $created->getTimestamp()) : null,
                    'date_paid'    => $paid ? gmdate('Y-m-d H:i:s', $paid->getTimestamp()) : null,
                ];
            }
        }
        usort($orders, static fn ($a, $b) => $b['id'] <=> $a['id']);

        return [ 'subscription_id' => $subscription->get_id(), 'relation' => $relation, 'orders' => $orders ];
    }

    // ------------------------------------------------------------ writes

    /**
     * Validate one write. Returns a structured error, or ['body' => plan,
     * 'report' => what the response says beside the result]. Writes
     * nothing.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function prepare(string $handler, array $params, bool $confirm = false): array
    {
        try {
            switch ($handler) {
                case 'subscription_status':
                    return self::prepare_status($params, $confirm);
                case 'subscription_next_payment':
                    return self::prepare_next_payment($params);
                case 'subscription_items':
                    return self::prepare_items($params);
                case 'subscription_cancel':
                    return self::prepare_cancel($params);
            }
        } catch (Order_Op_Refused $e) {
            return Op_Guard::error($e->code_name, $e->getMessage(), $e->data);
        }
        return Op_Guard::error('unknown_op', "No subscription write named \"{$handler}\".");
    }

    /**
     * Apply a validated write. Runs inside Safe_Mutation, after the
     * subscription snapshot is written.
     *
     * @param array<string, mixed> $plan
     * @return array{status: int, body: mixed}
     */
    public static function apply(string $handler, int $id, array $plan): array
    {
        if ('subscription_items' === $handler) {
            $out = Order_Ops::update($id, $plan);
            return [ 'status' => $out['status'], 'body' => self::view(self::existing([ 'id' => $id ])) ];
        }

        $subscription = Wc_Subscription_Snapshot::subscription($id);
        if (null === $subscription) {
            return [ 'status' => 404, 'body' => [ 'code' => 'unknown_subscription', 'message' => "No subscription has id {$id}." ] ];
        }

        try {
            if ('subscription_next_payment' === $handler) {
                $subscription->update_dates([ 'next_payment' => $plan['next_payment'] ]);
            } else {
                $subscription->update_status($plan['status'], $plan['note'], true);
            }
        } catch (\Throwable $e) {
            return [ 'status' => 409, 'body' => [ 'code' => 'subscription_refused', 'message' => 'WooCommerce Subscriptions refused the change: ' . $e->getMessage() ] ];
        }

        return [ 'status' => 200, 'body' => self::view(self::existing([ 'id' => $id ])) ];
    }

    /** @return array<string, mixed> */
    private static function prepare_status(array $params, bool $confirm): array
    {
        self::refuse_unknown($params, ['id', 'status', 'note'], 'subscriptions.update-status');
        $subscription = self::existing($params);

        $status = is_string($params['status'] ?? null) ? (string) preg_replace('/^wc-/', '', trim($params['status'])) : '';
        if (! in_array($status, self::SETTABLE_STATUSES, true)) {
            self::refuse(
                'invalid_params',
                'status must be one of: ' . implode(', ', self::SETTABLE_STATUSES) . '. End a subscription with subscriptions.cancel.',
                [ 'allowed' => self::SETTABLE_STATUSES ]
            );
        }
        $current = $subscription->get_status();
        if ($current === $status) {
            self::refuse('invalid_params', "Subscription {$subscription->get_id()} is already {$status}.");
        }
        if (! $subscription->can_be_updated_to($status)) {
            self::refuse(
                'status_not_allowed',
                "WooCommerce Subscriptions does not allow subscription {$subscription->get_id()} to go from {$current} to {$status} (its payment method may not support it, or its term has ended).",
                [ 'current' => $current, 'requested' => $status ]
            );
        }
        if (! $confirm) {
            self::refuse(
                'confirmation_required',
                "Changing subscription {$subscription->get_id()} from {$current} to {$status} needs confirm:true. " . self::EMAIL_NOTE,
                [ 'current' => $current, 'requested' => $status ]
            );
        }

        return [
            'body'   => [ 'id' => $subscription->get_id(), 'status' => $status, 'note' => self::note($params) ],
            'report' => [ 'side_effects' => self::EMAIL_NOTE ],
        ];
    }

    /** @return array<string, mixed> */
    private static function prepare_next_payment(array $params): array
    {
        self::refuse_unknown($params, ['id', 'next_payment'], 'subscriptions.update-next-payment');
        $subscription = self::existing($params);

        $raw = $params['next_payment'] ?? null;
        $ts  = is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?(Z|[+-]00:?00)?$/', trim($raw)) ? strtotime(trim($raw) . (preg_match('/(Z|[+-]00:?00)$/', trim($raw)) ? '' : ' UTC')) : false;
        if (false === $ts) {
            self::refuse('invalid_params', 'next_payment must be a UTC date and time, "YYYY-MM-DD HH:MM:SS".');
        }
        if ($ts <= time()) {
            self::refuse('invalid_params', 'next_payment must be in the future.');
        }
        if (! $subscription->can_date_be_updated('next_payment')) {
            self::refuse(
                'date_not_updatable',
                "Subscription {$subscription->get_id()}'s next payment date cannot be changed: it has ended, or its payment method does not support date changes.",
                [ 'status' => $subscription->get_status() ]
            );
        }

        $date = gmdate('Y-m-d H:i:s', $ts);
        if (method_exists($subscription, 'validate_date_updates')) {
            try {
                $subscription->validate_date_updates([ 'next_payment' => $date ]);
            } catch (\Throwable $e) {
                self::refuse('invalid_params', wp_strip_all_tags($e->getMessage()));
            }
        }

        return [
            'body'   => [ 'id' => $subscription->get_id(), 'next_payment' => $date ],
            'report' => [ 'previous_next_payment' => Wc_Subscription_Snapshot::date($subscription, 'next_payment') ],
        ];
    }

    /** @return array<string, mixed> */
    private static function prepare_items(array $params): array
    {
        self::refuse_unknown($params, ['id', 'line_items', 'shipping_lines', 'fee_lines', 'recalculate'], 'subscriptions.update-items');
        $subscription = self::existing($params);

        if (in_array($subscription->get_status(), self::ENDED_STATUSES, true)) {
            self::refuse('subscription_ended', "Subscription {$subscription->get_id()} is {$subscription->get_status()}; its items can no longer change.");
        }
        if (! $subscription->payment_method_supports('subscription_amount_changes')) {
            self::refuse(
                'amount_changes_unsupported',
                "Subscription {$subscription->get_id()}'s payment method does not support changing the recurring amount, so its items cannot be edited here."
            );
        }

        $checked = Order_Ops::prepare('order_update', $params);
        if (isset($checked['error'])) {
            return $checked;
        }
        return [ 'body' => $checked['body'] ];
    }

    /** @return array<string, mixed> */
    private static function prepare_cancel(array $params): array
    {
        self::refuse_unknown($params, ['id', 'immediately', 'note'], 'subscriptions.cancel');
        $subscription = self::existing($params);

        $now = $params['immediately'] ?? false;
        if (! is_bool($now)) {
            self::refuse('invalid_params', 'immediately must be true or false.');
        }
        $target  = $now ? 'cancelled' : 'pending-cancel';
        $current = $subscription->get_status();
        if (! $subscription->can_be_updated_to($target)) {
            $hint = $now ? '' : ' Pass immediately:true to cancel it now instead.';
            self::refuse(
                'status_not_allowed',
                "WooCommerce Subscriptions does not allow subscription {$subscription->get_id()} to go from {$current} to {$target}.{$hint}",
                [ 'current' => $current, 'requested' => $target ]
            );
        }

        return [
            'body'   => [ 'id' => $subscription->get_id(), 'status' => $target, 'note' => self::note($params) ],
            'report' => [
                'side_effects' => self::EMAIL_NOTE . ' A payment gateway that keeps its own recurring profile cancels it too, which a rollback cannot reverse.',
            ],
        ];
    }

    // ------------------------------------------------------------- views

    /** @return array<string, mixed> */
    private static function summary(\WC_Order $subscription): array
    {
        $lines = [];
        foreach ($subscription->get_items() as $item) {
            $lines[] = [ 'product_id' => $item->get_product_id(), 'variation_id' => $item->get_variation_id(), 'name' => $item->get_name(), 'quantity' => $item->get_quantity() ];
        }
        return [
            'id'               => $subscription->get_id(),
            'status'           => $subscription->get_status(),
            'customer_id'      => $subscription->get_customer_id(),
            'total'            => $subscription->get_total(),
            'currency'         => $subscription->get_currency(),
            'billing_period'   => $subscription->get_billing_period(),
            'billing_interval' => (int) $subscription->get_billing_interval(),
            'next_payment'     => Wc_Subscription_Snapshot::date($subscription, 'next_payment'),
            'end'              => Wc_Subscription_Snapshot::date($subscription, 'end'),
            'line_items'       => $lines,
        ];
    }

    /**
     * The curated subscription view: the order view orders.update returns
     * (no meta, no transaction id) plus the schedule and terms.
     *
     * @return array<string, mixed>
     */
    private static function view(\WC_Order $subscription): array
    {
        $dates = [ 'start' => Wc_Subscription_Snapshot::date($subscription, 'start') ];
        foreach (Wc_Subscription_Snapshot::DATES as $type) {
            $dates[ $type ] = Wc_Subscription_Snapshot::date($subscription, $type);
        }
        $related = [];
        foreach (self::RELATIONS as $kind) {
            $related[ $kind ] = count((array) $subscription->get_related_orders('ids', $kind));
        }

        return Order_Ops::view($subscription) + [
            'parent_id'               => $subscription->get_parent_id(),
            'billing_period'          => $subscription->get_billing_period(),
            'billing_interval'        => (int) $subscription->get_billing_interval(),
            'trial_period'            => $subscription->get_trial_period(),
            'requires_manual_renewal' => (bool) $subscription->get_requires_manual_renewal(),
            'suspension_count'        => (int) $subscription->get_suspension_count(),
            'dates'                   => $dates,
            'related_orders'          => $related,
        ];
    }

    /** @return array<string, mixed> */
    private static function terms(\WC_Product $product): array
    {
        $out = [];
        foreach (self::PRODUCT_META as $name => $key) {
            $value        = $product->get_meta($key, true, 'edit');
            $out[ $name ] = in_array($name, ['period_interval', 'length', 'trial_length'], true) ? (int) $value : (string) $value;
        }
        return $out;
    }

    private static function product_type(\WC_Product $product): string
    {
        $terms = get_the_terms($product->get_id(), 'product_type');
        return is_array($terms) && isset($terms[0]) ? (string) $terms[0]->slug : $product->get_type();
    }

    // -------------------------------------------------------- validation

    private static function existing(array $params): \WC_Order
    {
        $raw = $params['id'] ?? null;
        $id  = is_int($raw) || (is_string($raw) && ctype_digit($raw)) ? (int) $raw : 0;
        if ($id <= 0) {
            self::refuse('invalid_params', 'id must be a positive integer subscription id.');
        }
        $subscription = Wc_Subscription_Snapshot::subscription($id);
        if (null === $subscription) {
            self::refuse('unknown_subscription', "No subscription has id {$id}. List subscriptions with subscriptions.list.", [ 'id' => $id ]);
        }
        return $subscription;
    }

    /** @return string[] */
    private static function known_statuses(): array
    {
        return Wc_Subscription_Snapshot::statuses();
    }

    private static function note(array $params): string
    {
        $note = $params['note'] ?? '';
        if (! is_string($note)) {
            self::refuse('invalid_params', 'note must be a string.');
        }
        return sanitize_textarea_field($note);
    }

    private static function positive($value, string $name): int
    {
        $n = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;
        if ($n < 1) {
            self::refuse('invalid_params', "{$name} must be a positive integer.");
        }
        return $n;
    }

    /** @param array<string, mixed> $data */
    private static function refuse(string $code, string $message, array $data = []): never
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught and returned as a JSON error, never rendered.
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
}
