<?php

namespace WPMCP\Tools\BlockBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Replace a custom block's spec by id (re-validated before it is stored). The
 * write is an operation in history: the wpmcp_block post (row and meta) is
 * snapshotted first, so an update is undoable rather than a one-way overwrite
 * of the spec and its template.
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

        $operation_id = Block_Spec_Store::mutate(
            $id,
            'update-custom-block',
            $args,
            static fn (): bool => Block_Spec_Store::update($id, $spec)
        );
        if (is_wp_error($operation_id)) {
            return $operation_id;
        }

        $stored = Block_Spec_Store::get($id);

        return [
            'block_id'     => $id,
            'name'         => (string) ($stored['name'] ?? ''),
            'title'        => (string) ($stored['title'] ?? ''),
            'operation_id' => $operation_id,
        ];
    }
}
