<?php

namespace WPMCP\Tests\Free\Menus;

use WPMCP\Tools\Menus\Get_Menu;

class GetMenuTest extends \WP_UnitTestCase
{
    private array $menus = [];

    protected function tearDown(): void
    {
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

    public function test_returns_menu_with_its_items(): void
    {
        $menu_id = $this->menu('Footer Nav');
        $item_id = wp_update_nav_menu_item($menu_id, 0, [
            'menu-item-title'  => 'Contact',
            'menu-item-url'    => 'https://example.com/contact',
            'menu-item-status' => 'publish',
        ]);

        $out = (new Get_Menu())->handle(['id' => $menu_id]);

        $this->assertSame($menu_id, $out['id']);
        $this->assertSame('Footer Nav', $out['name']);
        $this->assertCount(1, $out['items']);
        $this->assertSame($item_id, $out['items'][0]['id']);
        $this->assertSame('Contact', $out['items'][0]['title']);
        $this->assertSame('https://example.com/contact', $out['items'][0]['url']);
    }

    public function test_missing_menu_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Get_Menu())->handle(['id' => 999999]);
    }

    public function test_requires_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Get_Menu())->handle([]);
    }
}
