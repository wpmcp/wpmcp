<?php

namespace WPMCP\Tools\WooCommerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Validate coupon tool input and apply it through WC_Coupon's setters.
 * Shared by create-coupon and update-coupon so both refuse the same inputs,
 * always before a snapshot is taken or anything is saved.
 *
 * The refusals exist because WooCommerce's setters coerce rather than reject:
 * an unknown discount_type becomes fixed_cart, a malformed email is dropped
 * from the restriction list without a word, and a second coupon can be given
 * a code that already exists (the storefront then applies whichever it finds
 * first). Each of those turns a typo into a live discount nobody intended.
 */
class Coupon_Input
{
    private const STATUSES = ['publish', 'draft', 'pending', 'private'];

    private const ID_LISTS = ['product_ids', 'excluded_product_ids', 'product_categories', 'excluded_product_categories'];

    private const BOOLEANS = ['individual_use', 'free_shipping', 'exclude_sale_items'];

    /** Throw on anything WooCommerce would coerce or silently drop. $exclude_id is the coupon being edited. */
    public static function validate(array $args, int $exclude_id, string $current_type): void
    {
        if (array_key_exists('code', $args)) {
            $code = wc_format_coupon_code((string) $args['code']);
            if ('' === $code) {
                throw new \InvalidArgumentException('code cannot be empty.');
            }
            $owner = Coupon_View::find_id_by_code($code, $exclude_id);
            if ($owner > 0) {
                throw new \InvalidArgumentException('The coupon code "' . esc_html($code) . '" is already used by coupon ' . (int) $owner . '.');
            }
        }

        $type = $current_type;
        if (array_key_exists('discount_type', $args)) {
            $type  = (string) $args['discount_type'];
            $types = array_keys(wc_get_coupon_types());
            if (! in_array($type, $types, true)) {
                throw new \InvalidArgumentException('discount_type must be one of: ' . esc_html(implode(', ', $types)) . '.');
            }
        }

        if (array_key_exists('amount', $args)) {
            $amount = $args['amount'];
            if (! is_numeric($amount) || (float) $amount < 0) {
                throw new \InvalidArgumentException('amount must be a non-negative number.');
            }
            if ('percent' === $type && (float) $amount > 100) {
                throw new \InvalidArgumentException('A percent coupon cannot discount more than 100.');
            }
        }

        foreach (['minimum_amount', 'maximum_amount'] as $key) {
            if (
                array_key_exists($key, $args) && '' !== (string) $args[ $key ]
                && (! is_numeric($args[ $key ]) || (float) $args[ $key ] < 0)
            ) {
                throw new \InvalidArgumentException(esc_html($key) . ' must be a non-negative number, or "" for no limit.');
            }
        }

        foreach (['usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items'] as $key) {
            if (
                array_key_exists($key, $args) && null !== $args[ $key ]
                && (! is_int($args[ $key ]) || $args[ $key ] < 0)
            ) {
                throw new \InvalidArgumentException(esc_html($key) . ' must be a non-negative integer (0 or null for no limit).');
            }
        }

        if (array_key_exists('date_expires', $args) && null !== $args['date_expires'] && '' !== $args['date_expires']) {
            if (! is_string($args['date_expires']) || false === strtotime($args['date_expires'])) {
                throw new \InvalidArgumentException('date_expires must be a date such as "2026-12-31", or null to remove the expiry.');
            }
        }

        foreach (self::ID_LISTS as $key) {
            if (array_key_exists($key, $args) && ! self::is_id_list($args[ $key ])) {
                throw new \InvalidArgumentException(esc_html($key) . ' must be an array of positive integer ids.');
            }
        }

        if (array_key_exists('email_restrictions', $args)) {
            if (! is_array($args['email_restrictions'])) {
                throw new \InvalidArgumentException('email_restrictions must be an array of email addresses.');
            }
            foreach ($args['email_restrictions'] as $email) {
                // A leading "*@" is WooCommerce's domain wildcard.
                $probe = is_string($email) ? preg_replace('/^\*@/', 'x@', trim($email)) : '';
                if (! is_string($email) || ! is_email($probe)) {
                    throw new \InvalidArgumentException('email_restrictions has an invalid address: ' . esc_html(is_scalar($email) ? (string) $email : gettype($email)) . '.');
                }
            }
        }

        if (array_key_exists('status', $args) && ! in_array((string) $args['status'], self::STATUSES, true)) {
            throw new \InvalidArgumentException(
                'status must be one of: ' . esc_html(implode(', ', self::STATUSES)) . '. Use delete-coupon to trash a coupon.'
            );
        }
    }

    /** Apply only the fields present in $args. Call validate() first. */
    public static function apply(\WC_Coupon $coupon, array $args): void
    {
        if (array_key_exists('code', $args)) {
            $coupon->set_code(wc_format_coupon_code((string) $args['code']));
        }
        if (array_key_exists('discount_type', $args)) {
            $coupon->set_discount_type((string) $args['discount_type']);
        }
        if (array_key_exists('amount', $args)) {
            $coupon->set_amount((string) $args['amount']);
        }
        if (array_key_exists('description', $args)) {
            $coupon->set_description(sanitize_textarea_field((string) $args['description']));
        }
        if (array_key_exists('date_expires', $args)) {
            $value = $args['date_expires'];
            $coupon->set_date_expires(null === $value || '' === $value ? null : (string) $value);
        }
        foreach (['minimum_amount', 'maximum_amount'] as $key) {
            if (array_key_exists($key, $args)) {
                $coupon->{'set_' . $key}((string) $args[ $key ]);
            }
        }
        if (array_key_exists('usage_limit', $args)) {
            $coupon->set_usage_limit((int) $args['usage_limit']);
        }
        if (array_key_exists('usage_limit_per_user', $args)) {
            $coupon->set_usage_limit_per_user((int) $args['usage_limit_per_user']);
        }
        if (array_key_exists('limit_usage_to_x_items', $args)) {
            $coupon->set_limit_usage_to_x_items(null === $args['limit_usage_to_x_items'] ? null : (int) $args['limit_usage_to_x_items']);
        }
        foreach (self::BOOLEANS as $key) {
            if (array_key_exists($key, $args)) {
                $coupon->{'set_' . $key}((bool) $args[ $key ]);
            }
        }
        foreach (self::ID_LISTS as $key) {
            if (array_key_exists($key, $args)) {
                $coupon->{'set_' . $key}(array_map('intval', (array) $args[ $key ]));
            }
        }
        if (array_key_exists('email_restrictions', $args)) {
            $coupon->set_email_restrictions(array_map('trim', (array) $args['email_restrictions']));
        }
        if (array_key_exists('status', $args)) {
            $coupon->set_status((string) $args['status']);
        }
    }

    private static function is_id_list($value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $id) {
            if (! is_int($id) || $id <= 0) {
                return false;
            }
        }
        return true;
    }
}
