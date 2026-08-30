<?php

namespace WPMCP\Tools\WidgetBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * List the control types a custom-widget spec may use, with the Elementor
 * control each maps to, the escaper applied to its value on output, and a
 * short description. Every declared type is compilable (the escaper table in
 * Widget_Spec::CONTROL_TYPES is what both the renderer and the compiler read),
 * so `compilable` is reported explicitly rather than left for the agent to
 * discover from a failed compile. Read-only.
 */
class List_Control_Types
{
    public function handle(array $args): array
    {
        $types = [];
        foreach (Widget_Spec::CONTROL_TYPES as $type => $meta) {
            $types[] = [
                'type'        => $type,
                'elementor'   => $meta['elementor'],
                'description' => $meta['desc'],
                'escaper'     => $meta['escaper'],
                'compilable'  => true,
            ];
        }
        return ['control_types' => $types];
    }
}
