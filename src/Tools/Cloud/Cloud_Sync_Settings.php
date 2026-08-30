<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Settings_Sync;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Preview the settings-sync payload (issue #135, phase B step 2): the exact
 * allowlisted governance settings that would be pushed to WP MCP Cloud.
 * Read-only; the push/apply halves land with the cloud /settings endpoints.
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
            'note'      => 'Preview only. Push and apply arrive with the cloud /settings contract (issue #135).',
        ];
    }
}
