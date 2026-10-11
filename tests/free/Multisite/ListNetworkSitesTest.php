<?php

namespace WPMCP\Tests\Free\Multisite;

use WPMCP\Tools\Multisite\List_Network_Sites;

/**
 * The genuine get_sites() round-trip runs in the multisite leg
 * (bin/test-local.sh --multisite, issue #530). On a single site: the
 * honest-failure path (WP_Error outside a network) and argument
 * validation (bad limit/offset are clamped, not fatal).
 */
class ListNetworkSitesTest extends \WP_UnitTestCase
{
    public function test_returns_a_wp_error_when_not_on_a_network(): void
    {
        if (is_multisite()) {
            $this->markTestSkipped('Single-site by nature: asserts the answer outside a network; the multisite leg (issue #530) runs this on one.');
        }
        $out = (new List_Network_Sites())->handle([]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('wpmcp_not_multisite', $out->get_error_code());
    }

    public function test_returns_a_wp_error_even_with_bad_limit_and_offset_arguments(): void
    {
        if (is_multisite()) {
            $this->markTestSkipped('Single-site by nature: asserts the answer outside a network; the multisite leg (issue #530) runs this on one.');
        }
        $out = (new List_Network_Sites())->handle(['limit' => -5, 'offset' => -10]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('wpmcp_not_multisite', $out->get_error_code());
    }

    public function test_returns_a_wp_error_with_a_non_numeric_limit(): void
    {
        if (is_multisite()) {
            $this->markTestSkipped('Single-site by nature: asserts the answer outside a network; the multisite leg (issue #530) runs this on one.');
        }
        $out = (new List_Network_Sites())->handle(['limit' => 'not-a-number']);

        $this->assertInstanceOf(\WP_Error::class, $out);
    }

    public function test_lists_the_sites_of_the_network(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Needs a network: runs in the multisite leg (bin/test-local.sh --multisite, issue #530).');
        }

        $this->setExpectedDeprecated('wpmu_new_blog');
        $blog_id = self::factory()->blog->create();

        $out = (new List_Network_Sites())->handle(['limit' => 100]);

        $this->assertIsArray($out);
        $ids = array_column($out['sites'], 'blog_id');
        $this->assertContains(get_main_site_id(), $ids);
        $this->assertContains($blog_id, $ids);
    }
}
