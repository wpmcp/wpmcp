<?php

namespace WPMCP\Tools\BlockBuilder;

use WPMCP\Safety\Save_Filters;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delete a custom block by moving its wpmcp_block post to the trash, as an
 * operation in history: undoable through rollback-operation as well as through
 * WordPress trash / restore-post. A block already in the trash is refused
 * rather than recorded as a second, no-op operation.
 */
class Delete_Custom_Block
{
    public function handle(array $args)
    {
        $id = (int) ($args['block_id'] ?? 0);
        if (! Block_Spec_Store::is_block($id)) {
            return new \WP_Error('block_not_found', "No custom block found with id {$id}.");
        }
        if ('trash' === get_post_status($id)) {
            return new \WP_Error('block_already_trashed', "Custom block {$id} is already in the trash.");
        }

        $operation_id = Block_Spec_Store::mutate(
            $id,
            'delete-custom-block',
            $args,
            // wp_trash_post() returns false or null when it did nothing. With
            // EMPTY_TRASH_DAYS set to 0 it deletes outright instead; the
            // full-row snapshot still makes that undoable.
            static fn (): bool => (bool) Save_Filters::trash_post($id)
        );
        if (is_wp_error($operation_id)) {
            return $operation_id;
        }

        return [
            'block_id'     => $id,
            'deleted'      => 'trash' === get_post_status($id) ? 'trashed' : 'deleted',
            'operation_id' => $operation_id,
        ];
    }
}
