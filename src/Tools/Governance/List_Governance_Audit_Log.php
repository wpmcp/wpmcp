<?php

namespace WPMCP\Tools\Governance;

use WPMCP\Governance\Governance_Audit_Log;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: lists governance-decision audit log entries, newest first.
 * Supports an optional 'limit' (default 20, mirroring List_Operations'
 * default), capped by Governance_Audit_Log's own retention (CAP entries
 * total exist to be listed). No session_id/date-range/other filters:
 * every entry already carries just ability/identity/allowed/timestamp, and
 * a bare recency-limited read is enough for this log's purpose.
 */
class List_Governance_Audit_Log
{
    public function handle(array $args): array
    {
        $limit = (int) ($args['limit'] ?? 20);

        return ['entries' => Governance_Audit_Log::list($limit)];
    }
}
