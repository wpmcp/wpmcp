<?php

/**
 * Scenario 1: "Find the 5 most recent orders of the customer with email X
 * and refund the newest one" (WooCommerce).
 *
 * Fixtures: one customer with 7 orders on different days, 5 orders of other
 * customers. Refunds are off by default (wpmcp_woo_op_enabled), so the seed
 * turns refunds.create on the way a site owner would.
 *
 * Ideal sequence on full: the op names are not in tools/list, so one
 * woo-ops call to learn them, then customers.list by email, orders.list for
 * that customer (newest first, 5), and refunds.create on the newest with
 * confirm:true (the description says refunds need it).
 */

use WPMCP\Bench\Surface;

return [
    'id'       => 1,
    'slug'     => 'woo-refund',
    'title'    => 'Find the 5 most recent orders of a customer by email and refund the newest',
    'requires' => [
        'WooCommerce' => static fn(): bool => class_exists('WooCommerce') && function_exists('wc_create_order'),
    ],

    'seed' => static function (): array {
        add_filter('wpmcp_woo_op_enabled', static fn($enabled, $op) => 'refunds.create' === $op ? true : $enabled, 10, 2);

        $product = new \WC_Product_Simple();
        $product->set_name('Bench Mug');
        $product->set_regular_price('12.00');
        $product->set_status('publish');
        $product->save();

        $email    = 'bench.buyer@example.test';
        $customer = wp_insert_user([
            'user_login' => 'bench_buyer',
            'user_email' => $email,
            'user_pass'  => wp_generate_password(24),
            'role'       => 'customer',
        ]);
        $other = wp_insert_user([
            'user_login' => 'bench_other',
            'user_email' => 'bench.other@example.test',
            'user_pass'  => wp_generate_password(24),
            'role'       => 'customer',
        ]);

        $make = static function (int $user, string $email, int $days_ago, int $qty) use ($product): int {
            $order = wc_create_order(['customer_id' => $user]);
            $order->add_product($product, $qty);
            $order->set_billing_email($email);
            $order->set_billing_first_name('Bench');
            $order->set_billing_last_name('Buyer');
            $order->calculate_totals();
            $order->set_status('processing');
            $order->set_date_created(time() - $days_ago * DAY_IN_SECONDS);
            $order->save();
            return $order->get_id();
        };

        $mine = [];
        for ($i = 7; $i >= 1; $i--) {
            $mine[ $i ] = $make((int) $customer, $email, $i * 3, $i);
        }
        for ($i = 1; $i <= 5; $i++) {
            $make((int) $other, 'bench.other@example.test', $i, 1);
        }

        return ['email' => $email, 'newest_order' => $mine[1]];
    },

    'surfaces' => static function (array $f): array {
        $full = [
            ['tool' => 'wpmcp/woo-ops', 'args' => [], 'as' => 'ops'],
            ['tool' => 'wpmcp/woo-read', 'args' => ['op' => 'customers.list', 'params' => ['email' => $f['email']]], 'as' => 'customers'],
            [
                'tool' => 'wpmcp/woo-read',
                'args' => static function (array $r): array {
                    $id = $r['customers']['body'][0]['id'] ?? null;
                    if (null === $id) {
                        throw new \RuntimeException('customers.list found no customer with that email');
                    }
                    return ['op' => 'orders.list', 'params' => ['customer' => (int) $id, 'per_page' => 5, 'orderby' => 'date', 'order' => 'desc']];
                },
                'as'   => 'orders',
            ],
            [
                'tool' => 'wpmcp/woo-write',
                'args' => static function (array $r): array {
                    $newest = $r['orders']['body'][0] ?? null;
                    if (null === $newest) {
                        throw new \RuntimeException('orders.list returned no orders');
                    }
                    return [
                        'op'      => 'refunds.create',
                        'params'  => ['order_id' => (int) $newest['id'], 'amount' => (string) $newest['total'], 'reason' => 'Bench refund'],
                        'confirm' => true,
                    ];
                },
                'as'   => 'refund',
            ],
        ];

        return [
            Surface::FULL    => $full,
            Surface::COMPACT => Surface::compact(['woo'], $full),
        ];
    },

    'verify' => static function (array $f, array $state): ?string {
        $orders = $state['orders']['body'] ?? [];
        if (5 !== count($orders)) {
            return sprintf('expected 5 orders, got %d', count($orders));
        }
        if ((int) $orders[0]['id'] !== (int) $f['newest_order']) {
            return 'the first order listed is not the newest';
        }
        $refunds = wc_get_order($f['newest_order'])->get_refunds();
        return 1 === count($refunds) ? null : 'the newest order has no refund';
    },
];
