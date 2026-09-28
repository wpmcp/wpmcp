<?php

namespace WPMCP\Tools\BlockBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Enable (publish) or disable (draft) a custom block by id. Snapshotted, so the
 * previous status is one rollback-operation away. Asking for the status the
 * block already has writes nothing and takes no snapshot, so repeated "make
 * sure it is on" calls cannot push real undo points out of the capped history.
 */
class Set_Block_Status
{
    public const STATUSES = ['publish', 'draft'];

    public function handle(array $args)
    {
        $id = (int) ($args['block_id'] ?? 0);
        if (! Block_Spec_Store::is_block($id)) {
            return new \WP_Error('block_not_found', "No custom block found with id {$id}.");
        }

        $status = (string) ($args['status'] ?? '');
        if (! in_array($status, self::STATUSES, true)) {
            return new \WP_Error('invalid_status', 'status must be "publish" or "draft".');
        }

        if (get_post_status($id) === $status) {
            return ['block_id' => $id, 'status' => $status, 'unchanged' => true];
        }

        $operation_id = Block_Spec_Store::mutate(
            $id,
            'set-block-status',
            $args,
            static function () use ($id, $status): bool {
                $result = wp_update_post(['ID' => $id, 'post_status' => $status], true);
                return ! is_wp_error($result) && 0 !== $result && get_post_status($id) === $status;
            }
        );
        if (is_wp_error($operation_id)) {
            return $operation_id;
        }

        return [
            'block_id'     => $id,
            'status'       => $status,
            'operation_id' => $operation_id,
        ];
    }
}
