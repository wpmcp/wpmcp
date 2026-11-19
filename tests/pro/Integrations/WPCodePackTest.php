<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\WPCode_Pack;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Code\Php_Snippet_Guard;

require_once __DIR__ . '/../../support/wpcode-double.php';

/**
 * WPCode (issue #697) on the plugin-data pair, paid-tier, against the
 * stand-in in tests/support/wpcode-double.php, kept the way WPCode 2.4
 * keeps its snippets ('wpcode' posts, wpcode_* terms, _wpcode_* meta).
 *
 * Writes are off until the site opts in; snippets are created inactive;
 * PHP code is statically checked and activated only behind the PHP
 * execution gate; raw HTML, CSS, JS and the global scripts only behind the
 * code injection gate; an active snippet is not edited; and every write is
 * undone in a test, an undo never leaving a snippet active.
 */
class WPCodePackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'wpcode-list-snippets'      => 'read',
        'wpcode-get-snippet'        => 'read',
        'wpcode-get-header-footer'  => 'read',
        'wpcode-create-snippet'     => 'write',
        'wpcode-update-snippet'     => 'write',
        'wpcode-activate-snippet'   => 'write',
        'wpcode-deactivate-snippet' => 'write',
        'wpcode-set-header-footer'  => 'write',
    ];

    private const NOOP = "// wpmcp test snippet: does nothing.\n\$wpmcp_wpcode_noop = \"a\\nb\";";

    protected function setUp(): void
    {
        parent::setUp();
        if (defined('WPCODE_VERSION')) {
            $this->markTestSkipped('The real WPCode is loaded.');
        }
        wpmcp_test_wpcode_register();
        Snapshot_Store::install();
        // Start from an empty undo history, whatever an earlier test left (issue #963).
        $GLOBALS['wpdb']->query('DELETE FROM ' . Snapshot_Store::table_name());
        delete_option(Governance_Audit_Log::OPTION);
        Gate::set_pro_for_tests(true);
        $admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        if (is_multisite()) {
            // PHP execution and unfiltered_html on a network need a network administrator.
            grant_super_admin($admin);
        }
        wp_set_current_user($admin);
        add_filter('wpmcp_wpcode_active', '__return_true');
        add_filter(WPCode_Pack::OPT_IN_FILTER, '__return_true');
        $GLOBALS['wpmcp_test_wpcode_executed']       = 0;
        $GLOBALS['wpmcp_test_wpcode_cache_rebuilds'] = 0;
        wpcode()->error->clear_errors();
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_wpcode_active');
        remove_all_filters(WPCode_Pack::OPT_IN_FILTER);
        remove_all_filters('wpmcp_allow_php_exec');
        remove_all_filters('wpmcp_allow_js_injection');
        Php_Snippet_Guard::set_environment_override(null);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
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

    /** A snippet stored the way WPCode stores one; returns its id. */
    private function seed(string $type = 'php', string $status = 'draft', array $extra = []): int
    {
        $id = (int) wp_insert_post([
            'post_type'    => 'wpcode',
            'post_title'   => $extra['title'] ?? 'Seeded',
            'post_content' => wp_slash($extra['code'] ?? ('php' === $type ? self::NOOP : '<p>seeded</p>')),
            'post_status'  => $status,
        ]);
        wp_set_post_terms($id, $type, 'wpcode_type');
        wp_set_post_terms($id, $extra['location'] ?? ('php' === $type ? 'everywhere' : 'site_wide_header'), 'wpcode_location');
        wp_set_post_terms($id, $extra['tags'] ?? [ 'alpha' ], 'wpcode_tags');
        update_post_meta($id, '_wpcode_auto_insert', 1);
        update_post_meta($id, '_wpcode_priority', 10);
        return $id;
    }

    /** Everything WPCode stores for a snippet, to compare before and after an undo. */
    private function image(int $id): array
    {
        $post = get_post($id);
        $meta = get_post_meta($id);
        unset($meta['_edit_lock'], $meta['_edit_last']);
        ksort($meta);
        return [
            'title'    => $post->post_title,
            'content'  => $post->post_content,
            'status'   => $post->post_status,
            'type'     => wp_get_object_terms($id, 'wpcode_type', [ 'fields' => 'slugs' ]),
            'location' => wp_get_object_terms($id, 'wpcode_location', [ 'fields' => 'slugs' ]),
            'tags'     => wp_get_object_terms($id, 'wpcode_tags', [ 'fields' => 'slugs' ]),
            'meta'     => $meta,
        ];
    }

    private function cached_ids(): array
    {
        $ids = [];
        foreach ((array) get_option('wpcode_snippets', []) as $list) {
            $ids = array_merge($ids, (array) $list);
        }
        return $ids;
    }

    /** @return array<int, array<string, mixed>> */
    private function audit(): array
    {
        return array_values(array_filter(Governance_Audit_Log::list(200), static fn (array $r): bool => WPCode_Pack::AUDIT_NAME === ($r['ability'] ?? '')));
    }

    private function open_php_gate(): void
    {
        add_filter('wpmcp_allow_php_exec', '__return_true');
        Php_Snippet_Guard::set_environment_override('local');
    }

    // ---------------------------------------------------------------
    // Catalog, tier and availability
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_wpcode_op_as_paid_tier_on_the_plugin_data_pair(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
        }
        $this->assertSame('WPCode', (new Plugin_Data_Integration())->operation_hosts()['wpcode-create-snippet']['name'] ?? null);

        Gate::set_pro_for_tests(false);
        $free = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (array_keys(self::OPS) as $name) {
            $this->assertArrayNotHasKey($name, $free, "{$name} is paid-tier");
        }
    }

    public function test_without_wpcode_every_op_names_the_plugin_to_install(): void
    {
        remove_all_filters('wpmcp_wpcode_active');
        add_filter('wpmcp_wpcode_active', '__return_false');
        add_filter('wpmcp_code_snippets_active', '__return_true');
        $out = $this->read('wpcode-list-snippets');
        remove_all_filters('wpmcp_code_snippets_active');

        $this->assertSame('wpcode_inactive', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('WPCode', $out['error']['message']);
        $this->assertStringContainsString('plugins.php', $out['error']['message']);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters(WPCode_Pack::OPT_IN_FILTER);
        $id = $this->seed('php', 'publish');
        foreach ([
            [ 'wpcode-create-snippet', [ 'title' => 'x', 'code' => self::NOOP, 'code_type' => 'php' ] ],
            [ 'wpcode-update-snippet', [ 'id' => $id, 'title' => 'x' ] ],
            [ 'wpcode-activate-snippet', [ 'id' => $id ] ],
            [ 'wpcode-deactivate-snippet', [ 'id' => $id ] ],
            [ 'wpcode-set-header-footer', [ 'header' => '' ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString(WPCode_Pack::OPT_IN_FILTER, (string) wp_json_encode($out), $op);
        }
        $this->assertSame('publish', get_post_status($id));
        $this->assertSame([], Snapshot_Store::recent(5));
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_snippets_filters_and_never_returns_code(): void
    {
        $php     = $this->seed('php', 'publish', [ 'tags' => [ 'perf' ] ]);
        $html    = $this->seed('html', 'draft');
        $trashed = $this->seed('js', 'trash');

        $all = $this->ok($this->read('wpcode-list-snippets'));
        $this->assertSame([ $php, $html ], array_column($all['result']['snippets'], 'id'), 'trashed snippets are left out unless asked for');
        $this->assertSame(2, $all['result']['total']);
        $this->assertArrayNotHasKey('code', $all['result']['snippets'][0]);
        $this->assertSame([ 'php', 'active', 'everywhere', [ 'perf' ], true, 10, '[wpcode id="' . $php . '"]' ], [
            $all['result']['snippets'][0]['code_type'], $all['result']['snippets'][0]['status'], $all['result']['snippets'][0]['location'],
            $all['result']['snippets'][0]['tags'], $all['result']['snippets'][0]['auto_insert'], $all['result']['snippets'][0]['priority'], $all['result']['snippets'][0]['shortcode'],
        ]);

        $this->assertSame([ $html ], array_column($this->ok($this->read('wpcode-list-snippets', [ 'status' => 'inactive' ]))['result']['snippets'], 'id'));
        $this->assertSame([ $php ], array_column($this->ok($this->read('wpcode-list-snippets', [ 'code_type' => 'php' ]))['result']['snippets'], 'id'));
        $this->assertSame([ $php ], array_column($this->ok($this->read('wpcode-list-snippets', [ 'tag' => 'perf' ]))['result']['snippets'], 'id'));
        $this->assertSame([ $trashed ], array_column($this->ok($this->read('wpcode-list-snippets', [ 'status' => 'trashed' ]))['result']['snippets'], 'id'));
        $paged = $this->ok($this->read('wpcode-list-snippets', [ 'per_page' => 1, 'page' => 2 ]));
        $this->assertSame([ $html ], array_column($paged['result']['snippets'], 'id'));
        $this->assertSame(2, $paged['result']['total']);
    }

    public function test_get_snippet_returns_code_settings_and_a_static_check_for_php(): void
    {
        $id = $this->seed('php');
        update_post_meta($id, '_wpcode_note', 'why it exists');
        update_post_meta($id, '_wpcode_last_error', [ 'message' => 'boom on line 2' ]);
        $out = $this->ok($this->read('wpcode-get-snippet', [ 'id' => $id ]));

        $this->assertSame(self::NOOP, $out['result']['snippet']['code']);
        $this->assertSame('why it exists', $out['result']['snippet']['note']);
        $this->assertSame('boom on line 2', $out['result']['snippet']['last_error']);
        $this->assertTrue($out['result']['validation']['syntax_valid']);

        $html = $this->ok($this->read('wpcode-get-snippet', [ 'id' => $this->seed('html') ]));
        $this->assertArrayNotHasKey('validation', $html['result']);

        $post = self::factory()->post->create();
        $this->assertNotEmpty($this->read('wpcode-get-snippet', [ 'id' => $post ])['error']['code'] ?? null, 'an ordinary post is not a snippet');
    }

    public function test_get_header_footer_reads_the_three_global_scripts(): void
    {
        update_option('ihaf_insert_header', '<meta name="x" content="1">');
        update_option('ihaf_insert_footer', '<script>window.a=1</script>');
        $out = $this->ok($this->read('wpcode-get-header-footer'));
        $this->assertSame([ 'header' => '<meta name="x" content="1">', 'body' => '', 'footer' => '<script>window.a=1</script>' ], $out['result']['header_footer']);
    }

    public function test_reads_need_wpcode_capability(): void
    {
        $editor = self::factory()->user->create([ 'role' => 'editor' ]);
        wp_set_current_user($editor);
        $this->assertNotEmpty($this->read('wpcode-list-snippets')['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // Create and update
    // ---------------------------------------------------------------

    public function test_create_stores_an_inactive_snippet_through_wpcode_and_rollback_trashes_it(): void
    {
        $out = $this->ok($this->write('wpcode-create-snippet', [
            'title'     => 'Disable emojis',
            'code'      => self::NOOP,
            'code_type' => 'php',
            'location'  => 'frontend_only',
            'priority'  => 5,
            'tags'      => [ 'perf', 'cleanup' ],
            'note'      => 'from MCP',
        ]));
        $snippet = $out['result']['snippet'];
        $id      = $snippet['id'];

        $this->assertSame('inactive', $snippet['status']);
        $this->assertSame('draft', get_post_status($id));
        $this->assertSame(self::NOOP, get_post_field('post_content', $id), 'backslashes survive WPCode\'s unslashing save');
        $this->assertSame([ 'php', 'frontend_only', 5 ], [ $snippet['code_type'], $snippet['location'], $snippet['priority'] ]);
        $this->assertEqualsCanonicalizing([ 'perf', 'cleanup' ], $snippet['tags']);
        $this->assertSame('from MCP', get_post_meta($id, '_wpcode_note', true));
        $this->assertSame(0, $GLOBALS['wpmcp_test_wpcode_executed'], 'an inactive snippet is never test-run');
        $this->assertTrue($out['result']['validation']['syntax_valid']);

        foreach ((array) $out['operation_ids'] as $operation) {
            $this->assertTrue(Rollback_Service::restore_operation((string) $operation));
        }
        $this->assertSame('trash', get_post_status($id));
    }

    public function test_create_refuses_unsafe_php_raw_code_without_unfiltered_html_and_unknown_locations(): void
    {
        $this->assertSame('validation_failed', $this->write('wpcode-create-snippet', [ 'title' => 'x', 'code' => 'eval($_GET["x"]);', 'code_type' => 'php' ])['error']['code'] ?? null);
        $this->assertSame('unknown_location', $this->write('wpcode-create-snippet', [ 'title' => 'x', 'code' => '<p>x</p>', 'code_type' => 'html', 'location' => 'nowhere' ])['error']['code'] ?? null);
        $this->assertNotEmpty($this->write('wpcode-create-snippet', [ 'title' => 'x', 'code' => '<p>x</p>', 'code_type' => 'universal' ])['error']['code'] ?? null, 'universal snippets are not created here');

        add_filter('map_meta_cap', [ self::class, 'deny_unfiltered_html' ], 10, 2);
        $out = $this->write('wpcode-create-snippet', [ 'title' => 'x', 'code' => '<script>1</script>', 'code_type' => 'js' ]);
        remove_filter('map_meta_cap', [ self::class, 'deny_unfiltered_html' ], 10);
        $this->assertSame('operation_denied', $out['error']['code'] ?? null);
        $this->assertSame('unfiltered_html', $out['error']['data']['capability'] ?? null);

        $this->assertSame(0, (int) (new \WP_Query([ 'post_type' => 'wpcode', 'post_status' => 'any', 'fields' => 'ids' ]))->found_posts);
    }

    /** @param string[] $caps */
    public static function deny_unfiltered_html(array $caps, string $cap): array
    {
        return 'unfiltered_html' === $cap ? [ 'do_not_allow' ] : $caps;
    }

    public function test_update_changes_an_inactive_snippet_keeps_wpcode_settings_and_rolls_back_exactly(): void
    {
        $id = $this->seed('php');
        update_post_meta($id, '_wpcode_compress_output', true);
        $before = $this->image($id);

        $new = "\$wpmcp_wpcode_noop = 2; // C:\\path";
        $out = $this->ok($this->write('wpcode-update-snippet', [ 'id' => $id, 'title' => 'Renamed', 'code' => $new, 'tags' => [ 'beta' ], 'priority' => 20, 'location' => 'admin_only' ]));

        $this->assertSame('Renamed', get_post_field('post_title', $id));
        $this->assertSame($new, get_post_field('post_content', $id));
        $this->assertSame([ 'beta' ], $out['result']['snippet']['tags']);
        $this->assertSame([ 20, 'admin_only', 'inactive' ], [ $out['result']['snippet']['priority'], $out['result']['snippet']['location'], $out['result']['snippet']['status'] ]);
        $this->assertSame('1', get_post_meta($id, '_wpcode_compress_output', true), 'WPCode\'s save would drop compress output unless it is passed again');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $after = $this->image($id);
        unset($before['meta']['_wp_old_slug'], $after['meta']['_wp_old_slug']);
        $this->assertSame($before, $after);
    }

    public function test_update_refuses_active_unsupported_and_failing_snippets(): void
    {
        $active    = $this->seed('php', 'publish');
        $universal = $this->seed('universal');
        $php       = $this->seed('php');

        $this->assertSame('snippet_active', $this->write('wpcode-update-snippet', [ 'id' => $active, 'title' => 'x' ])['error']['code'] ?? null);
        $this->assertSame('unsupported_snippet_type', $this->write('wpcode-update-snippet', [ 'id' => $universal, 'title' => 'x' ])['error']['code'] ?? null);
        $this->assertSame('validation_failed', $this->write('wpcode-update-snippet', [ 'id' => $php, 'code' => 'system($_GET["c"]);' ])['error']['code'] ?? null);
        $this->assertSame('snippet_not_found', $this->write('wpcode-update-snippet', [ 'id' => 999999, 'title' => 'x' ])['error']['code'] ?? null);
        $this->assertSame(self::NOOP, get_post_field('post_content', $php));
        $this->assertSame([], Snapshot_Store::recent(5));
    }

    // ---------------------------------------------------------------
    // Activation and deactivation
    // ---------------------------------------------------------------

    public function test_php_activation_is_refused_and_audited_while_php_execution_is_disabled(): void
    {
        $id  = $this->seed('php');
        $out = $this->write('wpcode-activate-snippet', [ 'id' => $id ]);

        $this->assertSame('opt_in_required', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertSame('draft', get_post_status($id));
        $this->assertSame(0, $GLOBALS['wpmcp_test_wpcode_executed']);
        $this->assertSame([], Snapshot_Store::recent(5));
        $this->assertFalse((bool) ($this->audit()[0]['allowed'] ?? true));

        Php_Snippet_Guard::set_environment_override('production');
        add_filter('wpmcp_allow_php_exec', '__return_true');
        $this->assertSame('php_execution_disabled', $this->write('wpcode-activate-snippet', [ 'id' => $id ])['error']['code'] ?? null);
    }

    public function test_php_activation_runs_through_wpcode_is_audited_and_rollback_deactivates(): void
    {
        $this->open_php_gate();
        $id     = $this->seed('php');
        $before = $this->image($id);
        $out    = $this->ok($this->write('wpcode-activate-snippet', [ 'id' => $id ]));

        $this->assertSame('active', $out['result']['snippet']['status']);
        $this->assertSame('publish', get_post_status($id));
        $this->assertSame(1, $GLOBALS['wpmcp_test_wpcode_executed'], 'WPCode test-runs PHP once while activating it');
        $this->assertContains($id, $this->cached_ids(), 'WPCode\'s cache of active snippets holds it');
        $this->assertTrue((bool) ($this->audit()[0]['allowed'] ?? false));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('draft', get_post_status($id));
        $this->assertNotContains($id, $this->cached_ids(), 'the undo rebuilt WPCode\'s cache');
        $after = $this->image($id);
        unset($before['meta']['_wp_old_slug'], $after['meta']['_wp_old_slug']);
        $this->assertSame($before, $after);
    }

    public function test_php_activation_refuses_code_wpcode_reports_an_error_for_and_code_failing_the_check(): void
    {
        $this->open_php_gate();
        $failing = $this->seed('php', 'draft', [ 'code' => '$x = 1; wpmcp_wpcode_fail();' ]);
        $out     = $this->write('wpcode-activate-snippet', [ 'id' => $failing ]);
        $this->assertSame('activation_failed', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('wpmcp_wpcode_fail', $out['error']['message']);
        $this->assertSame('draft', get_post_status($failing));

        $unsafe = $this->seed('php', 'draft', [ 'code' => 'eval($_GET["x"]);' ]);
        $this->assertSame('validation_failed', $this->write('wpcode-activate-snippet', [ 'id' => $unsafe ])['error']['code'] ?? null);
        $this->assertSame('snippet_active', $this->write('wpcode-activate-snippet', [ 'id' => $this->seed('php', 'publish') ])['error']['code'] ?? null);
        $this->assertSame('unsupported_snippet_type', $this->write('wpcode-activate-snippet', [ 'id' => $this->seed('universal') ])['error']['code'] ?? null);
        $this->assertSame('snippet_trashed', $this->write('wpcode-activate-snippet', [ 'id' => $this->seed('php', 'trash') ])['error']['code'] ?? null);
    }

    public function test_raw_code_activation_needs_the_injection_gate_and_unfiltered_html(): void
    {
        $id = $this->seed('html');
        $this->assertSame('opt_in_required', $this->write('wpcode-activate-snippet', [ 'id' => $id ])['error']['code'] ?? null);
        $this->assertSame('draft', get_post_status($id));

        add_filter('wpmcp_allow_js_injection', '__return_true');
        add_filter('map_meta_cap', [ self::class, 'deny_unfiltered_html' ], 10, 2);
        $this->assertSame('operation_denied', $this->write('wpcode-activate-snippet', [ 'id' => $id ])['error']['code'] ?? null);
        remove_filter('map_meta_cap', [ self::class, 'deny_unfiltered_html' ], 10);

        $out = $this->ok($this->write('wpcode-activate-snippet', [ 'id' => $id ]));
        $this->assertSame('active', $out['result']['snippet']['status']);
        $this->assertSame(0, $GLOBALS['wpmcp_test_wpcode_executed'], 'HTML is never test-run');
        $this->assertContains($id, $this->cached_ids());
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('draft', get_post_status($id));
    }

    public function test_deactivate_switches_any_snippet_off_and_its_undo_leaves_it_off(): void
    {
        $id = $this->seed('universal', 'publish');
        wpcode()->cache->cache_all_loaded_snippets();
        $this->assertContains($id, $this->cached_ids());

        $out = $this->ok($this->write('wpcode-deactivate-snippet', [ 'id' => $id ]));
        $this->assertSame('inactive', $out['result']['snippet']['status']);
        $this->assertNotContains($id, $this->cached_ids());
        $this->assertSame('snippet_inactive', $this->write('wpcode-deactivate-snippet', [ 'id' => $id ])['error']['code'] ?? null);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('draft', get_post_status($id), 'an undo never switches a snippet back on: activation goes through the gated op');
        $this->assertNotContains($id, $this->cached_ids());
    }

    // ---------------------------------------------------------------
    // Global header and footer
    // ---------------------------------------------------------------

    public function test_set_header_footer_needs_the_injection_gate_writes_only_the_areas_given_and_rolls_back(): void
    {
        update_option('ihaf_insert_header', '<meta name="old">');
        update_option('ihaf_insert_footer', '<script>old()</script>');

        $this->assertSame('opt_in_required', $this->write('wpcode-set-header-footer', [ 'header' => '<meta name="new">' ])['error']['code'] ?? null);
        $this->assertSame('<meta name="old">', get_option('ihaf_insert_header'));

        add_filter('wpmcp_allow_js_injection', '__return_true');
        $out = $this->ok($this->write('wpcode-set-header-footer', [ 'header' => '<meta name="new">', 'body' => '<noscript>x</noscript>' ]));
        $this->assertSame([ 'header' => '<meta name="new">', 'body' => '<noscript>x</noscript>', 'footer' => '<script>old()</script>' ], $out['result']['header_footer']);
        $this->assertGreaterThan(0, (int) ($GLOBALS['wpmcp_test_wpcode_page_cache_cleared'] ?? 0));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('<meta name="old">', get_option('ihaf_insert_header'));
        $this->assertFalse(get_option('ihaf_insert_body'), 'an area that did not exist is removed again');
        $this->assertSame('<script>old()</script>', get_option('ihaf_insert_footer'));
    }
}
