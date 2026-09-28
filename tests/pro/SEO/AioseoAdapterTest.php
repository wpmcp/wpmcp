<?php

namespace WPMCP\Tests\Pro\SEO;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\SEO\Aioseo_Store;
use WPMCP\Tools\SEO\Get_SEO_Status;
use WPMCP\Tools\SEO\SEO_Adapter;
use WPMCP\Tools\SEO\Set_Social_Image;
use WPMCP\Tools\SEO\Social_Meta;
use WPMCP\Tools\SEO\Term_SEO;
use WPMCP\Tools\SEO\Update_SEO_Meta;
use WPMCP\Tools\SEO\Update_Term_SEO_Meta;

require_once __DIR__ . '/../../support/aioseo-tables.php';

/**
 * All in One SEO support (issue #294). AIOSEO keeps each post's and term's
 * SEO fields in a row of its own `aioseo_posts` / `aioseo_terms` table, so
 * every write snapshots that row ('aioseo_row') and rollback restores it
 * exactly. The tables are created here from the plugin's schema; the plugin
 * itself is not installed in the test core, and presence is the
 * `wpmcp_seo_aioseo_active` filter.
 */
class AioseoAdapterTest extends \WP_UnitTestCase
{
    private const INPUT = [
        'title'         => 'AIOSEO title',
        'description'   => 'AIOSEO description',
        'focus_keyword' => 'aioseo keyword',
        'canonical'     => 'https://example.com/aioseo/',
        'noindex'       => true,
        'nofollow'      => true,
    ];

    private const URL = 'https://example.com/aioseo-share.png';

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_aioseo_tables();
        Snapshot_Store::install();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_aioseo_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        SEO_Adapter::set_active_plugin_for_tests('aioseo');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        SEO_Adapter::set_active_plugin_for_tests(null);
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_seo_aioseo_active');
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function term(): \WP_Term
    {
        return get_term(self::factory()->category->create(['name' => 'T ' . wp_generate_password(6, false)]), 'category');
    }

    public function test_post_fields_round_trip_through_the_row(): void
    {
        $post_id = self::factory()->post->create();

        SEO_Adapter::update_meta($post_id, self::INPUT);

        $this->assertSame(self::INPUT, SEO_Adapter::get_meta($post_id));

        $rows = wpmcp_test_aioseo_rows('post', $post_id);
        $this->assertCount(1, $rows);
        $this->assertSame('AIOSEO title', $rows[0]['title']);
        $this->assertSame('AIOSEO description', $rows[0]['description']);
        $this->assertSame('https://example.com/aioseo/', $rows[0]['canonical_url']);
        $this->assertSame('aioseo keyword', $rows[0]['focus_keyword']);
        $this->assertSame('aioseo keyword', json_decode($rows[0]['keyphrases'], true)['focus']['keyphrase']);
        $this->assertSame('0', $rows[0]['robots_default'], 'a robots override needs robots_default off');
        $this->assertSame('1', $rows[0]['robots_noindex']);
        $this->assertSame('1', $rows[0]['robots_nofollow']);
    }

    public function test_partial_write_updates_the_one_row_in_place(): void
    {
        $post_id = self::factory()->post->create();
        SEO_Adapter::update_meta($post_id, self::INPUT);
        $id = wpmcp_test_aioseo_rows('post', $post_id)[0]['id'];

        SEO_Adapter::update_meta($post_id, ['noindex' => false]);

        $rows = wpmcp_test_aioseo_rows('post', $post_id);
        $this->assertCount(1, $rows);
        $this->assertSame($id, $rows[0]['id']);
        $out = SEO_Adapter::get_meta($post_id);
        $this->assertFalse($out['noindex']);
        $this->assertTrue($out['nofollow']);
        $this->assertSame('AIOSEO title', $out['title']);
    }

    /** Robots flags on a row that still follows the global defaults are not in effect. */
    public function test_flags_under_robots_default_read_false(): void
    {
        global $wpdb;
        $post_id = self::factory()->post->create();
        $wpdb->insert($wpdb->prefix . 'aioseo_posts', [
            'post_id' => $post_id, 'robots_default' => 1, 'robots_noindex' => 1,
            'created' => '2026-01-01 00:00:00', 'updated' => '2026-01-01 00:00:00',
        ]);

        $this->assertFalse(SEO_Adapter::get_meta($post_id)['noindex']);
    }

    public function test_legacy_keyphrases_column_is_read_when_the_focus_column_is_empty(): void
    {
        global $wpdb;
        $post_id = self::factory()->post->create();
        $wpdb->insert($wpdb->prefix . 'aioseo_posts', [
            'post_id'    => $post_id,
            'keyphrases' => wp_json_encode(['focus' => ['keyphrase' => 'legacy kw'], 'additional' => []]),
            'created'    => '2026-01-01 00:00:00',
            'updated'    => '2026-01-01 00:00:00',
        ]);

        $this->assertSame('legacy kw', SEO_Adapter::get_meta($post_id)['focus_keyword']);
    }

    public function test_update_seo_meta_snapshots_the_row_and_rolls_back_exactly(): void
    {
        $post_id = self::factory()->post->create();
        SEO_Adapter::update_meta($post_id, ['title' => 'Before', 'description' => 'Kept']);
        $before = wpmcp_test_aioseo_rows('post', $post_id);

        $out = (new Update_SEO_Meta())->handle(['post_id' => $post_id, 'title' => 'After', 'noindex' => true]);

        $this->assertSame('After', $out['title']);
        $this->assertTrue($out['noindex']);
        $snapshot = Snapshot_Store::get_by_operation($out['operation_id'])['snapshot'];
        $this->assertSame('aioseo_row', $snapshot['object_type']);
        $this->assertSame('post:' . $post_id, $snapshot['object_id']);

        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored']);
        $this->assertSame($before, wpmcp_test_aioseo_rows('post', $post_id));
    }

    public function test_rollback_of_a_first_write_removes_the_row(): void
    {
        $post_id = self::factory()->post->create();

        $out = (new Update_SEO_Meta())->handle(['post_id' => $post_id, 'title' => 'First']);
        $this->assertCount(1, wpmcp_test_aioseo_rows('post', $post_id));

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertSame([], wpmcp_test_aioseo_rows('post', $post_id));
    }

    public function test_term_fields_round_trip_and_roll_back(): void
    {
        $term = $this->term();
        Term_SEO::update($term, ['title' => 'Term before']);
        $before = wpmcp_test_aioseo_rows('term', (int) $term->term_id);

        $read = Term_SEO::get($term);
        $this->assertTrue($read['supported']);
        $this->assertSame('aioseo', $read['plugin']);
        $this->assertSame(['focus_keyword'], $read['unsupported_fields']);

        $out = (new Update_Term_SEO_Meta())->handle(array_merge(
            ['taxonomy' => 'category', 'term_id' => $term->term_id],
            self::INPUT
        ));

        $this->assertSame(['focus_keyword'], $out['skipped_fields']);
        $this->assertSame('AIOSEO title', $out['fields']['title']);
        $this->assertSame('https://example.com/aioseo/', $out['fields']['canonical']);
        $this->assertTrue($out['fields']['noindex']);
        $this->assertTrue($out['fields']['nofollow']);
        $this->assertSame('aioseo_row', Snapshot_Store::get_by_operation($out['operation_id'])['snapshot']['object_type']);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertSame($before, wpmcp_test_aioseo_rows('term', (int) $term->term_id));
        $this->assertSame('Term before', Term_SEO::get($term)['fields']['title']);
    }

    public function test_social_fields_read_from_the_row(): void
    {
        global $wpdb;
        $post_id = self::factory()->post->create();
        $wpdb->insert($wpdb->prefix . 'aioseo_posts', [
            'post_id'                  => $post_id,
            'og_title'                 => 'OG title',
            'og_description'           => 'OG copy',
            'og_image_type'            => 'custom',
            'og_image_custom_url'      => self::URL,
            'twitter_use_og'           => 0,
            'twitter_title'            => 'Tweet title',
            'twitter_image_type'       => 'featured',
            'twitter_image_custom_url' => 'https://example.com/unused.png',
            'created'                  => '2026-01-01 00:00:00',
            'updated'                  => '2026-01-01 00:00:00',
        ]);

        $out = Social_Meta::get($post_id);

        $this->assertTrue($out['supported']);
        $this->assertSame('aioseo', $out['plugin']);
        $this->assertSame('OG title', $out['fields']['og_title']);
        $this->assertSame(self::URL, $out['fields']['og_image']);
        $this->assertSame('Tweet title', $out['fields']['twitter_title']);
        $this->assertSame('override', $out['sources']['twitter_title']);
        $this->assertSame('', $out['fields']['twitter_image'], 'a custom URL is only in effect under the custom image type');
        $this->assertSame('absent', $out['sources']['twitter_description']);
    }

    public function test_twitter_use_og_reports_the_twitter_card_as_inherited(): void
    {
        global $wpdb;
        $post_id = self::factory()->post->create();
        $wpdb->insert($wpdb->prefix . 'aioseo_posts', [
            'post_id'        => $post_id,
            'og_title'       => 'Shared',
            'twitter_use_og' => 1,
            'twitter_title'  => 'Ignored while mirroring',
            'created'        => '2026-01-01 00:00:00',
            'updated'        => '2026-01-01 00:00:00',
        ]);

        $out = Social_Meta::get($post_id);

        $this->assertSame('Shared', $out['fields']['twitter_title']);
        $this->assertSame('inherited', $out['sources']['twitter_title']);
    }

    public function test_set_social_image_writes_custom_images_and_rolls_back(): void
    {
        $post_id = self::factory()->post->create();
        SEO_Adapter::update_meta($post_id, ['title' => 'Row exists']);
        $before = wpmcp_test_aioseo_rows('post', $post_id);

        $out = (new Set_Social_Image())->handle(['post_id' => $post_id, 'image_url' => self::URL]);

        $this->assertTrue($out['supported']);
        $this->assertSame(self::URL, $out['fields']['og_image']);
        $this->assertSame(self::URL, $out['fields']['twitter_image']);
        $row = wpmcp_test_aioseo_rows('post', $post_id)[0];
        $this->assertSame('custom', $row['og_image_type']);
        $this->assertSame(self::URL, $row['og_image_custom_url']);
        $this->assertSame('custom', $row['twitter_image_type']);
        $this->assertSame(self::URL, $row['twitter_image_custom_url']);
        $this->assertSame('aioseo_row', Snapshot_Store::get_by_operation($out['operation_id'])['snapshot']['object_type']);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertSame($before, wpmcp_test_aioseo_rows('post', $post_id));
    }

    /** A twitter-only image is invisible while the card mirrors OpenGraph, so the mirror goes off and the copy is kept. */
    public function test_twitter_only_image_switches_off_the_og_mirror_and_keeps_copy(): void
    {
        global $wpdb;
        $post_id = self::factory()->post->create();
        $wpdb->insert($wpdb->prefix . 'aioseo_posts', [
            'post_id'        => $post_id,
            'og_title'       => 'Shared title',
            'og_description' => 'Shared copy',
            'twitter_use_og' => 1,
            'created'        => '2026-01-01 00:00:00',
            'updated'        => '2026-01-01 00:00:00',
        ]);

        $out = (new Set_Social_Image())->handle(['post_id' => $post_id, 'image_url' => self::URL, 'target' => 'twitter']);

        $this->assertSame('0', wpmcp_test_aioseo_rows('post', $post_id)[0]['twitter_use_og']);
        $this->assertSame('Shared title', $out['fields']['twitter_title']);
        $this->assertSame('override', $out['sources']['twitter_title']);
        $this->assertSame('Shared copy', $out['fields']['twitter_description']);
        $this->assertSame(self::URL, $out['fields']['twitter_image']);
        $this->assertSame('', $out['fields']['og_image']);
    }

    /** The same payload reads back as it does on the post-meta plugins. */
    public function test_conforms_to_the_post_meta_adapters(): void
    {
        $post_id = self::factory()->post->create();
        SEO_Adapter::update_meta($post_id, self::INPUT);
        $aioseo = SEO_Adapter::get_meta($post_id);

        SEO_Adapter::set_active_plugin_for_tests('rankmath');
        $other = self::factory()->post->create();
        SEO_Adapter::update_meta($other, self::INPUT);

        $this->assertSame(SEO_Adapter::get_meta($other), $aioseo);
    }

    public function test_plugin_info_and_presence_filter(): void
    {
        $info = SEO_Adapter::plugin_info();
        $this->assertSame('aioseo', $info['plugin']);
        $this->assertSame('All in One SEO', $info['name']);

        add_filter('wpmcp_seo_aioseo_active', '__return_true');
        $this->assertTrue(Aioseo_Store::present());
        remove_all_filters('wpmcp_seo_aioseo_active');
        add_filter('wpmcp_seo_aioseo_active', '__return_false');
        $this->assertFalse(Aioseo_Store::present());
        $this->assertNotSame('aioseo', SEO_Adapter::detect_active_plugin());
    }

    public function test_detected_when_present_and_nothing_ranks_above_it(): void
    {
        SEO_Adapter::set_active_plugin_for_tests(null);
        add_filter('wpmcp_seo_aioseo_active', '__return_false');
        $without = SEO_Adapter::detect_active_plugin();
        if ('' !== $without && 'slimseo' !== $without) {
            $this->markTestSkipped("A higher-precedence SEO plugin ({$without}) is loaded in this environment.");
        }

        remove_all_filters('wpmcp_seo_aioseo_active');
        add_filter('wpmcp_seo_aioseo_active', '__return_true');
        $this->assertSame('aioseo', SEO_Adapter::detect_active_plugin());
        $this->assertSame('All in One SEO', (new Get_SEO_Status())->handle([])['name']);
    }
}
