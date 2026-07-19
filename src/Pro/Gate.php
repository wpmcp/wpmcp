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
        if (! defined('WPMCP_TESTING') || ! WPMCP_TESTING) {
            return;
        }
        self::$test_override = $v;
    }

    /**
     * Live license check (issue #54). Resolves the Freemius instance via
     * Bootstrap::fs() and asks the SDK whether this install may run premium
     * code (active paid license or trial). Uses can_use_premium_code(), NOT
     * the __premium_only variant: pro code ships in this tree gated at
     * runtime, and __premium_only call sites are stripped by Freemius'
     * free-build processor, which would corrupt this method in a free zip
     * (and the variant also requires the is_premium build flag we do not set).
     *
     * Fails closed: no SDK, unexpected instance shape, or a non-strict-true
     * answer all mean free tier.
     */
    public static function is_pro(): bool
    {
        if (null !== self::$test_override) {
            return self::$test_override;
        }

        $fs = \WPMCP\Freemius\Bootstrap::fs();
        if (null === $fs || ! is_callable([$fs, 'can_use_premium_code'])) {
            return false;
        }

        return true === $fs->can_use_premium_code();
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
