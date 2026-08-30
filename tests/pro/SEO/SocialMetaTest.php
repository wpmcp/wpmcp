<?php

namespace WPMCP\Tests\Pro\SEO;

use WPMCP\Pro\Gate;
use WPMCP\Tools\SEO\Get_Social_Meta;
use WPMCP\Tools\SEO\SEO_Adapter;

/**
 * Extended-vocabulary reads (issue #67): per-post OG/Twitter overrides, and
 * the structured "unsupported" response the issue requires instead of an
 * error when the active plugin has no verified social map.
 */
class SocialMetaTest extends \WP_UnitTestCase
{
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            wp_delete_post($id, true);
        }
        $this->created = [];
        SEO_Adapter::set_active_plugin_for_tests(null);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function post(array $args = []): int
    {
        $id = $this->factory()->post->create($args);
        $this->created[] = $id;
        return $id;
    }

    public function test_yoast_social_fields_round_trip_from_postmeta(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('yoast');
        $id = $this->post();

        update_post_meta($id, '_yoast_wpseo_opengraph-title', 'OG title');
        update_post_meta($id, '_yoast_wpseo_twitter-description', 'Tweet copy');

        $out = SEO_Adapter::get_social_meta($id);

        $this->assertTrue($out['supported']);
        $this->assertSame('yoast', $out['plugin']);
        $this->assertSame('OG title', $out['fields']['og_title']);
        $this->assertSame('Tweet copy', $out['fields']['twitter_description']);
        $this->assertSame('', $out['fields']['og_image']);
    }

    public function test_rankmath_and_seopress_have_verified_maps(): void
    {
        $id = $this->post();

        foreach (['rankmath', 'seopress'] as $plugin) {
            SEO_Adapter::set_active_plugin_for_tests($plugin);
            $out = SEO_Adapter::get_social_meta($id);

            $this->assertTrue($out['supported'], "{$plugin} should be mapped");
            $this->assertArrayHasKey('og_title', $out['fields']);
            $this->assertArrayHasKey('twitter_image', $out['fields']);
        }
    }

    public function test_unmapped_plugin_reports_unsupported_rather_than_throwing(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('seoframework');
        $id = $this->post();

        $out = SEO_Adapter::get_social_meta($id);

        $this->assertFalse($out['supported']);
        $this->assertSame('seoframework', $out['plugin']);
        $this->assertNotEmpty($out['reason']);
    }

    public function test_no_active_plugin_reports_unsupported(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('');
        $id = $this->post();

        $out = SEO_Adapter::get_social_meta($id);

        $this->assertFalse($out['supported']);
        $this->assertSame('', $out['plugin']);
    }

    public function test_tool_wraps_the_adapter_and_reports_the_post_id(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('yoast');
        $id = $this->post();
        update_post_meta($id, '_yoast_wpseo_opengraph-title', 'OG title');

        $out = (new Get_Social_Meta())->handle(['post_id' => $id]);

        $this->assertSame($id, $out['post_id']);
        $this->assertTrue($out['supported']);
        $this->assertSame('OG title', $out['fields']['og_title']);
    }

    public function test_tool_requires_a_post_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Get_Social_Meta())->handle([]);
    }
}
