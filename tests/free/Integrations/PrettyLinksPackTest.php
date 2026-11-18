<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\Pretty_Links_Pack;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/link-management-stubs.php';

/**
 * Pretty Links (issue #682): free-tier ops on the plugin-data dispatcher
 * pair, so they add no top-level tools.
 *
 * Pretty Links is not loaded in the shared test core. Presence is driven
 * through wpmcp_pretty_links_active, and the storage is built the way Pretty
 * Links 4.0.18 keeps it (tests/support/link-management-stubs.php).
 *
 * The writes are off until the site opts in (wpmcp_enable_pretty_links_write)
 * and each is undone in a test.
 */
class PrettyLinksPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'prettylinks-list-links'      => 'read',
        'prettylinks-get-link'        => 'read',
        'prettylinks-get-link-clicks' => 'read',
        'prettylinks-create-link'     => 'write',
        'prettylinks-update-link'     => 'write',
        'prettylinks-delete-link'     => 'write',
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
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_pretty_links_active', '__return_true');
        add_filter('wpmcp_enable_pretty_links_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_pretty_links_active');
        remove_all_filters('wpmcp_enable_pretty_links_write');
        remove_all_filters('prli_pre_link_trash');
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

    private function row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'prli_links', $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private function click(int $link, string $ip, string $when, int $first = 1): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'prli_clicks', [
            'link_id' => $link, 'ip' => $ip, 'btype' => 'Firefox', 'os' => 'Linux', 'referer' => 'https://news.example.org/a',
            'host' => 'news.example.org', 'uri' => '/deal', 'first_click' => $first, 'created_at' => $when, 'vuid' => 'abc123', 'country' => 'GB',
        ]);
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

    public function test_presence_defaults_to_the_plugin_constants(): void
    {
        remove_all_filters('wpmcp_pretty_links_active');
        $this->assertSame(defined('PRLI_EDITION') || defined('PRLI_VERSION'), Pretty_Links_Pack::active());
    }

    public function test_while_pretty_links_is_missing_a_call_answers_plugin_missing_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_pretty_links_active');
        add_filter('wpmcp_pretty_links_active', '__return_false');
        $out = $this->read('prettylinks-list-links');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Pretty Links', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        $this->assertFalse($ops['prettylinks-get-link']['dependency_met']);
    }

    public function test_with_the_plugin_files_installed_but_inactive_it_answers_plugin_inactive(): void
    {
        remove_all_filters('wpmcp_pretty_links_active');
        add_filter('wpmcp_pretty_links_active', '__return_false');
        $dir     = WP_PLUGIN_DIR . '/pretty-link';
        $created = ! is_dir($dir);
        if ($created) {
            wp_mkdir_p($dir);
        }
        $file  = $dir . '/pretty-link.php';
        $owned = ! file_exists($file);
        if ($owned) {
            file_put_contents($file, "<?php\n// Plugin Name: Pretty Links\n");
        }
        try {
            $out = $this->read('prettylinks-get-link', [ 'link_id' => 1 ]);
        } finally {
            if ($owned) {
                unlink($file);
            }
            if ($created) {
                rmdir($dir);
            }
        }
        $this->assertSame('plugin_inactive', $out['error']['code'] ?? null);
        $this->assertStringContainsString('installed but not active', $out['error']['message']);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $id = wpmcp_test_prli_link();
        remove_all_filters('wpmcp_enable_pretty_links_write');
        foreach ([
            [ 'prettylinks-create-link', [ 'url' => 'https://example.com/x' ] ],
            [ 'prettylinks-update-link', [ 'link_id' => $id, 'name' => 'x' ] ],
            [ 'prettylinks-delete-link', [ 'link_id' => $id ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_pretty_links_write', $out['error']['message']);
        }
        $this->assertSame('The deal', $this->row($id)['name']);
    }

    public function test_every_op_takes_manage_options(): void
    {
        $id = wpmcp_test_prli_link();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        foreach ([ 'prettylinks-list-links' => [], 'prettylinks-get-link' => [ 'link_id' => $id ], 'prettylinks-get-link-clicks' => [ 'link_id' => $id ] ] as $op => $args) {
            $this->assertSame('operation_denied', $this->read($op, $args)['error']['code'] ?? null, $op);
        }
        $this->assertSame('operation_denied', $this->write('prettylinks-delete-link', [ 'link_id' => $id ])['error']['code'] ?? null);
        $this->assertNull($this->row($id)['deleted_at']);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_links_returns_active_links_newest_first_and_filters(): void
    {
        global $wpdb;
        $a = wpmcp_test_prli_link([ 'slug' => 'alpha', 'name' => 'Alpha', 'created_at' => '2026-11-01 10:00:00' ]);
        $b = wpmcp_test_prli_link([ 'slug' => 'beta', 'name' => 'Beta', 'url' => 'https://example.com/beta', 'created_at' => '2026-11-02 10:00:00' ]);
        $c = wpmcp_test_prli_link([ 'slug' => 'gone', 'name' => 'Gone', 'deleted_at' => '2026-11-03 10:00:00' ]);

        $out = $this->ok($this->read('prettylinks-list-links'));
        $this->assertSame([ $b, $a ], array_column($out['links'], 'id'));
        $this->assertSame(2, $out['total']);
        $this->assertSame(home_url('/beta'), $out['links'][0]['pretty_url']);
        $this->assertFalse($out['links'][0]['trashed']);

        $this->assertSame([ $c ], array_column($this->read('prettylinks-list-links', [ 'status' => 'trashed' ])['links'], 'id'));
        $this->assertSame(3, $this->read('prettylinks-list-links', [ 'status' => 'any' ])['total']);
        $this->assertSame([ $b ], array_column($this->read('prettylinks-list-links', [ 'search' => 'example.com/beta' ])['links'], 'id'));
        $paged = $this->read('prettylinks-list-links', [ 'page_size' => 1 ]);
        $this->assertSame(1, $paged['next_offset']);
        $this->assertNull($this->read('prettylinks-list-links', [ 'page_size' => 1, 'offset' => 1 ])['next_offset']);
        $this->assertSame($wpdb->prefix . 'prli_links', Pretty_Links_Pack::table());
    }

    public function test_get_link_returns_the_flags_and_live_click_counts(): void
    {
        $id = wpmcp_test_prli_link([ 'nofollow' => 1, 'sponsored' => 1, 'param_forwarding' => 1 ]);
        $this->click($id, '203.0.113.7', '2026-11-02 08:00:00', 1);
        $this->click($id, '203.0.113.7', '2026-11-02 09:00:00', 0);

        $link = $this->ok($this->read('prettylinks-get-link', [ 'link_id' => $id ]))['link'];
        $this->assertSame('deal', $link['slug']);
        $this->assertTrue($link['nofollow']);
        $this->assertTrue($link['sponsored']);
        $this->assertFalse($link['new_window']);
        $this->assertTrue($link['param_forwarding']);
        $this->assertSame(2, $link['recorded_clicks']);
        $this->assertSame(1, $link['recorded_uniques']);
        $this->assertSame('link_not_found', $this->read('prettylinks-get-link', [ 'link_id' => 9999 ])['error']['code'] ?? null);
    }

    public function test_get_link_clicks_masks_the_ip_leaves_the_visitor_id_out_and_filters_by_date(): void
    {
        $id = wpmcp_test_prli_link();
        $this->click($id, '203.0.113.7', '2026-11-02 08:00:00');
        $this->click($id, '2001:db8:85a3::8a2e:370:7334', '2026-11-03 08:00:00', 0);
        $this->click($id + 1, '198.51.100.1', '2026-11-03 08:00:00');

        $out = $this->ok($this->read('prettylinks-get-link-clicks', [ 'link_id' => $id ]));
        $this->assertSame(2, $out['total']);
        $this->assertSame(1, $out['unique_total']);
        $this->assertTrue($out['masked']);
        $this->assertSame('2001:db8:*', $out['clicks'][0]['ip']);
        $this->assertSame('203.0.*.*', $out['clicks'][1]['ip']);
        $this->assertStringNotContainsString('abc123', (string) wp_json_encode($out), 'the visitor id stays out');
        $this->assertStringNotContainsString('203.0.113.7', (string) wp_json_encode($out));
        $this->assertSame('GB', $out['clicks'][1]['country']);

        $this->assertSame(1, $this->read('prettylinks-get-link-clicks', [ 'link_id' => $id, 'from' => '2026-11-03' ])['total']);
        $this->assertSame(1, $this->read('prettylinks-get-link-clicks', [ 'link_id' => $id, 'to' => '2026-11-02' ])['total']);
        $this->assertSame(1, $this->read('prettylinks-get-link-clicks', [ 'link_id' => $id, 'unique' => true ])['total']);
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_create_link_inserts_one_row_and_rollback_removes_it(): void
    {
        $seen = 0;
        add_action('prli-create-link', static function () use (&$seen): void {
            ++$seen;
        });
        $existing = wpmcp_test_prli_link([ 'slug' => 'first' ]);

        $out = $this->ok($this->write('prettylinks-create-link', [ 'url' => ' https://example.com/new ', 'slug' => 'My-Link', 'name' => 'New', 'nofollow' => true, 'redirect_type' => '301' ]));
        $this->assertTrue($out['recoverable']);
        $link = $out['result']['link'];
        $this->assertGreaterThan($existing, $link['id']);
        $this->assertSame('My-Link', $link['slug']);
        $this->assertSame('https://example.com/new', $link['url']);
        $this->assertSame('301', $link['redirect_type']);
        $this->assertTrue($link['nofollow']);
        $this->assertTrue($link['track_me']);
        $this->assertSame('admin', $link['source']);
        $this->assertSame(1, $seen, 'the plugin\'s own prli-create-link action fires');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertNull($this->row($link['id']));
        $this->assertNotNull($this->row($existing));
    }

    public function test_create_link_generates_a_slug_and_defaults_the_name_to_it(): void
    {
        $link = $this->ok($this->write('prettylinks-create-link', [ 'url' => 'https://example.com/auto' ]))['result']['link'];
        $this->assertMatchesRegularExpression('/^[a-z0-9]{5}$/', $link['slug']);
        $this->assertSame($link['slug'], $link['name']);
        $this->assertSame('302', $link['redirect_type']);
    }

    public function test_create_link_refuses_a_bad_url_a_taken_slug_and_a_leading_slash(): void
    {
        wpmcp_test_prli_link([ 'slug' => 'taken' ]);
        $this->assertSame('url_invalid', $this->write('prettylinks-create-link', [ 'url' => 'javascript:alert(1)' ])['error']['code'] ?? null);
        $this->assertSame('url_invalid', $this->write('prettylinks-create-link', [ 'url' => 'example.com' ])['error']['code'] ?? null);
        $this->assertSame('slug_in_use', $this->write('prettylinks-create-link', [ 'url' => 'https://example.com', 'slug' => 'taken' ])['error']['code'] ?? null);
        $this->assertSame('invalid_slug', $this->write('prettylinks-create-link', [ 'url' => 'https://example.com', 'slug' => '/abs' ])['error']['code'] ?? null);
        $this->assertSame('invalid_slug', $this->write('prettylinks-create-link', [ 'url' => 'https://example.com', 'slug' => '!!!' ])['error']['code'] ?? null);
    }

    /**
     * Issue #950: a slug outside letters, digits, "-", "_", "/" and "." is
     * refused, never rewritten, and nothing is written.
     */
    public function test_create_link_refuses_a_slug_it_would_otherwise_rewrite(): void
    {
        global $wpdb;
        $count = static fn (): int => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->prefix . 'prli_links'));
        $before = $count();
        foreach ([ 'bad slug!', 'bad-slug!', "tab\there", 'a~b', 'a+b', 'a--b', 'trail-', 'a//b', 'ends/', '-lead' ] as $bad) {
            $out = $this->write('prettylinks-create-link', [ 'url' => 'https://example.com/offer', 'slug' => $bad ]);
            $this->assertSame('invalid_slug', $out['error']['code'] ?? null, $bad);
            $this->assertStringContainsString('letters, digits', (string) ($out['error']['message'] ?? ''), $bad);
        }
        $out = $this->write('prettylinks-create-link', [ 'url' => 'https://example.com/offer', 'slug' => 'bad slug!' ]);
        $this->assertSame('bad-slug', $out['error']['data']['suggested_slug'] ?? null);
        $this->assertSame($before, $count(), 'no link row is written on refusal');

        foreach ([ 'go/deal_2.0', "Caf\u{00e9}-menu", ' padded ' ] as $slug) {
            $link = $this->ok($this->write('prettylinks-create-link', [ 'url' => 'https://example.com/ok', 'slug' => $slug ]))['result']['link'];
            $this->assertSame(trim($slug), $link['slug']);
        }
    }

    public function test_update_link_refuses_a_bad_slug_and_leaves_the_row_alone(): void
    {
        $id     = wpmcp_test_prli_link([ 'slug' => 'keep-me' ]);
        $before = $this->row($id);
        $this->assertSame('invalid_slug', $this->write('prettylinks-update-link', [ 'link_id' => $id, 'slug' => 'bad slug!' ])['error']['code'] ?? null);
        $this->assertSame($before, $this->row($id));
    }

    public function test_update_link_still_accepts_a_legacy_slug_it_already_holds(): void
    {
        foreach ([ 'old~slug', 'old slug' ] as $legacy) {
            $id = wpmcp_test_prli_link([ 'slug' => $legacy ]);
            $this->ok($this->write('prettylinks-update-link', [ 'link_id' => $id, 'slug' => $legacy, 'name' => 'Renamed' ]));
            $this->assertSame($legacy, $this->row($id)['slug']);
            $this->assertSame('Renamed', $this->row($id)['name']);
        }
    }

    public function test_update_link_changes_only_the_args_given_and_rolls_back_exactly(): void
    {
        $id     = wpmcp_test_prli_link([ 'nofollow' => 1 ]);
        $before = $this->row($id);

        $out = $this->ok($this->write('prettylinks-update-link', [ 'link_id' => $id, 'url' => 'https://example.com/changed', 'sponsored' => true, 'nofollow' => false ]));
        $this->assertTrue($out['recoverable']);
        $link = $out['result']['link'];
        $this->assertSame('https://example.com/changed', $link['url']);
        $this->assertTrue($link['sponsored']);
        $this->assertFalse($link['nofollow']);
        $this->assertSame('deal', $link['slug']);
        $this->assertSame('The deal', $link['name']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, $this->row($id));
    }

    public function test_update_link_may_keep_its_own_slug_but_not_take_another(): void
    {
        $a = wpmcp_test_prli_link([ 'slug' => 'a' ]);
        wpmcp_test_prli_link([ 'slug' => 'b' ]);
        $this->ok($this->write('prettylinks-update-link', [ 'link_id' => $a, 'slug' => 'a', 'name' => 'Same slug' ]));
        $this->assertSame('slug_in_use', $this->write('prettylinks-update-link', [ 'link_id' => $a, 'slug' => 'b' ])['error']['code'] ?? null);
        $this->assertSame('invalid_slug', $this->write('prettylinks-update-link', [ 'link_id' => $a, 'slug' => '' ])['error']['code'] ?? null);
        $this->assertSame('link_not_found', $this->write('prettylinks-update-link', [ 'link_id' => 9999, 'name' => 'x' ])['error']['code'] ?? null);
        $this->assertSame('url_invalid', $this->write('prettylinks-update-link', [ 'link_id' => $a, 'url' => 'ftp://x' ])['error']['code'] ?? null);
    }

    public function test_delete_link_moves_it_to_the_trash_and_rollback_brings_it_back(): void
    {
        $id     = wpmcp_test_prli_link();
        $before = $this->row($id);
        $fired  = 0;
        add_action('prli_delete_link', static function () use (&$fired): void {
            ++$fired;
        });

        $out = $this->ok($this->write('prettylinks-delete-link', [ 'link_id' => $id ]));
        $this->assertTrue($out['recoverable']);
        $this->assertTrue($out['result']['link']['trashed']);
        $this->assertNotNull($this->row($id), 'a trash, not a delete');
        $this->assertNotNull($this->row($id)['deleted_at']);
        $this->assertSame(1, $fired);
        $this->assertSame(0, $this->read('prettylinks-list-links')['total']);

        $this->assertSame('already_trashed', $this->write('prettylinks-delete-link', [ 'link_id' => $id ])['error']['code'] ?? null);
        $this->assertSame('link_trashed', $this->write('prettylinks-update-link', [ 'link_id' => $id, 'name' => 'x' ])['error']['code'] ?? null);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, $this->row($id));
    }

    public function test_a_trash_the_plugin_blocks_is_refused(): void
    {
        $id = wpmcp_test_prli_link();
        add_filter('prli_pre_link_trash', static fn (): string => 'Used by a splash page.');
        $out = $this->write('prettylinks-delete-link', [ 'link_id' => $id ]);
        $this->assertSame('trash_blocked', $out['error']['code'] ?? null);
        $this->assertStringContainsString('splash', $out['error']['message']);
        $this->assertNull($this->row($id)['deleted_at']);
    }

    public function test_mask_ip_hides_the_host_part(): void
    {
        $this->assertSame('192.168.*.*', Pretty_Links_Pack::mask_ip('192.168.1.20'));
        $this->assertSame('2001:db8:*', Pretty_Links_Pack::mask_ip('2001:db8::1'));
        $this->assertSame('***', Pretty_Links_Pack::mask_ip('not-an-ip'));
        $this->assertNull(Pretty_Links_Pack::mask_ip(null));
    }
}
