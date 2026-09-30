<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only view of a Breakdance or Oxygen 6 site's design system, local
 * templates and element catalog (issue #391). Both products run the same
 * engine and keep their site-wide data under their own prefix (`breakdance_`
 * or `oxygen_`), so every method takes the builder slug.
 *
 * - Global settings (the color palette among them) sit in the
 *   `<prefix>global_settings_json_string` option, variables in
 *   `<prefix>variables_json_string` and the class selectors in
 *   `<prefix>oxy_selectors_json_string` (Oxygen 6) or
 *   `<prefix>breakdance_classes_json_string` (Breakdance), each a JSON
 *   string the engine writes and decodes whole. The engine's option API
 *   encodes that string once more before storing it, so the stored value
 *   is a JSON string literal; both layers are decoded (see
 *   Builder_Design_Data::engine_decode()) and the document is otherwise
 *   returned as stored.
 * - Templates are posts of the `<prefix>template`, `<prefix>header`,
 *   `<prefix>footer`, `<prefix>block` and `<prefix>popup` types. Each keeps
 *   its tree in the same data row a page does (read through
 *   Breakdance_Content) and its conditions in
 *   `_<prefix>template_settings`.
 * - The element catalog is every loaded class extending
 *   \Breakdance\Elements\Element, whose static contentControls(),
 *   designControls() and settingsControls() are the control schemas.
 */
class Breakdance_Design
{
    public const TEMPLATE_KINDS = ['template', 'header', 'footer', 'block', 'popup'];

    /** The option and post type prefix, without the meta row's leading underscore. */
    private static function prefix(string $builder): string
    {
        return ltrim(Breakdance_Cache::prefix($builder), '_');
    }

    /** @return array<string,mixed> */
    public static function design_system(string $builder): array
    {
        $prefix   = self::prefix($builder);
        $settings = self::json_option($prefix . 'global_settings_json_string');

        $classes = [];
        $keys    = 'oxygen' === $builder
            ? ['oxy_selectors_json_string', 'breakdance_classes_json_string']
            : ['breakdance_classes_json_string', 'oxy_selectors_json_string'];
        foreach ($keys as $key) {
            $classes = self::json_option($prefix . $key);
            if ([] !== $classes) {
                break;
            }
        }

        $palette = $settings['settings']['colors']['palette'] ?? ($settings['colors']['palette'] ?? null);

        $out = [
            'builder'         => $builder,
            'scope'           => 'design_system',
            'plugin_active'   => Breakdance_Cache::plugin_active($builder),
            'classes'         => $classes,
            'variables'       => self::json_option($prefix . 'variables_json_string'),
            'palettes'        => is_array($palette) ? [$palette] : [],
            'global_settings' => $settings,
        ];

        // The lists update-builder-content can write, each with the hash of
        // the option it lives in (Breakdance only: see Builder_Design_Write).
        if ('breakdance' === $builder) {
            $out['hashes'] = [
                'classes'  => Builder_Design_Data::option_hash($prefix . 'breakdance_classes_json_string'),
                'palettes' => Builder_Design_Data::option_hash($prefix . 'global_settings_json_string'),
            ];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public static function templates(string $builder): array
    {
        $prefix = self::prefix($builder);
        $types  = array_map(static fn ($kind) => $prefix . $kind, self::TEMPLATE_KINDS);

        $templates = [];
        foreach (Builder_Design_Data::posts($types) as $post) {
            $settings = get_post_meta($post->ID, '_' . $prefix . 'template_settings', true);
            if (is_string($settings)) {
                $settings = json_decode($settings, true);
            }
            $doc = Breakdance_Content::get_document($post->ID, $builder);

            $templates[] = [
                'id'       => (int) $post->ID,
                'title'    => $post->post_title,
                'status'   => $post->post_status,
                'type'     => substr($post->post_type, strlen($prefix)),
                'settings' => is_array($settings) ? $settings : [],
                'tree'     => null === $doc ? [] : Breakdance_Tree::nodes($doc),
            ];
        }

        return [
            'builder'       => $builder,
            'scope'         => 'templates',
            'plugin_active' => Breakdance_Cache::plugin_active($builder),
            'templates'     => $templates,
        ];
    }

    /**
     * The element list, or one element's control schemas when $element
     * names its class (the node `type` a tree stores).
     *
     * @return array<string,mixed>|\WP_Error
     */
    public static function catalog(string $builder, string $element = '')
    {
        if (! Breakdance_Cache::plugin_active($builder) || ! class_exists('Breakdance\\Elements\\Element')) {
            return Builder_Design_Data::not_loaded($builder);
        }

        $classes = self::element_classes();

        if ('' !== $element) {
            $class = ltrim($element, '\\');
            if (! in_array($class, $classes, true)) {
                return Builder_Design_Data::element_not_found($builder, $element);
            }

            return [
                'builder' => $builder,
                'scope'   => 'catalog',
                'element' => self::summary($class) + [
                    'controls' => [
                        'content'  => self::call($class, 'contentControls'),
                        'design'   => self::call($class, 'designControls'),
                        'settings' => self::call($class, 'settingsControls'),
                    ],
                ],
            ];
        }

        return [
            'builder'  => $builder,
            'scope'    => 'catalog',
            'elements' => array_map([self::class, 'summary'], $classes),
        ];
    }

    /** @return string[] every loaded, concrete element class */
    private static function element_classes(): array
    {
        $classes = [];
        foreach (get_declared_classes() as $class) {
            if (! is_subclass_of($class, 'Breakdance\\Elements\\Element')) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if (! $reflection->isAbstract()) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }

    /** @return array<string,string> */
    private static function summary(string $class): array
    {
        $name     = self::call($class, 'name');
        $category = self::call($class, 'category');

        return [
            'type'     => $class,
            'name'     => is_string($name) ? $name : $class,
            'category' => is_string($category) ? $category : '',
        ];
    }

    /** @return mixed an element's static method result, [] when absent or failing */
    private static function call(string $class, string $method)
    {
        if (! method_exists($class, $method)) {
            return [];
        }

        try {
            return $class::$method();
        } catch (\Throwable $e) {
            unset($e);
            return [];
        }
    }

    /** @return array<int|string,mixed> */
    private static function json_option(string $option): array
    {
        [, $decoded] = Builder_Design_Data::engine_decode($option);

        return is_array($decoded) ? $decoded : [];
    }
}
