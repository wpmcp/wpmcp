<?php

namespace WPMCP\Tools\Structure;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Update a classic widget instance's settings (issue #285). The given keys
 * are merged over the stored instance and the result goes through the
 * widget's own update() callback, so a partial update keeps the rest.
 */
class Update_Sidebar_Widget
{
    public function handle(array $args): array
    {
        $widget_id          = (string) ($args['widget_id'] ?? '');
        [ $widget, $number ] = Sidebar_Widget_Store::instance($widget_id);

        $old      = (array) Sidebar_Widget_Store::settings($widget)[ $number ];
        $instance = Sidebar_Widget_Store::sanitize(
            $widget,
            $number,
            array_merge($old, (array) ($args['instance'] ?? [])),
            $old
        );

        $operation_id = Sidebar_Widget_Store::run(
            'update-sidebar-widget',
            $args,
            $widget,
            function () use ($widget, $number, $instance): void {
                $settings            = Sidebar_Widget_Store::settings($widget);
                $settings[ $number ] = $instance;
                $widget->save_settings($settings);
            }
        );

        return [
            'widget_id'    => $widget_id,
            'sidebar_id'   => Sidebar_Widget_Store::locate(Sidebar_Widget_Store::sidebars(), $widget_id),
            'instance'     => $instance,
            'operation_id' => $operation_id,
            'recoverable'  => true,
        ];
    }
}
