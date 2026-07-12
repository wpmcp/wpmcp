<?php

namespace WPMCP\Pro;

if (! defined('ABSPATH')) {
    exit;
}

class Gate
{
    private static ?bool $test_override = null;

    public static function set_pro_for_tests(?bool $v): void
    {
        self::$test_override = $v;
    }

    public static function is_pro(): bool
    {
        if (null !== self::$test_override) {
            return self::$test_override;
        }
        return function_exists('wpmcp_fs') && wpmcp_fs()->can_use_premium_code__premium_only();
    }

    public static function can_use(string $feature): bool
    {
        return self::is_pro();
    }

    public static function history_limit(): int
    {
        return self::is_pro() ? PHP_INT_MAX : 20;
    }
}
