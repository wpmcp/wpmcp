<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Offload_Media_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;

/**
 * WP Offload Media offload status (issue #701): paid-tier, read-only ops on
 * the plugin-data pair, so they add no top-level tools.
 *
 * WP Offload Media is not loaded in the shared test core. Its items and
 * files tables are created here from the plugin's own definitions (3.4.4,
 * Items\Item::install_table and Items\File::install_table), its settings
 * are the tantan_wordpress_s3 option it reads when no define overrides
 * them, and presence is driven through wpmcp_offload_media_active.
 */
class OffloadMediaPackTest extends \WP_UnitTestCase
{
    private int $admin;

    public static function wpSetUpBeforeClass(): void
    {
        global $wpdb;
        $items = $wpdb->get_blog_prefix() . 'as3cf_items';
        $files = $wpdb->get_blog_prefix() . 'as3cf_files';
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$items} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            provider VARCHAR(18) NOT NULL,
            region VARCHAR(255) NOT NULL,
            bucket VARCHAR(255) NOT NULL,
            path VARCHAR(1024) NOT NULL,
            original_path VARCHAR(1024) NOT NULL,
            is_private BOOLEAN NOT NULL DEFAULT 0,
            source_type VARCHAR(18) NOT NULL,
            source_id BIGINT(20) UNSIGNED NOT NULL,
            source_path VARCHAR(1024) NOT NULL,
            original_source_path VARCHAR(1024) NOT NULL,
            extra_info LONGTEXT,
            originator TINYINT UNSIGNED NOT NULL DEFAULT 0,
            is_verified BOOLEAN NOT NULL DEFAULT 1,
            last_upgrade_routine INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uidx_source (source_type, source_id)
        )");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$files} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id BIGINT(20) UNSIGNED NOT NULL,
            path VARCHAR(1024) NOT NULL,
            is_private BOOLEAN NOT NULL DEFAULT 0,
            private_prefix VARCHAR(1024) NOT NULL DEFAULT '',
            source_path VARCHAR(1024) NOT NULL,
            size VARCHAR(255) NOT NULL,
            last_upgrade_routine INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        )");
    }

    public static function wpTearDownAfterClass(): void
    {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->get_blog_prefix() . 'as3cf_items');
        $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->get_blog_prefix() . 'as3cf_files');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        wp_set_current_user($this->admin);
        add_filter('wpmcp_offload_media_active', '__return_true');
        update_option('tantan_wordpress_s3', [
            'provider'          => 'aws',
            'region'            => 'eu-west-1',
            'bucket'            => 'media-bucket',
            'access-key-id'     => 'AKIAEXAMPLEKEY',
            'secret-access-key' => 'very-secret-value',
            'copy-to-s3'        => true,
            'serve-from-s3'     => true,
            'remove-local-file' => false,
            'object-prefix'     => 'wp-content/uploads/',
        ]);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->query('DELETE FROM ' . $wpdb->get_blog_prefix() . 'as3cf_items');
        $wpdb->query('DELETE FROM ' . $wpdb->get_blog_prefix() . 'as3cf_files');
        remove_all_filters('wpmcp_offload_media_active');
        delete_option('tantan_wordpress_s3');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'];
    }

    private function attachment(string $title = 'Photo'): int
    {
        return self::factory()->attachment->create([
            'post_title'     => $title,
            'post_mime_type' => 'image/jpeg',
            'file'           => '2026/11/' . sanitize_title($title) . '.jpg',
            'post_status'    => 'inherit',
        ]);
    }

    private function offload(int $id, bool $private = false): void
    {
        global $wpdb;
        $key = 'wp-content/uploads/2026/11/' . get_post_field('post_name', $id) . '.jpg';
        $wpdb->insert($wpdb->get_blog_prefix() . 'as3cf_items', [
            'provider'             => 'aws',
            'region'               => 'eu-west-1',
            'bucket'               => 'media-bucket',
            'path'                 => $key,
            'original_path'        => $key,
            'is_private'           => $private ? 1 : 0,
            'source_type'          => 'media-library',
            'source_id'            => $id,
            'source_path'          => '2026/11/x.jpg',
            'original_source_path' => '2026/11/x.jpg',
            'extra_info'           => '',
        ]);
        $item = (int) $wpdb->insert_id;
        foreach ([ '__as3cf_primary', 'thumbnail' ] as $size) {
            $wpdb->insert($wpdb->get_blog_prefix() . 'as3cf_files', [
                'item_id'     => $item,
                'path'        => $key,
                'is_private'  => $private ? 1 : 0,
                'source_path' => '2026/11/x.jpg',
                'size'        => $size,
            ]);
        }
    }

    public function test_the_catalog_lists_the_read_ops_on_the_plugin_data_pair(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach ([ 'offloadmedia-get-status', 'offloadmedia-get-attachment', 'offloadmedia-list-attachments' ] as $op) {
            $this->assertArrayHasKey($op, $ops, $op);
            $this->assertSame('read', $ops[ $op ]['mode'], $op);
        }
    }

    public function test_without_the_paid_tier_no_op_is_offered(): void
    {
        Gate::set_pro_for_tests(false);
        $names = array_column((new Plugin_Data_Integration())->catalog()['operations'], 'name');
        $this->assertSame([], preg_grep('/^offloadmedia-/', $names));
    }

    public function test_an_inactive_plugin_is_refused_with_its_name_and_the_plugins_screen(): void
    {
        remove_all_filters('wpmcp_offload_media_active');
        add_filter('wpmcp_offload_media_active', '__return_false');
        $out = $this->read('offloadmedia-get-status');
        $this->assertContains($out['error']['code'] ?? null, [ 'plugin_missing', 'plugin_inactive' ]);
        $this->assertStringContainsString('Install and activate WP Offload Media', $out['error']['message']);
        $this->assertStringContainsString('plugins.php', $out['error']['message']);
    }

    public function test_status_reports_provider_bucket_and_counts_and_never_the_credentials(): void
    {
        $a = $this->attachment('One');
        $this->attachment('Two');
        $this->offload($a);

        $status = $this->ok($this->read('offloadmedia-get-status'));
        $this->assertSame('aws', $status['provider']);
        $this->assertSame('eu-west-1', $status['region']);
        $this->assertSame('media-bucket', $status['bucket']);
        $this->assertTrue($status['settings']['copy_to_bucket']);
        $this->assertTrue($status['settings']['deliver_from_bucket']);
        $this->assertFalse($status['settings']['remove_local_file']);
        $this->assertTrue($status['credentials_set']);
        $this->assertSame([ 'total' => 2, 'offloaded' => 1, 'not_offloaded' => 1 ], $status['attachments']);

        $json = (string) wp_json_encode($status);
        $this->assertStringNotContainsString('AKIAEXAMPLEKEY', $json);
        $this->assertStringNotContainsString('very-secret-value', $json);
    }

    public function test_status_needs_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $out = $this->read('offloadmedia-get-status');
        $this->assertArrayHasKey('error', $out);
    }

    public function test_get_attachment_reports_the_bucket_object_for_an_offloaded_file(): void
    {
        $id = $this->attachment('Sunset');
        $this->offload($id);

        $got = $this->ok($this->read('offloadmedia-get-attachment', [ 'id' => $id ]))['attachment'];
        $this->assertSame($id, $got['id']);
        $this->assertTrue($got['offloaded']);
        $this->assertSame('aws', $got['provider']);
        $this->assertSame('media-bucket', $got['bucket']);
        $this->assertSame('wp-content/uploads/2026/11/sunset.jpg', $got['key']);
        $this->assertFalse($got['private']);
        $this->assertSame(2, $got['files_offloaded']);
        $this->assertFalse($got['local_file_exists']);
        $this->assertSame(wp_get_attachment_url($id), $got['url'], 'the URL the site serves, which WP Offload Media rewrites to the bucket');
    }

    public function test_get_attachment_reports_a_file_that_is_not_offloaded(): void
    {
        $id  = $this->attachment('Local');
        $got = $this->ok($this->read('offloadmedia-get-attachment', [ 'id' => $id ]))['attachment'];
        $this->assertFalse($got['offloaded']);
        $this->assertNull($got['bucket']);
        $this->assertNull($got['key']);
    }

    public function test_get_attachment_refuses_an_id_that_is_not_an_attachment(): void
    {
        $post = self::factory()->post->create();
        $this->assertSame('attachment_not_found', $this->read('offloadmedia-get-attachment', [ 'id' => $post ])['error']['code'] ?? null);
        $this->assertSame('attachment_not_found', $this->read('offloadmedia-get-attachment', [ 'id' => 999999 ])['error']['code'] ?? null);
    }

    public function test_list_attachments_filters_by_offload_state(): void
    {
        $up    = $this->attachment('Up');
        $down  = $this->attachment('Down');
        $this->offload($up, true);

        $all = $this->ok($this->read('offloadmedia-list-attachments'));
        $this->assertSame(2, $all['total']);
        $this->assertEqualsCanonicalizing([ $up, $down ], array_column($all['attachments'], 'id'));

        $off = $this->ok($this->read('offloadmedia-list-attachments', [ 'state' => 'offloaded' ]));
        $this->assertSame([ $up ], array_column($off['attachments'], 'id'));
        $this->assertTrue($off['attachments'][0]['private']);
        $this->assertSame(1, $off['total']);

        $not = $this->ok($this->read('offloadmedia-list-attachments', [ 'state' => 'not_offloaded' ]));
        $this->assertSame([ $down ], array_column($not['attachments'], 'id'));
        $this->assertFalse($not['attachments'][0]['offloaded']);
    }

    public function test_list_pages(): void
    {
        foreach ([ 'A', 'B', 'C' ] as $t) {
            $this->attachment($t);
        }
        $page = $this->ok($this->read('offloadmedia-list-attachments', [ 'per_page' => 2, 'page' => 2 ]));
        $this->assertSame(3, $page['total']);
        $this->assertCount(1, $page['attachments']);
    }

    public function test_without_the_items_table_nothing_counts_as_offloaded(): void
    {
        global $wpdb;
        $id = $this->attachment('Lonely');
        Offload_Media_Pack::use_items_table_for_tests($wpdb->get_blog_prefix() . 'as3cf_missing');
        try {
            $status = $this->ok($this->read('offloadmedia-get-status'));
            $this->assertSame(0, $status['attachments']['offloaded']);
            $got = $this->ok($this->read('offloadmedia-get-attachment', [ 'id' => $id ]))['attachment'];
            $this->assertFalse($got['offloaded']);
        } finally {
            Offload_Media_Pack::use_items_table_for_tests(null);
        }
    }
}
