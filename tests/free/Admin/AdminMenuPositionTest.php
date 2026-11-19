<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Plugin;

/**
 * Issue #1026: add_menu_page() was called without a position, so WordPress
 * appended the wpmcp menu after every other top-level item. It now sits at a
 * fixed decimal slot just below Settings (80), so other plugins' integer
 * positions (81, 85, 90 ...) never collide with it.
 */
class AdminMenuPositionTest extends \WP_UnitTestCase
{
    /** @return array<int|string, array<int, mixed>> */
    private function register(): array
    {
        global $menu, $submenu;
        $menu    = [];
        $submenu = [];

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        Plugin::instance()->register_admin_menu();

        return (array) $menu;
    }

    /** @param array<int|string, array<int, mixed>> $menu */
    private function wpmcp_key(array $menu)
    {
        foreach ($menu as $key => $item) {
            if ('wpmcp' === $item[2]) {
                return $key;
            }
        }
        $this->fail('Expected a top-level wpmcp menu entry.');
    }

    public function test_top_level_menu_is_registered_at_its_fixed_position(): void
    {
        $key = $this->wpmcp_key($this->register());

        $this->assertSame((string) Plugin::MENU_POSITION, (string) $key);
    }

    public function test_position_sits_just_below_settings_and_above_the_last_separator(): void
    {
        // Core: Settings is 80, the last separator is 99.
        $this->assertGreaterThan(80, Plugin::MENU_POSITION);
        $this->assertLessThan(81, Plugin::MENU_POSITION);
        $this->assertNotSame((float) (int) Plugin::MENU_POSITION, (float) Plugin::MENU_POSITION, 'A decimal slot avoids integer collisions.');
    }

    public function test_position_is_kept_when_menus_added_later_append_to_the_end(): void
    {
        global $menu;
        $this->register();
        add_menu_page('Other', 'Other', 'read', 'other-plugin', '__return_null');

        $keys = array_map('strval', array_keys((array) $menu));
        $this->assertContains((string) Plugin::MENU_POSITION, $keys);
        $this->assertSame('other-plugin', end($menu)[2]);
    }
}
