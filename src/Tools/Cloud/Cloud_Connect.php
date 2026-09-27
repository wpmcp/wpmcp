<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Cloud_Client;
use WPMCP\Cloud\Cloud_Config;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Connect this site to WP MCP Cloud: store the cloud URL + API key and verify
 * them by fetching the account (GET /me). Returns the account on success.
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

        return [
            'connected' => true,
            'url'       => Cloud_Config::base_url(),
            'account'   => is_array($me['account'] ?? null) ? $me['account'] : [],
        ];
    }
}
