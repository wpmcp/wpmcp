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
 * total exist to be listed), and an optional 'source' filter (issue #412:
 * mcp, rest, server, cli or php, see Call_Source) applied before the limit.
 */
class List_Governance_Audit_Log
{
    public function handle(array $args): array
    {
        $limit  = (int) ($args['limit'] ?? 20);
        $source = isset($args['source']) && is_string($args['source']) ? strtolower(trim($args['source'])) : '';

        return ['entries' => Governance_Audit_Log::list($limit, $source)];
    }
}
