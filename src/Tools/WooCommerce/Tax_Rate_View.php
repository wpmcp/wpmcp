<?php

namespace WPMCP\Tools\WooCommerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shape a WooCommerce tax rate (a woocommerce_tax_rates row plus its postcode
 * and city locations) into a flat row. The rate stays the stored string
 * ("20.0000") so a caller can compare it exactly, and the empty class slug
 * WooCommerce stores for the standard class is reported as 'standard', the
 * name every other part of the admin uses for it.
 */
class Tax_Rate_View
{
    /**
     * @param array<string, mixed> $row       A woocommerce_tax_rates row.
     * @param string[]             $postcodes
     * @param string[]             $cities
     */
    public static function row(array $row, array $postcodes, array $cities): array
    {
        $class = (string) ($row['tax_rate_class'] ?? '');

        return [
            'id'        => (int) ($row['tax_rate_id'] ?? 0),
            'country'   => (string) ($row['tax_rate_country'] ?? ''),
            'state'     => (string) ($row['tax_rate_state'] ?? ''),
            'postcodes' => array_values(array_map('strval', $postcodes)),
            'cities'    => array_values(array_map('strval', $cities)),
            'rate'      => (string) ($row['tax_rate'] ?? ''),
            'name'      => (string) ($row['tax_rate_name'] ?? ''),
            'priority'  => (int) ($row['tax_rate_priority'] ?? 1),
            'compound'  => (bool) (int) ($row['tax_rate_compound'] ?? 0),
            'shipping'  => (bool) (int) ($row['tax_rate_shipping'] ?? 0),
            'order'     => (int) ($row['tax_rate_order'] ?? 0),
            'class'     => '' === $class ? 'standard' : $class,
        ];
    }

    /** Read one rate by id through the same capture the snapshot uses, or null. */
    public static function find(int $tax_rate_id): ?array
    {
        $snapshot = \WPMCP\Safety\Snapshot::capture('wc_tax_rate', $tax_rate_id);
        $row      = $snapshot['data']['rate'];
        if (! is_array($row)) {
            return null;
        }
        return self::row($row, $snapshot['data']['postcodes'], $snapshot['data']['cities']);
    }
}
