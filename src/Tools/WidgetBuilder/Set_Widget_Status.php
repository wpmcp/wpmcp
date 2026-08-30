<?php

namespace WPMCP\Tools\WidgetBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Enable or disable a custom widget by setting its wpmcp_widget post status:
 * 'publish' (active, registered in the editor) or 'draft' (inactive).
 *
 * A widget that has been compiled (issue #72) also has a manifest entry, and
 * the manifest is what decides whether its generated class loads at all. The
 * status change flips that entry too, so disabling a widget really does remove
 * it from the builder in both forms, while deleting neither the spec nor the
 * generated file: re-enabling is one call and needs no recompile.
 */
class Set_Widget_Status
{
    public function handle(array $args)
    {
        $id = (int) ($args['widget_id'] ?? 0);
        if (! Widget_Spec_Store::is_widget($id)) {
            return new \WP_Error('widget_not_found', "No custom widget found with id {$id}.");
        }

        $status = 'draft' === ($args['status'] ?? '') ? 'draft' : 'publish';
        wp_update_post(['ID' => $id, 'post_status' => $status]);

        $compiled = Compiler\Compiled_Widget_Manifest::set_enabled($id, 'publish' === $status);

        return ['widget_id' => $id, 'status' => $status, 'compiled_enabled' => $compiled ? 'publish' === $status : null];
    }
}
