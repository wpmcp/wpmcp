<?php

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Subscriptions snapshots (issue #658), and the one place wpmcp
 * reaches the Subscriptions API.
 *
 * A subscription is an order of its own type (shop_subscription) in either
 * order store, so 'wc_subscription' captures everything 'wc_order_full'
 * does (status, props, addresses, totals, every item and its meta, through
 * Wc_Order_Snapshot) plus what only a subscription carries: its schedule
 * (trial end, next payment, cancelled and end dates), its suspension count,
 * and the dates Subscriptions keeps aside when a subscription is set to
 * pending cancellation, so a reactivation can bring them back.
 *
 * Restore runs in Subscriptions' own order: the schedule first, through
 * update_dates() (one call, so the captured dates are validated together,
 * as they were valid together), while the subscription still has the status
 * the write left it in; then the status, items and props through
 * Wc_Order_Snapshot. Subscriptions schedules a subscription's renewal and
 * end actions when it becomes active, from its dates at that moment, so
 * putting the dates back first means the actions it schedules are the
 * captured ones.
 *
 * What a restore cannot take back is said where the write happens: the
 * emails Subscriptions sent on the status change, and anything a payment
 * gateway did on its side (a gateway that keeps its own recurring profile
 * cancels it when the subscription is cancelled).
 */
final class Wc_Subscription_Snapshot
{
    public const TYPE = 'wc_subscription';

    /** The schedule dates a write can change, in Subscriptions' own date keys. */
    public const DATES = ['trial_end', 'next_payment', 'cancelled', 'end'];

    /** Order meta Subscriptions sets aside on pending cancellation. */
    private const META = ['end_date_pre_cancellation', 'trial_end_pre_cancellation'];

    /** @var object|null Test seam: stands in for the Subscriptions API. */
    private static $double = null;

    /**
     * Stand in for the Subscriptions API in the stub-backed suite: an object
     * with get(int): ?WC_Order, query(array): WC_Order[] and statuses():
     * array. Ignored outside the test suite.
     *
     * @param object|null $double
     */
    public static function set_double_for_tests($double): void
    {
        if (! defined('WPMCP_TESTING') || ! WPMCP_TESTING) {
            return;
        }
        self::$double = $double;
    }

    /** Whether WooCommerce Subscriptions is loaded (its main class is defined only once its dependencies are met). */
    public static function is_active(): bool
    {
        if (null !== self::$double) {
            return true;
        }
        return class_exists('WC_Subscriptions') && function_exists('wcs_get_subscription');
    }

    /** The subscription an id names, or null (an order of another type included). */
    public static function subscription(int $id): ?\WC_Order
    {
        if ($id <= 0) {
            return null;
        }
        if (null !== self::$double) {
            $found = self::$double->get($id);
        } elseif (self::is_active()) {
            $found = wcs_get_subscription($id);
        } else {
            return null;
        }
        return $found instanceof \WC_Order ? $found : null;
    }

    /**
     * Subscriptions matching wcs_get_subscriptions() arguments.
     *
     * @param array<string, mixed> $args
     * @return \WC_Order[]
     */
    public static function query(array $args): array
    {
        if (null !== self::$double) {
            $found = self::$double->query($args);
        } elseif (self::is_active() && function_exists('wcs_get_subscriptions')) {
            $found = wcs_get_subscriptions($args);
        } else {
            return [];
        }
        return array_values(array_filter((array) $found, static fn ($s) => $s instanceof \WC_Order));
    }

    /**
     * The subscription statuses this site knows, without the wc- prefix.
     *
     * @return string[]
     */
    public static function statuses(): array
    {
        if (null !== self::$double) {
            $keys = (array) self::$double->statuses();
        } elseif (function_exists('wcs_get_subscription_statuses')) {
            $keys = array_keys((array) wcs_get_subscription_statuses());
        } else {
            return [];
        }
        return array_values(array_map(static fn ($key) => (string) preg_replace('/^wc-/', '', (string) $key), $keys));
    }

    /** One schedule date as Subscriptions reports it: a UTC 'Y-m-d H:i:s' string, or 0 when unset. */
    public static function date(\WC_Order $subscription, string $type)
    {
        $value = method_exists($subscription, 'get_date') ? $subscription->get_date($type) : 0;
        return is_string($value) && '' !== $value ? $value : 0;
    }

    public static function capture(int $id): array
    {
        $subscription = self::subscription($id);
        if (null === $subscription) {
            return [
                'object_type' => self::TYPE,
                'object_id'   => $id,
                'data'        => [ 'exists' => false ],
            ];
        }

        $dates = [];
        foreach (self::DATES as $type) {
            $dates[ $type ] = self::date($subscription, $type);
        }
        $meta = [];
        foreach (self::META as $key) {
            $meta[ $key ] = $subscription->meta_exists($key) ? $subscription->get_meta($key, true, 'edit') : null;
        }

        return [
            'object_type' => self::TYPE,
            'object_id'   => $id,
            'data'        => [
                'exists'           => true,
                'order'            => Wc_Order_Snapshot::capture($id)['data'],
                'dates'            => $dates,
                'suspension_count' => method_exists($subscription, 'get_suspension_count') ? (int) $subscription->get_suspension_count('edit') : 0,
                'meta'             => $meta,
            ],
        ];
    }

    /**
     * Put a subscription back to a 'wc_subscription' snapshot. Returns a
     * warning when part of it could not be restored, else null.
     */
    public static function restore(array $snapshot): ?string
    {
        $data = (array) ($snapshot['data'] ?? []);
        $id   = (int) ($snapshot['object_id'] ?? 0);

        if (empty($data['exists'])) {
            return null; // Nothing existed to restore.
        }
        $subscription = self::subscription($id);
        if (null === $subscription) {
            return "Subscription {$id} no longer exists (or WooCommerce Subscriptions is inactive), so its snapshot could not be restored.";
        }

        foreach ((array) ($data['meta'] ?? []) as $key => $value) {
            if (! in_array($key, self::META, true)) {
                continue;
            }
            if (null === $value) {
                $subscription->delete_meta_data($key);
            } else {
                $subscription->update_meta_data($key, $value);
            }
        }
        if (method_exists($subscription, 'set_suspension_count')) {
            $subscription->set_suspension_count((int) ($data['suspension_count'] ?? 0));
        }
        $subscription->save();

        $warnings = [];
        $dates    = [];
        foreach (self::DATES as $type) {
            $dates[ $type ] = $data['dates'][ $type ] ?? 0;
        }
        $changed = false;
        foreach ($dates as $type => $value) {
            if (self::date($subscription, $type) !== $value) {
                $changed = true;
            }
        }
        if ($changed) {
            try {
                $subscription->update_dates($dates);
            } catch (\Throwable $e) {
                $warnings[] = "Subscription {$id}'s schedule could not be put back: " . $e->getMessage();
            }
        }

        $was_active = 'active' === $subscription->get_status('edit');
        $order      = Wc_Order_Snapshot::restore([ 'object_id' => $id, 'data' => (array) ($data['order'] ?? []) ]);
        if (null !== $order) {
            $warnings[] = $order;
        }

        // Subscriptions gives the customer their active subscriber role
        // itself when update_status() activates a subscription; a restore
        // sets the status directly, so it does the same.
        $after = self::subscription($id);
        if (! $was_active && null !== $after && 'active' === $after->get_status('edit') && function_exists('wcs_make_user_active')) {
            wcs_make_user_active($after->get_customer_id());
        }

        return [] === $warnings ? null : implode(' ', $warnings);
    }
}
