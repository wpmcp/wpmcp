<?php

namespace WPMCP\Tools;

use WPMCP\Safety\Rollback_Service;

if (! defined('ABSPATH')) {
    exit;
}

class Rollback_Session
{
    public function handle(array $args): array
    {
        $count = Rollback_Service::restore_session((string) ($args['session_id'] ?? ''));

        // Non-fatal conflict findings (currently only from db_rows restores:
        // rows that changed, vanished, or were reclaimed since an operation).
        return [
            'restored_count' => $count,
            'warnings'       => Rollback_Service::take_warnings(),
        ];
    }
}
