<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Cloud_Client;
use WPMCP\Cloud\Settings_Sync;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Push this site's governance posture to WP MCP Cloud (issue #135): the
 * upload half of settings sync. Another site pulls it with
 * cloud-apply-settings.
 *
 * Contract: POST /settings { settings: { option => value } } returns
 * { updated_at? }. The body is exactly Settings_Sync::export(), the same
 * allowlisted, secret-free payload cloud-sync-settings previews, so what an
 * operator reviews is what leaves the site.
 *
 * Nothing on this site changes, so there is nothing to snapshot; the paid
 * entitlement and manage_options are checked before any request is made.
 */
class Cloud_Push_Settings
{
    public function handle(array $args)
    {
        $denied = Settings_Sync::entitlement_error();
        if (null !== $denied) {
            return $denied;
        }

        $payload = Settings_Sync::export();
        if ([] === $payload) {
            return new \WP_Error('cloud_settings_empty', 'This site has no stored governance posture to push yet; every allowlisted setting is still at its default.');
        }

        $result = (new Cloud_Client())->post('/settings', ['settings' => $payload]);
        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'pushed'     => array_keys($payload),
            'count'      => count($payload),
            'updated_at' => is_scalar($result['updated_at'] ?? null) ? (string) $result['updated_at'] : '',
        ];
    }
}
