<?php

namespace WPMCP\Tools\Structure;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delete a classic widget instance: its settings and its placement in every
 * sidebar (issue #285). Both options are snapshotted together, so
 * rollback-operation brings the instance back where it was.
 */
class Delete_Sidebar_Widget
{
    public function handle(array $args): array
    {
        $widget_id           = (string) ($args['widget_id'] ?? '');
        [ $widget, $number ] = Sidebar_Widget_Store::instance($widget_id);
        $from                = Sidebar_Widget_Store::locate(Sidebar_Widget_Store::sidebars(), $widget_id);

        $operation_id = Sidebar_Widget_Store::run(
            'delete-sidebar-widget',
            $args,
            $widget,
            function () use ($widget, $number, $widget_id): void {
                $settings = Sidebar_Widget_Store::settings($widget);
                unset($settings[ $number ]);
                $widget->save_settings($settings);
                update_option('sidebars_widgets', Sidebar_Widget_Store::without(Sidebar_Widget_Store::sidebars(), $widget_id));
            }
        );

        return [
            'widget_id'    => $widget_id,
            'deleted'      => true,
            'from'         => $from,
            'operation_id' => $operation_id,
            'recoverable'  => true,
        ];
    }
}
