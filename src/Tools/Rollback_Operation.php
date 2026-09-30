<?php

namespace WPMCP\Tools;

use WPMCP\Safety\Edit_Lock;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

class Rollback_Operation
{
    public function handle(array $args): array
    {
        $operation_id = (string) ($args['operation_id'] ?? '');
        $row          = Snapshot_Store::get_by_operation($operation_id);
        // Refused before anything is restored while another user is
        // editing the post the undo would overwrite (issue #452).
        Edit_Lock::assert_restorable(null === $row ? [] : [$row]);

        $restored = Rollback_Service::restore_operation($operation_id);

        // Non-fatal conflict findings (currently only from db_rows restores:
        // rows that changed, vanished, or were reclaimed since the operation).
        // The restore still succeeded; the caller is told the ground shifted.
        return [
            'restored' => $restored,
            'warnings' => Rollback_Service::take_warnings(),
        ];
    }
}
