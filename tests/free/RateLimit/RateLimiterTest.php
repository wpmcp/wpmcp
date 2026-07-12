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
}
