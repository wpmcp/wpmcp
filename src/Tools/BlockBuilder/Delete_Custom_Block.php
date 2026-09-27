<?php

namespace WPMCP\Tools\BlockBuilder;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delete a custom block by moving its wpmcp_block post to the trash, as an
 * operation in history: undoable through rollback-operation as well as through
 * WordPress trash / restore-post.
 */
class Delete_Custom_Block
{
    public function handle(array $args)
    {
        $id = (int) ($args['block_id'] ?? 0);
        if (! Block_Spec_Store::is_block($id)) {
            return new \WP_Error('block_not_found', "No custom block found with id {$id}.");
        }

        $run = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'delete-custom-block',
                'args'        => $args,
            ],
            static function () use ($id): void {
                wp_trash_post($id);
            }
        );

        return ['block_id' => $id, 'deleted' => 'trashed', 'operation_id' => $run['operation_id']];
    }
}
