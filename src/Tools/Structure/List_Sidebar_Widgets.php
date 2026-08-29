<?php

namespace WPMCP\Tools\Structure;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: for a single registered sidebar id, return the widgets
 * assigned to it. Widget ids come from wp_get_sidebars_widgets(); each id is
 * resolved against the global $wp_registered_widgets to report its display
 * name where available.
 */
class List_Sidebar_Widgets
{
    public function handle(array $args): array
    {
        $sidebar_id = isset($args['sidebar_id']) ? (string) $args['sidebar_id'] : '';

        global $wp_registered_sidebars, $wp_registered_widgets;

        if (! isset($wp_registered_sidebars[ $sidebar_id ])) {
            throw new \InvalidArgumentException('Sidebar "' . esc_html($sidebar_id) . '" is not registered.');
        }

        // wp_get_sidebars_widgets() is on Plugin Check's forbidden-functions
        // list (it is a private core helper). The option it reads is the same
        // data, minus the theme-switch normalisation this read-only tool has
        // no use for.
        $sidebars_widgets = (array) get_option('sidebars_widgets', []);
        $widget_ids       = $sidebars_widgets[ $sidebar_id ] ?? [];

        $widgets = [];
        foreach ((array) $widget_ids as $widget_id) {
            $widget = $wp_registered_widgets[ $widget_id ] ?? null;
            $widgets[] = [
                'id'   => (string) $widget_id,
                'name' => (string) ($widget['name'] ?? ''),
            ];
        }

        return ['widgets' => $widgets];
    }
}
