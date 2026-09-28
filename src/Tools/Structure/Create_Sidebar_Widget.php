<?php

namespace WPMCP\Tools\Structure;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create a classic widget instance and place it in a sidebar (issue #285).
 * The settings pass through the widget's own update() callback; the new
 * instance and its placement are one undo point (see Sidebar_Widget_Store).
 */
class Create_Sidebar_Widget
{
    public function handle(array $args): array
    {
        $sidebar_id = (string) ($args['sidebar_id'] ?? '');
        $widget     = Sidebar_Widget_Store::widget_type((string) ($args['id_base'] ?? ''));
        Sidebar_Widget_Store::assert_sidebar($sidebar_id);

        $number    = Sidebar_Widget_Store::next_number($widget);
        $instance  = Sidebar_Widget_Store::sanitize($widget, $number, (array) ($args['instance'] ?? []), []);
        $widget_id = $widget->id_base . '-' . $number;
        $position  = 0;

        $operation_id = Sidebar_Widget_Store::run(
            'create-sidebar-widget',
            $args,
            $widget,
            function () use ($widget, $number, $instance, $sidebar_id, $widget_id, $args, &$position): void {
                $settings            = Sidebar_Widget_Store::settings($widget);
                $settings[ $number ] = $instance;
                $widget->save_settings($settings);

                [ $sidebars, $position ] = Sidebar_Widget_Store::place(
                    Sidebar_Widget_Store::sidebars(),
                    $sidebar_id,
                    $widget_id,
                    $args['position'] ?? null
                );
                update_option('sidebars_widgets', $sidebars);
            }
        );

        return [
            'widget_id'    => $widget_id,
            'sidebar_id'   => $sidebar_id,
            'position'     => $position,
            'instance'     => $instance,
            'operation_id' => $operation_id,
            'recoverable'  => true,
        ];
    }
}
