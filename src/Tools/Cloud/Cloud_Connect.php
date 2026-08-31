<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Cloud_Client;
use WPMCP\Cloud\Cloud_Config;
use WPMCP\Cloud\Cloud_Oauth;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Connect this site to WP MCP Cloud.
 *
 * Two paths behind one tool:
 *
 *  - url only (issue #135, phase B): start the PKCE OAuth flow. Returns the
 *    authorize URL for an admin to open in a browser; the redirect comes back
 *    to Admin\Cloud_Callback_Page, which calls Cloud_Oauth::exchange() and
 *    seals the token bundle. An agent cannot finish this on its own, and that
 *    is the point: granting a site's cloud identity is a human decision.
 *  - url + key (phase A): store the API key and verify it by fetching the
 *    account (GET /me). Kept while the cloud's authorization server is still
 *    being built, and as the fallback for installs that cannot open a browser.
 */
class Cloud_Connect
{
    public function handle(array $args)
    {
        $url = trim((string) ($args['url'] ?? ''));
        $key = trim((string) ($args['key'] ?? ''));

        if ('' === $url) {
            return new \WP_Error('missing_credentials', 'A cloud url is required.');
        }

        if ('' === $key) {
            $started = Cloud_Oauth::begin($url);
            if (is_wp_error($started)) {
                return $started;
            }

            return [
                'connected'     => false,
                'method'        => 'oauth',
                'authorize_url' => $started['url'],
                'next'          => 'Open authorize_url in a browser as a site administrator and approve the connect. WP MCP Cloud redirects back to this site, which completes the exchange and seals the token bundle. The pending request expires in 10 minutes.',
            ];
        }

        Cloud_Config::set($url, $key);

        $me = (new Cloud_Client())->get('/me');
        if (is_wp_error($me)) {
            return $me;
        }

        return [
            'connected' => true,
            'method'    => 'api_key',
            'url'       => Cloud_Config::base_url(),
            'account'   => is_array($me['account'] ?? null) ? $me['account'] : [],
        ];
    }
}
