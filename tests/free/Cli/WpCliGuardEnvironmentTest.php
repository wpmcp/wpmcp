<?php

namespace WPMCP\Tests\Free\Cli;

use WPMCP\Tools\Cli\Wp_Cli_Guard;

/**
 * Even when wp-cli execution is enabled (Wp_Cli_Guard::is_enabled()), a
 * production environment refuses to run any command unless a SEPARATE,
 * explicit WPMCP_ALLOW_WP_CLI_ON_PRODUCTION override is also set. Dev,
 * staging, and local (and the "unknown" fallback when
 * wp_get_environment_type() is unavailable, treated as non-production) are
 * allowed by default. This is deliberately independent of is_enabled(): an
 * integrator flips two separate switches to run wp-cli in production.
 *
 * wp_get_environment_type() cannot be swapped per-test without WP core
 * support for it, so this test drives the guard via the injectable-clock-style
 * seam Wp_Cli_Guard exposes for tests instead of the WP filter, matching how
 * Database_Guard::set_no_backslash_escapes_override() avoids depending on a
 * live server state.
 */
class WpCliGuardEnvironmentTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        Wp_Cli_Guard::set_environment_override(null);
        parent::tearDown();
    }

    public function test_refuses_production_by_default(): void
    {
        Wp_Cli_Guard::set_environment_override('production');

        $this->assertFalse(Wp_Cli_Guard::is_allowed_on_environment());
    }

    public function test_allows_production_with_explicit_override_constant_style(): void
    {
        Wp_Cli_Guard::set_environment_override('production');
        add_filter('wpmcp_allow_wp_cli_on_production', '__return_true');

        $this->assertTrue(Wp_Cli_Guard::is_allowed_on_environment());

        remove_all_filters('wpmcp_allow_wp_cli_on_production');
    }

    public function test_allows_development(): void
    {
        Wp_Cli_Guard::set_environment_override('development');
        $this->assertTrue(Wp_Cli_Guard::is_allowed_on_environment());
    }

    public function test_allows_staging(): void
    {
        Wp_Cli_Guard::set_environment_override('staging');
        $this->assertTrue(Wp_Cli_Guard::is_allowed_on_environment());
    }

    public function test_allows_local(): void
    {
        Wp_Cli_Guard::set_environment_override('local');
        $this->assertTrue(Wp_Cli_Guard::is_allowed_on_environment());
    }
}
