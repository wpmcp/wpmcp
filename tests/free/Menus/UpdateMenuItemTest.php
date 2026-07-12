<?php

namespace WPMCP\Tests\Free\Menus;

use WPMCP\Tools\Menus\Update_Menu_Item;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Safety\Snapshot_Store;

class UpdateMenuItemTest extends \WP_UnitTestCase
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
        $menu_id = wp_create_nav_menu('Update Item Menu ' . wp_generate_uuid4());
        $this->menus[] = $menu_id;
        $item_id = wp_update_nav_menu_item($menu_id, 0, [
            'menu-item-title'  => $title,
            'menu-item-url'    => $url,
            'menu-item-status' => 'publish',
        ]);
        return [$menu_id, (int) $item_id];
    }

    public function test_updates_item_title_and_url(): void
    {
        [$menu_id, $item_id] = $this->item('Old', 'https://example.com/old');

        $out = (new Update_Menu_Item())->handle([
            'item_id' => $item_id,
            'title'   => 'New',
            'url'     => 'https://example.com/new',
        ]);

        $this->assertSame($item_id, $out['item_id']);
        $items = wp_get_nav_menu_items($menu_id);
        $this->assertSame('New', $items[0]->title);
        $this->assertSame('https://example.com/new', $items[0]->url);
    }

    public function test_update_is_recoverable_via_post_snapshot(): void
    {
        [$menu_id, $item_id] = $this->item('Original', 'https://example.com/original');

        $out = (new Update_Menu_Item())->handle([
            'item_id' => $item_id,
            'title'   => 'Changed',
            'url'     => 'https://example.com/changed',
        ]);

        $this->assertArrayHasKey('operation_id', $out);
        $this->assertTrue($out['recoverable']);
        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));

        $rolled_back = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled_back['restored']);

        $items = wp_get_nav_menu_items($menu_id);
        $this->assertSame('Original', $items[0]->title);
        $this->assertSame('https://example.com/original', $items[0]->url);
    }

    public function test_requires_item_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Update_Menu_Item())->handle(['title' => 'X']);
    }

    public function test_missing_item_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Update_Menu_Item())->handle(['item_id' => 999999, 'title' => 'X']);
    }
}
