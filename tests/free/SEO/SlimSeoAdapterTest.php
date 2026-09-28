<?php

namespace WPMCP\Tests\Free\SEO;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\SEO\Get_SEO_Status;
use WPMCP\Tools\SEO\SEO_Adapter;
use WPMCP\Tools\SEO\Update_SEO_Meta;

/**
 * Slim SEO support in the SEO adapter (issue #294). Slim SEO keeps a post's
 * fields in ONE `slim_seo` post meta array (title, description, canonical,
 * facebook_image, twitter_image, noindex stored as 1). It saves that array
 * through array_filter(), so an empty field is absent rather than '', and an
 * all-empty array deletes the meta. It has no focus keyword and no nofollow
 * field. Read against the plugin's own save path (MetaTags/Settings/Base.php).
 */
class SlimSeoAdapterTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        SEO_Adapter::set_active_plugin_for_tests(null);
        remove_all_filters('wpmcp_seo_slim_seo_active');
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_writes_into_the_single_slim_seo_array_and_reads_back(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('slimseo');
        $post_id = self::factory()->post->create();

        SEO_Adapter::update_meta($post_id, [
            'title'         => 'Slim title',
            'description'   => 'Slim description',
            'canonical'     => 'https://example.com/slim/',
            'noindex'       => true,
            'nofollow'      => true,
            'focus_keyword' => 'ignored',
        ]);

        $stored = get_post_meta($post_id, 'slim_seo', true);
        $this->assertSame([
            'title'       => 'Slim title',
            'description' => 'Slim description',
            'canonical'   => 'https://example.com/slim/',
            'noindex'     => 1,
        ], $stored, 'only the keys Slim SEO has, noindex as 1');

        $this->assertSame([
            'title'         => 'Slim title',
            'description'   => 'Slim description',
            'focus_keyword' => '',
            'canonical'     => 'https://example.com/slim/',
            'noindex'       => true,
            'nofollow'      => false,
        ], SEO_Adapter::get_meta($post_id));
    }

    public function test_partial_write_keeps_other_keys_including_the_social_images(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('slimseo');
        $post_id = self::factory()->post->create();
        update_post_meta($post_id, 'slim_seo', ['title' => 'Kept', 'facebook_image' => 'https://example.com/fb.png']);

        SEO_Adapter::update_meta($post_id, ['description' => 'Added']);

        $stored = get_post_meta($post_id, 'slim_seo', true);
        $this->assertSame('Kept', $stored['title']);
        $this->assertSame('Added', $stored['description']);
        $this->assertSame('https://example.com/fb.png', $stored['facebook_image']);
    }

    public function test_cleared_fields_are_removed_like_the_plugin_does(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('slimseo');
        $post_id = self::factory()->post->create();

        SEO_Adapter::update_meta($post_id, ['title' => 'T', 'noindex' => true]);
        SEO_Adapter::update_meta($post_id, ['noindex' => false]);
        $this->assertSame(['title' => 'T'], get_post_meta($post_id, 'slim_seo', true));

        SEO_Adapter::update_meta($post_id, ['title' => '']);
        $this->assertFalse(metadata_exists('post', $post_id, 'slim_seo'), 'an empty array is deleted, not stored');
    }

    public function test_backslashes_survive_the_write(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('slimseo');
        $post_id = self::factory()->post->create();

        SEO_Adapter::update_meta($post_id, ['title' => 'C:\\dir \\ title']);

        $this->assertSame('C:\\dir \\ title', SEO_Adapter::get_meta($post_id)['title']);
    }

    public function test_plugin_info_reports_slim_seo(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('slimseo');

        $info = SEO_Adapter::plugin_info();
        $this->assertSame('slimseo', $info['plugin']);
        $this->assertSame('Slim SEO', $info['name']);
    }

    public function test_presence_follows_the_filter(): void
    {
        add_filter('wpmcp_seo_slim_seo_active', '__return_true');
        $this->assertTrue(SEO_Adapter::slim_seo_present());

        remove_all_filters('wpmcp_seo_slim_seo_active');
        add_filter('wpmcp_seo_slim_seo_active', '__return_false');
        $this->assertFalse(SEO_Adapter::slim_seo_present());
        $this->assertNotSame('slimseo', SEO_Adapter::detect_active_plugin(), 'inactive plugin is never detected');
    }

    /** With no other SEO plugin loaded, a present Slim SEO is the detected one. */
    public function test_detected_when_present_and_nothing_ranks_above_it(): void
    {
        add_filter('wpmcp_seo_slim_seo_active', '__return_false');
        $without = SEO_Adapter::detect_active_plugin();
        if ('' !== $without) {
            $this->markTestSkipped("A higher-precedence SEO plugin ({$without}) is loaded in this environment.");
        }

        remove_all_filters('wpmcp_seo_slim_seo_active');
        add_filter('wpmcp_seo_slim_seo_active', '__return_true');
        $this->assertSame('slimseo', SEO_Adapter::detect_active_plugin());
        $this->assertSame('slimseo', (new Get_SEO_Status())->handle([])['plugin']);
    }

    public function test_tool_write_is_a_post_snapshot_and_rolls_back(): void
    {
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        SEO_Adapter::set_active_plugin_for_tests('slimseo');
        $post_id = self::factory()->post->create();
        update_post_meta($post_id, 'slim_seo', ['title' => 'Before']);

        $out = (new Update_SEO_Meta())->handle(['post_id' => $post_id, 'title' => 'After', 'noindex' => true]);

        $this->assertSame('After', $out['title']);
        $this->assertTrue($out['noindex']);
        $this->assertSame('post', Snapshot_Store::get_by_operation($out['operation_id'])['snapshot']['object_type']);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertSame(['title' => 'Before'], get_post_meta($post_id, 'slim_seo', true));
    }
}
