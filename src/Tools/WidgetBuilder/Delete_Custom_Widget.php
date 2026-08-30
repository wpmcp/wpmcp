<?php

namespace WPMCP\Tools\WidgetBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delete a custom widget by moving its wpmcp_widget post to the trash
 * (reversible through WordPress trash / restore-post).
 *
 * A compiled widget's manifest entry is disabled at the same time, so the
 * generated class stops loading the moment the spec is deleted. The entry and
 * the generated file are kept, not removed: restoring the spec from the trash
 * and re-publishing it brings the compiled widget back without a recompile,
 * which is what "delete is restorable from the spec" has to mean for a feature
 * that also writes files.
 */
class Delete_Custom_Widget
{
    public function handle(array $args)
    {
        $id = (int) ($args['widget_id'] ?? 0);
        if (! Widget_Spec_Store::is_widget($id)) {
            return new \WP_Error('widget_not_found', "No custom widget found with id {$id}.");
        }

        wp_trash_post($id);
        Compiler\Compiled_Widget_Manifest::set_enabled($id, false);

        return ['widget_id' => $id, 'deleted' => 'trashed'];
    }
}
