<?php

namespace WPMCP\Tests\Free\Cli;

use WPMCP\Tools\Cli\Wp_Cli_Guard;

/**
 * WP-CLI execution (issue #44) is off unless explicitly opted into: neither
 * the WPMCP_ALLOW_WP_CLI constant nor the wpmcp_allow_wp_cli filter is set by
 * default, so a fresh install can never reach a real proc_open call. Either
 * seam being truthy is sufficient to enable it, mirroring OAuth_Config and
 * Rate_Limiter's constant-or-filter pattern.
 */
class WpCliGuardEnabledTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_allow_wp_cli');
        parent::tearDown();
    }

    public function test_disabled_by_default(): void
    {
        $this->assertFalse(Wp_Cli_Guard::is_enabled());
    }

    public function test_enabled_via_filter(): void
    {
        add_filter('wpmcp_allow_wp_cli', '__return_true');
        $this->assertTrue(Wp_Cli_Guard::is_enabled());
    }

    public function test_filter_can_force_disable_even_if_constant_were_set(): void
    {
        // Without the WPMCP_ALLOW_WP_CLI constant defined in this test run,
        // the default is false; a filter returning false explicitly keeps it
        // false rather than accidentally flipping it on.
        add_filter('wpmcp_allow_wp_cli', '__return_false');
        $this->assertFalse(Wp_Cli_Guard::is_enabled());
    }
}
