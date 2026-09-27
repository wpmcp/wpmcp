<?php

namespace WPMCP\Tools\WidgetBuilder;

use WPMCP\Safety\Safe_Mutation;
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
 *  - Enabling is what makes generated PHP execute again, so on a site that
 *    has since turned the compiler opt-in off the entry is STORED disabled
 *    (and reported so). Otherwise an agent could undo an operator's
 *    deliberate shutdown, or leave a flag that starts executing the moment
 *    the filter is turned back on.
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

        $status  = 'draft' === ($args['status'] ?? '') ? 'draft' : 'publish';
        $enable  = 'publish' === $status;
        $has     = null !== Compiled_Widget_Manifest::get($id);
        $allowed = Compile_Custom_Widget::is_enabled();

        // The operation's snapshot carries the compiled entry alongside the
        // post, so undoing a status change restores both: republishing the
        // spec while leaving its compiled class off would silently switch the
        // widget to the dynamic render path.
        $run = Safe_Mutation::run(
            [
                'object_type'         => 'post',
                'object_id'           => $id,
                'session_id'          => (string) ($args['session_id'] ?? 'default'),
                'tool_name'           => 'set-widget-status',
                'args'                => $args,
                'extra_snapshot_data' => $has ? ['compiled_widget_entry' => Compiled_Widget_Manifest::capture_entry($id)] : [],
            ],
            static function () use ($id, $status, $has, $enable, $allowed) {
                wp_update_post(['ID' => $id, 'post_status' => $status]);
                if (! $has) {
                    return null;
                }
                // Enabling on a site that has turned the compiler opt-in off
                // stores the entry as DISABLED, not merely reports it: the
                // flag is what decides, the moment the filter comes back on,
                // whether generated PHP executes again.
                return Compiled_Widget_Manifest::set_enabled($id, $enable && $allowed);
            }
        );

        if (is_wp_error($run['result'])) {
            // Do not swallow a failed write as "not compiled".
            return $run['result'];
        }

        $compiled = $has ? ($enable && $allowed) : null;
        $note     = ($has && $enable && ! $allowed)
            ? 'The widget compiler is disabled on this site, so the compiled class is stored as disabled; the spec renders dynamically. Re-enable it with set-widget-status after turning the compiler back on.'
            : null;

        $out = [
            'widget_id'        => $id,
            'status'           => $status,
            'compiled_enabled' => $compiled,
            'operation_id'     => $run['operation_id'],
        ];
        if (null !== $note) {
            $out['note'] = $note;
        }
        return $out;
    }
}
