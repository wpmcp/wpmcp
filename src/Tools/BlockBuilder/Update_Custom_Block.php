<?php

namespace WPMCP\Tools\BlockBuilder;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Replace a custom block's spec by id (re-validated before it is stored). The
 * write is an operation in history: Safe_Mutation snapshots the wpmcp_block
 * post (row and meta) first, so an update is undoable rather than a one-way
 * overwrite of the spec and its template.
 */
class Update_Custom_Block
{
    public function handle(array $args)
    {
        $id = (int) ($args['block_id'] ?? 0);
        if (! Block_Spec_Store::is_block($id)) {
            return new \WP_Error('block_not_found', "No custom block found with id {$id}.");
        }

        $spec  = is_array($args['spec'] ?? null) ? $args['spec'] : [];
        $valid = Block_Spec::validate($spec);
        if (is_wp_error($valid)) {
            return $valid;
        }

        $run = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-custom-block',
                'args'        => $args,
            ],
            static function () use ($id, $spec): void {
                Block_Spec_Store::update($id, $spec);
            }
        );

        $stored = Block_Spec_Store::get($id);

        return [
            'block_id'     => $id,
            'name'         => (string) ($stored['name'] ?? ''),
            'title'        => (string) ($stored['title'] ?? ''),
            'operation_id' => $run['operation_id'],
        ];
    }
}
