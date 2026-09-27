<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Cloud_Client;
use WPMCP\Cloud\Cloud_Config;
use WPMCP\Cloud\Cloud_Credentials;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Connect this site to WP MCP Cloud: store the cloud URL + API key and verify
 * them by fetching the account (GET /me). Returns the account on success.
 *
 * The credentials have to be stored before the probe, because Cloud_Client
 * reads them from the vault. Since issue #141 that store is a REPLACE over a
 * credential set that can contain a refresh token, and a refresh token is not
 * re-derivable the way a mistyped API key the operator still has in hand is.
 * So a failed probe restores exactly what was there before (or clears the
 * partial connection when there was nothing), and a mistyped cloud-connect
 * costs an error message instead of a working OAuth connection.
 */
class Cloud_Connect
{
    public function handle(array $args)
    {
        $url = (string) ($args['url'] ?? '');
        $key = (string) ($args['key'] ?? '');
        if ('' === trim($url) || '' === trim($key)) {
            return new \WP_Error('missing_credentials', 'Both a cloud url and an api key are required.');
        }

        // Cloud requests never follow redirects (they would replay the
        // Authorization header), so resolve an http->https, bare->www or
        // trailing-slash move now, with an unauthenticated probe, and store
        // the address the cloud actually answers on.
        $url = Cloud_Client::canonical_base_url($url);

        // Seal any unmigrated phase A pair first, explicitly, so the
        // snapshot below is a plain read of what is stored.
        Cloud_Credentials::migrate_plaintext();

        // The exact prior state: the raw blob (so a salt-rotated vault stays
        // recoverable) and the refresh health markers (so a rejected bundle
        // stays in its backoff).
        $previous = Cloud_Credentials::snapshot();
        if (! Cloud_Config::set($url, $key)) {
            // The vault did not take the new set (a failed seal, a filtered
            // write, no sodium implementation). Put back exactly what was
            // there, and never echo the key back.
            Cloud_Credentials::restore($previous);
            return new \WP_Error('cloud_credentials_not_stored', 'The cloud credentials could not be stored encrypted on this site, so nothing was changed.');
        }

        $me = (new Cloud_Client())->get('/me');
        if (is_wp_error($me)) {
            Cloud_Credentials::restore($previous);
            return $me;
        }

        return [
            'connected' => true,
            'url'       => Cloud_Config::base_url(),
            'account'   => is_array($me['account'] ?? null) ? $me['account'] : [],
        ];
    }
}
