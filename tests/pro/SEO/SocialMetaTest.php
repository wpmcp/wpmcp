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
        wp_set_current_user(0);
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

    /**
     * Round-tripped through the real postmeta keys rather than only asserting
     * that the neutral keys exist: an array-key assertion passes on a map
     * whose every key string is wrong, which is exactly what a "verified map"
     * claim must not be able to do.
     */
    public function test_rankmath_social_fields_round_trip_from_postmeta(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('rankmath');
        $id = $this->post();

        update_post_meta($id, 'rank_math_facebook_title', 'RM OG title');
        update_post_meta($id, 'rank_math_facebook_description', 'RM OG copy');
        update_post_meta($id, 'rank_math_facebook_image', 'https://example.com/og.png');
        update_post_meta($id, 'rank_math_twitter_title', 'RM tweet title');
        update_post_meta($id, 'rank_math_twitter_description', 'RM tweet copy');
        update_post_meta($id, 'rank_math_twitter_image', 'https://example.com/tw.png');

        $out = SEO_Adapter::get_social_meta($id);

        $this->assertTrue($out['supported']);
        $this->assertSame('rankmath', $out['plugin']);
        $this->assertSame([
            'og_title'            => 'RM OG title',
            'og_description'      => 'RM OG copy',
            'og_image'            => 'https://example.com/og.png',
            'twitter_title'       => 'RM tweet title',
            'twitter_description' => 'RM tweet copy',
            'twitter_image'       => 'https://example.com/tw.png',
        ], $out['fields']);
        $this->assertSame(
            array_fill_keys(array_keys($out['fields']), 'override'),
            $out['sources']
        );
    }

    public function test_seopress_social_fields_round_trip_from_postmeta(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('seopress');
        $id = $this->post();

        update_post_meta($id, '_seopress_social_fb_title', 'SP OG title');
        update_post_meta($id, '_seopress_social_fb_desc', 'SP OG copy');
        update_post_meta($id, '_seopress_social_fb_img', 'https://example.com/og.png');
        update_post_meta($id, '_seopress_social_twitter_title', 'SP tweet title');
        update_post_meta($id, '_seopress_social_twitter_desc', 'SP tweet copy');
        update_post_meta($id, '_seopress_social_twitter_img', 'https://example.com/tw.png');

        $out = SEO_Adapter::get_social_meta($id);

        $this->assertTrue($out['supported']);
        $this->assertSame('seopress', $out['plugin']);
        $this->assertSame([
            'og_title'            => 'SP OG title',
            'og_description'      => 'SP OG copy',
            'og_image'            => 'https://example.com/og.png',
            'twitter_title'       => 'SP tweet title',
            'twitter_description' => 'SP tweet copy',
            'twitter_image'       => 'https://example.com/tw.png',
        ], $out['fields']);
    }

    /**
     * All three mapped plugins render the Twitter card from the OpenGraph
     * fields when the Twitter ones are empty, so reporting the bare postmeta
     * would say `twitter_title: ''` for a post whose card does have a title.
     */
    public function test_empty_twitter_fields_inherit_the_open_graph_values(): void
    {
        foreach (['yoast', 'rankmath', 'seopress'] as $plugin) {
            SEO_Adapter::set_active_plugin_for_tests($plugin);
            $id = $this->post();

            $keys = [
                'yoast'    => '_yoast_wpseo_opengraph-title',
                'rankmath' => 'rank_math_facebook_title',
                'seopress' => '_seopress_social_fb_title',
            ];
            update_post_meta($id, $keys[$plugin], 'Shared OG title');

            $out = SEO_Adapter::get_social_meta($id);

            $this->assertSame(
                'Shared OG title',
                $out['fields']['twitter_title'],
                "{$plugin} mirrors OG onto the Twitter card"
            );
            $this->assertSame('inherited', $out['sources']['twitter_title']);
            $this->assertSame('override', $out['sources']['og_title']);
            $this->assertSame('absent', $out['sources']['og_image']);
            $this->assertSame('absent', $out['sources']['twitter_image']);
        }
    }

    /** An explicit Twitter value is never overwritten by the OG one. */
    public function test_an_explicit_twitter_value_is_reported_as_an_override(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('yoast');
        $id = $this->post();

        update_post_meta($id, '_yoast_wpseo_opengraph-title', 'OG title');
        update_post_meta($id, '_yoast_wpseo_twitter-title', 'Tweet title');

        $out = SEO_Adapter::get_social_meta($id);

        $this->assertSame('Tweet title', $out['fields']['twitter_title']);
        $this->assertSame('override', $out['sources']['twitter_title']);
    }

    /**
     * RankMath makes the mirror a per-post switch stored as 'on'/'off' and
     * defaulting to on, so an absent meta must read as mirroring and only an
     * explicit 'off' suppresses the inheritance.
     */
    public function test_rankmath_use_facebook_off_suppresses_the_inheritance(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('rankmath');
        $id = $this->post();

        update_post_meta($id, 'rank_math_facebook_title', 'OG title');
        update_post_meta($id, 'rank_math_twitter_use_facebook', 'off');

        $out = SEO_Adapter::get_social_meta($id);

        $this->assertSame('', $out['fields']['twitter_title']);
        $this->assertSame('absent', $out['sources']['twitter_title']);
    }

    /** And the stock install, where the flag has never been written. */
    public function test_rankmath_mirrors_by_default_when_the_flag_is_unset(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('rankmath');
        $id = $this->post();

        update_post_meta($id, 'rank_math_facebook_title', 'OG title');

        $out = SEO_Adapter::get_social_meta($id);

        $this->assertSame('OG title', $out['fields']['twitter_title']);
        $this->assertSame('inherited', $out['sources']['twitter_title']);
    }

    /**
     * A published but password-protected post: the social fields are post
     * content, and edit_posts alone does not entitle the caller to it.
     */
    public function test_a_password_protected_post_is_refused_without_edit_post(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('yoast');
        $id = $this->post(['post_password' => 'hunter2']);

        wp_set_current_user($this->factory()->user->create(['role' => 'author']));

        $this->expectException(\RuntimeException::class);
        (new Get_Social_Meta())->handle(['post_id' => $id]);
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
