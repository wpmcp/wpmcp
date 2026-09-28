<?php

namespace WPMCP\Tools\WidgetBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * List the custom widgets stored on this site (id, machine name, title,
 * active/inactive status, and whether each one has a compiled class that is
 * currently loading). Read-only.
 *
 * Staleness is deliberately NOT computed here: it costs a full compile per
 * row. get-custom-widget reports it for the one widget being inspected.
 */
class List_Custom_Widgets
{
    public function handle(array $args): array
    {
        // One loadability pass for the whole list: it hashes every compiled
        // file, so running it per row would be quadratic.
        $loadable = Compiler\Compiled_Widget_Manifest::loadable();
        $rows     = [];
        foreach (Widget_Spec_Store::all() as $row) {
            $id              = (int) ($row['widget_id'] ?? 0);
            $row['compiled'] = Compiler\Compiled_Widget_Manifest::status_for($id, null, $loadable);
            $rows[]          = $row;
        }
        return ['widgets' => $rows];
    }
}
