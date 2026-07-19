<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\Connection_Page;
use WPMCP\Connect\Exposure;
use WPMCP\Plugin;

/**
 * Issue #76: the Connection admin screen — the first-ten-minutes experience.
 * One nonce-protected admin action provisions a core Application Password
 * for a chosen user and returns filled client configs; the same screen
 * revokes it (disconnecting the client), flips the master exposure switch,
 * runs the server-side self-test, and serves the secret-free desktop
 * bundle. manage_options everywhere; the plaintext password exists only in
 * the provision response — the stored ledger holds UUIDs, never secrets.
 */
class ConnectionPageTest extends \WP_UnitTestCase
{
    private int $admin_id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin_id);
        add_filter('wp_is_application_passwords_available', '__return_true');
        delete_option(Connection_Page::OPTION);
        delete_option(Exposure::OPTION);
    }

    protected function tearDown(): void
    {
        remove_filter('wp_is_application_passwords_available', '__return_true');
        delete_option(Connection_Page::OPTION);
        delete_option(Exposure::OPTION);
        parent::tearDown();
    }

    private function post(string $action, array $extra = []): array
    {
        return array_merge([
            'wpmcp_connection_action' => $action,
            '_wpnonce'                => wp_create_nonce(Connection_Page::NONCE_ACTION),
        ], $extra);
    }

    public function test_connection_submenu_is_registered_under_manage_options(): void
    {
        global $menu, $submenu;
        $menu    = [];
        $submenu = [];

        Plugin::instance()->register_admin_menu();

        $found = null;
        foreach ($submenu['wpmcp'] ?? [] as $item) {
            if (Connection_Page::SLUG === $item[2]) {
                $found = $item;
            }
        }

        $this->assertNotNull($found, 'Expected a wpmcp-connection submenu entry.');
        $this->assertSame('manage_options', $found[1]);
    }

    public function test_provision_creates_an_application_password_and_returns_filled_configs(): void
    {
        $result = (new Connection_Page())->handle_request(
            $this->post('provision', ['user_id' => $this->admin_id, 'name' => 'Claude Code'])
        );

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('error', $result);
        $this->assertNotEmpty($result['password']);
        $this->assertNotEmpty($result['uuid']);

        $login = get_userdata($this->admin_id)->user_login;
        foreach (['claude-code', 'claude-desktop', 'cursor', 'vscode', 'generic'] as $id) {
            $this->assertStringContainsString(
                base64_encode($login . ':' . $result['password']),
                $result['configs'][$id]['snippet'],
                "Config for '$id' must be prefilled with the real credential."
            );
        }

        $this->assertCount(1, \WP_Application_Passwords::get_user_application_passwords($this->admin_id));
    }

    public function test_the_stored_ledger_records_the_uuid_but_never_the_secret(): void
    {
        $result = (new Connection_Page())->handle_request(
            $this->post('provision', ['user_id' => $this->admin_id, 'name' => 'Ledger check'])
        );

        $records = get_option(Connection_Page::OPTION);
        $this->assertCount(1, $records);
        $this->assertSame($result['uuid'], $records[0]['uuid']);
        $this->assertSame($this->admin_id, $records[0]['user_id']);
        $this->assertStringNotContainsString(
            $result['password'],
            (string) wp_json_encode($records),
            'The one-time password must never be persisted.'
        );
    }

    public function test_provision_requires_a_valid_nonce(): void
    {
        $result = (new Connection_Page())->handle_request([
            'wpmcp_connection_action' => 'provision',
            '_wpnonce'                => 'bogus',
            'user_id'                 => $this->admin_id,
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], \WP_Application_Passwords::get_user_application_passwords($this->admin_id));
    }

    public function test_provision_requires_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $result = (new Connection_Page())->handle_request(
            $this->post('provision', ['user_id' => $this->admin_id])
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], \WP_Application_Passwords::get_user_application_passwords($this->admin_id));
    }

    public function test_provision_surfaces_core_unavailability_instead_of_bypassing_it(): void
    {
        remove_filter('wp_is_application_passwords_available', '__return_true');
        add_filter('wp_is_application_passwords_available', '__return_false');

        $result = (new Connection_Page())->handle_request(
            $this->post('provision', ['user_id' => $this->admin_id])
        );

        remove_filter('wp_is_application_passwords_available', '__return_false');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], \WP_Application_Passwords::get_user_application_passwords($this->admin_id));
    }

    public function test_revoke_deletes_the_application_password_and_its_ledger_record(): void
    {
        $page = new Connection_Page();
        $made = $page->handle_request(
            $this->post('provision', ['user_id' => $this->admin_id, 'name' => 'To revoke'])
        );

        $result = $page->handle_request(
            $this->post('revoke', ['user_id' => $this->admin_id, 'uuid' => $made['uuid']])
        );

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(
            [],
            \WP_Application_Passwords::get_user_application_passwords($this->admin_id),
            'Revoking must invalidate the credential so the client is disconnected.'
        );
        $this->assertSame([], get_option(Connection_Page::OPTION));
    }

    public function test_toggle_flips_the_master_exposure_switch(): void
    {
        $page = new Connection_Page();

        $page->handle_request($this->post('toggle', ['enabled' => '0']));
        $this->assertFalse(Exposure::is_enabled());

        $page->handle_request($this->post('toggle', ['enabled' => '1']));
        $this->assertTrue(Exposure::is_enabled());
    }

    public function test_self_test_action_reports_endpoint_reachability(): void
    {
        add_filter('pre_http_request', static fn () => [
            'response' => ['code' => 401, 'message' => 'Unauthorized'],
            'headers'  => [],
            'body'     => '',
        ]);

        $result = (new Connection_Page())->handle_request($this->post('self_test'));

        $this->assertTrue($result['self_test']['ok']);
        $this->assertSame(401, $result['self_test']['status']);
    }

    public function test_render_shows_endpoint_exposure_state_and_least_privilege_guidance(): void
    {
        set_current_screen('admin_page_' . Connection_Page::SLUG);

        ob_start();
        (new Connection_Page())->render();
        $html = ob_get_clean();

        $this->assertStringContainsString('/wp-json/mcp/wpmcp-server', $html);
        $this->assertStringContainsString('least privilege', strtolower($html));
        $this->assertStringContainsString('wpmcp_connection_action', $html);
    }

    public function test_render_reveals_a_fresh_password_once_and_escapes_user_input(): void
    {
        $_POST = $this->post('provision', [
            'user_id' => $this->admin_id,
            'name'    => 'Reveal <b>once</b>',
        ]);

        set_current_screen('admin_page_' . Connection_Page::SLUG);
        ob_start();
        (new Connection_Page())->render();
        $html  = ob_get_clean();
        $_POST = [];

        $passwords = \WP_Application_Passwords::get_user_application_passwords($this->admin_id);
        $this->assertCount(1, $passwords);
        $this->assertStringNotContainsString('<b>once</b>', $html, 'User-supplied names must be escaped.');

        // A plain re-render (no POST) must not resurface any secret.
        ob_start();
        (new Connection_Page())->render();
        $second = ob_get_clean();
        $this->assertStringNotContainsString('shown only once', strtolower($second));
    }

    public function test_download_bundle_rejects_a_bad_nonce(): void
    {
        $_GET['_wpnonce'] = 'bogus';
        $sent             = null;

        $result = (new Connection_Page())->download_bundle(function ($path) use (&$sent) {
            $sent = $path;
        });

        unset($_GET['_wpnonce']);
        $this->assertWPError($result);
        $this->assertNull($sent, 'No bundle may be built or sent on a failed nonce check.');
    }

    public function test_download_bundle_rejects_non_admins(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $_GET['_wpnonce'] = wp_create_nonce(Connection_Page::NONCE_ACTION);
        $sent             = null;

        $result = (new Connection_Page())->download_bundle(function ($path) use (&$sent) {
            $sent = $path;
        });

        unset($_GET['_wpnonce']);
        $this->assertWPError($result);
        $this->assertNull($sent);
    }

    public function test_download_bundle_streams_a_built_bundle_for_an_authorized_admin(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('The zip extension is unavailable.');
        }

        $_GET['_wpnonce'] = wp_create_nonce(Connection_Page::NONCE_ACTION);
        $sent             = null;

        (new Connection_Page())->download_bundle(function ($path) use (&$sent) {
            $sent = $path;
        });

        unset($_GET['_wpnonce']);
        $this->assertNotNull($sent);
        $this->assertFileExists($sent);
        $this->assertStringEndsWith('.mcpb', $sent);
        unlink($sent);
    }
}
