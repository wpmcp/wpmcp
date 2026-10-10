<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\Handshake_Settings_Page;
use WPMCP\MCP\Handshake_Instructions;
use WPMCP\Plugin;

/**
 * The admin-editable half of issue #80: a settings screen backed by the
 * wpmcp_handshake_instructions option, registered through the Settings API
 * with a sanitize callback that strips markup and clamps length.
 */
class HandshakeSettingsTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Handshake_Instructions::OPTION);
    }

    protected function tearDown(): void
    {
        delete_option(Handshake_Instructions::OPTION);
        parent::tearDown();
    }

    public function test_handshake_submenu_is_registered_under_the_management_capability(): void
    {
        global $menu, $submenu;
        $menu    = [];
        $submenu = [];

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        Plugin::instance()->register_admin_menu();

        $found = null;
        foreach ($submenu['wpmcp'] ?? [] as $item) {
            // $item = [menu_title, capability, menu_slug, page_title, ...]
            if ('wpmcp-handshake' === $item[2]) {
                $found = $item;
                break;
            }
        }

        $this->assertNotNull($found, 'Expected a wpmcp-handshake submenu entry.');
        $this->assertSame(\WPMCP\Admin\Management_Access::CAPABILITY, $found[1]);
    }

    public function test_setting_is_registered_with_a_sanitizing_callback(): void
    {
        Handshake_Settings_Page::register_setting();

        $sanitized = sanitize_option(
            Handshake_Instructions::OPTION,
            "<script>alert(1)</script>Keep this line.\nAnd this one."
        );

        $this->assertStringNotContainsString('<script', $sanitized);
        $this->assertStringNotContainsString('alert(1)', $sanitized);
        $this->assertStringContainsString('Keep this line.', $sanitized);
        $this->assertStringContainsString("\n", $sanitized, 'Newlines in admin guidance must survive sanitization.');
    }

    public function test_sanitize_clamps_oversized_input_and_rejects_non_strings(): void
    {
        $this->assertSame(
            Handshake_Instructions::MAX_ADMIN_LENGTH,
            mb_strlen(Handshake_Settings_Page::sanitize(str_repeat('y', 50000)))
        );
        $this->assertSame('', Handshake_Settings_Page::sanitize(['not' => 'a string']));
        $this->assertSame('', Handshake_Settings_Page::sanitize(null));
    }

    public function test_render_outputs_an_escaped_textarea_with_the_saved_value(): void
    {
        update_option(Handshake_Instructions::OPTION, 'Ask before publishing & keep drafts.');

        ob_start();
        (new Handshake_Settings_Page())->render();
        $html = ob_get_clean();

        $this->assertStringContainsString(Handshake_Instructions::OPTION, $html);
        $this->assertStringContainsString('<textarea', $html);
        $this->assertStringContainsString('Ask before publishing &amp; keep drafts.', $html);
    }
}
