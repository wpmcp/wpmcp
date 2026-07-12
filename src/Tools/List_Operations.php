<?php

namespace WPMCP\Tools;

use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

class List_Operations
{
    public function handle(array $args): array
    {
        $limit = (int) ($args['limit'] ?? 20);
        $rows  = Snapshot_Store::recent($limit);
        $ops   = array_map(fn($r) => [
            'operation_id' => $r['operation_id'],
            'session_id'   => $r['session_id'],
            'tool_name'    => $r['tool_name'],
            'object_type'  => $r['object_type'],
            'object_id'    => (int) $r['object_id'],
            'created_at'   => $r['created_at'],
        ], $rows);
        return ['operations' => $ops];
    }
}
