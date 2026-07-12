<?php

namespace WPMCP\Tests\Free\RateLimit;

use WPMCP\RateLimit\Rate_Limiter;

class RateLimiterTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        Rate_Limiter::set_clock_override(null);
        remove_all_filters('wpmcp_rate_limit');
        remove_all_filters('wpmcp_rate_limit_window');
        remove_all_filters('wpmcp_rate_limit_enabled');
        parent::tearDown();
    }

    public function test_calls_under_the_limit_are_allowed_and_remaining_decrements(): void
    {
        add_filter('wpmcp_rate_limit', fn() => 3);

        $first = Rate_Limiter::check('user:1');
        $this->assertTrue($first['allowed']);
        $this->assertSame(2, $first['remaining']);
        $this->assertSame(0, $first['retry_after']);

        $second = Rate_Limiter::check('user:1');
        $this->assertTrue($second['allowed']);
        $this->assertSame(1, $second['remaining']);

        $third = Rate_Limiter::check('user:1');
        $this->assertTrue($third['allowed']);
        $this->assertSame(0, $third['remaining']);
    }

    public function test_call_over_the_limit_is_denied_with_positive_retry_after(): void
    {
        add_filter('wpmcp_rate_limit', fn() => 2);
        add_filter('wpmcp_rate_limit_window', fn() => 60);
        Rate_Limiter::set_clock_override(fn() => 1000);

        $this->assertTrue(Rate_Limiter::check('user:1')['allowed']);
        $this->assertTrue(Rate_Limiter::check('user:1')['allowed']);

        $denied = Rate_Limiter::check('user:1');
        $this->assertFalse($denied['allowed']);
        $this->assertSame(0, $denied['remaining']);
        $this->assertGreaterThan(0, $denied['retry_after']);
        $this->assertLessThanOrEqual(60, $denied['retry_after']);
    }

    public function test_counter_resets_after_the_window_elapses(): void
    {
        add_filter('wpmcp_rate_limit', fn() => 1);
        add_filter('wpmcp_rate_limit_window', fn() => 60);

        $now = 1000;
        Rate_Limiter::set_clock_override(function () use (&$now) {
            return $now;
        });

        $this->assertTrue(Rate_Limiter::check('user:1')['allowed']);
        $this->assertFalse(Rate_Limiter::check('user:1')['allowed']);

        // Advance past the window boundary into the next bucket.
        $now += 60;

        $reset = Rate_Limiter::check('user:1');
        $this->assertTrue($reset['allowed']);
        $this->assertSame(0, $reset['remaining']);
    }

    public function test_different_clients_have_independent_counters(): void
    {
        add_filter('wpmcp_rate_limit', fn() => 1);
        Rate_Limiter::set_clock_override(fn() => 1000);

        // Exhaust user:1's budget.
        $this->assertTrue(Rate_Limiter::check('user:1')['allowed']);
        $this->assertFalse(Rate_Limiter::check('user:1')['allowed']);

        // user:2 is unaffected and still has a full budget.
        $other = Rate_Limiter::check('user:2');
        $this->assertTrue($other['allowed']);
        $this->assertSame(0, $other['remaining']);
    }

    public function test_no_call_is_denied_when_the_limiter_is_disabled(): void
    {
        add_filter('wpmcp_rate_limit', fn() => 1);
        add_filter('wpmcp_rate_limit_enabled', '__return_false');
        Rate_Limiter::set_clock_override(fn() => 1000);

        // Well past the limit of 1, every call stays allowed.
        for ($i = 0; $i < 5; $i++) {
            $result = Rate_Limiter::check('user:1');
            $this->assertTrue($result['allowed']);
            $this->assertSame(0, $result['retry_after']);
        }
    }
}
