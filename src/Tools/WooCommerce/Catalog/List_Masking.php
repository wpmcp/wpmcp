<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

use WPMCP\Privacy\Masking;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Hides personal data in the raw wc/v3 LIST reads before the body reaches the
 * model: orders.list, orders.notes, refunds.list and customers.list. A list
 * carries many people at once, so names, emails, phones, addresses, IPs,
 * free text and custom meta values keep only their first character (the shared
 * Privacy\Masking rule). Ids, order numbers, statuses, totals, dates, currency,
 * countries and line items stay readable. The single-record reads (orders.get,
 * customers.get, refunds.get) are not touched: an administrator gets full values
 * there, and an agent that must ship one order reads its address from orders.get.
 */
final class List_Masking
{
    /** The ops whose body is masked. */
    public const OPS = ['orders.list', 'orders.notes', 'refunds.list', 'customers.list'];

    /** Top-level keys masked, per op. */
    private const KEYS = [
        'orders.list'    => ['customer_note', 'customer_ip_address', 'customer_user_agent'],
        'orders.notes'   => ['author', 'note'],
        'refunds.list'   => ['reason'],
        'customers.list' => ['email', 'first_name', 'last_name', 'username', 'avatar_url'],
    ];

    /** Address blocks masked (every field but the country), per op. */
    private const ADDRESSES = [
        'orders.list'    => ['billing', 'shipping'],
        'customers.list' => ['billing', 'shipping'],
    ];

    /** Ops whose records carry plugin meta_data (values are masked, keys stay). */
    private const META = ['orders.list', 'refunds.list', 'customers.list'];

    public static function applies(string $op): bool
    {
        return in_array($op, self::OPS, true);
    }

    /**
     * @param mixed $body
     * @return mixed
     */
    public static function apply(string $op, $body)
    {
        if (! self::applies($op) || ! is_array($body)) {
            return $body;
        }
        if (! array_is_list($body)) {
            return self::record($op, $body);
        }
        return array_map(static fn ($record) => is_array($record) ? self::record($op, $record) : $record, $body);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private static function record(string $op, array $record): array
    {
        $record = Masking::keys($record, self::KEYS[ $op ]);
        foreach (self::ADDRESSES[ $op ] ?? [] as $block) {
            if (isset($record[ $block ]) && is_array($record[ $block ])) {
                $country = $record[ $block ]['country'] ?? null;
                $record[ $block ] = Masking::value($record[ $block ]);
                if (null !== $country) {
                    $record[ $block ]['country'] = $country;
                }
            }
        }
        if (in_array($op, self::META, true) && isset($record['meta_data']) && is_array($record['meta_data'])) {
            $record['meta_data'] = array_map(
                static fn ($meta) => is_array($meta) ? Masking::keys($meta, ['value']) : $meta,
                $record['meta_data']
            );
        }
        return $record;
    }
}
