<?php

namespace WPMCP\Tools\WidgetBuilder;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delete a custom widget by moving its wpmcp_widget post to the trash
 * (reversible through WordPress trash / restore-post), as an operation in
 * history so the delete is undoable from the operation as well as from the
 * trash.
 *
 * A compiled widget's generated class stops loading the moment the spec leaves
 * 'publish', because the loader reads post status directly; the manifest entry
 * is disabled alongside it as a durable second switch. The entry and the
 * generated file are kept, not removed, so restoring the spec (through this
 * plugin's set-widget-status OR through the generic restore-post ability)
 * brings the compiled widget back without a recompile, which is what "delete
 * is restorable from the spec" has to mean for a feature that also writes
 * files.
 *
 * Permanently deleting the post is different: that purges the manifest entry
 * and unlinks the generated file (Widget_Registry::purge_on_delete()), so a
 * site does not accumulate orphaned generated PHP.
 */
class Delete_Custom_Widget
{
    public function handle(array $args)
    {
        $id = (int) ($args['widget_id'] ?? 0);
        if (! Widget_Spec_Store::is_widget($id)) {
            return new \WP_Error('widget_not_found', "No custom widget found with id {$id}.");
        }

        // No manifest flip here, deliberately. The loader reads post status,
        // so the trash alone already stops the compiled class loading, and
        // leaving the flag untouched is what makes the generic restore-post
        // ability a complete undo: flipping it here would bring the spec back
        // published but the compiled entry disabled, silently switching the
        // widget to the dynamic render path.
        $run = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'delete-custom-widget',
                'args'        => $args,
            ],
            static function () use ($id): void {
                wp_trash_post($id);
            }
        );

        $entry = Compiler\Compiled_Widget_Manifest::get($id);

        return [
            'widget_id'    => $id,
            'deleted'      => 'trashed',
            'compiled'     => null !== $entry,
            'operation_id' => $run['operation_id'],
        ];
    }
}
