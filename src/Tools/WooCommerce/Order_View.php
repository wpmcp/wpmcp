<?php

namespace WPMCP\Tools\WooCommerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shape a WC_Order into safe summary/detail rows. Works uniformly whether the
 * store uses HPOS (custom order tables) or the legacy CPT, because it only
 * reads through the WC_Order CRUD getters.
 */
class Order_View
{
    public static function summary(\WC_Order $order): array
    {
        return [
            'id'           => $order->get_id(),
            'number'       => $order->get_order_number(),
            'status'       => $order->get_status(),
            'total'        => $order->get_total(),
            'currency'     => $order->get_currency(),
            'customer_id'  => $order->get_customer_id(),
            'date_created' => $order->get_date_created() ? $order->get_date_created()->date('c') : null,
        ];
    }

    public static function detail(\WC_Order $order): array
    {
        return array_merge(self::summary($order), [
            'billing_email' => $order->get_billing_email(),
            'payment_method' => $order->get_payment_method(),
            'items'         => self::items($order),
            'customer_note' => $order->get_customer_note(),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private static function items(\WC_Order $order): array
    {
        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = [
                'name'     => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'total'    => $item->get_total(),
            ];
        }
        return $items;
    }
}
