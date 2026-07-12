<?php

namespace WPMCP\Tools\WooCommerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: summarize sales over a date range by aggregating orders via
 * wc_get_orders() (HPOS- and CPT-safe). Returns order count, gross sales,
 * items sold, and the top products by quantity. Reads have nothing to roll
 * back, so this never touches Safe_Mutation.
 *
 * Aggregation is done in PHP over the queried orders rather than through the
 * legacy WC admin report classes, so it does not depend on their reporting
 * cache tables being present or warm.
 */
class Get_Sales_Report
{
    /**
     * Order statuses that count as realized revenue. Pending, cancelled,
     * failed and refunded orders are excluded so the report reflects money
     * actually taken.
     */
    private const REVENUE_STATUSES = ['processing', 'completed', 'on-hold'];

    public function handle(array $args): array
    {
        $date_from = $this->normalize_date($args['date_from'] ?? '', '-30 days');
        $date_to   = $this->normalize_date($args['date_to'] ?? '', 'now');

        $orders = wc_get_orders([
            'limit'        => -1,
            'status'       => self::REVENUE_STATUSES,
            'date_created' => $date_from . '...' . $date_to,
            'return'       => 'objects',
        ]);

        $order_count = 0;
        $gross_sales = 0.0;
        $items_sold  = 0;
        $by_product  = [];

        foreach ($orders as $order) {
            $order_count++;
            $gross_sales += (float) $order->get_total();

            foreach ($order->get_items() as $item) {
                $qty         = (int) $item->get_quantity();
                $items_sold += $qty;
                $product_id  = (int) $item->get_product_id();

                if (! isset($by_product[ $product_id ])) {
                    $by_product[ $product_id ] = [
                        'product_id' => $product_id,
                        'name'       => $item->get_name(),
                        'quantity'   => 0,
                        'revenue'    => 0.0,
                    ];
                }
                $by_product[ $product_id ]['quantity'] += $qty;
                $by_product[ $product_id ]['revenue']  += (float) $item->get_total();
            }
        }

        usort($by_product, static fn($a, $b) => $b['quantity'] <=> $a['quantity']);
        $top_products = array_slice(array_values($by_product), 0, 10);

        return [
            'date_from'    => $date_from,
            'date_to'      => $date_to,
            'order_count'  => $order_count,
            'gross_sales'  => round($gross_sales, 2),
            'items_sold'   => $items_sold,
            'currency'     => get_woocommerce_currency(),
            'top_products' => $top_products,
        ];
    }

    /** Coerce a caller-supplied date to Y-m-d, falling back to $default. */
    private function normalize_date($value, string $default): string
    {
        $value = trim((string) $value);
        $ts    = '' !== $value ? strtotime($value) : false;
        if (false === $ts) {
            $ts = strtotime($default);
        }
        return gmdate('Y-m-d', $ts);
    }
}
