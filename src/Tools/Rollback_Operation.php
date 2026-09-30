<?php

namespace WPMCP\Tools;

use WPMCP\Safety\Rollback_Service;

if (! defined('ABSPATH')) {
    exit;
}

class Rollback_Operation
{
    public function handle(array $args): array
    {
        $restored = Rollback_Service::restore_operation((string) ($args['operation_id'] ?? ''));

        // Non-fatal conflict findings (currently only from db_rows restores:
        // rows that changed, vanished, or were reclaimed since the operation).
        // The restore still succeeded; the caller is told the ground shifted.
        return [
            'restored' => $restored,
            'warnings' => Rollback_Service::take_warnings(),
        ];
    }
}
