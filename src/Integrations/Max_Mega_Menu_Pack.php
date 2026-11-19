<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Max Mega Menu (issue #908) on the plugin-data pair, free tier, so it adds
 * no top-level tools. Ops are prefixed megamenu- because the pair is shared.
 *
 * Max Mega Menu (3.10) keeps:
 *  - the megamenu_settings option: global keys (css output method, prefix
 *    and the rest) plus one array per theme location (enabled, theme, event,
 *    effect, the mobile settings), a location being on while 'enabled' is
 *    set and a menu is assigned (Mega_Menu_Location::is_active);
 *  - its saved themes, read and saved through max_mega_menu_get_themes()
 *    and max_mega_menu_save_themes() (the megamenu_themes option), merged
 *    over Mega_Menu_Theme::get_default();
 *  - each menu item's settings in the _megamenu post meta of the
 *    nav_menu_item, merged over Mega_Menu_Nav_Menus::get_menu_item_defaults();
 *  - sub menu widgets as core widgets in the 'mega-menu' sidebar, each
 *    instance naming its parent item in mega_menu_parent_menu_id (edited
 *    with the core widget tools, read here).
 * Its CSS is rebuilt on the megamenu_delete_cache action, which fires
 * megamenu_delete_cache_failed with the compile error.
 *
 * Reads: megamenu-get-settings, -get-theme, -list-items, -get-item. Menus
 * carry no visitor data, so nothing needs masking.
 * Writes are off until a site opts in with wpmcp_enable_megamenu_write and
 * merge only known keys, as the plugin's own screens do:
 *  - update-location and update-settings under a snapshot of
 *    megamenu_settings;
 *  - update-theme under a snapshot of megamenu_themes, compiled first and
 *    refused when the SCSS does not compile; custom_css needs
 *    unfiltered_html; refused on a network that shares themes across sites
 *    (a site option the snapshot cannot hold);
 *  - update-item under a post snapshot of the menu item (its meta).
 * Each write rebuilds the CSS and reports whether that worked; an undo
 * rebuilds it too (after_options_restored, after_post_restored).
 * clear-css-cache only rebuilds the CSS: not snapshotted and not behind the
 * opt-in, since a rebuild destroys nothing.
 *
 * Every op takes edit_theme_options, the capability the plugin's screens use
 * (filterable there through megamenu_options_capability). Presence is
 * filterable (wpmcp_megamenu_active, default: MEGAMENU_VERSION is defined).
 */
final class Max_Mega_Menu_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking Max Mega Menu's own hooks, not defining any.

    public const SETTINGS = 'megamenu_settings';

    public const THEMES = 'megamenu_themes';

    public const ITEM_META = '_megamenu';

    public const OPT_IN_FILTER = 'wpmcp_enable_megamenu_write';

    public const CAP = 'edit_theme_options';

    private const SLUG = '/^[a-z0-9_-]{1,40}$/';

    /** Per-location keys the Locations screen saves, and their allowed values (null: any slug-like value). */
    private const LOCATION_KEYS = [
        'enabled'                 => 'bool',
        'theme'                   => null,
        'event'                   => [ 'hover', 'hover_intent', 'click' ],
        'effect'                  => null,
        'effect_speed'            => null,
        'effect_mobile'           => null,
        'effect_mobile_direction' => [ 'vertical', 'horizontal' ],
        'effect_speed_mobile'     => null,
        'mobile_behaviour'        => [ 'standard', 'accordion' ],
        'mobile_state'            => null,
        'second_click'            => [ 'go', 'close', 'disabled' ],
        'descriptions'            => [ 'enabled', 'disabled' ],
        'unbind'                  => [ 'enabled', 'disabled' ],
        'prefix'                  => [ 'enabled', 'disabled' ],
        'container'               => [ 'div', 'nav' ],
        'active_instance'         => null,
    ];

    /** Global keys of the General Settings screen. */
    private const GLOBAL_KEYS = [
        'css' => [ 'fs', 'head', 'disabled' ],
    ];

    private const ITEM_BOOLS = [ 'hide_text', 'disable_link', 'hide_on_mobile', 'hide_on_desktop', 'close_after_click', 'hide_sub_menu_on_mobile', 'hide_arrow', 'collapse_children' ];

    private const ITEM_ENUMS = [
        'type'          => [ 'flyout', 'megamenu', 'grid', 'tabbed' ],
        'align'         => [ 'bottom-left', 'bottom-right' ],
        'item_align'    => [ 'float-left', 'left', 'right' ],
        'icon_position' => [ 'left', 'top', 'right' ],
    ];

    private const ITEM_INTS = [ 'panel_columns', 'mega_menu_columns', 'submenu_columns' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_megamenu_active', defined('MEGAMENU_VERSION'));
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        if (self::active()) {
            return true;
        }
        $refusal         = Plugin_Refusal::make('plugin_missing', 'Max Mega Menu');
        $refusal['code'] = 'inactive' === $refusal['data']['state'] ? 'plugin_inactive' : 'plugin_missing';
        return $refusal;
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $slug     = [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 ];
        $settings = [ 'type' => 'object', 'minProperties' => 1 ];
        $item     = [ 'id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'read' ] ];
        $base     = [
            'capability' => self::CAP,
            'requires'   => static fn () => self::presence(),
        ];
        $write    = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_megamenu_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $settings_target = static fn (): array => [ 'object_type' => 'option', 'object_id' => self::SETTINGS ];

        return [
            'megamenu-get-settings'    => $base + [
                'mode'         => 'read',
                'description'  => 'Max Mega Menu global settings, each theme location (enabled, active, menu_id, theme and its settings) and the themes (id, title)',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => static fn (): array => self::get_settings(),
            ],
            'megamenu-get-theme'       => $base + [
                'mode'         => 'read',
                'description'  => 'Read one Max Mega Menu theme (colours, sizes, responsive_breakpoint, custom_css) merged over the defaults',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'theme' => $slug ], 'required' => [ 'theme' ] ],
                'validate'     => static fn (array $args): ?array => self::theme_refusal((string) $args['theme']),
                'handler'      => static fn (array $args): array => [ 'theme' => [ 'id' => (string) $args['theme'], 'settings' => self::themes()[ (string) $args['theme'] ] ] ],
            ],
            'megamenu-list-items'      => $base + [
                'mode'         => 'read',
                'description'  => 'List the items of the menu at a theme location (location) or of a menu (menu_id) with their mega menu type, icon and mobile flags',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'location' => $slug, 'menu_id' => [ 'type' => 'integer', 'minimum' => 1 ] ] ],
                'objects'      => [ 'menu_id' => [ 'type' => 'term', 'access' => 'read' ] ],
                'validate'     => static fn (array $args): ?array => self::menu_refusal($args),
                'handler'      => static fn (array $args): array => self::list_items(self::menu_of($args)),
            ],
            'megamenu-get-item'        => $base + [
                'mode'         => 'read',
                'objects'      => $item,
                'description'  => 'Read one menu item\'s Max Mega Menu settings (type, columns, icon, hide on mobile) and the widgets in its panel',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'id' ] ],
                'validate'     => static fn (array $args): ?array => self::item_refusal((int) $args['id']),
                'handler'      => static fn (array $args): array => [ 'item' => self::view_item((int) $args['id'], true) ],
            ],
            'megamenu-update-location' => $write + [
                'description'  => 'Change a theme location\'s Max Mega Menu settings (enabled, theme, event, effect, mobile_behaviour and the rest); only the keys given change. Rebuilds the CSS. Undo: rollback-operation',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'location' => $slug, 'settings' => $settings ], 'required' => [ 'location', 'settings' ] ],
                'validate'     => static fn (array $args): ?array => self::location_write_refusal($args),
                'snapshot'     => $settings_target,
                'handler'      => static fn (array $args): array => self::update_location((string) $args['location'], (array) $args['settings']),
            ],
            'megamenu-update-settings' => $write + [
                'description'  => 'Change Max Mega Menu global settings (css: fs, head or disabled). Rebuilds the CSS. Undo: rollback-operation',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'settings' => $settings ], 'required' => [ 'settings' ] ],
                'validate'     => static fn (array $args): ?array => self::values_refusal((array) $args['settings'], self::GLOBAL_KEYS),
                'snapshot'     => $settings_target,
                'handler'      => static fn (array $args): array => self::update_global((array) $args['settings']),
            ],
            'megamenu-update-theme'    => $write + [
                'description'  => 'Change a Max Mega Menu theme: settings keys from get-theme (custom_css needs unfiltered_html); refused when it does not compile. Rebuilds the CSS. Undo: rollback-operation',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'theme' => $slug, 'settings' => $settings ], 'required' => [ 'theme', 'settings' ] ],
                'validate'     => static fn (array $args): ?array => self::theme_write_refusal((string) $args['theme'], (array) $args['settings']),
                'snapshot'     => static fn (): array => [ 'object_type' => 'option', 'object_id' => self::THEMES ],
                'handler'      => static fn (array $args): array => self::update_theme((string) $args['theme'], (array) $args['settings']),
            ],
            'megamenu-update-item'     => $write + [
                'objects'      => [ 'id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'write' ] ],
                'description'  => 'Change a menu item\'s Max Mega Menu settings: type (flyout, megamenu, grid; top level only), align, icon, icon_position, item_align, panel_columns, mega_menu_columns, submenu_columns and the hide_* flags. Undo: rollback-operation',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ], 'settings' => $settings ], 'required' => [ 'id', 'settings' ] ],
                'validate'     => static fn (array $args): ?array => self::item_write_refusal((int) $args['id'], (array) $args['settings']),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['id'] ],
                'handler'      => static fn (array $args): array => self::update_item((int) $args['id'], (array) $args['settings']),
            ],
            'megamenu-clear-css-cache' => $base + [
                'mode'         => 'write',
                'description'  => 'Rebuild Max Mega Menu\'s generated CSS and report a compile failure. Not snapshotted: a rebuild destroys nothing',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => static fn (): array => [ 'css' => self::rebuild_css() ],
            ],
        ];
    }

    // ------------------------------------------------------------ rollback

    /**
     * After an undo restored Max Mega Menu's settings or themes, rebuild the
     * CSS from them, as the forward write did.
     *
     * @param string[] $names
     */
    public static function after_options_restored($names = []): void
    {
        if (self::active() && [] !== array_intersect((array) $names, [ self::SETTINGS, self::THEMES ])) {
            self::rebuild_css();
        }
    }

    /** After an undo restored a menu item, rebuild the CSS, as the write did. */
    public static function after_post_restored($post_id = 0): void
    {
        if (self::active() && 'nav_menu_item' === get_post_type((int) $post_id)) {
            self::rebuild_css();
        }
    }

    // ------------------------------------------------------------ refusals

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    private static function theme_refusal(string $theme): ?array
    {
        $themes = self::themes();
        return isset($themes[ $theme ]) ? null : self::refusal('theme_not_found', sprintf('"%s" is not a Max Mega Menu theme.', $theme), [ 'themes' => array_keys($themes) ]);
    }

    private static function location_refusal(string $location): ?array
    {
        $locations = self::locations();
        return isset($locations[ $location ]) ? null : self::refusal('location_not_found', sprintf('"%s" is not a theme or Max Mega Menu location.', $location), [ 'locations' => array_keys($locations) ]);
    }

    private static function menu_refusal(array $args): ?array
    {
        if (isset($args['location'])) {
            $refused = self::location_refusal((string) $args['location']);
            if (null !== $refused) {
                return $refused;
            }
        } elseif (! isset($args['menu_id'])) {
            return self::refusal('menu_required', 'Give location or menu_id.');
        }
        return 0 === self::menu_of($args) ? self::refusal('menu_not_found', 'No menu is assigned there.', $args) : null;
    }

    private static function is_item(int $id): bool
    {
        $post = get_post($id);
        return $post instanceof \WP_Post && 'nav_menu_item' === $post->post_type;
    }

    private static function item_refusal(int $id): ?array
    {
        return self::is_item($id) ? null : self::refusal('menu_item_not_found', sprintf('%d is not a menu item.', $id), [ 'id' => $id ]);
    }

    /**
     * Refusal for a key not in $allowed or a value outside it, or null.
     *
     * @param array<string, mixed>                     $settings
     * @param array<string, string[]|string|null>      $allowed
     */
    private static function values_refusal(array $settings, array $allowed): ?array
    {
        $unknown = array_values(array_diff(array_keys($settings), array_keys($allowed)));
        if ([] !== $unknown) {
            return self::refusal('unknown_setting', sprintf('Not a setting this op changes: %s.', implode(', ', $unknown)), [ 'unknown' => $unknown, 'settings' => array_keys($allowed) ]);
        }
        foreach ($settings as $key => $value) {
            $rule = $allowed[ $key ];
            if ('bool' === $rule) {
                $ok = is_bool($value);
            } elseif ('int' === $rule) {
                $ok = is_int($value) && $value >= 1 && $value <= 12;
            } elseif (is_array($rule)) {
                $ok = is_string($value) && in_array($value, $rule, true);
            } else {
                $ok = (is_string($value) || is_int($value)) && 1 === preg_match(self::SLUG, (string) $value);
            }
            if (! $ok) {
                return self::refusal('invalid_setting', sprintf('Invalid value for %s.', $key), [ 'key' => $key, 'allowed' => is_array($rule) ? $rule : $rule ]);
            }
        }
        return null;
    }

    private static function location_write_refusal(array $args): ?array
    {
        $settings = (array) $args['settings'];
        $refused  = self::location_refusal((string) $args['location']) ?? self::values_refusal($settings, self::LOCATION_KEYS);
        if (null === $refused && isset($settings['theme'])) {
            $refused = self::theme_refusal((string) $settings['theme']);
        }
        return $refused;
    }

    private static function theme_write_refusal(string $theme, array $settings): ?array
    {
        if (is_multisite() && function_exists('max_mega_menu_share_themes_across_multisite') && max_mega_menu_share_themes_across_multisite()) {
            return self::refusal('themes_shared', 'This network shares Max Mega Menu themes across its sites, so a theme change would reach every site; change it in the Max Mega Menu theme editor instead.');
        }
        if (! function_exists('max_mega_menu_save_themes')) {
            return self::refusal('plugin_api_missing', 'Max Mega Menu\'s theme functions are not loaded.');
        }
        $refused = self::theme_refusal($theme);
        if (null !== $refused) {
            return $refused;
        }
        $current = self::themes()[ $theme ];
        $unknown = array_values(array_diff(array_keys($settings), array_keys($current)));
        if ([] !== $unknown) {
            return self::refusal('unknown_setting', sprintf('Not a setting of this theme: %s.', implode(', ', $unknown)), [ 'unknown' => $unknown ]);
        }
        foreach ($settings as $key => $value) {
            if (! is_scalar($value) || strlen((string) $value) > ('custom_css' === $key ? 100000 : 500)) {
                return self::refusal('invalid_setting', sprintf('Invalid value for %s.', $key), [ 'key' => $key ]);
            }
        }
        if (array_key_exists('custom_css', $settings) && ! current_user_can('unfiltered_html')) {
            return self::refusal('operation_denied', 'A theme\'s custom_css is compiled into the site\'s stylesheet, so it needs the unfiltered_html capability.', [ 'reason' => 'capability', 'capability' => 'unfiltered_html' ]);
        }
        if (class_exists('Mega_Menu_Theme') && method_exists('Mega_Menu_Theme', 'from_settings')) {
            $compiled = \Mega_Menu_Theme::from_settings(self::prepare_theme(array_merge($current, $settings)))->test_compilation();
            if (is_wp_error($compiled)) {
                return self::refusal('theme_compile_failed', 'The theme would not compile, so nothing was saved: ' . $compiled->get_error_message(), [ 'theme' => $theme ]);
            }
        }
        return null;
    }

    private static function item_write_refusal(int $id, array $settings): ?array
    {
        $refused = self::item_refusal($id);
        if (null !== $refused) {
            return $refused;
        }
        $allowed = array_fill_keys(self::ITEM_BOOLS, 'bool') + self::ITEM_ENUMS + array_fill_keys(self::ITEM_INTS, 'int') + [ 'icon' => 'icon' ];
        $icon    = $settings['icon'] ?? null;
        unset($settings['icon']);
        $refused = self::values_refusal($settings, $allowed);
        if (null !== $refused) {
            return $refused;
        }
        if (null !== $icon && (! is_string($icon) || 1 !== preg_match('/^[A-Za-z0-9 _-]{1,100}$/', $icon))) {
            return self::refusal('invalid_setting', 'Invalid value for icon (a class name such as dashicons-cart, or disabled).', [ 'key' => 'icon' ]);
        }
        if (isset($settings['type']) && 'flyout' !== $settings['type'] && 0 !== (int) get_post_meta($id, '_menu_item_menu_item_parent', true)) {
            return self::refusal('top_level_only', 'Only a top level menu item opens a mega menu panel.', [ 'id' => $id ]);
        }
        return null;
    }

    // ------------------------------------------------------------ reads

    /** @return array<string, mixed> */
    private static function settings(): array
    {
        $settings = get_option(self::SETTINGS, []);
        return is_array($settings) ? $settings : [];
    }

    /** Theme locations plus the plugin's own custom locations: id => title. */
    private static function locations(): array
    {
        $locations = get_registered_nav_menus();
        $custom    = get_option('megamenu_locations', []);
        return is_array($custom) ? array_merge($locations, $custom) : $locations;
    }

    /** @return array<string, array<string, mixed>> every theme, merged over the defaults, as Mega_Menu_Theme::get_all() builds them. */
    private static function themes(): array
    {
        $defaults = class_exists('Mega_Menu_Theme') ? (array) \Mega_Menu_Theme::get_default()->settings : [];
        $all      = apply_filters('megamenu_themes', [ 'default' => $defaults ]);
        $saved    = function_exists('max_mega_menu_get_themes') ? max_mega_menu_get_themes() : get_option(self::THEMES);
        foreach (is_array($saved) ? $saved : [] as $id => $theme) {
            $all[ (string) $id ] = array_merge($all[ (string) $id ] ?? [], (array) $theme);
        }
        foreach ($all as $id => $theme) {
            $all[ $id ] = array_merge($defaults, (array) $theme);
        }
        return $all;
    }

    /** @return array<string, mixed> */
    private static function get_settings(): array
    {
        $settings  = self::settings();
        $assigned  = (array) get_nav_menu_locations();
        $locations = [];
        foreach (self::locations() as $id => $title) {
            $own     = isset($settings[ $id ]) && is_array($settings[ $id ]) ? $settings[ $id ] : [];
            $menu    = (int) ($assigned[ $id ] ?? 0);
            $enabled = isset($own['enabled']) && (bool) $own['enabled'];
            $locations[] = [
                'location' => (string) $id,
                'title'    => (string) $title,
                'enabled'  => $enabled,
                'active'   => $enabled && $menu > 0 && is_nav_menu($menu),
                'menu_id'  => $menu,
                'theme'    => (string) ($own['theme'] ?? 'default'),
                'settings' => $own,
            ];
            unset($settings[ $id ]);
        }
        $themes = [];
        foreach (self::themes() as $id => $theme) {
            $themes[] = [ 'id' => (string) $id, 'title' => (string) ($theme['title'] ?? $id) ];
        }
        return [
            'settings'  => $settings,
            'locations' => $locations,
            'themes'    => $themes,
            'css'       => [
                'last_updated'            => (int) get_option('megamenu_css_last_updated', 0),
                'filesystem_write_failed' => (bool) get_option('megamenu_failed_to_write_css_to_filesystem'),
            ],
        ];
    }

    private static function menu_of(array $args): int
    {
        if (isset($args['location'])) {
            $menu = (int) (get_nav_menu_locations()[ (string) $args['location'] ] ?? 0);
        } else {
            $menu = (int) ($args['menu_id'] ?? 0);
        }
        return $menu > 0 && is_nav_menu($menu) ? $menu : 0;
    }

    /** @return array<string, mixed> */
    private static function item_settings(int $id): array
    {
        $defaults = class_exists('Mega_Menu_Nav_Menus') ? (array) \Mega_Menu_Nav_Menus::get_menu_item_defaults() : [];
        return array_merge($defaults, array_filter((array) get_post_meta($id, self::ITEM_META, true)));
    }

    /** @return array<string, mixed> */
    private static function view_item(int $id, bool $full): array
    {
        $post     = get_post($id);
        $settings = self::item_settings($id);
        $out      = [
            'id'             => $id,
            'title'          => (string) ($post->post_title ?: get_the_title((int) get_post_meta($id, '_menu_item_object_id', true))),
            'parent'         => (int) get_post_meta($id, '_menu_item_menu_item_parent', true),
            'type'           => (string) ($settings['type'] ?? 'flyout'),
            'icon'           => (string) ($settings['icon'] ?? 'disabled'),
            'hide_on_mobile' => 'true' === ($settings['hide_on_mobile'] ?? 'false'),
        ];
        if ($full) {
            $out['settings'] = $settings;
            $out['widgets']  = self::widgets($id);
        }
        return $out;
    }

    /** Widgets of the 'mega-menu' sidebar placed in this item's panel. */
    private static function widgets(int $id): array
    {
        $sidebars = get_option('sidebars_widgets', []);
        $out      = [];
        foreach ((array) ($sidebars['mega-menu'] ?? []) as $widget_id) {
            if (1 !== preg_match('/^(.+)-(\d+)$/', (string) $widget_id, $m)) {
                continue;
            }
            $instances = get_option('widget_' . $m[1], []);
            $instance  = is_array($instances) ? ($instances[ (int) $m[2] ] ?? null) : null;
            if (! is_array($instance) || (int) ($instance['mega_menu_parent_menu_id'] ?? 0) !== $id) {
                continue;
            }
            $out[] = [
                'widget_id' => (string) $widget_id,
                'id_base'   => $m[1],
                'title'     => (string) ($instance['title'] ?? ''),
                'columns'   => (int) ($instance['mega_menu_columns'] ?? 1),
            ];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function list_items(int $menu): array
    {
        $items = wp_get_nav_menu_items($menu);
        $out   = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $out[] = self::view_item((int) $item->ID, false);
        }
        return [ 'menu_id' => $menu, 'items' => $out ];
    }

    // ------------------------------------------------------------ writes

    /** @return array{regenerated: bool, error?: string} */
    private static function rebuild_css(): array
    {
        if (! has_action('megamenu_delete_cache')) {
            return [ 'regenerated' => false, 'error' => 'Max Mega Menu\'s style manager is not loaded, so the CSS was not rebuilt.' ];
        }
        $failed   = null;
        $listener = static function ($error = null) use (&$failed): void {
            $failed = is_wp_error($error) ? $error->get_error_message() : 'The CSS did not compile.';
        };
        add_action('megamenu_delete_cache_failed', $listener);
        try {
            do_action('megamenu_delete_cache');
        } finally {
            remove_action('megamenu_delete_cache_failed', $listener);
        }
        return null === $failed ? [ 'regenerated' => true ] : [ 'regenerated' => false, 'error' => $failed ];
    }

    /** @return array<string, mixed> */
    private static function update_location(string $location, array $changes): array
    {
        $settings = self::settings();
        $own      = isset($settings[ $location ]) && is_array($settings[ $location ]) ? $settings[ $location ] : [];
        foreach ($changes as $key => $value) {
            if ('enabled' === $key) {
                // Max Mega Menu stores '1' for on and drops the key for off.
                if ($value) {
                    $own['enabled'] = '1';
                } else {
                    unset($own['enabled']);
                }
                continue;
            }
            $own[ $key ] = sanitize_text_field((string) $value);
        }
        $settings[ $location ] = $own;
        update_option(self::SETTINGS, $settings);
        do_action('megamenu_after_save_settings');

        return [ 'location' => $location, 'settings' => $own, 'css' => self::rebuild_css() ];
    }

    /** @return array<string, mixed> */
    private static function update_global(array $changes): array
    {
        $settings = self::settings();
        foreach ($changes as $key => $value) {
            $settings[ $key ] = sanitize_text_field((string) $value);
        }
        update_option(self::SETTINGS, $settings);
        do_action('megamenu_after_save_general_settings');

        return [ 'settings' => array_intersect_key($settings, self::GLOBAL_KEYS), 'css' => self::rebuild_css() ];
    }

    /** Values as the theme editor saves them (Mega_Menu_Themes::get_prepared_theme_for_saving). */
    private static function prepare_theme(array $theme): array
    {
        if (isset($theme['responsive_breakpoint']) && is_numeric($theme['responsive_breakpoint'])) {
            $theme['responsive_breakpoint'] .= 'px';
        }
        unset($theme['toggle_blocks']);
        return array_map(static fn ($v) => esc_attr((string) $v), $theme);
    }

    /** @return array<string, mixed> */
    private static function update_theme(string $theme, array $changes): array
    {
        $saved = max_mega_menu_get_themes();
        $saved = is_array($saved) ? $saved : [];
        $own   = isset($saved[ $theme ]) && is_array($saved[ $theme ]) ? $saved[ $theme ] : [];
        foreach (self::prepare_theme($changes) as $key => $value) {
            $own[ $key ] = $value;
        }
        $saved[ $theme ] = $own;
        max_mega_menu_save_themes($saved);
        if (function_exists('max_mega_menu_save_last_updated_theme')) {
            max_mega_menu_save_last_updated_theme($theme);
        }
        do_action('megamenu_after_theme_save');

        return [ 'theme' => [ 'id' => $theme, 'settings' => self::themes()[ $theme ] ?? $own ], 'css' => self::rebuild_css() ];
    }

    /** @return array<string, mixed> */
    private static function update_item(int $id, array $changes): array
    {
        $meta = get_post_meta($id, self::ITEM_META, true);
        $meta = is_array($meta) ? $meta : [];
        foreach ($changes as $key => $value) {
            if (in_array($key, self::ITEM_BOOLS, true)) {
                $meta[ $key ] = $value ? 'true' : 'false';
            } elseif (in_array($key, self::ITEM_INTS, true)) {
                $meta[ $key ] = (int) $value;
            } else {
                $meta[ $key ] = sanitize_text_field((string) $value);
            }
        }
        update_post_meta($id, self::ITEM_META, wp_slash($meta));
        do_action('megamenu_save_menu_item_settings', $id);

        return [ 'item' => self::view_item($id, true), 'css' => self::rebuild_css() ];
    }
}
