<?php

namespace WPMCP\Tools\WidgetBuilder;

use WPMCP\Tools\WidgetBuilder\Compiler\Compile_Custom_Widget;
use WPMCP\Tools\WidgetBuilder\Compiler\Compiled_Widget_Manifest;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Enable or disable a custom widget by setting its wpmcp_widget post status:
 * 'publish' (active, registered in the editor) or 'draft' (inactive).
 *
 * Post status is the source of truth for BOTH widget forms: the compiled
 * loader reads it too (Compiled_Widget_Manifest::load_enabled()), so a draft
 * widget stops rendering in either form no matter which path set the status.
 * The manifest's own enabled flag is flipped alongside it as a second, durable
 * switch, and the two directions are not equally privileged:
 *
 *  - Disabling always flips. Turning execution OFF must never be blocked.
 *  - Enabling is what makes generated PHP execute again, so it is refused on a
 *    site that has since turned the compiler opt-in off. Otherwise an agent
 *    could undo an operator's deliberate shutdown of the feature.
 *
 * Neither direction deletes the spec or the generated file, so re-enabling is
 * one call and needs no recompile.
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

        $enable   = 'publish' === $status;
        $compiled = null;
        $note     = null;

        if (null === Compiled_Widget_Manifest::get($id)) {
            $compiled = null; // Not compiled; nothing to flip.
        } elseif ($enable && ! Compile_Custom_Widget::is_enabled()) {
            $compiled = false;
            $note     = 'The widget compiler is disabled on this site, so the compiled class stays off; the spec still renders dynamically.';
        } else {
            $flipped = Compiled_Widget_Manifest::set_enabled($id, $enable);
            if (is_wp_error($flipped)) {
                // Do not swallow a failed write as "not compiled".
                return $flipped;
            }
            $compiled = $enable;
        }

        $out = ['widget_id' => $id, 'status' => $status, 'compiled_enabled' => $compiled];
        if (null !== $note) {
            $out['note'] = $note;
        }
        return $out;
    }
}
