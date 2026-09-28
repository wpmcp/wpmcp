<?php

namespace WPMCP\Tools\WidgetBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * List the control types a custom-widget spec may use, with the Elementor
 * control each maps to, the escaper applied to its value on output, and a
 * short description. `compilable` is derived from that same table (the escaper
 * a type declares must be one the generated-code lint accepts), so an agent
 * learns which types compile without discovering it from a failed compile, and
 * the flag cannot drift away from what the compiler will actually accept.
 * Read-only.
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
                // Derived, not asserted: a type is compilable exactly when it
                // declares the escaper Widget_Compiler emits its value through.
                // Hardcoding true would keep reading as a computed capability
                // while silently lying the first time a type is added without
                // one.
                'compilable'  => isset($meta['escaper']) && in_array(
                    $meta['escaper'],
                    \WPMCP\Tools\WidgetBuilder\Compiler\Generated_Code_Lint::ALLOWED_CALLS,
                    true
                ),
            ];
        }
        return ['control_types' => $types];
    }
}
