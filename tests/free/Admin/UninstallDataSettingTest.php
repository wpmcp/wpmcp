<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\Connection_Page;
use WPMCP\Data_Registry;

/**
 * The "Delete all WP MCP data when the plugin is deleted" setting on the
 * Connection screen (issue #480). Off by default, saved with the screen's
 * nonce and capability checks, read-only while WPMCP_UNINSTALL_DELETE_DATA
 * is defined, and the help text says plainly which way data goes.
 */
class UninstallDataSettingTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        delete_site_option(Data_Registry::SETTING);
        Data_Registry::reset_for_tests();
    }

    protected function tearDown(): void
    {
        delete_site_option(Data_Registry::SETTING);
        Data_Registry::reset_for_tests();
        parent::tearDown();
    }

    private function post(string $value, ?string $nonce = null): array
    {
        return [
            'wpmcp_connection_action' => 'uninstall_data',
            '_wpnonce'                => $nonce ?? wp_create_nonce(Connection_Page::NONCE_ACTION),
            'delete'                  => $value,
        ];
    }

    public function test_saving_turns_the_setting_on_and_off(): void
    {
        $result = (new Connection_Page())->handle_request($this->post('1'));
        $this->assertSame([ 'action' => 'uninstall_data', 'delete' => true ], $result);
        $this->assertTrue(Data_Registry::delete_on_uninstall());

        $result = (new Connection_Page())->handle_request($this->post('0'));
        $this->assertSame([ 'action' => 'uninstall_data', 'delete' => false ], $result);
        $this->assertFalse(Data_Registry::delete_on_uninstall());
    }

    public function test_saving_requires_a_valid_nonce(): void
    {
        $result = (new Connection_Page())->handle_request($this->post('1', 'bad'));

        $this->assertArrayHasKey('error', $result);
        $this->assertFalse(Data_Registry::delete_on_uninstall());
    }

    public function test_saving_requires_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));

        $result = (new Connection_Page())->handle_request($this->post('1'));

        $this->assertArrayHasKey('error', $result);
        $this->assertFalse(Data_Registry::delete_on_uninstall());
    }

    public function test_saving_is_refused_while_the_constant_is_defined(): void
    {
        Data_Registry::set_constant_for_tests(true, false);

        $result = (new Connection_Page())->handle_request($this->post('1'));

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('WPMCP_UNINSTALL_DELETE_DATA', $result['error']);
        $this->assertFalse((bool) get_site_option(Data_Registry::SETTING));
    }

    public function test_render_says_data_is_kept_by_default(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('Delete all WP MCP data when the plugin is deleted', $html);
        $this->assertStringContainsString('is kept when the plugin is deleted', $html);
        $this->assertStringContainsString('name="wpmcp_connection_action" value="uninstall_data"', $html);
    }

    public function test_render_warns_what_will_be_deleted_when_on(): void
    {
        Data_Registry::set_delete_on_uninstall(true);

        $html = $this->render();

        $this->assertStringContainsString('backup archives', $html);
        $this->assertStringContainsString('cannot be undone', $html);
    }

    public function test_render_is_read_only_while_the_constant_is_defined(): void
    {
        Data_Registry::set_constant_for_tests(true, true);

        $html = $this->render();

        $this->assertStringContainsString('WPMCP_UNINSTALL_DELETE_DATA', $html);
        $this->assertStringNotContainsString('value="uninstall_data"', $html);
    }

    private function render(): string
    {
        $_POST = [];
        ob_start();
        (new Connection_Page())->render();
        return (string) ob_get_clean();
    }
}
