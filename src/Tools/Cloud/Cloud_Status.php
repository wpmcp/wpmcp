<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Cloud_Config;
use WPMCP\Cloud\Cloud_Credentials;
use WPMCP\Cloud\Token_Refresher;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Report whether this site is connected to WP MCP Cloud and where. Read-only.
 *
 * token_status (issue #141) separates the four states an operator has to tell
 * apart, because all of them otherwise present as a bare connected: false:
 *
 *   ok         - usable credentials (an API key, or a token the cloud has not
 *                rejected)
 *   rejected   - the cloud rejected the refresh token of a connection with no
 *                API key to fall back on; re-run cloud-connect
 *   unreadable - a sealed vault that no longer decrypts. wp_salt('auth')
 *                rotated (a moved or restored site with fresh salts), so the
 *                credentials are gone rather than never having been set
 *   none       - this site was never connected
 */
class Cloud_Status
{
    public function handle(array $args): array
    {
        return [
            'connected'    => Cloud_Config::is_configured(),
            'url'          => Cloud_Config::base_url(),
            'token_status' => self::token_status(),
        ];
    }

    private static function token_status(): string
    {
        // Usable credentials first: an unreadable vault next to a working
        // legacy pair is still a working connection.
        if (Cloud_Config::is_configured()) {
            if (Token_Refresher::is_unhealthy() && '' === Cloud_Config::api_key()) {
                // With an API key, Cloud_Client falls back to it and the
                // connection works; only a token-only connection is dead.
                return 'rejected';
            }
            return 'ok';
        }
        return Cloud_Credentials::is_unreadable() ? 'unreadable' : 'none';
    }
}
