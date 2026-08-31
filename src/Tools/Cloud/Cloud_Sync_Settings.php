<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Settings_Sync;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Preview the settings-sync payload (issue #135, phase B step 2): the exact
 * allowlisted governance settings that would be pushed to WP MCP Cloud.
 *
 * Read-only. The push half lands with the cloud /settings endpoints; the
 * apply half is Settings_Sync::apply(), which is gated on the paid-cloud
 * entitlement and routes every write through Safe_Mutation.
 */
class Cloud_Sync_Settings
{
    public function handle(array $args)
    {
        $payload = Settings_Sync::export();

        return [
            'allowlist' => Settings_Sync::allowlist(),
            'payload'   => $payload,
            'count'     => count($payload),
            'note'      => 'Preview only. Push arrives with the cloud /settings contract (issue #135). The allowlist is persisted options only: the code-level safety gates (wpmcp_enable_db_writes, wpmcp_allow_php_exec, wpmcp_wp_cli_allowlist and friends) are filters with no stored value and cannot sync.',
        ];
    }
}
