<?php

namespace WPMCP\Tools\Builders;

use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * BeTheme's site-wide scopes on get-builder-content (issue #643), read only.
 * Recorded from the BeTheme 29.0.5 source:
 *
 * - design_system: the theme options, one array in the `betheme` option
 *   (Muffin Options, opt_name 'betheme'). The read returns the colors
 *   (keys holding 'color' or 'background'), the typography (font-*) and the
 *   layout keys, plus the builder storage mode; with `element` it returns
 *   that one option key. Keys holding API keys or secrets (the Google Maps
 *   key, the reCAPTCHA keys) are never returned.
 * - templates: the `template` posts BeBuilder saves (page, section, wrap,
 *   header, footer, popup and the WooCommerce kinds, `mfn_template_type`),
 *   kept to the ones the caller may read.
 * - catalog: the item types Mfn_Builder_Fields defines, so BeTheme must be
 *   active; with `element`, that item's fields (or 'section' / 'wrap' for
 *   theirs).
 */
final class BeTheme_Design
{
    public const OPTION = 'betheme';

    /** Option keys never returned: credentials for outside services. */
    private const SECRET = '/(api-key|secret|token|password|purchase-code|recaptcha-key)/';

    private const LAYOUT_KEYS = ['layout', 'grid-width', 'mobile-grid-width', 'layout-boxed-padding', 'header-style', 'footer-style', 'style', 'skin', 'builder-storage', 'builder-blocks'];

    /** @return array<string,mixed>|\WP_Error */
    public static function read(string $scope, string $element)
    {
        if ('templates' === $scope) {
            return self::templates();
        }

        return 'design_system' === $scope ? self::design_system($element) : self::catalog($element);
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function design_system(string $key = '')
    {
        $options = get_option(self::OPTION, []);
        $options = is_array($options) ? $options : [];
        $out     = [
            'builder'        => 'betheme',
            'scope'          => 'design_system',
            'plugin_active'  => BeTheme_Cache::plugin_active(),
            'plugin_version' => BeTheme_Content::plugin_version(),
            'storage'        => 'encode' === ($options['builder-storage'] ?? '') ? 'encoded' : 'serialized',
        ];

        if ('' !== $key) {
            if (1 === preg_match(self::SECRET, $key)) {
                return new \WP_Error('forbidden_option', "Theme option '{$key}' holds a credential and is not returned.");
            }
            if (! array_key_exists($key, $options)) {
                return new \WP_Error('option_not_found', "BeTheme has no stored option '{$key}'; it uses the theme default.");
            }
            return $out + ['key' => $key, 'value' => $options[ $key ]];
        }

        $colors = [];
        $fonts  = [];
        $layout = [];
        foreach ($options as $name => $value) {
            $name = (string) $name;
            if (1 === preg_match(self::SECRET, $name)) {
                continue;
            }
            if (in_array($name, self::LAYOUT_KEYS, true)) {
                $layout[ $name ] = $value;
            } elseif (0 === strpos($name, 'font-') || 0 === strpos($name, 'typography')) {
                $fonts[ $name ] = $value;
            } elseif (false !== strpos($name, 'color') || 0 === strpos($name, 'background-')) {
                $colors[ $name ] = $value;
            }
        }

        return $out + ['colors' => $colors, 'typography' => $fonts, 'layout' => $layout, 'option_count' => count($options)];
    }

    /** @return array<string,mixed> */
    public static function templates(): array
    {
        $templates = [];
        $posts     = Readable_Posts::get_posts([
            'post_type'      => BeTheme_Content::TEMPLATE_POST_TYPE,
            'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => 200,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);
        foreach ($posts as $post) {
            if (! $post instanceof \WP_Post) {
                continue;
            }
            $layout      = BeTheme_Cache::decode((int) $post->ID) ?? [];
            $templates[] = [
                'id'       => (int) $post->ID,
                'title'    => (string) $post->post_title,
                'status'   => (string) $post->post_status,
                'type'     => (string) get_post_meta((int) $post->ID, 'mfn_template_type', true),
                'sections' => count($layout),
            ];
        }

        return ['builder' => 'betheme', 'scope' => 'templates', 'templates' => $templates];
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function catalog(string $element)
    {
        $fields = BeTheme_Content::fields();
        if (null === $fields) {
            return Builder_Design_Data::not_loaded('betheme');
        }
        $out = ['builder' => 'betheme', 'scope' => 'catalog', 'plugin_version' => BeTheme_Content::plugin_version()];

        if ('' !== $element) {
            $kind = in_array($element, ['section', 'wrap'], true) ? $element : 'item';
            $list = BeTheme_Content::field_list($kind, $element);
            if (null === $list) {
                return Builder_Design_Data::element_not_found('betheme', $element);
            }
            $controls = [];
            foreach ($list as $field) {
                if (! isset($field['id']) || in_array($field['type'] ?? '', ['html', 'header', 'helper', 'info'], true)) {
                    continue;
                }
                $control = ['id' => (string) $field['id'], 'type' => (string) ($field['type'] ?? ''), 'title' => wp_strip_all_tags((string) ($field['title'] ?? ''))];
                if (isset($field['options']) && is_array($field['options'])) {
                    $control['options'] = array_map(static fn ($v) => is_scalar($v) ? wp_strip_all_tags((string) $v) : $v, $field['options']);
                }
                if (isset($field['std'])) {
                    $control['default'] = $field['std'];
                }
                if (isset($field['selector'], $field['style'])) {
                    $control['style'] = true;
                }
                if (! empty($field['responsive'])) {
                    $control['responsive'] = true;
                }
                $controls[] = $control;
            }

            return $out + ['element' => ['type' => $element, 'fields' => $controls]];
        }

        $items = [];
        foreach ((array) $fields->get_items() as $type => $item) {
            if (! is_array($item)) {
                continue;
            }
            $items[] = [
                'type'     => (string) $type,
                'title'    => wp_strip_all_tags((string) ($item['title'] ?? $type)),
                'category' => (string) ($item['cat'] ?? ''),
                'size'     => (string) ($item['size'] ?? ''),
            ];
        }

        return $out + ['elements' => $items];
    }
}
