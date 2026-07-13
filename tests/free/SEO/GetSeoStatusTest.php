<?php

namespace WPMCP\Tests\Free\SEO;

use WPMCP\Tools\SEO\Get_SEO_Status;

class GetSeoStatusTest extends \WP_UnitTestCase
{
    public function test_reports_the_active_plugin_name_and_version(): void
    {
        if ('' === wpmcp_seo_plugin()) {
            $this->markTestSkipped('No SEO plugin active');
        }

        $out = (new Get_SEO_Status())->handle([]);

        $this->assertTrue($out['active']);
        $this->assertSame(wpmcp_seo_plugin(), $out['plugin']);
        $this->assertNotEmpty($out['name']);
    }

    public function test_reports_inactive_when_no_seo_plugin_is_present(): void
    {
        if ('' !== wpmcp_seo_plugin()) {
            $this->markTestSkipped('An SEO plugin is active in this test environment.');
        }

        $out = (new Get_SEO_Status())->handle([]);

        $this->assertFalse($out['active']);
        $this->assertSame('', $out['plugin']);
    }
}
