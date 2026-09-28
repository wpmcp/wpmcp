<?php

namespace WPMCP\Tools\WooCommerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: would this coupon code be accepted at checkout, and if not, why?
 *
 * Checkout validation (WC_Discounts) runs against a live cart, which an agent
 * does not have, so this runs the store-level rules one by one and reports
 * each as pass, fail or skipped:
 *  - published: checkout only resolves published coupons;
 *  - expiry and the overall usage limit;
 *  - the per-customer limit and the email allow-list, when an email is given;
 *  - minimum and maximum spend, when a subtotal is given.
 * Product, category and sale-item restrictions depend on the cart contents
 * and are listed as 'cart' so the caller knows they were not evaluated.
 * valid is true only when no evaluated rule failed. Nothing is written: no
 * usage is recorded and no hold is placed on the coupon.
 */
class Validate_Coupon
{
    public function handle(array $args): array
    {
        $code = wc_format_coupon_code((string) ($args['code'] ?? ''));
        if ('' === $code) {
            throw new \InvalidArgumentException('A coupon code is required.');
        }

        $email = null;
        if (isset($args['email']) && '' !== trim((string) $args['email'])) {
            $email = strtolower(sanitize_email((string) $args['email']));
            if (! is_email($email)) {
                throw new \InvalidArgumentException('email is not a valid address.');
            }
        }
        $subtotal = null;
        if (isset($args['subtotal']) && '' !== (string) $args['subtotal']) {
            if (! is_numeric($args['subtotal']) || (float) $args['subtotal'] < 0) {
                throw new \InvalidArgumentException('subtotal must be a non-negative number.');
            }
            $subtotal = (float) $args['subtotal'];
        }

        $id = Coupon_View::find_id_by_code($code);
        if ($id <= 0) {
            return [
                'code'   => $code,
                'id'     => null,
                'valid'  => false,
                'checks' => [self::check('exists', 'fail', 'No coupon has this code.')],
            ];
        }

        $coupon = new \WC_Coupon($id);
        $checks = [];

        $checks[] = 'publish' === $coupon->get_status()
            ? self::check('published', 'pass', 'The coupon is published.')
            : self::check('published', 'fail', 'The coupon is ' . $coupon->get_status() . '; checkout only accepts published coupons.');

        $expires  = $coupon->get_date_expires();
        $checks[] = ($expires && time() > $expires->getTimestamp())
            ? self::check('expiry', 'fail', 'The coupon expired on ' . $expires->date('Y-m-d') . '.')
            : self::check('expiry', 'pass', $expires ? 'Expires on ' . $expires->date('Y-m-d') . '.' : 'No expiry date.');

        $limit    = (int) $coupon->get_usage_limit();
        $used     = (int) $coupon->get_usage_count();
        $checks[] = ($limit > 0 && $used >= $limit)
            ? self::check('usage_limit', 'fail', "Used {$used} of {$limit} times.")
            : self::check('usage_limit', 'pass', $limit > 0 ? "Used {$used} of {$limit} times." : "Used {$used} times, no limit.");

        $checks[] = $this->per_customer_check($coupon, $email);
        $checks[] = $this->email_check($coupon, $email);
        $checks[] = $this->spend_check($coupon, $subtotal);

        if (
            $coupon->get_product_ids() || $coupon->get_excluded_product_ids()
            || $coupon->get_product_categories() || $coupon->get_excluded_product_categories()
            || $coupon->get_exclude_sale_items()
        ) {
            $checks[] = self::check('cart_restrictions', 'cart', 'Product, category or sale-item rules apply; they are checked against the cart at checkout.');
        }

        $valid = true;
        foreach ($checks as $check) {
            if ('fail' === $check['status']) {
                $valid = false;
            }
        }

        return [
            'code'   => $coupon->get_code(),
            'id'     => $id,
            'valid'  => $valid,
            'checks' => $checks,
        ];
    }

    private function per_customer_check(\WC_Coupon $coupon, ?string $email): array
    {
        $limit = (int) $coupon->get_usage_limit_per_user();
        if ($limit <= 0) {
            return self::check('usage_limit_per_user', 'pass', 'No per-customer limit.');
        }
        if (null === $email) {
            return self::check('usage_limit_per_user', 'skipped', "Limited to {$limit} use(s) per customer; pass email to check one customer.");
        }
        $store = $coupon->get_data_store();
        $used  = is_callable([$store, 'get_usage_by_email']) ? (int) $store->get_usage_by_email($coupon, $email) : 0;
        return $used >= $limit
            ? self::check('usage_limit_per_user', 'fail', "{$email} has used it {$used} of {$limit} time(s).")
            : self::check('usage_limit_per_user', 'pass', "{$email} has used it {$used} of {$limit} time(s).");
    }

    private function email_check(\WC_Coupon $coupon, ?string $email): array
    {
        $allowed = array_map('strtolower', $coupon->get_email_restrictions());
        if ([] === $allowed) {
            return self::check('email_restrictions', 'pass', 'Any customer may use it.');
        }
        if (null === $email) {
            return self::check('email_restrictions', 'skipped', 'Restricted to specific emails; pass email to check one customer.');
        }
        foreach ($allowed as $pattern) {
            // WooCommerce allows a "*" wildcard, e.g. "*@example.com".
            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
            if (1 === preg_match($regex, $email)) {
                return self::check('email_restrictions', 'pass', "{$email} is on the allow-list.");
            }
        }
        return self::check('email_restrictions', 'fail', "{$email} is not on the coupon's allow-list.");
    }

    private function spend_check(\WC_Coupon $coupon, ?float $subtotal): array
    {
        // An unset limit reads back as '' or '0' depending on how it was saved.
        $min = (float) $coupon->get_minimum_amount() > 0 ? (string) $coupon->get_minimum_amount() : '';
        $max = (float) $coupon->get_maximum_amount() > 0 ? (string) $coupon->get_maximum_amount() : '';
        if ('' === $min && '' === $max) {
            return self::check('spend', 'pass', 'No minimum or maximum spend.');
        }
        $rule = trim(('' !== $min ? "minimum {$min} " : '') . ('' !== $max ? "maximum {$max}" : ''));
        if (null === $subtotal) {
            return self::check('spend', 'skipped', "Spend rule ({$rule}); pass subtotal to check an order amount.");
        }
        if (('' !== $min && $subtotal < (float) $min) || ('' !== $max && $subtotal > (float) $max)) {
            return self::check('spend', 'fail', "A subtotal of {$subtotal} is outside the spend rule ({$rule}).");
        }
        return self::check('spend', 'pass', "A subtotal of {$subtotal} meets the spend rule ({$rule}).");
    }

    /** @return array{check: string, status: string, message: string} */
    private static function check(string $name, string $status, string $message): array
    {
        return ['check' => $name, 'status' => $status, 'message' => $message];
    }
}
