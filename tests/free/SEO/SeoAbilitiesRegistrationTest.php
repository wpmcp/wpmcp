<?php

namespace WPMCP\Tests\Free\SEO;

/**
 * Verifies the SEO abilities are registered as free-tier abilities.
 * get-seo-status is registered unconditionally (it must be able to report
 * "no SEO plugin active"); get-seo-meta and update-seo-meta are registered
 * only when an SEO plugin is active, following the conditional-registration
 * pattern used for the ACF tools. Plugin::boot() registers abilities once at
 * wp_abilities_api_init against the plugin activation state already decided
 * by the test bootstrap, so this asserts directly against the live
 * wp_get_abilities() registry.
 */
class SeoAbilitiesRegistrationTest extends \WP_UnitTestCase
{
    public function test_get_seo_status_is_always_registered(): void
    {
        $names = array_keys(wp_get_abilities());

        $this->assertContains('wpmcp/get-seo-status', $names);
    }

    public function test_meta_tools_are_registered_when_an_seo_plugin_is_active(): void
    {
        if ('' === wpmcp_seo_plugin()) {
            $this->markTestSkipped('No SEO plugin active');
        }

        $names = array_keys(wp_get_abilities());

        $this->assertContains('wpmcp/get-seo-meta', $names);
        $this->assertContains('wpmcp/update-seo-meta', $names);
    }

    public function test_meta_tools_are_not_registered_when_no_seo_plugin_is_active(): void
    {
        if ('' !== wpmcp_seo_plugin()) {
            $this->markTestSkipped('An SEO plugin is active in this test environment.');
        }

        $names = array_keys(wp_get_abilities());

        $this->assertNotContains('wpmcp/get-seo-meta', $names);
        $this->assertNotContains('wpmcp/update-seo-meta', $names);
    }

    public function test_seo_abilities_have_description_and_category(): void
    {
        $abilities = wp_get_abilities();

        $names = ['wpmcp/get-seo-status'];
        if ('' !== wpmcp_seo_plugin()) {
            $names[] = 'wpmcp/get-seo-meta';
            $names[] = 'wpmcp/update-seo-meta';
        }

        foreach ($names as $name) {
            $ability = $abilities[$name];
            $this->assertNotEmpty($ability->get_description(), "Expected {$name} to have a description");
            $this->assertSame('wpmcp', $ability->get_category());
        }
    }
}
