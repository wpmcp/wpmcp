<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\Thirsty_Affiliates_Pack;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/link-management-stubs.php';

/**
 * ThirstyAffiliates (issue #682): free-tier ops on the plugin-data dispatcher
 * pair, so they add no top-level tools.
 *
 * ThirstyAffiliates is not loaded in the shared test core. Presence is driven
 * through wpmcp_thirstyaffiliates_active, its thirstylink post type and
 * thirstylink-category taxonomy are registered here the way
 * Models/Affiliate_Links_CPT.php does (post capabilities, no public UI), and
 * its click tables are built from its own DDL
 * (tests/support/link-management-stubs.php).
 *
 * The writes are off until the site opts in
 * (wpmcp_enable_thirstyaffiliates_write) and each is undone in a test.
 */
class ThirstyAffiliatesPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'thirstyaffiliates-list-links'      => 'read',
        'thirstyaffiliates-get-link'        => 'read',
        'thirstyaffiliates-get-link-clicks' => 'read',
        'thirstyaffiliates-create-link'     => 'write',
        'thirstyaffiliates-update-link'     => 'write',
        'thirstyaffiliates-delete-link'     => 'write',
    ];

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_link_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_link_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        register_post_type('thirstylink', [ 'public' => false, 'show_ui' => true, 'hierarchical' => true, 'capability_type' => 'post', 'supports' => [ 'title', 'custom-fields' ], 'rewrite' => [ 'slug' => 'go' ] ]);
        register_taxonomy('thirstylink-category', 'thirstylink', [ 'public' => false, 'show_ui' => true, 'hierarchical' => true ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_thirstyaffiliates_active', '__return_true');
        add_filter('wpmcp_enable_thirstyaffiliates_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_thirstyaffiliates_active');
        remove_all_filters('wpmcp_enable_thirstyaffiliates_write');
        unregister_taxonomy('thirstylink-category');
        unregister_post_type('thirstylink');
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && ! isset($out['error']) ? $out['result'] : $out;
    }

    private function write(string $op, array $args): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    /** A link post as ThirstyAffiliates' Affiliate_Link::save() stores it. */
    private function link(string $name = 'Gear deal', array $meta = [], string $status = 'publish'): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'thirstylink', 'post_title' => $name, 'post_status' => $status ]);
        foreach ($meta + [ 'destination_url' => 'https://shop.example.com/gear', 'redirect_type' => '301', 'no_follow' => 'yes' ] as $key => $value) {
            update_post_meta($id, '_ta_' . $key, $value);
        }
        return $id;
    }

    private function click(int $link, string $when, array $meta): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'ta_link_clicks', [ 'link_id' => $link, 'date_clicked' => $when ]);
        $click = (int) $wpdb->insert_id;
        foreach ($meta as $key => $value) {
            $wpdb->insert($wpdb->prefix . 'ta_link_clicks_meta', [ 'click_id' => $click, 'meta_key' => $key, 'meta_value' => $value ]);
        }
    }

    // ---------------------------------------------------------------
    // Catalog and availability
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_op_with_its_mode(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
        }
    }

    public function test_while_thirstyaffiliates_is_missing_a_call_answers_plugin_missing_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_thirstyaffiliates_active');
        add_filter('wpmcp_thirstyaffiliates_active', '__return_false');
        $out = $this->read('thirstyaffiliates-list-links');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate ThirstyAffiliates', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
    }

    public function test_with_the_plugin_files_installed_but_inactive_it_answers_plugin_inactive(): void
    {
        remove_all_filters('wpmcp_thirstyaffiliates_active');
        add_filter('wpmcp_thirstyaffiliates_active', '__return_false');
        $dir     = WP_PLUGIN_DIR . '/thirstyaffiliates';
        $created = ! is_dir($dir);
        if ($created) {
            wp_mkdir_p($dir);
        }
        $file  = $dir . '/thirstyaffiliates.php';
        $owned = ! file_exists($file);
        if ($owned) {
            file_put_contents($file, "<?php\n// Plugin Name: ThirstyAffiliates\n");
        }
        try {
            $out = $this->read('thirstyaffiliates-get-link', [ 'id' => 1 ]);
        } finally {
            if ($owned) {
                unlink($file);
            }
            if ($created) {
                rmdir($dir);
            }
        }
        $this->assertSame('plugin_inactive', $out['error']['code'] ?? null);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $id = $this->link();
        remove_all_filters('wpmcp_enable_thirstyaffiliates_write');
        foreach ([
            [ 'thirstyaffiliates-create-link', [ 'name' => 'x', 'url' => 'https://example.com' ] ],
            [ 'thirstyaffiliates-update-link', [ 'id' => $id, 'name' => 'x' ] ],
            [ 'thirstyaffiliates-delete-link', [ 'id' => $id ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_thirstyaffiliates_write', $out['error']['message']);
        }
        $this->assertSame('Gear deal', get_post($id)->post_title);
    }

    public function test_a_subscriber_may_neither_read_nor_write(): void
    {
        $id = $this->link();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'subscriber' ]));
        $this->assertSame('operation_denied', $this->read('thirstyaffiliates-get-link', [ 'id' => $id ])['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->read('thirstyaffiliates-list-links')['error']['code'] ?? null);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame($id, $this->read('thirstyaffiliates-get-link', [ 'id' => $id ])['link']['id'] ?? null, 'an editor reads links');
        $this->assertSame('operation_denied', $this->read('thirstyaffiliates-get-link-clicks', [ 'id' => $id ])['error']['code'] ?? null, 'the click report is for administrators');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'subscriber' ]));
        $this->assertSame('operation_denied', $this->write('thirstyaffiliates-delete-link', [ 'id' => $id ])['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->write('thirstyaffiliates-create-link', [ 'name' => 'x', 'url' => 'https://example.com' ])['error']['code'] ?? null);
        $this->assertSame('publish', get_post($id)->post_status);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_links_returns_links_with_settings_and_hides_other_authors_drafts(): void
    {
        $cat = self::factory()->term->create([ 'taxonomy' => 'thirstylink-category', 'name' => 'Gear' ]);
        $a   = $this->link('Alpha');
        wp_set_object_terms($a, [ $cat ], 'thirstylink-category');
        $b   = $this->link('Beta', [ 'redirect_type' => 'global' ]);
        $c   = $this->link('Private draft', [], 'draft');
        wp_update_post([ 'ID' => $c, 'post_author' => self::factory()->user->create([ 'role' => 'administrator' ]) ]);

        $out = $this->ok($this->read('thirstyaffiliates-list-links'));
        $this->assertEqualsCanonicalizing([ $a, $b, $c ], array_column($out['links'], 'id'), 'an administrator reads every draft');
        $links = array_column($out['links'], null, 'id');
        $this->assertSame('301', $links[ $a ]['redirect_type']);
        $this->assertSame('yes', $links[ $a ]['no_follow']);
        $this->assertSame('https://shop.example.com/gear', $links[ $a ]['url']);
        $this->assertSame('Gear', $links[ $a ]['categories'][0]['name']);
        $this->assertSame([ $a ], array_column($this->read('thirstyaffiliates-list-links', [ 'category' => $cat ])['links'], 'id'));
        $this->assertSame([ $b ], array_column($this->read('thirstyaffiliates-list-links', [ 'search' => 'Beta' ])['links'], 'id'));
        $this->assertSame([ $c ], array_column($this->read('thirstyaffiliates-list-links', [ 'status' => 'draft' ])['links'], 'id'));

        $author = self::factory()->user->create([ 'role' => 'author' ]);
        wp_set_current_user($author);
        $this->assertNotContains($c, array_column($this->read('thirstyaffiliates-list-links')['links'] ?? [], 'id'), 'another author\'s draft stays hidden');
    }

    public function test_get_link_returns_the_settings_and_a_live_click_count(): void
    {
        $id = $this->link('Alpha', [ 'rel_tags' => 'sponsored', 'css_classes' => 'btn' ]);
        $this->click($id, '2026-11-02 08:00:00', []);
        $this->click($id + 1, '2026-11-02 08:00:00', []);

        $link = $this->ok($this->read('thirstyaffiliates-get-link', [ 'id' => $id ]))['link'];
        $this->assertSame('Alpha', $link['name']);
        $this->assertSame('sponsored', $link['rel_tags']);
        $this->assertSame('btn', $link['css_classes']);
        $this->assertSame('global', $link['new_window'], 'an unset toggle reads as global');
        $this->assertSame(1, $link['clicks']);
        $this->assertSame('link_not_found', $this->read('thirstyaffiliates-get-link', [ 'id' => self::factory()->post->create() ])['error']['code'] ?? null);
    }

    public function test_get_link_clicks_masks_the_ip_and_filters_by_date(): void
    {
        $id = $this->link();
        $this->click($id, '2026-11-02 08:00:00', [ 'user_ip_address' => '203.0.113.7', 'http_referer' => 'https://blog.example.org/post', 'redirect_url' => 'https://shop.example.com/gear', 'redirect_type' => '301', 'browser_device' => 'Chrome on Mac' ]);
        $this->click($id, '2026-11-03 08:00:00', [ 'user_ip_address' => '2001:db8::1' ]);

        $out = $this->ok($this->read('thirstyaffiliates-get-link-clicks', [ 'id' => $id ]));
        $this->assertSame(2, $out['total']);
        $this->assertTrue($out['masked']);
        $this->assertSame('2001:db8:*', $out['clicks'][0]['ip']);
        $this->assertSame('203.0.*.*', $out['clicks'][1]['ip']);
        $this->assertSame('https://blog.example.org/post', $out['clicks'][1]['referer']);
        $this->assertSame('Chrome on Mac', $out['clicks'][1]['browser']);
        $this->assertStringNotContainsString('203.0.113.7', (string) wp_json_encode($out));
        $this->assertSame(1, $this->read('thirstyaffiliates-get-link-clicks', [ 'id' => $id, 'from' => '2026-11-03' ])['total']);
        $this->assertSame(1, $this->read('thirstyaffiliates-get-link-clicks', [ 'id' => $id, 'to' => '2026-11-02' ])['total']);
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_create_link_stores_the_post_meta_and_categories_and_rollback_session_trashes_it(): void
    {
        $cat = self::factory()->term->create([ 'taxonomy' => 'thirstylink-category', 'name' => 'Gear' ]);

        $out = $this->ok($this->write('thirstyaffiliates-create-link', [ 'name' => 'New deal', 'url' => ' https://example.com/new ', 'slug' => 'new-deal', 'redirect_type' => '307', 'no_follow' => 'no', 'categories' => [ $cat ] ]));
        $link = $out['result']['link'];
        $post = get_post($link['id']);
        $this->assertSame('thirstylink', $post->post_type);
        $this->assertSame('publish', $post->post_status);
        $this->assertSame('new-deal', $post->post_name);
        $this->assertSame('https://example.com/new', get_post_meta($post->ID, '_ta_destination_url', true));
        $this->assertSame('307', get_post_meta($post->ID, '_ta_redirect_type', true));
        $this->assertSame('no', get_post_meta($post->ID, '_ta_no_follow', true));
        $this->assertSame([ $cat ], wp_get_post_terms($post->ID, 'thirstylink-category', [ 'fields' => 'ids' ]));
        $this->assertSame('New deal', $link['name']);

        $this->assertNotEmpty($out['operation_ids'] ?? []);
        foreach ((array) $out['operation_ids'] as $operation) {
            Rollback_Service::restore_operation((string) $operation);
        }
        $this->assertSame('trash', get_post_status($post->ID));
    }

    public function test_create_link_refuses_a_bad_url_an_unknown_category_and_a_missing_cap(): void
    {
        $this->assertSame('url_invalid', $this->write('thirstyaffiliates-create-link', [ 'name' => 'x', 'url' => 'javascript:alert(1)' ])['error']['code'] ?? null);
        $this->assertSame('unknown_category', $this->write('thirstyaffiliates-create-link', [ 'name' => 'x', 'url' => 'https://example.com', 'categories' => [ 99999 ] ])['error']['code'] ?? null);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'contributor' ]));
        $this->assertSame('operation_denied', $this->write('thirstyaffiliates-create-link', [ 'name' => 'x', 'url' => 'https://example.com' ])['error']['code'] ?? null, 'a contributor may not publish');
    }

    public function test_update_link_changes_only_the_args_given_and_rolls_back_exactly(): void
    {
        $cat = self::factory()->term->create([ 'taxonomy' => 'thirstylink-category', 'name' => 'Gear' ]);
        $id  = $this->link('Alpha');
        $before = [ get_post($id)->post_title, get_post_meta($id, '_ta_destination_url', true), get_post_meta($id, '_ta_no_follow', true), get_post_meta($id, '_ta_new_window', true) ];

        $out = $this->ok($this->write('thirstyaffiliates-update-link', [ 'id' => $id, 'name' => 'Alpha 2', 'url' => 'https://example.com/b', 'no_follow' => 'no', 'new_window' => 'yes', 'categories' => [ $cat ] ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('Alpha 2', get_post($id)->post_title);
        $this->assertSame('https://example.com/b', get_post_meta($id, '_ta_destination_url', true));
        $this->assertSame('301', get_post_meta($id, '_ta_redirect_type', true), 'untouched');
        $this->assertSame([ $cat ], wp_get_post_terms($id, 'thirstylink-category', [ 'fields' => 'ids' ]));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, [ get_post($id)->post_title, get_post_meta($id, '_ta_destination_url', true), get_post_meta($id, '_ta_no_follow', true), get_post_meta($id, '_ta_new_window', true) ]);
        $this->assertSame([], wp_get_post_terms($id, 'thirstylink-category', [ 'fields' => 'ids' ]));
    }

    public function test_update_link_refuses_a_non_link_a_trashed_link_and_a_bad_url(): void
    {
        $id = $this->link();
        $this->assertSame('link_not_found', $this->write('thirstyaffiliates-update-link', [ 'id' => self::factory()->post->create(), 'name' => 'x' ])['error']['code'] ?? null);
        $this->assertSame('url_invalid', $this->write('thirstyaffiliates-update-link', [ 'id' => $id, 'url' => 'ftp://x' ])['error']['code'] ?? null);
        wp_trash_post($id);
        $this->assertSame('link_not_found', $this->write('thirstyaffiliates-update-link', [ 'id' => $id, 'name' => 'x' ])['error']['code'] ?? null);
    }

    public function test_delete_link_trashes_it_keeps_its_clicks_and_rollback_brings_it_back(): void
    {
        $id = $this->link();
        $this->click($id, '2026-11-02 08:00:00', [ 'user_ip_address' => '203.0.113.7' ]);

        $out = $this->ok($this->write('thirstyaffiliates-delete-link', [ 'id' => $id ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('trash', get_post_status($id));
        $this->assertSame('trash', $out['result']['link']['status']);
        global $wpdb;
        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE link_id = %d', $wpdb->prefix . 'ta_link_clicks', $id)), 'clicks are kept');
        $this->assertSame('link_not_found', $this->write('thirstyaffiliates-delete-link', [ 'id' => $id ])['error']['code'] ?? null);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('publish', get_post_status($id));
        $this->assertSame('https://shop.example.com/gear', get_post_meta($id, '_ta_destination_url', true));
    }

    public function test_presence_defaults_to_the_plugin_function(): void
    {
        remove_all_filters('wpmcp_thirstyaffiliates_active');
        $this->assertSame(function_exists('ThirstyAffiliates') || defined('THIRSTY_VERSION'), Thirsty_Affiliates_Pack::active());
    }
}
