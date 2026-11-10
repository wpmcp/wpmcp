<?php

namespace WPMCP\Tools;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

class Rollback_Operation
{
    /** @return array|\WP_Error */
    public function handle(array $args)
    {
        $operation_id = (string) ($args['operation_id'] ?? '');

        // An id with no undo point is refused as an error naming it (issue
        // #758), so a mistyped or truncated id never reads as "nothing to
        // undo". Every other restored:false carries a warning saying why.
        if (null === Snapshot_Store::get_by_operation($operation_id)) {
            return new \WP_Error('operation_not_found', self::not_found($operation_id));
        }

        $restored = Rollback_Service::restore_operation($operation_id);

        // Non-fatal conflict findings (currently only from db_rows restores:
        // rows that changed, vanished, or were reclaimed since the operation).
        // The restore still succeeded; the caller is told the ground shifted.
        return [
            'restored' => $restored,
            'warnings' => Rollback_Service::take_warnings(),
        ];
    }

    /** The plain-text refusal for an operation id with no undo point (issue #758). */
    private static function not_found(string $operation_id): string
    {
        if ('' === trim($operation_id)) {
            return 'No operation_id was given: pass the id of the operation to undo (list-operations lists them).';
        }
        if (! wp_is_uuid($operation_id)) {
            return sprintf('No undo point exists for operation %s: it is not an operation id (operation ids are UUIDs). Check the operation_id (list-operations lists them).', $operation_id);
        }
        return sprintf('No undo point exists for operation %s: check the operation_id (list-operations lists them).', $operation_id);
    }
}
