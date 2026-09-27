<?php

namespace WPMCP\Tools\BlockBuilder;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Enable (publish) or disable (draft) a custom block by id. Snapshotted through
 * Safe_Mutation, so the previous status is one rollback-operation away.
 */
class Set_Block_Status
{
    public function handle(array $args)
    {
        $id = (int) ($args['block_id'] ?? 0);
        if (! Block_Spec_Store::is_block($id)) {
            return new \WP_Error('block_not_found', "No custom block found with id {$id}.");
        }

        $status = 'draft' === ($args['status'] ?? '') ? 'draft' : 'publish';
        $run    = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'set-block-status',
                'args'        => $args,
            ],
            static function () use ($id, $status): void {
                wp_update_post(['ID' => $id, 'post_status' => $status]);
            }
        );

        return [
            'block_id'     => $id,
            'status'       => $status,
            'operation_id' => $run['operation_id'],
        ];
    }
}
