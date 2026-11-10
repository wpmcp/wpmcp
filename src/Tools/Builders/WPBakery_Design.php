<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * get-builder-content's site-wide scopes for WPBakery Page Builder (issue
 * #639), storage per its 9.1 source:
 *
 * - templates: the saved ("my") templates its templates panel keeps in the
 *   `wpb_js_templates` option, an array of `<id> => {name, template}` where
 *   template is a shortcode layout, each returned with its path tree, plus
 *   the hash update-builder-content's templates scope is pinned to. The
 *   plugin's built-in templates are named too (addressed `default:<n>`)
 *   while it is loaded, since they live in its code, not the database.
 * - design_system: the Design Options settings (accent colors, element
 *   margin, gutter and breakpoints, each a `wpb_js_<key>` option, used only
 *   while `wpb_js_use_custom` is on) and the site-wide custom CSS
 *   (`wpb_js_custom_css`). Read only.
 * - catalog: its element map (WPBMap), which exists only while the plugin is
 *   loaded; with element, that element's params.
 */
final class WPBakery_Design
{
    public const TEMPLATES_OPTION = 'wpb_js_templates';

    private const PREFIX = 'wpb_js_';

    /** Design Options color keys (vc-design-options get_color_settings()). */
    private const COLORS = [
        'vc_color', 'vc_color_hover', 'vc_color_call_to_action_bg', 'vc_color_google_maps_bg',
        'vc_color_post_slider_caption_bg', 'vc_color_progress_bar_bg', 'vc_color_separator_border',
        'vc_color_tab_bg', 'vc_color_tab_bg_active',
    ];

    private const LAYOUT = ['margin', 'gutter', 'responsive_max', 'responsive_md', 'responsive_lg'];

    /** The param keys a catalog element returns; the rest is editor UI. */
    private const PARAM_KEYS = ['param_name', 'type', 'heading', 'description', 'value', 'std', 'group', 'admin_label', 'dependency'];

    /**
     * @return array<string,mixed>|\WP_Error
     */
    public static function read(string $scope, string $element = '')
    {
        if ('templates' === $scope) {
            return self::templates();
        }

        return 'design_system' === $scope ? self::design_system() : self::catalog($element);
    }

    /** The loaded plugin's version, or null when it is not loaded. */
    public static function plugin_version(): ?string
    {
        $version = apply_filters('wpmcp_wpbakery_version', defined('WPB_VC_VERSION') ? (string) constant('WPB_VC_VERSION') : null);

        return is_string($version) && '' !== $version ? $version : null;
    }

    /** @return array<string,mixed> */
    public static function templates(): array
    {
        $templates = [];
        foreach (self::user_templates() as $id => $template) {
            $templates[] = [
                'id'      => (string) $id,
                'name'    => (string) $template['name'],
                'content' => (string) $template['template'],
                'tree'    => WPBakery_Shortcodes::tree((string) $template['template']),
            ];
        }

        $defaults = [];
        foreach (self::default_templates() as $index => $template) {
            $defaults[] = ['id' => 'default:' . $index, 'name' => (string) ($template['name'] ?? '')];
        }

        return [
            'builder'        => 'wpbakery',
            'scope'          => 'templates',
            'plugin_version' => self::plugin_version(),
            'templates'      => $templates,
            'defaults'       => $defaults,
            'hash'           => Builder_Design_Data::option_hash(self::TEMPLATES_OPTION),
        ];
    }

    /**
     * Saved templates as stored, skipping entries not in the shape the
     * templates panel writes.
     *
     * @return array<string,array{name:string,template:string}>
     */
    public static function user_templates(): array
    {
        $stored = get_option(self::TEMPLATES_OPTION, []);
        if (! is_array($stored)) {
            return [];
        }

        $out = [];
        foreach ($stored as $id => $template) {
            if (is_array($template) && isset($template['template']) && is_string($template['template'])) {
                $out[ (string) $id ] = ['name' => (string) ($template['name'] ?? ''), 'template' => $template['template']];
            }
        }

        return $out;
    }

    /**
     * The layout a template id names: a saved template's id, or
     * `default:<n>` for a built-in one (plugin loaded only).
     */
    public static function template_content(string $id): ?string
    {
        if (preg_match('/^default:(\d+)$/', $id, $m)) {
            $template = self::default_templates()[ (int) $m[1] ] ?? null;

            return is_array($template) && isset($template['content']) ? (string) $template['content'] : null;
        }

        return self::user_templates()[ $id ]['template'] ?? null;
    }

    /** @return array<int,array<string,mixed>> the plugin's built-in templates */
    private static function default_templates(): array
    {
        if (! function_exists('wpbakery')) {
            return [];
        }

        try {
            $templates = wpbakery()->templatesPanelEditor()->getDefaultTemplates();
        } catch (\Throwable $e) {
            unset($e);
            return [];
        }

        return is_array($templates) ? $templates : [];
    }

    /** @return array<string,mixed> */
    public static function design_system(): array
    {
        $colors = [];
        foreach (self::COLORS as $key) {
            $colors[ $key ] = (string) get_option(self::PREFIX . $key, '');
        }
        $layout = [];
        foreach (self::LAYOUT as $key) {
            $layout[ $key ] = (string) get_option(self::PREFIX . $key, '');
        }

        return [
            'builder'        => 'wpbakery',
            'scope'          => 'design_system',
            'plugin_version' => self::plugin_version(),
            'use_custom'     => '1' === (string) get_option(self::PREFIX . 'use_custom', ''),
            'colors'         => $colors,
            'layout'         => $layout,
            'custom_css'     => (string) get_option(self::PREFIX . 'custom_css', ''),
        ];
    }

    /**
     * @return array<string,mixed>|\WP_Error
     */
    public static function catalog(string $element = '')
    {
        $map = self::map();
        if (null === $map) {
            return Builder_Design_Data::not_loaded('wpbakery');
        }

        if ('' !== $element) {
            $entry = $map[ $element ] ?? null;
            if (! is_array($entry)) {
                return Builder_Design_Data::element_not_found('wpbakery', $element);
            }

            $params = [];
            foreach ((array) ($entry['params'] ?? []) as $param) {
                if (is_array($param)) {
                    $params[] = array_intersect_key($param, array_flip(self::PARAM_KEYS));
                }
            }

            return [
                'builder' => 'wpbakery',
                'scope'   => 'catalog',
                'element' => self::summary($element, $entry) + ['params' => $params],
            ];
        }

        $elements = [];
        foreach ($map as $tag => $entry) {
            if (is_array($entry)) {
                $elements[] = self::summary((string) $tag, $entry);
            }
        }
        usort($elements, static fn (array $a, array $b): int => strcmp($a['tag'], $b['tag']));

        return [
            'builder'        => 'wpbakery',
            'scope'          => 'catalog',
            'plugin_version' => self::plugin_version(),
            'elements'       => $elements,
        ];
    }

    /** @return array<string,array<string,mixed>>|null the element map, null when the plugin is not loaded */
    private static function map(): ?array
    {
        $map = apply_filters('wpmcp_wpbakery_map', null);
        if (null === $map && class_exists('WPBMap') && method_exists('WPBMap', 'getAllShortCodes')) {
            try {
                $map = \WPBMap::getAllShortCodes();
            } catch (\Throwable $e) {
                unset($e);
                $map = null;
            }
        }

        return is_array($map) ? $map : null;
    }

    /** @return array<string,mixed> */
    private static function summary(string $tag, array $entry): array
    {
        $category = $entry['category'] ?? '';

        return [
            'tag'         => $tag,
            'name'        => (string) ($entry['name'] ?? $tag),
            'category'    => is_array($category) ? implode(', ', array_map('strval', $category)) : (string) $category,
            'description' => (string) ($entry['description'] ?? ''),
            'container'   => ! empty($entry['is_container']) || ! empty($entry['as_parent']),
            'deprecated'  => ! empty($entry['deprecated']),
        ];
    }
}
