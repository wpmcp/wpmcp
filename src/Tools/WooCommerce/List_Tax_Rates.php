<?php

namespace WPMCP\Tools\WooCommerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: the store's tax classes and tax rates, optionally narrowed to
 * one class or one country, with paging. Rates are read per class through
 * WC_Tax::get_rates_for_tax_class(), the same query the tax settings screen
 * uses, so the order matches what the store owner sees in wp-admin.
 */
class List_Tax_Rates
{
    public function handle(array $args): array
    {
        $classes = [['slug' => 'standard', 'name' => 'Standard']];
        foreach (\WC_Tax::get_tax_rate_classes() as $class) {
            $classes[] = ['slug' => (string) $class->slug, 'name' => (string) $class->name];
        }

        $wanted = null;
        if (isset($args['class']) && '' !== (string) $args['class']) {
            $wanted = sanitize_title((string) $args['class']);
            if (! in_array($wanted, array_column($classes, 'slug'), true)) {
                throw new \InvalidArgumentException('Unknown tax class "' . esc_html($wanted) . '".');
            }
        }
        $country = isset($args['country']) ? strtoupper(trim((string) $args['country'])) : '';

        $rows = [];
        foreach ($classes as $class) {
            if (null !== $wanted && $wanted !== $class['slug']) {
                continue;
            }
            $slug = 'standard' === $class['slug'] ? '' : $class['slug'];
            foreach ((array) \WC_Tax::get_rates_for_tax_class($slug) as $rate) {
                $rate = (array) $rate;
                if ('' !== $country && $country !== (string) $rate['tax_rate_country']) {
                    continue;
                }
                $rows[] = Tax_Rate_View::row($rate, (array) ($rate['postcode'] ?? []), (array) ($rate['city'] ?? []));
            }
        }

        $per_page = max(1, min(100, (int) ($args['per_page'] ?? 50)));
        $page     = max(1, (int) ($args['page'] ?? 1));
        $total    = count($rows);

        return [
            'classes'  => $classes,
            'rates'    => array_slice($rows, ($page - 1) * $per_page, $per_page),
            'total'    => $total,
            'page'     => $page,
            'has_more' => $page * $per_page < $total,
        ];
    }
}
