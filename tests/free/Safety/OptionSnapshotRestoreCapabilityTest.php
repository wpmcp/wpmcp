<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Menus\Assign_Menu_To_Location;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\Settings\Update_Settings;
use WPMCP\Tools\Structure\Create_Sidebar_Widget;

/**
 * Restoring an option snapshot rewrites a site-wide setting, and the rollback
 * tools are registered at edit_posts, so an option (or option_set) snapshot
 * that names no restore capability needs manage_options to restore: a
 * missing declaration fails closed (issue #502). Writers whose own capability
 * sits below manage_options declare it, so the people who made the change
 * can still undo it.
 */
class OptionSnapshotRestoreCapabilityTest extends \WP_UnitTestCase
{
    private const SIDEBAR = 'wpmcp-restore-cap';

    /** @var array<string, mixed> */
    private array $prior = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        foreach ([ 'sidebars_widgets', 'widget_text', 'blogdescription' ] as $name) {
            $this->prior[ $name ] = get_option($name);
        }
    }

    protected function tearDown(): void
    {
        unregister_sidebar(self::SIDEBAR);
        foreach ($this->prior as $name => $value) {
            if (false === $value) {
                delete_option($name);
            } else {
                update_option($name, $value);
            }
        }
        wp_set_current_user(0);
        parent::tearDown();
    }

    /** An editor who was also given edit_theme_options, as theme-managing roles often are. */
    private function theme_editor(): int
    {
        $user = self::factory()->user->create([ 'role' => 'editor' ]);
        get_userdata($user)->add_cap('edit_theme_options');
        $this->assertTrue(user_can($user, 'edit_theme_options'));
        $this->assertFalse(user_can($user, 'manage_options'));
        return $user;
    }

    private function change_tagline(): string
    {
        update_option('blogdescription', 'Before');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $out = (new Update_Settings())->handle([ 'settings' => [ 'blogdescription' => 'After' ] ]);
        $this->assertSame('After', get_option('blogdescription'));
        return (string) $out['operation_ids'][0];
    }

    public function test_an_undeclared_option_snapshot_takes_manage_options_to_restore_by_operation(): void
    {
        $operation_id = $this->change_tagline();
        $this->assertArrayNotHasKey('restore_capability', Snapshot_Store::get_by_operation($operation_id)['snapshot']['data']);

        wp_set_current_user($this->theme_editor());
        $rolled = (new Rollback_Operation())->handle([ 'operation_id' => $operation_id ]);

        $this->assertFalse($rolled['restored']);
        $this->assertSame('After', get_option('blogdescription'));
        $this->assertStringContainsString('manage_options', $rolled['warnings'][0] ?? '');
    }

    public function test_an_undeclared_option_snapshot_is_skipped_in_a_session_rollback_below_manage_options(): void
    {
        $this->change_tagline();

        wp_set_current_user(self::factory()->user->create([ 'role' => 'author' ]));
        $out = (new Rollback_Session())->handle([ 'session_id' => 'default' ]);

        $this->assertSame('After', get_option('blogdescription'));
        $this->assertSame(0, $out['restored_count']);
        $this->assertNotEmpty(array_filter($out['warnings'], static fn (string $w): bool => false !== strpos($w, 'blogdescription') && false !== strpos($w, 'manage_options')));
    }

    public function test_an_administrator_can_still_restore_an_undeclared_option_snapshot(): void
    {
        $operation_id = $this->change_tagline();

        $rolled = (new Rollback_Operation())->handle([ 'operation_id' => $operation_id ]);

        $this->assertTrue($rolled['restored']);
        $this->assertSame('Before', get_option('blogdescription'));
    }

    public function test_a_menu_location_change_can_be_undone_by_the_theme_editor_who_made_it_but_not_by_an_author(): void
    {
        register_nav_menus([ 'wpmcp-cap-loc' => 'Cap test location' ]);
        $menu_id = wp_create_nav_menu('Cap test ' . uniqid());

        $editor = $this->theme_editor();
        wp_set_current_user($editor);
        $out = (new Assign_Menu_To_Location())->handle([ 'menu_id' => $menu_id, 'location' => 'wpmcp-cap-loc' ]);
        $this->assertSame('edit_theme_options', Snapshot_Store::get_by_operation($out['operation_id'])['snapshot']['data']['restore_capability'] ?? null);

        wp_set_current_user(self::factory()->user->create([ 'role' => 'author' ]));
        $this->assertFalse((new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ])['restored']);
        $this->assertSame($menu_id, (int) (get_theme_mod('nav_menu_locations')['wpmcp-cap-loc'] ?? 0));

        wp_set_current_user($editor);
        $this->assertTrue((new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ])['restored']);
        $this->assertArrayNotHasKey('wpmcp-cap-loc', (array) get_theme_mod('nav_menu_locations', []));
    }

    public function test_a_sidebar_widget_write_can_be_undone_in_a_session_by_the_theme_editor_who_made_it_but_not_by_an_author(): void
    {
        register_sidebar([ 'id' => self::SIDEBAR, 'name' => 'Cap test' ]);
        $sidebars                  = (array) get_option('sidebars_widgets', []);
        $sidebars[ self::SIDEBAR ] = [];
        update_option('sidebars_widgets', $sidebars);

        $editor = $this->theme_editor();
        wp_set_current_user($editor);
        $session = 'widget-cap-' . uniqid();
        $out     = (new Create_Sidebar_Widget())->handle([
            'sidebar_id' => self::SIDEBAR,
            'id_base'    => 'text',
            'instance'   => [ 'title' => 'Hello', 'text' => 'World' ],
            'session_id' => $session,
        ]);
        $snapshot = Snapshot_Store::get_by_operation($out['operation_id'])['snapshot'];
        $this->assertSame('option_set', $snapshot['object_type']);
        $this->assertSame('edit_theme_options', $snapshot['data']['restore_capability'] ?? null);

        $this->assertSame(1, (new Rollback_Session())->handle([ 'session_id' => $session ])['restored_count']);
        $this->assertSame([], array_values((array) (get_option('sidebars_widgets')[ self::SIDEBAR ] ?? [])));
    }

    public function test_an_author_cannot_restore_a_sidebar_widget_write_from_the_default_session(): void
    {
        register_sidebar([ 'id' => self::SIDEBAR, 'name' => 'Cap test' ]);
        $sidebars                  = (array) get_option('sidebars_widgets', []);
        $sidebars[ self::SIDEBAR ] = [];
        update_option('sidebars_widgets', $sidebars);

        wp_set_current_user($this->theme_editor());
        (new Create_Sidebar_Widget())->handle([
            'sidebar_id' => self::SIDEBAR,
            'id_base'    => 'text',
            'instance'   => [ 'title' => 'Hello', 'text' => 'World' ],
        ]);
        $placed = array_values((array) get_option('sidebars_widgets')[ self::SIDEBAR ]);
        $this->assertCount(1, $placed);

        wp_set_current_user(self::factory()->user->create([ 'role' => 'author' ]));
        $out = (new Rollback_Session())->handle([ 'session_id' => 'default' ]);

        $this->assertSame(0, $out['restored_count']);
        $this->assertSame($placed, array_values((array) get_option('sidebars_widgets')[ self::SIDEBAR ]));
    }
    public function test_an_integration_option_write_declares_the_capability_its_op_took(): void
    {
        set_theme_mod('header_textcolor', 'aabbcc');
        $editor = $this->theme_editor();
        wp_set_current_user($editor);
        $enable = static fn () => true;
        add_filter('wpmcp_enable_theme_write', $enable);
        try {
            $out = (new Theme_Integration())->handle_write([
                'operation' => 'set-mods',
                'args'      => [ 'values' => [ 'header_textcolor' => '336699' ] ],
            ]);
        } finally {
            remove_filter('wpmcp_enable_theme_write', $enable);
        }
        $this->assertArrayHasKey('operation_id', $out, (string) wp_json_encode($out));
        $this->assertSame('edit_theme_options', Snapshot_Store::get_by_operation($out['operation_id'])['snapshot']['data']['restore_capability'] ?? null);

        wp_set_current_user(self::factory()->user->create([ 'role' => 'author' ]));
        $this->assertFalse((new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ])['restored']);
        $this->assertSame('336699', get_theme_mod('header_textcolor'));

        wp_set_current_user($editor);
        $this->assertTrue((new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ])['restored']);
        $this->assertSame('aabbcc', get_theme_mod('header_textcolor'));
    }
}
