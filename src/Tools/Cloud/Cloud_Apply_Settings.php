<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Cloud_Client;
use WPMCP\Cloud\Settings_Sync;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Apply a synced governance posture (issue #135): the write half of settings
 * sync.
 *
 * With a `settings` map, applies that payload (typically copied from
 * cloud-sync-settings on another site). Without one, pulls the posture last
 * pushed to WP MCP Cloud with cloud-push-settings (GET /settings returns
 * { settings: { option => value } }) and applies that.
 *
 * Everything that makes this safe lives in Settings_Sync::apply(): the paid
 * entitlement gate, manage_options, the allowlist re-filter, the per-option
 * coercion, the merge-not-replace and narrow-not-widen rules, and a
 * Safe_Mutation snapshot per write so a synced posture is undoable with
 * rollback-operation. A pulled payload gets exactly the same treatment as a
 * pasted one: the cloud is not trusted any more than the caller is.
 */
class Cloud_Apply_Settings
{
    public function handle(array $args)
    {
        $source = 'payload';

        // An explicit null is "omitted": MCP clients commonly send null for an
        // optional argument, and that must mean "pull", not "empty payload".
        if (null !== ($args['settings'] ?? null)) {
            $payload = $args['settings'];
            if (! is_array($payload) || [] === $payload) {
                return new \WP_Error('missing_settings', 'The settings map is empty; run cloud-sync-settings on the source site to produce one, or omit settings to pull the posture from WP MCP Cloud.');
            }
        } else {
            // Gate before the request, not only inside apply(): a site
            // without the entitlement should not fetch the posture at all.
            $denied = Settings_Sync::entitlement_error();
            if (null !== $denied) {
                return $denied;
            }

            $result = (new Cloud_Client())->get('/settings');
            if (is_wp_error($result)) {
                return $result;
            }
            $payload = $result['settings'] ?? null;
            if (! is_array($payload) || [] === $payload) {
                return new \WP_Error('cloud_settings_empty', 'WP MCP Cloud holds no settings posture for this account yet; push one from a source site with cloud-push-settings.');
            }
            $source = 'cloud';
        }

        $result = Settings_Sync::apply($payload, (string) ($args['session_id'] ?? 'default'));
        if (is_wp_error($result)) {
            return $result;
        }

        return $result + [
            'source' => $source,
            'note'   => 'Each applied option carries a rollback snapshot; undo any of them with rollback-operation and the matching operation id.',
        ];
    }
}
