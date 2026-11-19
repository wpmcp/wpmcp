<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Instagram_Feed_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/instagram-feed-stubs.php';

/**
 * Smash Balloon Instagram Feed (issue #902): free-tier ops on the
 * plugin-data dispatcher pair, so they add no top-level tools.
 *
 * The plugin is not loaded in the shared test core. Presence is driven
 * through wpmcp_instagram_feed_active, and the storage is built the way
 * Instagram Feed 6.16.0 keeps it (tests/support/instagram-feed-stubs.php).
 *
 * Access tokens are never returned. The writes are off until the site opts
 * in (wpmcp_enable_instagram_feed_write) and each is undone in a test.
 */
class InstagramFeedPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'instagramfeed-list-feeds'      => 'read',
        'instagramfeed-get-feed'        => 'read',
        'instagramfeed-list-sources'    => 'read',
        'instagramfeed-update-feed'     => 'write',
        'instagramfeed-clear-feed-cache' => 'write',
    ];

    private const TOKEN = 'IGQVJSECRETTOKENVALUE0123456789';

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_sbi_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_sbi_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_instagram_feed_active', '__return_true');
        add_filter('wpmcp_enable_instagram_feed_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_instagram_feed_active');
        remove_all_filters('wpmcp_enable_instagram_feed_write');
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

    private function feed_row(int $id): array
    {
        global $wpdb;
        return (array) $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'sbi_feeds', $id), ARRAY_A);
    }

    private function cache_value(int $id): string
    {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare('SELECT cache_value FROM %i WHERE id = %d', $wpdb->prefix . 'sbi_feed_caches', $id));
    }

    // ---------------------------------------------------------------
    // Catalog and availability
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_op_with_its_mode_and_manage_options(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            $this->assertSame('manage_options', $ops[ $name ]['capability'], $name);
        }
    }

    public function test_presence_defaults_to_the_plugin_version_constant(): void
    {
        remove_all_filters('wpmcp_instagram_feed_active');
        $this->assertSame(defined('SBIVER'), Instagram_Feed_Pack::active());
    }

    public function test_while_the_plugin_is_missing_a_call_answers_plugin_missing_naming_it(): void
    {
        remove_all_filters('wpmcp_instagram_feed_active');
        add_filter('wpmcp_instagram_feed_active', '__return_false');
        $out = $this->read('instagramfeed-list-feeds');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Instagram Feed', $out['error']['message']);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $feed = wpmcp_test_sbi_feed();
        $row  = wpmcp_test_sbi_cache((string) $feed, 'header', '{"x":1}');
        remove_all_filters('wpmcp_enable_instagram_feed_write');
        foreach ([
            [ 'instagramfeed-update-feed', [ 'feed_id' => $feed, 'settings' => [ 'cols' => 3 ] ] ],
            [ 'instagramfeed-clear-feed-cache', [ 'feed_id' => $feed ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_instagram_feed_write', $out['error']['message']);
        }
        $this->assertSame('4', json_decode($this->feed_row($feed)['settings'], true)['cols']);
        $this->assertSame('{"x":1}', $this->cache_value($row));
    }

    public function test_every_op_takes_manage_options(): void
    {
        $feed = wpmcp_test_sbi_feed();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        foreach ([ 'instagramfeed-list-feeds' => [], 'instagramfeed-get-feed' => [ 'feed_id' => $feed ], 'instagramfeed-list-sources' => [] ] as $op => $args) {
            $this->assertSame('operation_denied', $this->read($op, $args)['error']['code'] ?? null, $op);
        }
        $this->assertSame('operation_denied', $this->write('instagramfeed-update-feed', [ 'feed_id' => $feed, 'settings' => [ 'cols' => 2 ] ])['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_feeds_returns_each_feed_with_its_sources_embeds_and_cache_age(): void
    {
        global $wpdb;
        $a    = wpmcp_test_sbi_feed();
        $b    = wpmcp_test_sbi_feed([ 'id' => '17841400000000002', 'source_details' => [ 'id' => '17841400000000002', 'username' => 'acme.blog' ] ], [ 'feed_name' => 'Blog feed', 'feed_title' => 'Blog feed' ]);
        $post = self::factory()->post->create([ 'post_title' => 'Home' ]);
        $wpdb->insert($wpdb->prefix . 'sbi_instagram_feed_locator', [ 'feed_id' => '*' . $a, 'post_id' => $post, 'html_location' => 'content', 'shortcode_atts' => '{"feed":"' . $a . '"}', 'last_update' => '2026-11-01 10:00:00' ]);
        wpmcp_test_sbi_cache((string) $a, 'posts', '{"data":[]}', '2026-11-02 08:00:00');

        $out = $this->ok($this->read('instagramfeed-list-feeds'));
        $this->assertSame(2, $out['total']);
        $feeds = array_column($out['feeds'], null, 'id');
        $this->assertSame('Shop feed', $feeds[ $a ]['name']);
        $this->assertSame('[instagram-feed feed=' . $a . ']', $feeds[ $a ]['shortcode']);
        $this->assertSame([ 'acme.shop' ], $feeds[ $a ]['sources']);
        $this->assertSame([ 'acme.blog' ], $feeds[ $b ]['sources']);
        $this->assertSame(1, $feeds[ $a ]['embeds']);
        $this->assertSame(0, $feeds[ $b ]['embeds']);
        $this->assertSame('2026-11-02 08:00:00', $feeds[ $a ]['posts_cached_at']);
        $this->assertNull($feeds[ $b ]['posts_cached_at']);
    }

    public function test_get_feed_returns_settings_placements_and_cache_rows_without_tokens(): void
    {
        global $wpdb;
        $feed = wpmcp_test_sbi_feed([ 'connected_accounts' => [ 'x' => [ 'access_token' => self::TOKEN ] ], 'access_token' => self::TOKEN ]);
        $post = self::factory()->post->create([ 'post_title' => 'Gallery page' ]);
        $wpdb->insert($wpdb->prefix . 'sbi_instagram_feed_locator', [ 'feed_id' => '*' . $feed, 'post_id' => $post, 'html_location' => 'content', 'shortcode_atts' => '{"feed":"' . $feed . '"}', 'last_update' => '2026-11-01 10:00:00' ]);
        wpmcp_test_sbi_cache((string) $feed, 'posts', '{"data":[1,2]}');
        wpmcp_test_sbi_cache((string) $feed, 'posts_backup', '');

        $out = $this->ok($this->read('instagramfeed-get-feed', [ 'feed_id' => $feed ]));
        $this->assertStringNotContainsString(self::TOKEN, (string) wp_json_encode($out));
        $this->assertSame('20', $out['feed']['settings']['num']);
        $this->assertArrayNotHasKey('connected_accounts', $out['feed']['settings']);
        $this->assertArrayNotHasKey('access_token', $out['feed']['settings']);
        $this->assertSame($post, $out['feed']['placements'][0]['post_id']);
        $this->assertSame('Gallery page', $out['feed']['placements'][0]['title']);
        $this->assertSame('content', $out['feed']['placements'][0]['location']);
        $caches = array_column($out['feed']['cache'], null, 'key');
        $this->assertFalse($caches['posts']['empty']);
        $this->assertTrue($caches['posts_backup']['empty']);
    }

    public function test_get_feed_refuses_an_unknown_feed(): void
    {
        $out = $this->read('instagramfeed-get-feed', [ 'feed_id' => 99999 ]);
        $this->assertSame('feed_not_found', $out['error']['code'] ?? null);
    }

    public function test_list_sources_reports_connection_state_and_never_the_token(): void
    {
        wpmcp_test_sbi_feed();
        wpmcp_test_sbi_source();
        wpmcp_test_sbi_source([ 'account_id' => '2', 'username' => 'old.account', 'expires' => '2020-01-01 00:00:00', 'connect_type' => 'personal' ]);
        wpmcp_test_sbi_source([ 'account_id' => '3', 'username' => 'soon.account', 'expires' => gmdate('Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS) ]);
        wpmcp_test_sbi_source([ 'account_id' => '4', 'username' => 'broken.account', 'error' => '{"error":{"message":"Error validating access token: Session has expired. https://graph.instagram.com/me?access_token=' . self::TOKEN . '","code":190}}' ]);
        wpmcp_test_sbi_source([ 'account_id' => '5', 'username' => 'page.account', 'expires' => '0000-00-00 00:00:00' ]);

        $out = $this->ok($this->read('instagramfeed-list-sources'));
        $this->assertStringNotContainsString(self::TOKEN, (string) wp_json_encode($out));
        $by = array_column($out['sources'], null, 'username');
        $this->assertSame('connected', $by['acme.shop']['state']);
        $this->assertSame(1, $by['acme.shop']['used_in_feeds']);
        $this->assertSame('expired', $by['old.account']['state']);
        $this->assertSame('expiring_soon', $by['soon.account']['state']);
        $this->assertSame('error', $by['broken.account']['state']);
        $this->assertSame(190, $by['broken.account']['error']['code']);
        $this->assertStringContainsString('Session has expired', $by['broken.account']['error']['message']);
        $this->assertSame('connected', $by['page.account']['state']);
        $this->assertNull($by['page.account']['expires']);
        $this->assertArrayNotHasKey('access_token', $by['acme.shop']);
        $this->assertStringContainsString('page=sbi-settings', $out['reconnect_url']);
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_update_feed_changes_only_the_given_display_settings_and_undoes(): void
    {
        $feed    = wpmcp_test_sbi_feed();
        $before  = $this->feed_row($feed);
        $header  = wpmcp_test_sbi_cache((string) $feed, 'header', '{"h":1}');
        $posts   = wpmcp_test_sbi_cache((string) $feed, 'posts', '{"data":[1]}');

        $out = $this->ok($this->write('instagramfeed-update-feed', [ 'feed_id' => $feed, 'settings' => [ 'cols' => 3, 'showheader' => false ] ]));
        $this->assertNotEmpty($out['operation_id']);
        $stored = json_decode($this->feed_row($feed)['settings'], true);
        $this->assertSame('3', $stored['cols']);
        $this->assertFalse($stored['showheader']);
        $this->assertSame('20', $stored['num']);
        $this->assertSame([ [ 'id' => '17841400000000001', 'username' => 'acme.shop' ] ], $stored['source_details']);
        $this->assertSame('', $this->cache_value($header), 'the rendered cache is cleared so the change shows');
        $this->assertSame('{"data":[1]}', $this->cache_value($posts), 'a cosmetic change keeps the fetched posts');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before['settings'], $this->feed_row($feed)['settings']);
    }

    public function test_update_feed_with_a_retrieval_setting_also_clears_the_posts_cache(): void
    {
        $feed  = wpmcp_test_sbi_feed();
        $posts = wpmcp_test_sbi_cache((string) $feed, 'posts', '{"data":[1]}');
        $this->ok($this->write('instagramfeed-update-feed', [ 'feed_id' => $feed, 'settings' => [ 'num' => 9 ] ]));
        $this->assertSame('9', json_decode($this->feed_row($feed)['settings'], true)['num']);
        $this->assertSame('', $this->cache_value($posts));
    }

    public function test_update_feed_refuses_an_unknown_setting_and_an_unknown_feed(): void
    {
        $feed = wpmcp_test_sbi_feed();
        $out  = $this->write('instagramfeed-update-feed', [ 'feed_id' => $feed, 'settings' => [ 'access_token' => 'x' ] ]);
        $this->assertArrayHasKey('error', $out);
        $this->assertSame('feed_not_found', $this->write('instagramfeed-update-feed', [ 'feed_id' => 99999, 'settings' => [ 'cols' => 2 ] ])['error']['code'] ?? null);
    }

    public function test_clear_feed_cache_blanks_the_feed_cache_keeps_backups_and_undoes(): void
    {
        $feed     = wpmcp_test_sbi_feed();
        $other    = wpmcp_test_sbi_feed();
        $posts    = wpmcp_test_sbi_cache((string) $feed, 'posts', '{"data":[1]}');
        $header   = wpmcp_test_sbi_cache((string) $feed, 'header', '{"h":1}');
        $backup   = wpmcp_test_sbi_cache((string) $feed, 'posts_backup', '{"data":[0]}');
        $custom   = wpmcp_test_sbi_cache($feed . '_CUSTOMIZER', 'posts', '{"c":1}');
        $elsewise = wpmcp_test_sbi_cache((string) $other, 'posts', '{"o":1}');

        $out = $this->ok($this->write('instagramfeed-clear-feed-cache', [ 'feed_id' => $feed ]));
        $this->assertSame(3, $out['result']['cleared']);
        $this->assertSame('', $this->cache_value($posts));
        $this->assertSame('', $this->cache_value($header));
        $this->assertSame('', $this->cache_value($custom));
        $this->assertSame('{"data":[0]}', $this->cache_value($backup));
        $this->assertSame('{"o":1}', $this->cache_value($elsewise));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('{"data":[1]}', $this->cache_value($posts));
        $this->assertSame('{"h":1}', $this->cache_value($header));
        $this->assertSame('{"c":1}', $this->cache_value($custom));
    }

    public function test_the_sources_table_holding_tokens_can_never_be_snapshotted(): void
    {
        $this->assertNull(\WPMCP\Safety\Plugin_Table_Rows_Snapshot::table('sbi', 'sources'));
        $this->assertNotNull(\WPMCP\Safety\Plugin_Table_Rows_Snapshot::table('sbi', 'feed_caches'));
    }

    public function test_clear_feed_cache_without_posts_keeps_the_fetched_posts(): void
    {
        $feed   = wpmcp_test_sbi_feed();
        $posts  = wpmcp_test_sbi_cache((string) $feed, 'posts', '{"data":[1]}');
        $header = wpmcp_test_sbi_cache((string) $feed, 'header', '{"h":1}');
        $this->ok($this->write('instagramfeed-clear-feed-cache', [ 'feed_id' => $feed, 'include_posts' => false ]));
        $this->assertSame('{"data":[1]}', $this->cache_value($posts));
        $this->assertSame('', $this->cache_value($header));
    }
}
