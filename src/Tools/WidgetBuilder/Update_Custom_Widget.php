<?php

namespace WPMCP\Tools\WidgetBuilder;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Replace a custom widget's spec by id. The new spec is validated before it is
 * stored on the wpmcp_widget post, and the write is an operation in history
 * (Safe_Mutation snapshots the spec post) so an update is undoable rather than
 * a one-way overwrite of the source of truth. Reports `template_filtered` the
 * same way Create_Custom_Widget does; a template the kses gate empties is
 * refused and the previous spec stays in place.
 *
 * If the widget has a compiled class, that class is DISABLED here rather than
 * left in place. A compiled class wins over the spec at registration time, so
 * leaving it enabled would mean an accepted update silently changed nothing
 * on the front end: the site would keep rendering the previous template with
 * no error anywhere. Disabling it makes the spec store the source of truth it
 * is documented as; the response says so, and recompiling re-enables it.
 */
class Update_Custom_Widget
{
    public function handle(array $args)
    {
        $id = (int) ($args['widget_id'] ?? 0);
        if (! Widget_Spec_Store::is_widget($id)) {
            return new \WP_Error('widget_not_found', "No custom widget found with id {$id}.");
        }

        $spec  = is_array($args['spec'] ?? null) ? $args['spec'] : [];
        $valid = Widget_Spec::validate($spec);
        if (is_wp_error($valid)) {
            return self::explain_legacy($id, $valid);
        }

        // Capture the compiled entry with the post, so undoing this update
        // restores the previous spec AND re-enables the compiled class that
        // was rendering it.
        $compiled = null !== Compiler\Compiled_Widget_Manifest::get($id);
        $captured = Compiler\Compiled_Widget_Manifest::capture_entry($id);

        $run = Safe_Mutation::run(
            [
                'object_type'         => 'post',
                'object_id'           => $id,
                'session_id'          => (string) ($args['session_id'] ?? 'default'),
                'tool_name'           => 'update-custom-widget',
                'args'                => $args,
                'extra_snapshot_data' => $compiled ? ['compiled_widget_entry' => $captured] : [],
            ],
            static function () use ($id, $spec, $compiled, $captured) {
                // Disable the stale compiled class FIRST. If that write fails,
                // the spec is not touched: accepting the update while the old
                // class keeps winning at registration would be a silent no-op
                // on the front end.
                if ($compiled) {
                    $off = Compiler\Compiled_Widget_Manifest::set_enabled($id, false);
                    if (is_wp_error($off)) {
                        return $off;
                    }
                }
                $updated = Widget_Spec_Store::update($id, $spec);
                if (is_wp_error($updated) && $compiled) {
                    // The spec stayed as it was, so its compiled class is
                    // still the right one to render.
                    Compiler\Compiled_Widget_Manifest::restore_entry($captured);
                }
                return $updated;
            }
        );
        if (is_wp_error($run['result'])) {
            return $run['result'];
        }

        $out                 = Create_Custom_Widget::response($id, $spec);
        $out['operation_id'] = $run['operation_id'];

        if ($compiled) {
            $out['compiled_disabled'] = true;
            $out['note']              = 'This widget had a compiled class built from the previous spec. It has been disabled so the updated spec is what renders; run compile-custom-widget to compile the new spec.';
        }

        return $out;
    }

    /**
     * A spec stored before the current validation rules keeps rendering
     * (Widget_Spec::is_renderable()), but an update is a new write and must
     * meet them. When the stored spec itself fails, say so and name the
     * field, so an agent resubmitting the spec it just read knows exactly
     * what to change rather than seeing a bare refusal.
     */
    private static function explain_legacy(int $id, \WP_Error $error): \WP_Error
    {
        $stored = Widget_Spec_Store::get($id);
        if (! is_array($stored) || true === Widget_Spec::validate($stored)) {
            return $error;
        }
        $data  = (array) $error->get_error_data();
        $field = (string) ($data['field'] ?? '');
        $data['stored_spec_predates_rules'] = true;

        return new \WP_Error(
            $error->get_error_code(),
            $error->get_error_message()
                . ' This widget was stored under older, looser rules and still renders as stored; the update has to fix '
                . ('' !== $field ? $field : 'the field named above') . ' before it can be saved.',
            $data
        );
    }
}
