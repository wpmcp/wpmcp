<?php

namespace WPMCP\Tools\Structure;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Move a classic widget instance to a sidebar and position, including the
 * inactive widgets area (issue #285). The instance's settings are untouched;
 * the snapshot still covers both options so every widget write in a session
 * unwinds through the same path.
 */
class Move_Sidebar_Widget
{
    public function handle(array $args): array
    {
        $widget_id  = (string) ($args['widget_id'] ?? '');
        $sidebar_id = (string) ($args['sidebar_id'] ?? '');
        [ $widget ] = Sidebar_Widget_Store::instance($widget_id);
        Sidebar_Widget_Store::assert_sidebar($sidebar_id);

        $from     = Sidebar_Widget_Store::locate(Sidebar_Widget_Store::sidebars(), $widget_id);
        $position = 0;

        $operation_id = Sidebar_Widget_Store::run(
            'move-sidebar-widget',
            $args,
            $widget,
            function () use ($widget_id, $sidebar_id, $args, &$position): void {
                [ $sidebars, $position ] = Sidebar_Widget_Store::place(
                    Sidebar_Widget_Store::without(Sidebar_Widget_Store::sidebars(), $widget_id),
                    $sidebar_id,
                    $widget_id,
                    $args['position'] ?? null
                );
                update_option('sidebars_widgets', $sidebars);
            }
        );

        return [
            'widget_id'    => $widget_id,
            'from'         => $from,
            'sidebar_id'   => $sidebar_id,
            'position'     => $position,
            'operation_id' => $operation_id,
            'recoverable'  => true,
        ];
    }
}
