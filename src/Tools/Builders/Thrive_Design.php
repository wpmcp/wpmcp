<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Thrive Architect's site-wide scopes on get-builder-content (issue #640),
 * read only. Recorded from the Thrive Architect 11.3 source:
 *
 * - design_system: the global colours (TCB_Color_Manager, option
 *   `thrv_global_colours`), global gradients (`thrv_global_gradients`), the
 *   favourite colours and gradients the picker keeps (`thrv_custom_colours`,
 *   `thrv_custom_gradients`) and the global styles per element kind
 *   (tve_get_global_styles_option_names(): `tve_global_<kind>_styles`). The
 *   option names go through the same filters Thrive applies.
 * - templates: the user templates (see Thrive_Page::templates()).
 * - catalog: the elements tcb_elements() registers, so Thrive must be
 *   loaded; with `element`, that element's default markup (what the editor
 *   drops in) and its control components.
 */
final class Thrive_Design
{
    public const STYLE_OPTIONS = [
        'button'     => 'tve_global_button_styles',
        'section'    => 'tve_global_section_styles',
        'contentbox' => 'tve_global_contentbox_styles',
        'link'       => 'tve_global_link_styles',
        'text'       => 'tve_global_text_styles',
    ];

    /** @return array<string,mixed>|\WP_Error */
    public static function read(string $scope, string $element)
    {
        if ('templates' === $scope) {
            return Thrive_Page::templates();
        }

        return 'design_system' === $scope ? self::design_system() : self::catalog($element);
    }

    /** @return array<string,mixed> */
    public static function design_system(): array
    {
        $styles = [];
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- applying Thrive Architect's own option-name filter, as it does when reading the option; wpmcp adds no callback to it.
        $names  = apply_filters('tcb_global_styles_option_name', self::STYLE_OPTIONS);
        foreach (is_array($names) ? $names : self::STYLE_OPTIONS as $kind => $option) {
            $value            = get_option((string) $option, []);
            $styles[ $kind ] = is_array($value) ? $value : [];
        }

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- applying Thrive Architect's own option-name filter, as it does when reading the option; wpmcp adds no callback to it.
        $colors = (string) apply_filters('tcb_global_colors_option_name', 'thrv_global_colours');
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- applying Thrive Architect's own option-name filter, as it does when reading the option; wpmcp adds no callback to it.
        $gradients = (string) apply_filters('tcb_global_gradients_option_name', 'thrv_global_gradients');

        return [
            'builder'        => 'thrive',
            'scope'          => 'design_system',
            'plugin_active'  => Thrive_Content::plugin_active(),
            'plugin_version' => Thrive_Page::plugin_version(),
            'colors'         => self::option_list($colors),
            'gradients'      => self::option_list($gradients),
            'favorite_colors'    => self::option_list('thrv_custom_colours'),
            'favorite_gradients' => self::option_list('thrv_custom_gradients'),
            'global_styles'  => $styles,
        ];
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function catalog(string $element)
    {
        if (! Thrive_Content::plugin_active() || ! function_exists('tcb_elements')) {
            return Builder_Design_Data::not_loaded('thrive');
        }

        $all = tcb_elements()->get();
        $out = [
            'builder'        => 'thrive',
            'scope'          => 'catalog',
            'plugin_version' => Thrive_Page::plugin_version(),
        ];

        if ('' !== $element) {
            $instance = is_array($all) ? ($all[ $element ] ?? null) : null;
            if (! is_object($instance)) {
                return Builder_Design_Data::element_not_found('thrive', $element);
            }
            $item = self::describe($element, $instance);
            try {
                $item['layout'] = (string) $instance->layout();
            } catch (\Throwable $e) {
                $item['layout'] = '';
            }
            try {
                $item['components'] = array_keys((array) $instance->components());
            } catch (\Throwable $e) {
                $item['components'] = [];
            }

            return $out + ['element' => $item];
        }

        $elements = [];
        foreach (is_array($all) ? $all : [] as $tag => $instance) {
            if (is_object($instance)) {
                $elements[] = self::describe((string) $tag, $instance);
            }
        }

        return $out + ['elements' => $elements];
    }

    /** @return array<string,mixed> */
    private static function describe(string $tag, object $instance): array
    {
        $call = static function (string $method) use ($instance) {
            try {
                return method_exists($instance, $method) ? $instance->$method() : null;
            } catch (\Throwable $e) {
                return null;
            }
        };

        return [
            'tag'        => $tag,
            'name'       => (string) $call('name'),
            'category'   => (string) $call('category'),
            'identifier' => (string) $call('identifier'),
            'hidden'     => (bool) $call('hide'),
        ];
    }

    /** @return array<int|string,mixed> */
    private static function option_list(string $option): array
    {
        $value = get_option($option, []);

        return is_array($value) ? $value : [];
    }
}
