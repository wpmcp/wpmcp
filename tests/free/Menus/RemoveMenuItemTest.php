<?php

namespace WPMCP\Tests\Free\Menus;

use WPMCP\Tools\Menus\Remove_Menu_Item;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Safety\Snapshot_Store;

class RemoveMenuItemTest extends \WP_UnitTestCase
{
    private array $menus = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    protected function tearDown(): void
    {
        foreach ($this->menus as $id) {
            wp_delete_nav_menu($id);
        }
        $this->menus = [];
        parent::tearDown();
    }

    private function item(string $title, string $url): array
    {
        $menu_id = wp_create_nav_menu('Remove Item Menu ' . wp_generate_uuid4());
        $this->menus[] = $menu_id;
        $item_id = wp_update_nav_menu_item($menu_id, 0, [
            'menu-item-title'  => $title,
            'menu-item-url'    => $url,
            'menu-item-status' => 'publish',
        ]);
        return [$menu_id, (int) $item_id];
    }

    public function test_removes_the_item(): void
    {
        [$menu_id, $item_id] = $this->item('Gone', 'https://example.com/gone');

        $out = (new Remove_Menu_Item())->handle(['item_id' => $item_id]);

        $this->assertSame($item_id, $out['item_id']);
        $this->assertEmpty(wp_get_nav_menu_items($menu_id));
    }

    public function test_removal_is_recoverable_via_post_snapshot(): void
    {
        [$menu_id, $item_id] = $this->item('Restore Me', 'https://example.com/restore');

        $out = (new Remove_Menu_Item())->handle(['item_id' => $item_id]);
        $this->assertTrue($out['recoverable']);
        $this->assertEmpty(wp_get_nav_menu_items($menu_id));

        $rolled_back = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled_back['restored']);

        $items = wp_get_nav_menu_items($menu_id);
        $this->assertCount(1, $items);
        $this->assertSame('Restore Me', $items[0]->title);
    }

    public function test_requires_item_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Remove_Menu_Item())->handle([]);
    }

    public function test_missing_item_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Remove_Menu_Item())->handle(['item_id' => 999999]);
    }
}
