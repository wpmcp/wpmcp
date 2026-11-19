<?php

/**
 * A stand-in for the parts of Max Mega Menu the mega menu pack reads
 * (issue #908), recorded from Max Mega Menu 3.10.10:
 *
 *  - includes/functions.php: max_mega_menu_get_themes(),
 *    max_mega_menu_save_themes() and
 *    max_mega_menu_share_themes_across_multisite(), which keep the saved
 *    themes in the megamenu_themes option (or the site option on an old
 *    multisite install that shares them);
 *  - classes/theme.class.php: Mega_Menu_Theme::get_default() (a few of its
 *    ~100 keys), from_settings() and test_compilation(), which compiles the
 *    theme's SCSS and answers a WP_Error when it does not compile (here:
 *    custom_css holding "@error");
 *  - classes/nav-menus.class.php: Mega_Menu_Nav_Menus::get_menu_item_defaults().
 *
 * Loaded only where the real plugin is not. It never defines
 * MEGAMENU_VERSION, so presence stays driven by wpmcp_megamenu_active.
 */

if (! function_exists('max_mega_menu_share_themes_across_multisite')) {
    function max_mega_menu_share_themes_across_multisite()
    {
        if (get_option('megamenu_multisite_share_themes') === 'false') {
            return false;
        }
        return apply_filters('megamenu_share_themes_across_multisite', true);
    }
}

if (! function_exists('max_mega_menu_get_themes')) {
    function max_mega_menu_get_themes()
    {
        if (! max_mega_menu_share_themes_across_multisite()) {
            return get_option('megamenu_themes');
        }
        return get_site_option('megamenu_themes');
    }
}

if (! function_exists('max_mega_menu_save_themes')) {
    function max_mega_menu_save_themes($themes)
    {
        if (! max_mega_menu_share_themes_across_multisite()) {
            return update_option('megamenu_themes', $themes);
        }
        return update_site_option('megamenu_themes', $themes);
    }
}

if (! class_exists('Mega_Menu_Theme')) {
    class Mega_Menu_Theme
    {
        public $id;

        public $settings;

        public function __construct($id, $settings)
        {
            $this->id       = $id;
            $this->settings = $settings;
        }

        public static function get_default()
        {
            return new self('default', [
                'title'                     => 'Default',
                'container_background_from' => '#222',
                'container_background_to'   => '#222',
                'menu_item_link_color'      => '#ffffff',
                'menu_item_link_font_size'  => '14px',
                'panel_background_from'     => '#f1f1f1',
                'responsive_breakpoint'     => '768px',
                'mobile_columns'            => '1',
                'shadow'                    => 'off',
                'custom_css'                => '/** Push menu onto new line **/ #{$wrap} { clear: both; }',
            ]);
        }

        public static function from_settings($settings)
        {
            return new self('test', $settings);
        }

        public function test_compilation()
        {
            if (false !== strpos((string) ($this->settings['custom_css'] ?? ''), '@error')) {
                return new WP_Error('scss_compile_fail', 'Error: unexpected @error on line 1');
            }
            return '#mega-menu-wrap-test {}';
        }
    }
}

if (! class_exists('Mega_Menu_Nav_Menus')) {
    class Mega_Menu_Nav_Menus
    {
        public static function get_menu_item_defaults()
        {
            return apply_filters('megamenu_menu_item_defaults', [
                'type'                    => 'flyout',
                'align'                   => 'bottom-left',
                'icon'                    => 'disabled',
                'hide_text'               => 'false',
                'disable_link'            => 'false',
                'hide_on_mobile'          => 'false',
                'hide_on_desktop'         => 'false',
                'close_after_click'       => 'false',
                'hide_sub_menu_on_mobile' => 'false',
                'hide_arrow'              => 'false',
                'item_align'              => 'left',
                'icon_position'           => 'left',
                'panel_columns'           => 6,
                'mega_menu_columns'       => 1,
                'mega_menu_order'         => 0,
                'collapse_children'       => 'false',
                'submenu_columns'         => 1,
            ]);
        }
    }
}
