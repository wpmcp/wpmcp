<?php

namespace WPMCP\Tests\Free\Multisite;

use WPMCP\Tools\Multisite\List_Network_Sites;

/**
 * The genuine get_sites() round-trip is production-only (this harness is
 * single-site; see Multisite_Adapter's class docblock). What is testable
 * here is the honest-failure path (WP_Error outside a network) and argument
 * validation (bad limit/offset are clamped, not fatal).
 */
class ListNetworkSitesTest extends \WP_UnitTestCase
{
    public function test_returns_a_wp_error_when_not_on_a_network(): void
    {
        $out = (new List_Network_Sites())->handle([]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('wpmcp_not_multisite', $out->get_error_code());
    }

    public function test_returns_a_wp_error_even_with_bad_limit_and_offset_arguments(): void
    {
        $out = (new List_Network_Sites())->handle(['limit' => -5, 'offset' => -10]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('wpmcp_not_multisite', $out->get_error_code());
    }

    public function test_returns_a_wp_error_with_a_non_numeric_limit(): void
    {
        $out = (new List_Network_Sites())->handle(['limit' => 'not-a-number']);

        $this->assertInstanceOf(\WP_Error::class, $out);
    }
}
