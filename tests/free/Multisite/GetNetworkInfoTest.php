<?php

namespace WPMCP\Tests\Free\Multisite;

use WPMCP\Tools\Multisite\Get_Network_Info;

/**
 * This harness boots WordPress single-site, so is_multisite() is always
 * false here and the genuine get_network()/get_blog_count() round-trip is
 * production-only (see Multisite_Adapter's class docblock for the full
 * rationale). What this test covers is the honest-failure path: called
 * outside a network, the tool must return a clear WP_Error, never a fatal.
 */
class GetNetworkInfoTest extends \WP_UnitTestCase
{
    public function test_returns_a_wp_error_when_not_on_a_network(): void
    {
        $out = (new Get_Network_Info())->handle([]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('wpmcp_not_multisite', $out->get_error_code());
    }
}
