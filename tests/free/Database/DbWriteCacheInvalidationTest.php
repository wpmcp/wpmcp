<?php

namespace WPMCP\Tests\Free\Database;

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
    protected function setUp(): void
    {
        parent::setUp();
        add_filter('wpmcp_enable_db_writes', '__return_true');
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
}
