<?php

namespace WPMCP\Tools;

use WPMCP\Safety\Edit_Lock;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

class Rollback_Session
{
    public function handle(array $args): array
    {
        $session_id = (string) ($args['session_id'] ?? '');
        // All or nothing: refused before any operation is undone while
        // another user is editing a post the session changed (issue #452).
        Edit_Lock::assert_restorable(Snapshot_Store::list_by_session($session_id));

        $count = Rollback_Service::restore_session($session_id);

        // Non-fatal conflict findings (currently only from db_rows restores:
        // rows that changed, vanished, or were reclaimed since an operation).
        return [
            'restored_count' => $count,
            'warnings'       => Rollback_Service::take_warnings(),
        ];
    }
}
