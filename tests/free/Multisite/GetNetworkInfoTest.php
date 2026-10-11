<?php

namespace WPMCP\Tests\Free\Multisite;

use WPMCP\Tools\Multisite\Get_Network_Info;

/**
 * On a single site the tool must return a clear WP_Error, never a fatal.
 * The genuine get_network()/get_blog_count() round-trip runs in the
 * multisite leg (bin/test-local.sh --multisite, issue #530).
 */
class GetNetworkInfoTest extends \WP_UnitTestCase
{
    public function test_returns_a_wp_error_when_not_on_a_network(): void
    {
        if (is_multisite()) {
            $this->markTestSkipped('Single-site by nature: asserts the answer outside a network; the multisite leg (issue #530) runs this on one.');
        }
        $out = (new Get_Network_Info())->handle([]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('wpmcp_not_multisite', $out->get_error_code());
    }

    public function test_reports_the_network_and_counts_its_sites(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Needs a network: runs in the multisite leg (bin/test-local.sh --multisite, issue #530).');
        }

        $this->setExpectedDeprecated('wpmu_new_blog');
        self::factory()->blog->create();
        wp_update_network_site_counts();

        $out = (new Get_Network_Info())->handle([]);

        $this->assertIsArray($out);
        $this->assertSame(get_main_site_id(), $out['main_site_id']);
        $this->assertSame(2, $out['site_count']);
    }
}
