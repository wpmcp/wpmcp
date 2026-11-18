<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Hardening_Pack;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\MCP\Tool_Search;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Security\Hardening_Audit;
use WPMCP\Tools\Security\Hardening_Settings;

/**
 * Apply the hardening fixes scan-security reports, each undoable (issue
 * #847): get-hardening-status and set-hardening on the theme dispatcher
 * pair, so no top-level tool is added.
 *
 * Every setting is stored in one WP MCP option and applied by WP MCP's own
 * hooks (no wp-config.php or .htaccess edits). Each test switches a setting
 * on through set-hardening, proves the effect on the request WordPress
 * would serve, then undoes it with rollback-operation and proves the effect
 * is gone.
 */
class HardeningPackTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $GLOBALS['wpdb']->query('DELETE FROM ' . Snapshot_Store::table_name());
        delete_option(Hardening_Settings::OPTION);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        remove_all_filters(Hardening_Pack::ENABLE_FILTER);
        remove_all_filters('wpmcp_hardening_handled_elsewhere');
        Gate::set_pro_for_tests(null);
        delete_option(Hardening_Settings::OPTION);
        parent::tearDown();
    }

    private function enable_writes(): void
    {
        add_filter(Hardening_Pack::ENABLE_FILTER, '__return_true');
    }

    private function catalog(): array
    {
        return array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
    }

    private function status(): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => 'get-hardening-status', 'args' => [] ]);
    }

    private function set(array $settings): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => 'set-hardening', 'args' => [ 'settings' => $settings ] ]);
    }

    private function undo(array $out): void
    {
        $this->assertArrayHasKey('operation_id', $out, 'every hardening write is an undo point: ' . wp_json_encode($out));
        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
    }

    private function on(string $setting): array
    {
        $this->enable_writes();
        $out = $this->set([ $setting => true ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertTrue(Hardening_Settings::enabled($setting));
        return $out;
    }

    // ---------------------------------------------------------------
    // Catalog and opt-in
    // ---------------------------------------------------------------

    public function test_both_ops_are_free_ops_on_the_theme_pair(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = $this->catalog();

        $this->assertSame('read', $ops['get-hardening-status']['mode']);
        $this->assertSame('write', $ops['set-hardening']['mode']);
        $this->assertSame('manage_options', $ops['get-hardening-status']['capability']);
        $this->assertSame('manage_options', $ops['set-hardening']['capability']);
        $this->assertTrue($ops['get-hardening-status']['enabled']);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $this->assertFalse($this->catalog()['set-hardening']['enabled']);

        $out = $this->set([ 'disable_xmlrpc' => true ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? '');
        $this->assertStringContainsString(Hardening_Pack::ENABLE_FILTER, $out['error']['message']);
        $this->assertFalse(Hardening_Settings::enabled('disable_xmlrpc'));
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_tool_search_finds_the_ops(): void
    {
        [ $read, $write ] = (new Theme_Integration())->abilities();

        $this->assertContains('get-hardening-status', Tool_Search::match($read, 'hardening'));
        $this->assertContains('set-hardening', Tool_Search::match($write, 'hardening'));
    }

    public function test_a_write_that_changes_nothing_is_refused_without_an_undo_point(): void
    {
        $this->enable_writes();
        $out = $this->set([ 'disable_xmlrpc' => false ]);

        $this->assertSame('no_change', $out['error']['code'] ?? '');
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_an_unknown_setting_is_refused(): void
    {
        $this->enable_writes();
        $out = $this->set([ 'disable_everything' => true ]);

        $this->assertArrayHasKey('error', $out);
        $this->assertFalse(get_option(Hardening_Settings::OPTION));
    }

    // ---------------------------------------------------------------
    // Status
    // ---------------------------------------------------------------

    public function test_status_lists_every_setting_with_its_audit_finding(): void
    {
        $out = $this->status()['result'];

        $this->assertSame(Hardening_Settings::SETTINGS, array_keys($out['settings']));
        foreach ($out['settings'] as $name => $row) {
            $this->assertFalse($row['enabled'], $name);
            $this->assertArrayHasKey('handled_elsewhere', $row, $name);
            $this->assertArrayHasKey('audit_finding', $row, $name);
        }
        $this->assertSame('harden_xmlrpc', $out['settings']['disable_xmlrpc']['audit_finding']);
        $this->assertArrayHasKey('security_plugins', $out);
        $this->assertFalse($out['writes_enabled']);
    }

    public function test_enabling_a_setting_another_layer_already_handles_is_refused(): void
    {
        $this->enable_writes();
        add_filter(
            'wpmcp_hardening_handled_elsewhere',
            static fn (array $handled): array => [ 'disable_file_editor' => 'wp-config.php (DISALLOW_FILE_EDIT)' ] + $handled
        );

        $this->assertSame('wp-config.php (DISALLOW_FILE_EDIT)', $this->status()['result']['settings']['disable_file_editor']['handled_elsewhere']);

        $out = $this->set([ 'disable_file_editor' => true ]);
        $this->assertSame('handled_elsewhere', $out['error']['code'] ?? '');
        $this->assertFalse(Hardening_Settings::enabled('disable_file_editor'));
    }

    // ---------------------------------------------------------------
    // Each setting: on, verified, undone
    // ---------------------------------------------------------------

    public function test_disable_xmlrpc_turns_off_the_api_and_pingbacks_then_undoes(): void
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter.
        $this->assertTrue((bool) apply_filters('xmlrpc_enabled', true));

        $out = $this->on('disable_xmlrpc');
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter.
        $this->assertFalse((bool) apply_filters('xmlrpc_enabled', true));
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter.
        $this->assertSame([], apply_filters('xmlrpc_methods', [ 'pingback.ping' => 'this:pingback_ping' ]));
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter.
        $this->assertArrayNotHasKey('X-Pingback', apply_filters('wp_headers', [ 'X-Pingback' => 'https://example.org/xmlrpc.php' ], null));

        $this->undo($out);
        $this->assertFalse(Hardening_Settings::enabled('disable_xmlrpc'));
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter.
        $this->assertTrue((bool) apply_filters('xmlrpc_enabled', true));
    }

    public function test_hide_wp_version_removes_the_generator_from_markup_and_feeds_then_undoes(): void
    {
        $this->assertStringContainsString('WordPress', get_echo('wp_generator'));

        $out = $this->on('hide_wp_version');
        $this->assertSame('', trim(get_echo('wp_generator')));
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter, as the_generator() applies it in feeds.
        $this->assertSame('', (string) apply_filters('the_generator', get_the_generator('rss2'), 'rss2'));

        $this->undo($out);
        $this->assertStringContainsString('WordPress', get_echo('wp_generator'));
    }

    public function test_block_user_enumeration_hides_users_from_visitors_only_then_undoes(): void
    {
        $admin = get_current_user_id();
        $out   = $this->on('block_user_enumeration');

        wp_set_current_user(0);
        $response = rest_do_request(new \WP_REST_Request('GET', '/wp/v2/users'));
        $this->assertSame(401, $response->get_status(), 'visitors cannot list users');
        $response = rest_do_request(new \WP_REST_Request('GET', '/wp/v2/users/' . $admin));
        $this->assertSame(401, $response->get_status(), 'visitors cannot read one user');
        $this->assertSame([ 'error' => '404' ], Hardening_Settings::filter_request([ 'author' => (string) $admin ], [ 'author' => (string) $admin ]));
        $this->assertFalse((bool) apply_filters('wp_sitemaps_add_provider', new \stdClass(), 'users'));

        // The block editor lists users for a logged-in author.
        wp_set_current_user($admin);
        $request = new \WP_REST_Request('GET', '/wp/v2/users');
        $request->set_param('context', 'edit');
        $this->assertSame(200, rest_do_request($request)->get_status(), 'logged-in users keep the users route');
        $this->assertSame([ 'author' => '1' ], Hardening_Settings::filter_request([ 'author' => '1' ], [ 'author' => '1' ]));

        $this->undo($out);
        wp_set_current_user(0);
        $this->assertSame(200, rest_do_request(new \WP_REST_Request('GET', '/wp/v2/users'))->get_status());
    }

    public function test_hide_php_errors_turns_display_errors_off_then_undoes(): void
    {
        // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed -- the test restores what it changed.
        $before = ini_get('display_errors');
        // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed -- arranges a site that shows errors.
        ini_set('display_errors', '1');
        try {
            $out = $this->on('hide_php_errors');
            Hardening_Settings::apply_error_display();
            $this->assertContains(ini_get('display_errors'), [ '0', '', 'Off', 'off' ]);
            $this->assertSame('pass', (new Hardening_Audit())->debug_display_finding()['status']);

            $this->undo($out);
            $this->assertFalse(Hardening_Settings::enabled('hide_php_errors'));
        } finally {
            // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed -- restores the runner's setting.
            ini_set('display_errors', (string) $before);
        }
    }

    public function test_disable_file_editor_denies_the_editor_capabilities_then_undoes(): void
    {
        if (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) {
            $this->markTestSkipped('The runner already defines DISALLOW_FILE_EDIT.');
        }
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        $this->assertTrue(current_user_can('edit_plugins'));

        $out = $this->on('disable_file_editor');
        $this->assertFalse(current_user_can('edit_plugins'));
        $this->assertFalse(current_user_can('edit_themes'));
        $this->assertFalse(current_user_can('edit_files'));
        $this->assertTrue(current_user_can('activate_plugins'), 'only the editor goes');
        $this->assertSame('pass', (new Hardening_Audit())->evaluate_file_edit(Hardening_Audit::file_edit_disallowed())['status']);

        $this->undo($out);
        $this->assertTrue(current_user_can('edit_plugins'));
    }

    public function test_security_headers_are_added_without_overriding_existing_ones_then_undone(): void
    {
        $out     = $this->on('security_headers');
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter.
        $headers = apply_filters('wp_headers', [ 'X-Frame-Options' => 'DENY' ], null);

        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('DENY', $headers['X-Frame-Options'], 'a header the site already sends is kept');
        $this->assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);

        $this->undo($out);
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter.
        $this->assertArrayNotHasKey('X-Content-Type-Options', apply_filters('wp_headers', [], null));
    }

    public function test_every_setting_on_keeps_the_mcp_endpoint_and_the_editor_routes_working(): void
    {
        $this->enable_writes();
        $out = $this->set(array_fill_keys(Hardening_Settings::SETTINGS, true));
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertSame(Hardening_Settings::SETTINGS, array_keys(array_filter(Hardening_Settings::all())));

        $routes = rest_get_server()->get_routes();
        $mcp    = array_filter(array_keys($routes), static fn (string $route): bool => 0 === strpos($route, '/wpmcp/'));
        $this->assertNotEmpty($mcp, 'the MCP routes stay registered');

        foreach ([ '/wp/v2/users/me', '/wp/v2/types', '/wp/v2/settings' ] as $route) {
            $request = new \WP_REST_Request('GET', $route);
            $request->set_param('context', 'edit');
            $this->assertSame(200, rest_do_request($request)->get_status(), $route . ' must keep answering the block editor');
        }

        // One undo point for the whole call.
        $this->undo($out);
        $this->assertSame([], array_filter(Hardening_Settings::all()));
    }
}
