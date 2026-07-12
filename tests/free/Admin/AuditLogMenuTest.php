<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Plugin;

class AuditLogMenuTest extends \WP_UnitTestCase
{
    public function test_audit_log_submenu_is_registered_under_manage_options(): void
    {
        global $menu, $submenu;
        $menu    = [];
        $submenu = [];

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        Plugin::instance()->register_admin_menu();

        $this->assertArrayHasKey('wpmcp', $submenu);

        $found = null;
        foreach ($submenu['wpmcp'] as $item) {
            // $item = [menu_title, capability, menu_slug, page_title, ...]
            if ('wpmcp-audit-log' === $item[2]) {
                $found = $item;
                break;
            }
        }

        $this->assertNotNull($found, 'Expected a wpmcp-audit-log submenu entry.');
        $this->assertSame('manage_options', $found[1]);
    }
}
