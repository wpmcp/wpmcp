<?php

namespace WPMCP\Tests\Free\Menus;

use WPMCP\Tools\Menus\Delete_Menu;

class DeleteMenuTest extends \WP_UnitTestCase
{
    private array $menus = [];

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_delete_menu');
        foreach ($this->menus as $id) {
            wp_delete_nav_menu($id);
        }
        $this->menus = [];
        parent::tearDown();
    }

    private function menu(string $name): int
    {
        $id = wp_create_nav_menu($name);
        $this->menus[] = $id;
        return $id;
    }

    public function test_disabled_by_default(): void
    {
        $id = $this->menu('Doomed');
        $this->expectException(\RuntimeException::class);
        (new Delete_Menu())->handle(['id' => $id, 'confirm' => true]);
    }

    public function test_requires_confirm_when_enabled(): void
    {
        add_filter('wpmcp_enable_delete_menu', '__return_true');
        $id = $this->menu('Doomed');
        $this->expectException(\InvalidArgumentException::class);
        (new Delete_Menu())->handle(['id' => $id]);
    }

    public function test_deletes_menu_when_enabled_and_confirmed(): void
    {
        add_filter('wpmcp_enable_delete_menu', '__return_true');
        $id = $this->menu('Doomed');
        wp_update_nav_menu_item($id, 0, [
            'menu-item-title'  => 'Item',
            'menu-item-url'    => 'https://example.com/',
            'menu-item-status' => 'publish',
        ]);

        $out = (new Delete_Menu())->handle(['id' => $id, 'confirm' => true]);

        $this->assertSame($id, $out['id']);
        $this->assertSame('Doomed', $out['name']);
        $this->assertFalse($out['recoverable']);
        $this->assertNotEmpty($out['recoverability_note']);
        $this->assertCount(1, $out['items']);
        $this->assertSame('Item', $out['items'][0]['title']);

        $this->assertFalse(wp_get_nav_menu_object($id));
    }

    public function test_missing_menu_throws(): void
    {
        add_filter('wpmcp_enable_delete_menu', '__return_true');
        $this->expectException(\RuntimeException::class);
        (new Delete_Menu())->handle(['id' => 999999, 'confirm' => true]);
    }
}
