<?php

namespace WPMCP\Tests\Free\Database;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Database\Delete_Rows;
use WPMCP\Tools\Database\Insert_Row;
use WPMCP\Tools\Database\Update_Rows;

/**
 * The raw row-write tools bypass every core write API, so nothing invalidates
 * WordPress's object cache for them. Only wp_users/wp_usermeta are protected,
 * which leaves wp_options, wp_posts and wp_postmeta writable through these
 * tools; a write to any of them leaves get_option()/get_post()/get_post_meta()
 * serving the pre-write value for the rest of the request (and for the life of
 * the entry under a persistent object cache).
 *
 * These tests prime the relevant cache first, then assert the reader agrees
 * with the database after the tool has written.
 */
class DbWriteCacheInvalidationTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        Snapshot_Store::install();
    }

    protected function setUp(): void
    {
        parent::setUp();
        add_filter('wpmcp_enable_db_writes', '__return_true');
        // db_rows restores are manage_options-gated.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function test_update_rows_invalidates_the_options_cache(): void
    {
        global $wpdb;

        update_option('wpmcp_cache_probe', 'before');
        $this->assertSame('before', get_option('wpmcp_cache_probe'));

        (new Update_Rows())->handle([
            'table'   => $wpdb->options,
            'data'    => ['option_value' => 'after'],
            'where'   => ['option_name' => 'wpmcp_cache_probe'],
            'confirm' => true,
        ]);

        $this->assertSame(
            'after',
            get_option('wpmcp_cache_probe'),
            'get_option() still serves the pre-write value: the tool did not invalidate the options cache.'
        );
    }

    public function test_update_rows_invalidates_the_post_cache(): void
    {
        global $wpdb;

        $post_id = self::factory()->post->create(['post_title' => 'before']);
        $this->assertSame('before', get_post($post_id)->post_title);

        (new Update_Rows())->handle([
            'table'   => $wpdb->posts,
            'data'    => ['post_title' => 'after'],
            'where'   => ['ID' => $post_id],
            'confirm' => true,
        ]);

        $this->assertSame(
            'after',
            get_post($post_id)->post_title,
            'get_post() still serves the pre-write row: the tool did not invalidate the post cache.'
        );
    }

    public function test_insert_row_invalidates_the_post_meta_cache(): void
    {
        global $wpdb;

        $post_id = self::factory()->post->create();
        $this->assertSame('', get_post_meta($post_id, 'wpmcp_probe', true));

        (new Insert_Row())->handle([
            'table'   => $wpdb->postmeta,
            'data'    => [
                'post_id'    => $post_id,
                'meta_key'   => 'wpmcp_probe',
                'meta_value' => 'inserted',
            ],
            'confirm' => true,
        ]);

        $this->assertSame(
            'inserted',
            get_post_meta($post_id, 'wpmcp_probe', true),
            'get_post_meta() still serves the pre-write meta: the tool did not invalidate the meta cache.'
        );
    }

    public function test_delete_rows_invalidates_the_options_cache(): void
    {
        global $wpdb;

        update_option('wpmcp_cache_probe_delete', 'present');
        $this->assertSame('present', get_option('wpmcp_cache_probe_delete'));

        (new Delete_Rows())->handle([
            'table'   => $wpdb->options,
            'where'   => ['option_name' => 'wpmcp_cache_probe_delete'],
            'confirm' => true,
        ]);

        $this->assertFalse(
            get_option('wpmcp_cache_probe_delete'),
            'get_option() still serves the deleted row: the tool did not invalidate the options cache.'
        );
    }

    /**
     * The undo path is as raw a write as the operation it undoes, so it has
     * to invalidate too: otherwise the Safety layer reports the restore as
     * successful while get_option() keeps serving the value it overwrote.
     */
    public function test_rollback_of_update_rows_invalidates_the_options_cache(): void
    {
        global $wpdb;

        update_option('wpmcp_cache_probe_rollback', 'before');

        $result = (new Update_Rows())->handle([
            'table'   => $wpdb->options,
            'data'    => ['option_value' => 'after'],
            'where'   => ['option_name' => 'wpmcp_cache_probe_rollback'],
            'confirm' => true,
        ]);
        $this->assertNotEmpty($result['operation_id'] ?? null);
        $this->assertSame('after', get_option('wpmcp_cache_probe_rollback'));

        $this->assertTrue(Rollback_Service::restore_operation($result['operation_id']));

        $this->assertSame(
            'before',
            get_option('wpmcp_cache_probe_rollback'),
            'get_option() still serves the value the rollback overwrote: the restore did not invalidate the options cache.'
        );
    }

    /**
     * clean_term_cache() with no taxonomy treats its ids as
     * term_taxonomy_ids. Those only coincide with term_ids on a site that
     * has never shared or deleted a term, so the fixture forces the two
     * sequences apart before writing to wp_terms.
     */
    public function test_update_rows_invalidates_the_term_cache_when_term_id_and_term_taxonomy_id_differ(): void
    {
        global $wpdb;

        $first = self::factory()->term->create(['taxonomy' => 'category']);
        $wpdb->insert($wpdb->term_taxonomy, [
            'term_id'     => $first,
            'taxonomy'    => 'post_tag',
            'description' => '',
            'parent'      => 0,
            'count'       => 0,
        ]);

        $term_id = self::factory()->term->create(['taxonomy' => 'category', 'name' => 'before']);
        $term    = get_term($term_id, 'category');
        $this->assertNotSame($term_id, (int) $term->term_taxonomy_id, 'Fixture must produce a term whose two ids differ.');
        $this->assertSame('before', $term->name);

        (new Update_Rows())->handle([
            'table'   => $wpdb->terms,
            'data'    => ['name' => 'after'],
            'where'   => ['term_id' => $term_id],
            'confirm' => true,
        ]);

        $this->assertSame(
            'after',
            get_term($term_id, 'category')->name,
            'get_term() still serves the pre-write row: the tool cleared the wrong term (term_id passed where a term_taxonomy_id was expected).'
        );
    }

    /**
     * clean_post_cache() starts with get_post() and returns early once the
     * row is gone, so on the delete path it clears nothing unless the post
     * object happened to be cached. The posts 'last_changed' key, which
     * every WP_Query result cache hangs off, must move regardless.
     */
    public function test_delete_rows_on_posts_bumps_last_changed_for_an_uncached_post(): void
    {
        global $wpdb;

        $post_id = self::factory()->post->create();
        wp_cache_delete($post_id, 'posts');
        $before = wp_cache_get_last_changed('posts');

        (new Delete_Rows())->handle([
            'table'   => $wpdb->posts,
            'where'   => ['ID' => $post_id],
            'confirm' => true,
        ]);

        $this->assertNull(get_post($post_id));
        $this->assertNotSame(
            $before,
            wp_cache_get_last_changed('posts'),
            'posts last_changed did not move: WP_Query result caches would keep listing the deleted post.'
        );
    }

    /**
     * An inserted post is not in the data the caller supplied by id, so the
     * tool has to hand the new auto-increment id to the invalidation; a
     * cached WP_Query result set must see the new row afterwards.
     */
    public function test_insert_row_into_posts_is_visible_to_a_cached_query(): void
    {
        global $wpdb;

        $args = [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ];
        $existing = self::factory()->post->create();
        $this->assertContains($existing, (new \WP_Query($args))->posts);

        $now    = current_time('mysql');
        $result = (new Insert_Row())->handle([
            'table'   => $wpdb->posts,
            'data'    => [
                'post_title'            => 'inserted',
                'post_status'           => 'publish',
                'post_type'             => 'post',
                'post_date'             => $now,
                'post_date_gmt'         => $now,
                'post_modified'         => $now,
                'post_modified_gmt'     => $now,
                'post_content'          => '',
                'post_excerpt'          => '',
                'to_ping'               => '',
                'pinged'                => '',
                'post_content_filtered' => '',
            ],
            'confirm' => true,
        ]);
        $this->assertGreaterThan(0, $result['insert_id']);

        $this->assertContains(
            $result['insert_id'],
            (new \WP_Query($args))->posts,
            'WP_Query still serves the pre-insert result set: the tool did not invalidate the posts query cache.'
        );
    }
}
