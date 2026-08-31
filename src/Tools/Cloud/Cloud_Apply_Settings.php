<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Settings_Sync;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Apply a synced governance posture (issue #135, phase B step 2): the write
 * half of settings sync, and the ability that finally makes Settings_Sync's
 * engine reachable from something other than a test.
 *
 * Everything that makes this safe lives in Settings_Sync::apply(): the paid
 * entitlement gate, manage_options, the allowlist re-filter, the per-option
 * coercion, the merge-not-replace and narrow-not-widen rules, and a
 * Safe_Mutation snapshot per write so a synced posture is undoable with
 * rollback-operation. This class only unwraps the payload.
 */
class Cloud_Apply_Settings
{
    public function handle(array $args)
    {
        $payload = $args['settings'] ?? null;
        if (! is_array($payload) || [] === $payload) {
            return new \WP_Error('missing_settings', 'A settings map is required; run cloud-sync-settings on the source site to produce one.');
        }

        $result = Settings_Sync::apply($payload, (string) ($args['session_id'] ?? 'default'));
        if (is_wp_error($result)) {
            return $result;
        }

        return $result + [
            'note' => 'Each applied option carries a rollback snapshot; undo any of them with rollback-operation and the matching operation id.',
        ];
    }
}
