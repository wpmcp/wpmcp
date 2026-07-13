<?php

namespace WPMCP\Tests\Free\Multisite;

use WPMCP\Tools\Multisite\Get_Site_Details;

/**
 * The genuine get_site()/get_blog_details() round-trip is production-only
 * (this harness is single-site; see Multisite_Adapter's class docblock).
 * What is testable here: missing blog_id throws (mirrors Delete_Identity's
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
        $out = (new Get_Site_Details())->handle(['blog_id' => 1]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('wpmcp_not_multisite', $out->get_error_code());
    }

    public function test_throws_on_a_non_numeric_blog_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Get_Site_Details())->handle(['blog_id' => 'not-a-number']);
    }
}
