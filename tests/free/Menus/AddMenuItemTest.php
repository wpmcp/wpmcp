<?php

namespace WPMCP\Tests\Free\Menus;

use WPMCP\Tools\Menus\Add_Menu_Item;

class AddMenuItemTest extends \WP_UnitTestCase
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

    public function test_adds_a_custom_link_item(): void
    {
        $menu_id = $this->menu('Add Item Menu');

        $out = (new Add_Menu_Item())->handle([
            'menu_id' => $menu_id,
            'title'   => 'Home',
            'url'     => 'https://example.com/',
        ]);

        $this->assertGreaterThan(0, $out['item_id']);

        $items = wp_get_nav_menu_items($menu_id);
        $this->assertCount(1, $items);
        $this->assertSame('Home', $items[0]->title);
        $this->assertSame('https://example.com/', $items[0]->url);
    }

    public function test_requires_menu_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Add_Menu_Item())->handle(['title' => 'X', 'url' => 'https://example.com/']);
    }

    public function test_missing_menu_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Add_Menu_Item())->handle([
            'menu_id' => 999999,
            'title'   => 'X',
            'url'     => 'https://example.com/',
        ]);
    }
}
