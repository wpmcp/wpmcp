<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only view of a Bricks site's design system, local templates and
 * element catalog (issue #391), from the storage the Bricks data model
 * documents:
 *
 * - global classes in the `bricks_global_classes` option (id, name,
 *   settings with breakpoint and state suffixes, modified, user_id) and
 *   their categories in `bricks_global_classes_categories`;
 * - global variables in `bricks_global_variables` (id, name, value,
 *   category) and their categories in `bricks_global_variables_categories`;
 * - color palettes in `bricks_color_palette` (id, name, colors of id, raw,
 *   light, dark);
 * - templates as `bricks_template` posts whose `_bricks_template_type`
 *   names the area, with header elements in `_bricks_page_header_2`,
 *   footer elements in `_bricks_page_footer_2`, every other type in
 *   `_bricks_page_content_2`, and conditions in `_bricks_template_settings`;
 * - the element catalog in the static \Bricks\Elements::$elements registry,
 *   which only exists while Bricks is loaded.
 *
 * Everything is read as stored; nothing here writes.
 */
class Bricks_Design
{
    public const TEMPLATE_POST_TYPE = 'bricks_template';

    /** Whether Bricks is loaded on this request. */
    public static function plugin_active(): bool
    {
        return (bool) apply_filters('wpmcp_bricks_active', defined('BRICKS_VERSION'));
    }

    /** @return array<string,mixed> */
    public static function design_system(): array
    {
        return [
            'builder'             => 'bricks',
            'scope'               => 'design_system',
            'plugin_active'       => self::plugin_active(),
            'classes'             => self::option_list('bricks_global_classes'),
            'class_categories'    => self::option_list('bricks_global_classes_categories'),
            'variables'           => self::option_list('bricks_global_variables'),
            'variable_categories' => self::option_list('bricks_global_variables_categories'),
            'palettes'            => self::option_list('bricks_color_palette'),
        ];
    }

    /** @return array<string,mixed> */
    public static function templates(): array
    {
        $templates = [];
        foreach (Builder_Design::posts([self::TEMPLATE_POST_TYPE]) as $post) {
            $type     = (string) get_post_meta($post->ID, '_bricks_template_type', true);
            $settings = get_post_meta($post->ID, '_bricks_template_settings', true);
            $settings = is_array($settings) ? $settings : [];

            $templates[] = [
                'id'         => (int) $post->ID,
                'title'      => $post->post_title,
                'status'     => $post->post_status,
                'type'       => $type,
                'conditions' => is_array($settings['templateConditions'] ?? null) ? $settings['templateConditions'] : [],
                'settings'   => $settings,
                'content'    => self::elements($post->ID, self::content_key($type)),
            ];
        }

        return [
            'builder'       => 'bricks',
            'scope'         => 'templates',
            'plugin_active' => self::plugin_active(),
            'templates'     => $templates,
        ];
    }

    /**
     * The element list, or one element's controls when $element names it.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public static function catalog(string $element = '')
    {
        if (! class_exists('Bricks\\Elements') || ! is_array(\Bricks\Elements::$elements ?? null) || [] === \Bricks\Elements::$elements) {
            return Builder_Design::not_loaded('bricks');
        }

        $registry = \Bricks\Elements::$elements;

        if ('' !== $element) {
            $entry = $registry[$element] ?? null;
            if (! is_array($entry)) {
                return Builder_Design::element_not_found('bricks', $element);
            }

            return [
                'builder' => 'bricks',
                'scope'   => 'catalog',
                'element' => [
                    'name'           => (string) ($entry['name'] ?? $element),
                    'label'          => (string) ($entry['label'] ?? $element),
                    'category'       => (string) ($entry['category'] ?? ''),
                    'controls'       => self::controls($entry, 'controls'),
                    'control_groups' => self::controls($entry, 'controlGroups'),
                ],
            ];
        }

        $elements = [];
        foreach ($registry as $name => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $elements[] = [
                'name'     => (string) ($entry['name'] ?? $name),
                'label'    => (string) ($entry['label'] ?? $name),
                'category' => (string) ($entry['category'] ?? ''),
            ];
        }

        return ['builder' => 'bricks', 'scope' => 'catalog', 'elements' => $elements];
    }

    /** The element row a template type keeps its tree in. */
    private static function content_key(string $type): string
    {
        if ('header' === $type) {
            return '_bricks_page_header_2';
        }

        return 'footer' === $type ? '_bricks_page_footer_2' : Bricks_Content::META_KEY;
    }

    /** @return array<int|string,mixed> a stored element list (native array or legacy JSON) */
    private static function elements(int $post_id, string $key): array
    {
        $raw = get_post_meta($post_id, $key, true);
        if (is_string($raw) && '' !== $raw) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? $raw : [];
    }

    /** @return array<int|string,mixed> */
    private static function option_list(string $option): array
    {
        $value = get_option($option, []);

        return is_array($value) ? $value : [];
    }

    /**
     * An element's controls or control groups. Bricks fills them into the
     * registry entry when it loads the element; when an entry lacks them,
     * the element class is loaded the way Bricks does (construct, load())
     * and its public property read.
     *
     * @param array<string,mixed> $entry
     * @return array<int|string,mixed>
     */
    private static function controls(array $entry, string $key): array
    {
        if (is_array($entry[$key] ?? null)) {
            return $entry[$key];
        }

        $class = (string) ($entry['class'] ?? '');
        if ('' === $class || ! class_exists($class)) {
            return [];
        }

        try {
            $instance = new $class();
            if (method_exists($instance, 'load')) {
                $instance->load();
            }
            $property = 'controls' === $key ? 'controls' : 'control_groups';
            $value    = $instance->{$property} ?? null;

            return is_array($value) ? $value : [];
        } catch (\Throwable $e) {
            unset($e);
            return [];
        }
    }
}
