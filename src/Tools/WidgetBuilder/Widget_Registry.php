<?php

namespace WPMCP\Tools\WidgetBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registers active custom-widget specs as real Elementor widgets at runtime.
 *
 * Data-driven, no code generation: every active spec is registered as an
 * instance of the single Dynamic_Widget, which renders by interpolating control
 * values into the spec's template (see Widget_Renderer). There is no eval and
 * no per-widget generated class, keeping wpmcp's single-eval-site invariant.
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

        foreach (Widget_Spec_Store::all(true) as $row) {
            $spec = Widget_Spec_Store::get((int) $row['widget_id']);
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
        ]);
        if (empty($posts)) {
            return null;
        }
        $spec = get_post_meta($posts[0]->ID, '_wpmcp_widget_spec', true);
        return is_array($spec) ? $spec : null;
    }
}
