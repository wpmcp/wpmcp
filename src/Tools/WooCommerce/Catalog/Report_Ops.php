<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only totals reports behind the reports.* ops (issue #292): sales
 * totals for a period (optionally broken down by day, week or month), top
 * sellers, orders by status, customer totals and coupon totals.
 *
 * Two sources give the same numbers:
 *  - analytics: WooCommerce's own analytics lookup tables (wc_order_stats,
 *    wc_order_product_lookup, wc_order_coupon_lookup, wc_customer_lookup),
 *    aggregated in SQL. They are filled asynchronously, so they are used only
 *    when they exist and agree with the order store on how many orders and
 *    refunds fall in the range; otherwise the report says why it fell back;
 *  - orders: order queries through wc_get_orders(), which work on HPOS and
 *    the legacy post store alike, aggregated here. Bounded: a range holding
 *    more than MAX_ORDERS counted orders is refused rather than truncated.
 * source:"orders" forces the second. Orders by status always counts through
 * order queries, which is already a cheap count per status.
 *
 * Which orders count follows WooCommerce Analytics: every order status except
 * the ones its "excluded statuses" setting lists (pending, failed and
 * cancelled by default). Dates are the store's local time. Responses carry
 * aggregate counts and amounts only: never a customer's name, email or
 * address.
 */
final class Report_Ops
{
    /** Counted orders the order-query fallback aggregates, at most. */
    public const MAX_ORDERS = 5000;

    /** The longest custom range, in days. */
    public const MAX_RANGE_DAYS = 731;

    public const DEFAULT_LIMIT = 10;
    public const MAX_LIMIT     = 50;

    private const PERIODS   = ['day', 'week', 'month', 'last_month', 'year', 'custom'];
    private const INTERVALS = ['day', 'week', 'month'];

    private const KEYS = [
        'report_sales'       => ['period', 'date_from', 'date_to', 'interval', 'source'],
        'report_top_sellers' => ['period', 'date_from', 'date_to', 'limit', 'source'],
        'report_orders'      => ['period', 'date_from', 'date_to'],
        'report_customers'   => ['period', 'date_from', 'date_to', 'source'],
        'report_coupons'     => ['period', 'date_from', 'date_to', 'limit', 'source'],
    ];

    /**
     * Run one report. Returns ['status', 'body'] or a structured error.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function read(string $handler, array $params): array
    {
        try {
            if (! isset(self::KEYS[ $handler ])) {
                self::refuse('Unknown report handler.');
            }
            $unknown = array_values(array_diff(array_map('strval', array_keys($params)), self::KEYS[ $handler ]));
            if ([] !== $unknown) {
                self::refuse('Unknown field(s): ' . implode(', ', $unknown) . '. Accepted: ' . implode(', ', self::KEYS[ $handler ]) . '.');
            }
            $range = self::range($params);
            $opts  = self::options($params);
            $body  = self::run($handler, $range, $opts);
        } catch (Order_Op_Refused $e) {
            return Op_Guard::error($e->code_name, $e->getMessage(), $e->data);
        }

        return [
            'status' => 200,
            'body'   => [
                'range'    => [ 'from' => $range['from'], 'to' => $range['to'], 'timezone' => wp_timezone_string() ],
                'currency' => get_woocommerce_currency(),
            ] + $body,
        ];
    }

    /**
     * @param array{from: string, to: string, start: string, end: string} $range
     * @param array{interval: ?string, limit: int, source: string}         $opts
     * @return array<string, mixed>
     */
    private static function run(string $handler, array $range, array $opts): array
    {
        if ('report_orders' === $handler) {
            return [ 'source' => 'orders' ] + self::orders_by_status($range);
        }

        $note = null;
        if ('auto' === $opts['source']) {
            $note = self::analytics_unusable($handler, $range);
            if (null === $note) {
                return [ 'source' => 'analytics' ] + self::from_analytics($handler, $range, $opts);
            }
        }

        $ids = self::counted_order_ids($range);
        $out = [ 'source' => 'orders' ];
        if (null !== $note) {
            $out['source_note'] = $note;
        }
        return $out + self::from_orders($handler, $range, $opts, $ids);
    }

    // ---------------------------------------------------------------- params

    /**
     * The local date range a period names, inclusive.
     *
     * @param array<string, mixed> $params
     * @return array{from: string, to: string, start: string, end: string}
     */
    private static function range(array $params): array
    {
        $period = $params['period'] ?? 'month';
        if (! is_string($period) || ! in_array($period, self::PERIODS, true)) {
            self::refuse('period must be one of: ' . implode(', ', self::PERIODS) . '.');
        }

        $today = new \DateTimeImmutable('today', wp_timezone());
        switch ($period) {
            case 'day':
                $from = $today;
                $to   = $today;
                break;
            case 'week':
                $from = $today->modify('-6 days');
                $to   = $today;
                break;
            case 'last_month':
                $from = $today->modify('first day of last month');
                $to   = $today->modify('last day of last month');
                break;
            case 'year':
                $from = $today->setDate((int) $today->format('Y'), 1, 1);
                $to   = $today;
                break;
            case 'custom':
                $from = self::date($params['date_from'] ?? null, 'date_from');
                $to   = self::date($params['date_to'] ?? null, 'date_to');
                if ($from > $to) {
                    self::refuse('date_from must not be after date_to.');
                }
                if ((int) $from->diff($to)->days >= self::MAX_RANGE_DAYS) {
                    self::refuse('A custom range spans at most ' . self::MAX_RANGE_DAYS . ' days.');
                }
                break;
            default: // month
                $from = $today->modify('first day of this month');
                $to   = $today;
        }

        return [
            'from'  => $from->format('Y-m-d'),
            'to'    => $to->format('Y-m-d'),
            'start' => $from->format('Y-m-d') . ' 00:00:00',
            'end'   => $to->format('Y-m-d') . ' 23:59:59',
        ];
    }

    private static function date($value, string $name): \DateTimeImmutable
    {
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value, wp_timezone()) : false;
        if (false === $date || $date->format('Y-m-d') !== $value) {
            self::refuse("A custom period needs {$name} as YYYY-MM-DD.");
        }
        return $date;
    }

    /**
     * @param array<string, mixed> $params
     * @return array{interval: ?string, limit: int, source: string}
     */
    private static function options(array $params): array
    {
        $interval = $params['interval'] ?? null;
        if (null !== $interval && ! in_array($interval, self::INTERVALS, true)) {
            self::refuse('interval must be one of: ' . implode(', ', self::INTERVALS) . '.');
        }

        $limit = $params['limit'] ?? self::DEFAULT_LIMIT;
        if (! is_int($limit) && ! (is_string($limit) && ctype_digit($limit))) {
            $limit = 0;
        }
        $limit = (int) $limit;
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            self::refuse('limit must be from 1 to ' . self::MAX_LIMIT . '.');
        }

        $source = $params['source'] ?? 'auto';
        if (! in_array($source, ['auto', 'orders'], true)) {
            self::refuse('source must be auto or orders.');
        }

        return [ 'interval' => $interval, 'limit' => $limit, 'source' => $source ];
    }

    // -------------------------------------------------------- order queries

    /** Order statuses the reports count, without the wc- prefix. */
    private static function counted_statuses(): array
    {
        $excluded = get_option('woocommerce_excluded_report_order_statuses', ['pending', 'failed', 'cancelled']);
        $excluded = array_merge(is_array($excluded) ? $excluded : [], ['trash', 'auto-draft', 'checkout-draft']);
        $all      = array_map(static fn ($s) => str_starts_with($s, 'wc-') ? substr($s, 3) : $s, array_keys(wc_get_order_statuses()));
        return array_values(array_diff($all, $excluded));
    }

    /** "start...end" as UTC timestamps, which wc_get_orders() reads unambiguously. */
    private static function date_query(array $range): string
    {
        $tz = wp_timezone();
        return (new \DateTimeImmutable($range['start'], $tz))->getTimestamp() . '...' . (new \DateTimeImmutable($range['end'], $tz))->getTimestamp();
    }

    /**
     * How many orders of one type and status set were created in the range.
     *
     * @param string[] $statuses
     */
    private static function count_orders(array $range, string $type, array $statuses = []): int
    {
        $args = [
            'type'         => $type,
            'date_created' => self::date_query($range),
            'limit'        => 1,
            'paginate'     => true,
            'return'       => 'ids',
        ];
        if ([] !== $statuses) {
            $args['status'] = $statuses;
        } else {
            $args['status'] = array_keys(wc_get_order_statuses());
        }
        $found = wc_get_orders($args);
        return (int) ($found->total ?? 0);
    }

    /**
     * Ids of the counted orders in the range, refused past MAX_ORDERS.
     *
     * @return int[]
     */
    private static function counted_order_ids(array $range): array
    {
        $ids = wc_get_orders([
            'type'         => 'shop_order',
            'status'       => self::counted_statuses(),
            'date_created' => self::date_query($range),
            'limit'        => self::MAX_ORDERS + 1,
            'return'       => 'ids',
            'orderby'      => 'ID',
            'order'        => 'ASC',
        ]);
        if (count($ids) > self::MAX_ORDERS) {
            self::refuse(
                'More than ' . self::MAX_ORDERS . ' orders fall in this range and the analytics tables cannot answer for it. Narrow the range.',
                [ 'max_orders' => self::MAX_ORDERS ],
                'range_too_large'
            );
        }
        return array_map('intval', $ids);
    }

    /**
     * @param int[] $ids
     * @return array<string, mixed>
     */
    private static function from_orders(string $handler, array $range, array $opts, array $ids): array
    {
        $orders = array_filter(array_map('wc_get_order', $ids), static fn ($o) => $o instanceof \WC_Order);

        if ('report_sales' === $handler) {
            $rows = [];
            foreach ($orders as $order) {
                $total    = (float) $order->get_total();
                $tax      = (float) $order->get_total_tax();
                $shipping = (float) $order->get_shipping_total();
                $rows[]   = [
                    'day'      => $order->get_date_created()->date('Y-m-d'),
                    'orders'   => 1,
                    'items'    => (int) $order->get_item_count(),
                    'gross'    => $total,
                    'net'      => $total - $tax - $shipping,
                    'shipping' => $shipping,
                    'taxes'    => $tax,
                ];
            }
            $refunds = 0.0;
            $refund_ids = wc_get_orders([
                'type'         => 'shop_order_refund',
                'date_created' => self::date_query($range),
                'limit'        => -1,
                'return'       => 'ids',
            ]);
            foreach ($refund_ids as $refund_id) {
                $refund   = wc_get_order($refund_id);
                $refunds += $refund ? (float) $refund->get_amount() : 0.0;
            }
            return self::sales_body($rows, $refunds, $opts['interval']);
        }

        if ('report_top_sellers' === $handler) {
            $products = [];
            foreach ($orders as $order) {
                foreach ($order->get_items() as $item) {
                    $id = (int) $item->get_product_id();
                    $products[ $id ]['quantity']    = ($products[ $id ]['quantity'] ?? 0) + (int) $item->get_quantity();
                    $products[ $id ]['net_revenue'] = ($products[ $id ]['net_revenue'] ?? 0.0) + (float) $item->get_total();
                    $products[ $id ]['name']        = $products[ $id ]['name'] ?? $item->get_name();
                }
            }
            return [ 'products' => self::top($products, $opts['limit']) ];
        }

        if ('report_customers' === $handler) {
            $orders_by = [];
            foreach ($orders as $order) {
                $user = (int) $order->get_customer_id();
                $key  = $user > 0 ? 'u' . $user : 'g' . md5(strtolower(trim((string) $order->get_billing_email())) ?: 'order' . $order->get_id());
                $orders_by[ $key ] = ($orders_by[ $key ] ?? 0) + 1;
            }
            return self::customers_body($orders_by, $range);
        }

        // report_coupons
        $coupons = [];
        $with    = 0;
        foreach ($orders as $order) {
            $items = $order->get_items('coupon');
            if ([] !== $items) {
                $with++;
            }
            foreach ($items as $item) {
                $code = wc_format_coupon_code((string) $item->get_code());
                $coupons[ $code ]['orders']   = ($coupons[ $code ]['orders'] ?? 0) + 1;
                $coupons[ $code ]['discount'] = ($coupons[ $code ]['discount'] ?? 0.0) + (float) $item->get_discount();
            }
        }
        return self::coupons_body($coupons, $with, $opts['limit']);
    }

    /** @return array<string, mixed> */
    private static function orders_by_status(array $range): array
    {
        $statuses = [];
        foreach (array_keys(wc_get_order_statuses()) as $status) {
            $count = self::count_orders($range, 'shop_order', [ $status ]);
            if ($count > 0) {
                $statuses[ str_starts_with($status, 'wc-') ? substr($status, 3) : $status ] = $count;
            }
        }
        ksort($statuses);
        return [ 'total' => array_sum($statuses), 'statuses' => $statuses ];
    }

    // ------------------------------------------------------------ analytics

    /**
     * Null when the analytics tables can answer this report for this range,
     * else the reason they cannot.
     */
    private static function analytics_unusable(string $handler, array $range): ?string
    {
        if (! class_exists('Automattic\\WooCommerce\\Admin\\API\\Reports\\Orders\\Stats\\DataStore')) {
            return 'This WooCommerce has no analytics data store.';
        }
        if ('no' === get_option('woocommerce_analytics_enabled', 'yes')) {
            return 'WooCommerce Analytics is switched off on this store.';
        }
        $tables = [ 'wc_order_stats' ];
        $tables[] = [
            'report_top_sellers' => 'wc_order_product_lookup',
            'report_customers'   => 'wc_customer_lookup',
            'report_coupons'     => 'wc_order_coupon_lookup',
        ][ $handler ] ?? 'wc_order_stats';
        foreach (array_unique($tables) as $table) {
            if (! self::table_exists($table)) {
                return "The analytics table {$table} does not exist.";
            }
        }

        global $wpdb;
        $statuses = self::counted_statuses();
        $in       = implode(',', array_fill(0, count($statuses), '%s'));
        $args     = array_merge(array_map(static fn ($s) => 'wc-' . $s, $statuses), [ $range['start'], $range['end'] ]);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- aggregate read of WooCommerce's analytics table; the IN list is built from placeholders.
        $orders = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wc_order_stats WHERE parent_id = 0 AND status IN ({$in}) AND date_created BETWEEN %s AND %s", $args));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- aggregate read of WooCommerce's analytics table; both dates are placeholders.
        $refunds = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wc_order_stats WHERE parent_id > 0 AND total_sales < 0 AND date_created BETWEEN %s AND %s", $range['start'], $range['end']));

        if ($orders !== self::count_orders($range, 'shop_order', $statuses) || $refunds !== self::count_orders($range, 'shop_order_refund')) {
            return 'The analytics tables are behind the order store for this range (an import may still be running), so orders were queried directly.';
        }
        return null;
    }

    private static function table_exists(string $table): bool
    {
        global $wpdb;
        $name = $wpdb->prefix . $table;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema probe.
        return $name === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($name)));
    }

    /** @return array<string, mixed> */
    private static function from_analytics(string $handler, array $range, array $opts): array
    {
        global $wpdb;
        $statuses = self::counted_statuses();
        $in       = implode(',', array_fill(0, count($statuses), '%s'));
        $where    = "s.parent_id = 0 AND s.status IN ({$in}) AND s.date_created BETWEEN %s AND %s";
        $args     = array_merge(array_map(static fn ($s) => 'wc-' . $s, $statuses), [ $range['start'], $range['end'] ]);
        $stats    = "{$wpdb->prefix}wc_order_stats";

        if ('report_sales' === $handler) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- aggregate read of the analytics table; every value is a placeholder.
            $days = (array) $wpdb->get_results($wpdb->prepare("SELECT DATE(s.date_created) AS day, COUNT(*) AS orders, SUM(s.num_items_sold) AS items, SUM(s.total_sales) AS gross, SUM(s.net_total) AS net, SUM(s.shipping_total) AS shipping, SUM(s.tax_total) AS taxes FROM {$stats} s WHERE {$where} GROUP BY DATE(s.date_created)", $args), ARRAY_A);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- aggregate read of the analytics table; both dates are placeholders.
            $refunds = -1 * (float) $wpdb->get_var($wpdb->prepare("SELECT SUM(total_sales) FROM {$stats} WHERE parent_id > 0 AND total_sales < 0 AND date_created BETWEEN %s AND %s", $range['start'], $range['end']));
            $rows = array_map(static fn ($d) => [
                'day'      => (string) $d['day'],
                'orders'   => (int) $d['orders'],
                'items'    => (int) $d['items'],
                'gross'    => (float) $d['gross'],
                'net'      => (float) $d['net'],
                'shipping' => (float) $d['shipping'],
                'taxes'    => (float) $d['taxes'],
            ], $days);
            return self::sales_body($rows, $refunds, $opts['interval']);
        }

        if ('report_top_sellers' === $handler) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- aggregate read of the analytics tables; every value is a placeholder.
            $found = (array) $wpdb->get_results($wpdb->prepare("SELECT p.product_id, SUM(p.product_qty) AS quantity, SUM(p.product_net_revenue) AS net_revenue FROM {$wpdb->prefix}wc_order_product_lookup p INNER JOIN {$stats} s ON s.order_id = p.order_id WHERE {$where} GROUP BY p.product_id", $args), ARRAY_A);
            $products = [];
            foreach ($found as $row) {
                $id      = (int) $row['product_id'];
                $product = wc_get_product($id);
                $products[ $id ] = [
                    'name'        => $product ? $product->get_name() : "Product {$id}",
                    'quantity'    => (int) $row['quantity'],
                    'net_revenue' => (float) $row['net_revenue'],
                ];
            }
            return [ 'products' => self::top($products, $opts['limit']) ];
        }

        if ('report_customers' === $handler) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- aggregate read of the analytics tables; only ids and counts leave the query.
            $found = (array) $wpdb->get_results($wpdb->prepare("SELECT s.customer_id, c.user_id, COUNT(*) AS orders FROM {$stats} s LEFT JOIN {$wpdb->prefix}wc_customer_lookup c ON c.customer_id = s.customer_id WHERE {$where} GROUP BY s.customer_id, c.user_id", $args), ARRAY_A);
            $orders_by = [];
            foreach ($found as $row) {
                $key = ! empty($row['user_id']) ? 'u' . (int) $row['user_id'] : 'g' . (int) $row['customer_id'];
                $orders_by[ $key ] = ($orders_by[ $key ] ?? 0) + (int) $row['orders'];
            }
            return self::customers_body($orders_by, $range);
        }

        // report_coupons
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- aggregate read of the analytics tables; every value is a placeholder.
        $found = (array) $wpdb->get_results($wpdb->prepare("SELECT l.coupon_id, COUNT(DISTINCT l.order_id) AS orders, SUM(l.discount_amount) AS discount FROM {$wpdb->prefix}wc_order_coupon_lookup l INNER JOIN {$stats} s ON s.order_id = l.order_id WHERE {$where} GROUP BY l.coupon_id", $args), ARRAY_A);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- aggregate read of the analytics tables; every value is a placeholder.
        $with    = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT l.order_id) FROM {$wpdb->prefix}wc_order_coupon_lookup l INNER JOIN {$stats} s ON s.order_id = l.order_id WHERE {$where}", $args));
        $coupons = [];
        foreach ($found as $row) {
            $id   = (int) $row['coupon_id'];
            $code = $id > 0 ? wc_format_coupon_code((string) wc_get_coupon_code_by_id($id)) : '';
            $code = '' === $code ? "deleted coupon {$id}" : $code;
            $coupons[ $code ]['orders']   = ($coupons[ $code ]['orders'] ?? 0) + (int) $row['orders'];
            $coupons[ $code ]['discount'] = ($coupons[ $code ]['discount'] ?? 0.0) + (float) $row['discount'];
        }
        return self::coupons_body($coupons, $with, $opts['limit']);
    }

    // ------------------------------------------------------------- shaping

    /**
     * Totals, and intervals when asked, from per-day (or per-order) rows.
     *
     * @param array<int, array{day: string, orders: int, items: int, gross: float, net: float, shipping: float, taxes: float}> $rows
     * @return array<string, mixed>
     */
    private static function sales_body(array $rows, float $refunds, ?string $interval): array
    {
        $body = [ 'totals' => self::sum($rows) + [] ];
        $body['totals']['refunds']             = round($refunds, 2);
        $body['totals']['average_order_value'] = $body['totals']['orders'] > 0 ? round($body['totals']['gross_sales'] / $body['totals']['orders'], 2) : 0.0;

        if (null !== $interval) {
            $groups = [];
            foreach ($rows as $row) {
                $date = new \DateTimeImmutable($row['day']);
                $key  = 'day' === $interval ? $date->format('Y-m-d') : ('week' === $interval ? $date->format('o-\WW') : $date->format('Y-m'));
                $groups[ $key ][] = $row;
            }
            ksort($groups);
            $body['intervals'] = [];
            foreach ($groups as $key => $group) {
                $body['intervals'][] = [ 'interval' => (string) $key ] + self::sum($group);
            }
        }
        return $body;
    }

    /** @return array{orders: int, items_sold: int, gross_sales: float, net_sales: float, shipping: float, taxes: float} */
    private static function sum(array $rows): array
    {
        $col = static fn (string $k) => array_sum(array_column($rows, $k));
        return [
            'orders'      => (int) $col('orders'),
            'items_sold'  => (int) $col('items'),
            'gross_sales' => round((float) $col('gross'), 2),
            'net_sales'   => round((float) $col('net'), 2),
            'shipping'    => round((float) $col('shipping'), 2),
            'taxes'       => round((float) $col('taxes'), 2),
        ];
    }

    /**
     * Products by quantity sold, then net revenue, then id.
     *
     * @param array<int, array{name: string, quantity: int, net_revenue: float}> $products
     * @return array<int, array<string, mixed>>
     */
    private static function top(array $products, int $limit): array
    {
        $rows = [];
        foreach ($products as $id => $p) {
            $rows[] = [ 'product_id' => (int) $id, 'name' => (string) $p['name'], 'quantity' => (int) $p['quantity'], 'net_revenue' => round((float) $p['net_revenue'], 2) ];
        }
        usort($rows, static fn ($a, $b) => [ $b['quantity'], $b['net_revenue'], $a['product_id'] ] <=> [ $a['quantity'], $a['net_revenue'], $b['product_id'] ]);
        return array_slice($rows, 0, $limit);
    }

    /**
     * @param array<string, int> $orders_by orders per customer key
     * @return array<string, mixed>
     */
    private static function customers_body(array $orders_by, array $range): array
    {
        $registered = count(array_filter(array_keys($orders_by), static fn ($k) => str_starts_with((string) $k, 'u')));
        $accounts   = new \WP_User_Query([
            'role'        => 'customer',
            'fields'      => 'ID',
            'number'      => 1,
            'count_total' => true,
            'date_query'  => [ [ 'after' => $range['start'], 'before' => $range['end'], 'inclusive' => true ] ],
        ]);

        return [
            'totals' => [
                'customers'            => count($orders_by),
                'registered_customers' => $registered,
                'guest_customers'      => count($orders_by) - $registered,
                'repeat_customers'     => count(array_filter($orders_by, static fn ($n) => $n > 1)),
                'new_accounts'         => (int) $accounts->get_total(),
            ],
        ];
    }

    /**
     * @param array<string, array{orders: int, discount: float}> $coupons
     * @return array<string, mixed>
     */
    private static function coupons_body(array $coupons, int $with, int $limit): array
    {
        $rows = [];
        foreach ($coupons as $code => $c) {
            $rows[] = [ 'code' => (string) $code, 'orders' => (int) $c['orders'], 'discount' => round((float) $c['discount'], 2) ];
        }
        usort($rows, static fn ($a, $b) => [ $b['orders'], $b['discount'], $a['code'] ] <=> [ $a['orders'], $a['discount'], $b['code'] ]);

        return [
            'totals'  => [
                'coupons_used'        => count($rows),
                'orders_with_coupons' => $with,
                'discount_total'      => round((float) array_sum(array_column($rows, 'discount')), 2),
            ],
            'coupons' => array_slice($rows, 0, $limit),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function refuse(string $message, array $data = [], string $code = 'invalid_params'): never
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught in read() and returned as a JSON error, never rendered.
        throw new Order_Op_Refused($code, $message, $data);
    }
}
