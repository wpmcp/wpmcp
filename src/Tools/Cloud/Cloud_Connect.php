<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Cloud_Client;
use WPMCP\Cloud\Cloud_Config;
use WPMCP\Cloud\Gateway_Consent;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Connect this site to WP MCP Cloud: store the cloud URL + API key and verify
 * them by fetching the account (GET /me). Returns the account on success.
 *
 * `gateway_consent` is the disclosed gateway consent checkbox (issue #130)
 * and defaults to false. It is recorded on every successful connect, so a
 * connect that leaves it unticked withdraws consent, and withdrawing it
 * kills a gateway credential the cloud already holds. See Gateway_Consent.
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

        Cloud_Config::set($url, $key);

        $me = (new Cloud_Client())->get('/me');
        if (is_wp_error($me)) {
            return $me;
        }

        $consent = Gateway_Consent::record(true === ($args['gateway_consent'] ?? false), get_current_user_id());

        return [
            'connected'       => true,
            'url'             => Cloud_Config::base_url(),
            'account'         => is_array($me['account'] ?? null) ? $me['account'] : [],
            'gateway_consent' => $consent['granted'],
            'gateway_revoked' => $consent['revoked'],
        ];
    }
}
