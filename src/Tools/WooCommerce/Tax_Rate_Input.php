<?php

namespace WPMCP\Tools\WooCommerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Validate tool input for a tax rate and map it onto the column names
 * WC_Tax::_insert_tax_rate() / _update_tax_rate() take. Shared by
 * create-tax-rate and update-tax-rate so both refuse the same things, and
 * always BEFORE a snapshot is taken or a row is written.
 *
 * WooCommerce's own formatters are forgiving in ways that hide mistakes: an
 * unknown class slug silently becomes the standard class, and a non-numeric
 * rate becomes 0.0000. Both are refused here instead, because a tax rate that
 * quietly applies to the wrong class, or charges nothing, is the kind of
 * error nobody notices until the accounts are wrong.
 */
class Tax_Rate_Input
{
    /**
     * @return array{fields: array<string, mixed>, postcodes: ?string[], cities: ?string[]}
     */
    public static function parse(array $args, bool $creating): array
    {
        $fields = [];

        if (array_key_exists('rate', $args) || $creating) {
            $rate = $args['rate'] ?? null;
            if (! is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100) {
                throw new \InvalidArgumentException('rate must be a number from 0 to 100 (a percentage, e.g. "20" or "7.25").');
            }
            $fields['tax_rate'] = (string) $rate;
        }

        if (array_key_exists('country', $args)) {
            $country = strtoupper(trim((string) $args['country']));
            if ('' !== $country) {
                $known = function_exists('WC') && WC()->countries ? array_keys(WC()->countries->get_countries()) : [];
                if (! in_array($country, $known, true)) {
                    throw new \InvalidArgumentException('country must be a two-letter ISO code WooCommerce knows, or "" for every country.');
                }
            }
            $fields['tax_rate_country'] = $country;
        }

        if (array_key_exists('state', $args)) {
            $fields['tax_rate_state'] = strtoupper(sanitize_text_field((string) $args['state']));
        }

        if (array_key_exists('name', $args)) {
            $name = sanitize_text_field((string) $args['name']);
            if ('' === $name) {
                throw new \InvalidArgumentException('name cannot be empty; it is the label shown on invoices.');
            }
            $fields['tax_rate_name'] = $name;
        }

        if (array_key_exists('priority', $args)) {
            $priority = $args['priority'];
            if (! is_int($priority) || $priority < 1) {
                throw new \InvalidArgumentException('priority must be an integer of 1 or more.');
            }
            $fields['tax_rate_priority'] = $priority;
        }

        if (array_key_exists('order', $args)) {
            $order = $args['order'];
            if (! is_int($order) || $order < 0) {
                throw new \InvalidArgumentException('order must be a non-negative integer.');
            }
            $fields['tax_rate_order'] = $order;
        }

        foreach (['compound' => 'tax_rate_compound', 'shipping' => 'tax_rate_shipping'] as $arg => $column) {
            if (array_key_exists($arg, $args)) {
                $fields[ $column ] = $args[ $arg ] ? 1 : 0;
            }
        }

        if (array_key_exists('class', $args)) {
            $fields['tax_rate_class'] = self::class_slug((string) $args['class']);
        }

        return [
            'fields'    => $fields,
            'postcodes' => array_key_exists('postcodes', $args) ? self::string_list($args['postcodes'], 'postcodes') : null,
            'cities'    => array_key_exists('cities', $args) ? self::string_list($args['cities'], 'cities') : null,
        ];
    }

    /** 'standard' (or '') is the default class; any other slug must already exist. */
    private static function class_slug(string $class): string
    {
        $slug = sanitize_title($class);
        if ('' === $slug || 'standard' === $slug) {
            return '';
        }
        $known = class_exists('WC_Tax') ? \WC_Tax::get_tax_class_slugs() : [];
        if (! in_array($slug, $known, true)) {
            throw new \InvalidArgumentException(
                'class must be "standard" or an existing tax class slug (' . esc_html(implode(', ', $known)) . ').'
            );
        }
        return $slug;
    }

    /** @return string[] */
    private static function string_list($value, string $name): array
    {
        if (! is_array($value)) {
            throw new \InvalidArgumentException(esc_html($name) . ' must be an array of strings (an empty array clears it).');
        }
        $out = [];
        foreach ($value as $item) {
            if (! is_scalar($item)) {
                throw new \InvalidArgumentException(esc_html($name) . ' must be an array of strings.');
            }
            $item = sanitize_text_field((string) $item);
            if ('' !== $item) {
                $out[] = $item;
            }
        }
        return $out;
    }
}
