<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Max_Mega_Menu_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/max-mega-menu-double.php';

/**
 * Max Mega Menu (issue #908): free-tier ops on the plugin-data dispatcher
 * pair, so they add no top-level tools.
 *
 * Max Mega Menu keeps its global and per-location settings in the
 * megamenu_settings option (one array per theme location: enabled, theme,
 * event, effect and the mobile settings), its saved themes in
 * megamenu_themes (read through max_mega_menu_get_themes()) and each menu
 * item's settings in the _megamenu post meta of the nav_menu_item. Sub menu
 * widgets are core widgets in the 'mega-menu' sidebar, each holding
 * mega_menu_parent_menu_id. Its CSS is rebuilt on the megamenu_delete_cache
 * action, which fires megamenu_delete_cache_failed when the SCSS does not
 * compile. The plugin's own functions and classes come from the recorded
 * double; presence is driven through wpmcp_megamenu_active.
 */
class MaxMegaMenuPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'megamenu-get-settings'    => 'read',
        'megamenu-get-theme'       => 'read',
        'megamenu-list-items'      => 'read',
        'megamenu-get-item'        => 'read',
        'megamenu-update-location' => 'write',
        'megamenu-update-settings' => 'write',
        'megamenu-update-theme'    => 'write',
        'megamenu-update-item'     => 'write',
        'megamenu-clear-css-cache' => 'write',
    ];

    /** @var int */
    private $rebuilds = 0;

    /** @var int */
    private $menu;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_megamenu_active', '__return_true');
        add_filter('wpmcp_enable_megamenu_write', '__return_true');
        update_option('megamenu_multisite_share_themes', 'false');
        register_nav_menus([ 'primary' => 'Primary Menu', 'footer' => 'Footer Menu' ]);
        $this->menu = (int) wp_create_nav_menu('Main');
        set_theme_mod('nav_menu_locations', [ 'primary' => $this->menu ]);
        update_option('megamenu_settings', [
            'css'     => 'fs',
            'prefix'  => 'disabled',
            'primary' => [ 'enabled' => '1', 'theme' => 'default', 'event' => 'hover', 'effect' => 'fade_up', 'mobile_behaviour' => 'standard' ],
        ]);
        update_option('megamenu_themes', [ 'dark' => [ 'title' => 'Dark', 'container_background_from' => '#000' ] ]);
        $this->rebuilds = 0;
        add_action('megamenu_delete_cache', [ $this, 'count_rebuild' ]);
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_megamenu_active');
        remove_all_filters('wpmcp_enable_megamenu_write');
        remove_all_actions('megamenu_delete_cache');
        remove_all_actions('megamenu_delete_cache_failed');
        unregister_nav_menu('primary');
        unregister_nav_menu('footer');
        parent::tearDown();
    }

    public function count_rebuild(): void
    {
        ++$this->rebuilds;
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && ! isset($out['error']) ? $out['result'] : $out;
    }

    private function write(string $op, array $args): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function item(string $title = 'Shop', int $parent = 0): int
    {
        return (int) wp_update_nav_menu_item($this->menu, 0, [ 'menu-item-title' => $title, 'menu-item-url' => 'https://example.com/' . sanitize_title($title), 'menu-item-status' => 'publish', 'menu-item-parent-id' => $parent ]);
    }

    // ---------------------------------------------------------------
    // Catalog and availability
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_op_with_its_mode(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
        }
    }

    public function test_while_max_mega_menu_is_missing_a_call_names_the_plugin(): void
    {
        remove_all_filters('wpmcp_megamenu_active');
        add_filter('wpmcp_megamenu_active', '__return_false');
        $out = $this->read('megamenu-get-settings');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Max Mega Menu', $out['error']['message']);
        $this->assertSame('https://wordpress.org/plugins/megamenu/', $out['error']['data']['install_url'] ?? null);
    }

    public function test_presence_defaults_to_the_plugin_constant(): void
    {
        remove_all_filters('wpmcp_megamenu_active');
        $this->assertSame(defined('MEGAMENU_VERSION'), Max_Mega_Menu_Pack::active());
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters('wpmcp_enable_megamenu_write');
        $out = $this->write('megamenu-update-location', [ 'location' => 'primary', 'settings' => [ 'event' => 'click' ] ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('wpmcp_enable_megamenu_write', (string) wp_json_encode($out));
        $this->assertSame('hover', get_option('megamenu_settings')['primary']['event']);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_get_settings_reports_global_settings_locations_and_themes(): void
    {
        $out       = $this->ok($this->read('megamenu-get-settings'));
        $locations = array_column($out['locations'], null, 'location');

        $this->assertSame('fs', $out['settings']['css']);
        $this->assertArrayNotHasKey('primary', $out['settings'], 'location arrays are not global settings');
        $this->assertTrue($locations['primary']['enabled']);
        $this->assertTrue($locations['primary']['active']);
        $this->assertSame($this->menu, $locations['primary']['menu_id']);
        $this->assertSame('default', $locations['primary']['theme']);
        $this->assertSame('hover', $locations['primary']['settings']['event']);
        $this->assertFalse($locations['footer']['enabled']);
        $this->assertFalse($locations['footer']['active']);
        $this->assertSame([ 'default', 'dark' ], array_column($out['themes'], 'id'));
        $this->assertSame('Dark', array_column($out['themes'], 'title', 'id')['dark']);
    }

    public function test_get_theme_merges_the_saved_theme_over_the_defaults(): void
    {
        $out = $this->ok($this->read('megamenu-get-theme', [ 'theme' => 'dark' ]));
        $this->assertSame('dark', $out['theme']['id']);
        $this->assertSame('#000', $out['theme']['settings']['container_background_from']);
        $this->assertSame('768px', $out['theme']['settings']['responsive_breakpoint']);
        $this->assertSame('theme_not_found', $this->read('megamenu-get-theme', [ 'theme' => 'nope' ])['error']['code'] ?? null);
    }

    public function test_items_read_their_settings_over_the_defaults_and_the_panel_widgets(): void
    {
        $top   = $this->item('Shop');
        $child = $this->item('Shoes', $top);
        update_post_meta($top, '_megamenu', [ 'type' => 'megamenu', 'panel_columns' => 4, 'icon' => 'dashicons-cart' ]);
        update_option('widget_text', [ 3 => [ 'title' => 'Sale', 'text' => 'Up to 50% off', 'mega_menu_parent_menu_id' => $top, 'mega_menu_columns' => 2 ], '_multiwidget' => 1 ]);
        update_option('sidebars_widgets', [ 'mega-menu' => [ 'text-3' ], 'array_version' => 3 ]);

        $list  = $this->ok($this->read('megamenu-list-items', [ 'location' => 'primary' ]));
        $items = array_column($list['items'], null, 'id');
        $this->assertSame('megamenu', $items[ $top ]['type']);
        $this->assertSame('flyout', $items[ $child ]['type']);
        $this->assertSame($top, $items[ $child ]['parent']);

        $one = $this->ok($this->read('megamenu-get-item', [ 'id' => $top ]));
        $this->assertSame(4, (int) $one['item']['settings']['panel_columns']);
        $this->assertSame('false', $one['item']['settings']['hide_on_mobile']);
        $this->assertSame([ [ 'widget_id' => 'text-3', 'id_base' => 'text', 'title' => 'Sale', 'columns' => 2 ] ], $one['item']['widgets']);

        $post = self::factory()->post->create();
        $this->assertSame('menu_item_not_found', $this->read('megamenu-get-item', [ 'id' => $post ])['error']['code'] ?? null);
        $this->assertSame('location_not_found', $this->read('megamenu-list-items', [ 'location' => 'nowhere' ])['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_update_location_merges_settings_rebuilds_css_and_rolls_back(): void
    {
        $before = get_option('megamenu_settings');
        $out    = $this->ok($this->write('megamenu-update-location', [ 'location' => 'primary', 'settings' => [ 'enabled' => false, 'theme' => 'dark', 'event' => 'click' ] ]));

        $this->assertTrue($out['recoverable']);
        $saved = get_option('megamenu_settings');
        $this->assertArrayNotHasKey('enabled', $saved['primary'], 'Max Mega Menu unsets enabled to turn a location off');
        $this->assertSame('dark', $saved['primary']['theme']);
        $this->assertSame('click', $saved['primary']['event']);
        $this->assertSame('fade_up', $saved['primary']['effect'], 'other keys kept');
        $this->assertSame(1, $this->rebuilds);
        $this->assertTrue($out['result']['css']['regenerated']);

        $on = $this->ok($this->write('megamenu-update-location', [ 'location' => 'footer', 'settings' => [ 'enabled' => true ] ]));
        $this->assertSame('1', get_option('megamenu_settings')['footer']['enabled']);

        $this->assertTrue(Rollback_Service::restore_operation($on['operation_id']));
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, get_option('megamenu_settings'));
        $this->assertGreaterThanOrEqual(4, $this->rebuilds, 'each undo rebuilds the CSS too');
    }

    public function test_update_location_refuses_bad_input_without_writing(): void
    {
        $before = get_option('megamenu_settings');
        $this->assertSame('location_not_found', $this->write('megamenu-update-location', [ 'location' => 'nowhere', 'settings' => [ 'event' => 'click' ] ])['error']['code'] ?? null);
        $this->assertSame('theme_not_found', $this->write('megamenu-update-location', [ 'location' => 'primary', 'settings' => [ 'theme' => 'nope' ] ])['error']['code'] ?? null);
        $this->assertSame('unknown_setting', $this->write('megamenu-update-location', [ 'location' => 'primary', 'settings' => [ 'evil' => 'x' ] ])['error']['code'] ?? null);
        $this->assertSame('invalid_setting', $this->write('megamenu-update-location', [ 'location' => 'primary', 'settings' => [ 'event' => '<script>' ] ])['error']['code'] ?? null);
        $this->assertSame($before, get_option('megamenu_settings'));
        $this->assertSame(0, $this->rebuilds);
    }

    public function test_update_settings_changes_the_css_output_and_rolls_back(): void
    {
        $out = $this->ok($this->write('megamenu-update-settings', [ 'settings' => [ 'css' => 'head' ] ]));
        $this->assertSame('head', get_option('megamenu_settings')['css']);
        $this->assertSame('1', get_option('megamenu_settings')['primary']['enabled'], 'locations kept');
        $this->assertSame('invalid_setting', $this->write('megamenu-update-settings', [ 'settings' => [ 'css' => 'inline' ] ])['error']['code'] ?? null);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('fs', get_option('megamenu_settings')['css']);
    }

    public function test_a_failed_css_rebuild_is_reported(): void
    {
        add_action('megamenu_delete_cache', static function (): void {
            do_action('megamenu_delete_cache_failed', new \WP_Error('scss_compile_fail', 'Error: undefined variable $x'));
        });
        $out = $this->ok($this->write('megamenu-clear-css-cache', []));
        $this->assertFalse($out['result']['css']['regenerated']);
        $this->assertStringContainsString('undefined variable', $out['result']['css']['error']);
    }

    public function test_clear_css_cache_rebuilds_without_a_snapshot_or_opt_in(): void
    {
        remove_all_filters('wpmcp_enable_megamenu_write');
        $out = $this->ok($this->write('megamenu-clear-css-cache', []));
        $this->assertFalse($out['recoverable']);
        $this->assertSame(1, $this->rebuilds);
        $this->assertTrue($out['result']['css']['regenerated']);
    }

    public function test_update_theme_saves_known_keys_and_rolls_back(): void
    {
        $before = get_option('megamenu_themes');
        $out    = $this->ok($this->write('megamenu-update-theme', [ 'theme' => 'dark', 'settings' => [ 'menu_item_link_color' => '#ff0000', 'responsive_breakpoint' => '900' ] ]));
        $themes = get_option('megamenu_themes');
        $this->assertSame('#ff0000', $themes['dark']['menu_item_link_color']);
        $this->assertSame('900px', $themes['dark']['responsive_breakpoint']);
        $this->assertSame('#000', $themes['dark']['container_background_from'], 'other keys kept');
        $this->assertSame(1, $this->rebuilds);

        $default = $this->ok($this->write('megamenu-update-theme', [ 'theme' => 'default', 'settings' => [ 'shadow' => 'on' ] ]));
        $this->assertSame('on', get_option('megamenu_themes')['default']['shadow']);

        $this->assertTrue(Rollback_Service::restore_operation($default['operation_id']));
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, get_option('megamenu_themes'));
    }

    public function test_update_theme_refuses_unknown_keys_bad_scss_and_custom_css_without_unfiltered_html(): void
    {
        $before = get_option('megamenu_themes');
        $this->assertSame('theme_not_found', $this->write('megamenu-update-theme', [ 'theme' => 'nope', 'settings' => [ 'shadow' => 'on' ] ])['error']['code'] ?? null);
        $this->assertSame('unknown_setting', $this->write('megamenu-update-theme', [ 'theme' => 'dark', 'settings' => [ 'nope' => 'on' ] ])['error']['code'] ?? null);
        $this->assertSame('theme_compile_failed', $this->write('megamenu-update-theme', [ 'theme' => 'dark', 'settings' => [ 'custom_css' => '@error "x";' ] ])['error']['code'] ?? null);

        $deny = static fn (array $caps, string $cap): array => 'unfiltered_html' === $cap ? [ 'do_not_allow' ] : $caps;
        add_filter('map_meta_cap', $deny, 10, 2);
        $out = $this->write('megamenu-update-theme', [ 'theme' => 'dark', 'settings' => [ 'custom_css' => 'a { color: red; }' ] ]);
        remove_filter('map_meta_cap', $deny, 10);
        $this->assertSame('operation_denied', $out['error']['code'] ?? null, (string) wp_json_encode($out));

        $this->assertSame($before, get_option('megamenu_themes'));
    }

    public function test_update_item_merges_settings_and_rolls_back(): void
    {
        $top = $this->item('Shop');
        update_post_meta($top, '_megamenu', [ 'type' => 'flyout', 'mega_menu_order' => [ 5 => 2 ] ]);
        $out = $this->ok($this->write('megamenu-update-item', [ 'id' => $top, 'settings' => [ 'type' => 'megamenu', 'panel_columns' => 4, 'hide_on_mobile' => true, 'icon' => 'dashicons-cart' ] ]));

        $this->assertTrue($out['recoverable']);
        $meta = get_post_meta($top, '_megamenu', true);
        $this->assertSame('megamenu', $meta['type']);
        $this->assertSame(4, $meta['panel_columns']);
        $this->assertSame('true', $meta['hide_on_mobile']);
        $this->assertSame([ 5 => 2 ], $meta['mega_menu_order'], 'other keys kept');
        $this->assertSame('true', $out['result']['item']['settings']['hide_on_mobile']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame([ 'type' => 'flyout', 'mega_menu_order' => [ 5 => 2 ] ], get_post_meta($top, '_megamenu', true));
    }

    public function test_update_item_refuses_a_non_menu_item_a_sub_item_panel_and_bad_values(): void
    {
        $top   = $this->item('Shop');
        $child = $this->item('Shoes', $top);
        $page  = self::factory()->post->create([ 'post_type' => 'page' ]);
        $this->assertSame('menu_item_not_found', $this->write('megamenu-update-item', [ 'id' => $page, 'settings' => [ 'hide_text' => true ] ])['error']['code'] ?? null);
        $this->assertSame('top_level_only', $this->write('megamenu-update-item', [ 'id' => $child, 'settings' => [ 'type' => 'megamenu' ] ])['error']['code'] ?? null);
        $this->assertSame('unknown_setting', $this->write('megamenu-update-item', [ 'id' => $top, 'settings' => [ 'grid' => [] ] ])['error']['code'] ?? null);
        $this->assertSame('invalid_setting', $this->write('megamenu-update-item', [ 'id' => $top, 'settings' => [ 'align' => 'middle' ] ])['error']['code'] ?? null);
        $this->assertSame('', get_post_meta($top, '_megamenu', true));
    }
}
