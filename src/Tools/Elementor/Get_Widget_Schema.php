<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: return the settings schema for a single Elementor widget type.
 *
 * By default, cataloged widgets (Widget_Catalog, issue #59) answer with the
 * hand-curated subset - typed params, defaults, responsive hints, and the
 * plugin the widget needs - which is what an agent should reach for first.
 * The full control stack Elementor itself defines (control name, type,
 * label, default, section) is available behind `full: true`, and is the
 * automatic fallback for widgets the catalog does not curate.
 *
 * Never mutates anything, so this is not routed through the safety core.
 * Degrades gracefully with a WP_Error when Elementor is not loaded.
 */
class Get_Widget_Schema
{
    public function handle(array $args)
    {
        if (! class_exists('\\Elementor\\Plugin')) {
            return new \WP_Error('elementor_not_active', 'Elementor is not active on this site.');
        }

        if (empty($args['widget_name'])) {
            return new \WP_Error('missing_widget_name', 'A widget_name is required.');
        }

        $widget_name = (string) $args['widget_name'];
        $full        = ! empty($args['full']);
        $registered  = \Elementor\Plugin::instance()->widgets_manager->get_widget_types($widget_name);

        if (null === $registered && ! Widget_Catalog::has($widget_name)) {
            return new \WP_Error('unknown_widget', "No Elementor widget registered as '{$widget_name}'.");
        }

        if (! $full && Widget_Catalog::has($widget_name)) {
            $schema = Widget_Catalog::curated_schema($widget_name);

            return [
                'widget_name' => $widget_name,
                'curated'     => true,
                'requires'    => $schema['requires'],
                'available'   => null !== Widget_Catalog::installed_widget($widget_name),
                'category'    => $schema['category'],
                'purpose'     => $schema['purpose'],
                'params'      => $schema['params'],
            ];
        }

        if (null === $registered) {
            return new \WP_Error(
                'widget_not_installed',
                "Widget '{$widget_name}' is cataloged but not installed, so its full control stack cannot be introspected."
            );
        }

        return [
            'widget_name' => $widget_name,
            'curated'     => false,
            'controls'    => Widget_View::controls($registered),
        ];
    }
}
