<?php

namespace WPMCP\Tests\Free\Menus;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\List_Operations;
use WPMCP\Tools\Menus\Add_Menu_Item;
use WPMCP\Tools\Menus\Create_Menu;

/**
 * Issue #598: create-menu and add-menu-item record a creation row under the
 * caller's session_id and return an operation_id. Undoing create-menu
 * deletes the menu only while it is as the call left it; undoing
 * add-menu-item removes the item.
 */
class MenuCreationUndoTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function test_create_menu_returns_an_operation_that_is_listed(): void
    {
        $out = (new Create_Menu())->handle(['name' => 'Search test menu', 'session_id' => 'menus-598']);

        $this->assertArrayHasKey('operation_id', $out);
        $listed = (new List_Operations())->handle(['session_id' => 'menus-598'])['operations'];
        $this->assertCount(1, $listed);
        $this->assertSame('create-menu', $listed[0]['tool_name']);
        $this->assertSame($out['id'], $listed[0]['object_id']);
        $this->assertSame('menus', $listed[0]['domain']);
        $this->assertTrue($listed[0]['rollback_available']);
    }

    public function test_undoing_create_menu_deletes_the_unchanged_menu(): void
    {
        $out = (new Create_Menu())->handle(['name' => 'Undo me', 'session_id' => 'menus-598']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertFalse(wp_get_nav_menu_object($out['id']));
        $listed = (new List_Operations())->handle(['session_id' => 'menus-598'])['operations'];
        $this->assertNotNull($listed[0]['rolled_back_at']);
        $this->assertFalse(Rollback_Service::restore_operation($out['operation_id']), 'An undone creation is never undone again');
    }

    public function test_undoing_create_menu_refuses_a_menu_that_has_items_since(): void
    {
        $out = (new Create_Menu())->handle(['name' => 'Has items', 'session_id' => 'menus-598']);
        wp_update_nav_menu_item($out['id'], 0, ['menu-item-title' => 'Added in wp-admin', 'menu-item-url' => 'https://example.com/', 'menu-item-status' => 'publish']);

        $this->assertFalse(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertNotFalse(wp_get_nav_menu_object($out['id']), 'The menu is kept');
        $this->assertCount(1, wp_get_nav_menu_items($out['id']));
        $warnings = implode(' ', Rollback_Service::take_warnings());
        $this->assertStringContainsString('refused', $warnings);
        $this->assertStringContainsString('item', $warnings);
        $listed = (new List_Operations())->handle(['session_id' => 'menus-598'])['operations'];
        $this->assertNull($listed[0]['rolled_back_at'], 'A refused undo stays retryable');
    }

    public function test_undoing_create_menu_refuses_a_renamed_menu(): void
    {
        $out = (new Create_Menu())->handle(['name' => 'Old name', 'session_id' => 'menus-598']);
        wp_update_nav_menu_object($out['id'], ['menu-name' => 'New name']);

        $this->assertFalse(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertNotFalse(wp_get_nav_menu_object($out['id']));
    }

    public function test_undoing_create_menu_refuses_a_menu_assigned_to_a_location(): void
    {
        register_nav_menus(['wpmcp-598' => 'Test location']);
        $out = (new Create_Menu())->handle(['name' => 'Placed', 'session_id' => 'menus-598']);
        set_theme_mod('nav_menu_locations', ['wpmcp-598' => $out['id']]);

        $this->assertFalse(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertNotFalse(wp_get_nav_menu_object($out['id']));

        remove_theme_mod('nav_menu_locations');
        unregister_nav_menu('wpmcp-598');
    }

    public function test_undoing_create_menu_takes_edit_theme_options(): void
    {
        $out = (new Create_Menu())->handle(['name' => 'Gated', 'session_id' => 'menus-598']);
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $this->assertFalse(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertNotFalse(wp_get_nav_menu_object($out['id']));
    }

    public function test_add_menu_item_returns_an_operation_whose_undo_removes_the_item(): void
    {
        $menu = wp_create_nav_menu('Existing menu');

        $out = (new Add_Menu_Item())->handle(['menu_id' => $menu, 'title' => 'Orange giraffe tour', 'url' => 'https://example.com', 'type' => 'custom', 'session_id' => 'menus-598']);

        $this->assertArrayHasKey('operation_id', $out);
        $listed = (new List_Operations())->handle(['session_id' => 'menus-598'])['operations'];
        $this->assertCount(1, $listed);
        $this->assertSame('add-menu-item', $listed[0]['tool_name']);
        $this->assertTrue($listed[0]['rollback_available']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertNull(get_post($out['item_id']), 'The item is removed, as remove-menu-item removes it');
        $this->assertSame([], wp_get_nav_menu_items($menu));
        $this->assertNotFalse(wp_get_nav_menu_object($menu), 'The menu it was added to stays');
    }

    public function test_rollback_session_removes_the_menu_and_the_items_the_session_added(): void
    {
        $menu = (new Create_Menu())->handle(['name' => 'Session menu', 'session_id' => 'menus-598-session']);
        $one  = (new Add_Menu_Item())->handle(['menu_id' => $menu['id'], 'title' => 'One', 'url' => 'https://example.com/1', 'session_id' => 'menus-598-session']);
        $two  = (new Add_Menu_Item())->handle(['menu_id' => $menu['id'], 'title' => 'Two', 'url' => 'https://example.com/2', 'session_id' => 'menus-598-session']);

        $this->assertSame(3, Rollback_Service::restore_session('menus-598-session'));

        $this->assertNull(get_post($one['item_id']));
        $this->assertNull(get_post($two['item_id']));
        $this->assertFalse(wp_get_nav_menu_object($menu['id']));
    }
}
