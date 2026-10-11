<?php

namespace WPMCP\Tests\Free\Multisite;

use WPMCP\Tools\Multisite\Get_Site_Details;

/**
 * The genuine get_site()/get_blog_details() round-trip runs in the
 * multisite leg (bin/test-local.sh --multisite, issue #530). Everywhere:
 * missing blog_id throws (mirrors Delete_Identity's
 * "entirely missing input" handling); a well-formed blog_id still returns a
 * WP_Error outside a network (honest failure, not a fatal).
 */
class GetSiteDetailsTest extends \WP_UnitTestCase
{
    public function test_throws_on_a_missing_blog_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Get_Site_Details())->handle([]);
    }

    public function test_returns_a_wp_error_when_not_on_a_network(): void
    {
        if (is_multisite()) {
            $this->markTestSkipped('Single-site by nature: asserts the answer outside a network; the multisite leg (issue #530) runs this on one.');
        }
        $out = (new Get_Site_Details())->handle(['blog_id' => 1]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('wpmcp_not_multisite', $out->get_error_code());
    }

    public function test_throws_on_a_non_numeric_blog_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Get_Site_Details())->handle(['blog_id' => 'not-a-number']);
    }

    public function test_returns_the_details_of_a_site_on_the_network(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Needs a network: runs in the multisite leg (bin/test-local.sh --multisite, issue #530).');
        }

        $this->setExpectedDeprecated('wpmu_new_blog');
        $blog_id = self::factory()->blog->create(['title' => 'Second Site']);

        $out = (new Get_Site_Details())->handle(['blog_id' => $blog_id]);

        $this->assertIsArray($out);
        $this->assertSame($blog_id, $out['blog_id']);
        $this->assertSame('Second Site', $out['name']);
    }
}
