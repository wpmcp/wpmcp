<?php

namespace WPMCP\Tools\WidgetBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registers active custom-widget specs as real Elementor widgets at runtime.
 *
 * Two forms, one spec store. By default a spec is registered as an instance of
 * the single Dynamic_Widget, which renders by interpolating control values into
 * the spec's template (see Widget_Renderer): no code generation, no eval.
 *
 * A spec that has been compiled (issue #72, PRO and opt-in) is registered from
 * its generated class instead, loaded by Compiled_Widget_Manifest only when the
 * file's hash still matches the manifest option. Compilation adds a third
 * execution site to the plugin (a require of plugin-generated PHP from a
 * protected wp-content sandbox); it is never reached unless a site turns the
 * compiler on, and the AI still never authors PHP. See docs/wip/issue-72.md.
 */
class Widget_Registry
{
    /** Hooked on elementor/widgets/register. */
    public static function register($widgets_manager = null): void
    {
        if (! class_exists('\\Elementor\\Widget_Base') || ! class_exists('\\WPMCP\\Tools\\WidgetBuilder\\Dynamic_Widget')) {
            return;
        }
        if (null === $widgets_manager) {
            if (! class_exists('\\Elementor\\Plugin')) {
                return;
            }
            $widgets_manager = \Elementor\Plugin::instance()->widgets_manager;
        }

        // Compiled widgets first: a spec with an enabled, hash-verified class
        // registers from that class, and must NOT also register a
        // Dynamic_Widget under the same name.
        $compiled = Compiler\Compiled_Widget_Manifest::load_enabled();
        foreach ($compiled as $class) {
            if (! class_exists($class, false)) {
                continue;
            }
            $widgets_manager->register(new $class());
        }

        foreach (Widget_Spec_Store::all(true) as $row) {
            $widget_id = (int) $row['widget_id'];
            if (isset($compiled[ $widget_id ])) {
                continue;
            }
            $spec = Widget_Spec_Store::get($widget_id);
            if (! is_array($spec) || true !== Widget_Spec::validate($spec)) {
                continue;
            }
            $widget = new Dynamic_Widget([], ['widget_name' => $spec['name']]);
            $widget->set_spec($spec);
            $widgets_manager->register($widget);
        }
    }

    /** Resolve an active spec by its machine name (used when Elementor rebuilds a widget). */
    public static function spec_for(string $name): ?array
    {
        Widget_Spec_Store::ensure_post_type();
        $posts = get_posts([
            'post_type'        => Widget_Spec_Store::POST_TYPE,
            'post_status'      => 'publish',
            'name'             => sanitize_title($name),
            'posts_per_page'   => 1,
            'suppress_filters' => true,
        ]);
        if (empty($posts)) {
            return null;
        }
        $spec = get_post_meta($posts[0]->ID, '_wpmcp_widget_spec', true);
        return is_array($spec) ? $spec : null;
    }
}
