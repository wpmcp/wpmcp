<?php

namespace WPMCP\Cloud;

use WPMCP\Auth\PKCE;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * PKCE OAuth connect to WP MCP Cloud (issue #135, phase B step 1).
 *
 * The site acts as an OAuth 2.1 public client against the cloud's
 * authorization server, reusing this plugin's own Auth\PKCE for the S256
 * challenge so client and server agree by construction. The resulting token
 * bundle is sealed in Token_Vault; refresh goes through
 * Token_Vault::with_refresh_lock() so rotation races resolve as
 * lock / re-read / treat-a-finished-winner-as-success.
 *
 * The client_id is the site itself (its home URL), which is what a public
 * client with no registration step can prove nothing about but must still
 * send: an authorization request without client_id is malformed and no
 * conforming server can process it.
 *
 * The pending state record is single-use and short-lived: it is deleted on
 * every exit path of exchange() and rejected once older than STATE_TTL, so a
 * captured state/verifier pair cannot be replayed.
 *
 * TODO(#135): the cloud-side /oauth/authorize and /oauth/token endpoints, and
 * the admin redirect handler that calls exchange(); cloud-connect keeps taking
 * an API key until the backend ships them.
 */
class Cloud_Oauth
{
    private const STATE_OPTION = 'wpmcp_cloud_oauth_state';
    private const STATE_TTL    = 600; // seconds
    private const SCOPE        = 'assets settings';

    /**
     * Begin the flow: generate verifier + S256 challenge, persist them with a
     * state nonce, and return the authorize URL the admin must visit.
     *
     * @return array{url:string,state:string}|\WP_Error
     */
    public static function begin(string $cloud_url)
    {
        $cloud_url = Cloud_Config::normalize($cloud_url);
        if ('' === $cloud_url) {
            return new \WP_Error('missing_cloud_url', 'A cloud url is required to start the OAuth connect.');
        }

        // This string becomes a link an admin is told to click and, minutes
        // later, the host a PKCE verifier is POSTed to, so it is validated
        // before it is persisted rather than at use time.
        //
        // Structural validation, deliberately not wp_http_validate_url(): that
        // helper resolves the host and refuses private ranges, which would make
        // the check depend on the resolver and would lock out a self-hosted
        // cloud on an internal network. What actually matters here is the
        // shape: https, because the authorization code and the verifier both
        // cross this connection, a real host, and no embedded credentials.
        $parts = wp_parse_url($cloud_url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return new \WP_Error('invalid_cloud_url', 'The cloud url must be an absolute url with a scheme and a host.');
        }
        if ('https' !== strtolower((string) $parts['scheme'])) {
            return new \WP_Error('invalid_cloud_url', 'The cloud url must use https; OAuth carries the authorization code and PKCE verifier over it.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return new \WP_Error('invalid_cloud_url', 'The cloud url must not embed credentials.');
        }

        $verifier  = self::random_token(48);
        $challenge = PKCE::challenge_from_verifier($verifier);
        $state     = self::random_token(24);

        update_option(self::STATE_OPTION, [
            'verifier' => $verifier,
            'state'    => $state,
            'url'      => $cloud_url,
            'created'  => time(),
        ], false);

        $url = add_query_arg([
            'response_type'         => 'code',
            'client_id'             => self::client_id(),
            'scope'                 => self::SCOPE,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
            'state'                 => $state,
            'redirect_uri'          => self::redirect_uri(),
        ], $cloud_url . '/wpmcp-cloud/v1/oauth/authorize');

        return ['url' => $url, 'state' => $state];
    }

    /**
     * Complete the flow: exchange the authorization code for a token bundle
     * and seal it in the vault. The pending state is consumed no matter how
     * this ends.
     *
     * @return true|\WP_Error
     */
    public static function exchange(string $code, string $state)
    {
        $pending = get_option(self::STATE_OPTION, null);
        $stored  = is_array($pending) ? (string) ($pending['state'] ?? '') : '';

        // '' === '' passes hash_equals, so an absent/malformed pending record
        // must be rejected before the comparison, not by it.
        if ('' === $stored || '' === $state || ! hash_equals($stored, $state)) {
            delete_option(self::STATE_OPTION);
            return new \WP_Error('oauth_state_mismatch', 'OAuth state does not match the pending connect; start over with cloud-connect.');
        }

        $created = (int) ($pending['created'] ?? 0);
        if ($created <= 0 || (time() - $created) > self::STATE_TTL) {
            delete_option(self::STATE_OPTION);
            return new \WP_Error('oauth_state_expired', 'The pending OAuth connect expired; start over with cloud-connect.');
        }

        $cloud_url = Cloud_Config::normalize((string) ($pending['url'] ?? ''));
        $verifier  = (string) ($pending['verifier'] ?? '');
        delete_option(self::STATE_OPTION);

        if ('' === $cloud_url || '' === $verifier) {
            return new \WP_Error('oauth_state_mismatch', 'The pending OAuth connect is incomplete; start over with cloud-connect.');
        }

        $tokens = self::token_request($cloud_url, [
            'grant_type'    => 'authorization_code',
            'client_id'     => self::client_id(),
            'code'          => $code,
            'code_verifier' => $verifier,
            'redirect_uri'  => self::redirect_uri(),
        ]);
        if (is_wp_error($tokens)) {
            return $tokens;
        }

        // Point the site at the cloud we actually authenticated against BEFORE
        // sealing the bundle. Without this an OAuth-only connect leaves
        // wpmcp_cloud_url empty, so live_bundle()'s issuer check rejects the
        // token that was just minted and is_configured() may still be false;
        // and set_url() rather than Cloud_Config::set(), because set() clears
        // the vault and would destroy the bundle on the next line.
        Cloud_Config::set_url($cloud_url);
        Token_Vault::store($tokens['access_token'], $tokens['refresh_token'], $tokens['expires_at'], Cloud_Config::base_url());

        return true;
    }

    /**
     * Rotate the sealed bundle with the refresh grant, under the vault mutex.
     *
     * @param string $stale_access_token The token that was just refused, so a
     *                                   worker that loses the mutex can tell a
     *                                   finished rotation from an in-flight one.
     * @return array|\WP_Error the bundle now in the vault
     */
    public static function refresh(string $stale_access_token = '')
    {
        return Token_Vault::with_refresh_lock(static function (array $bundle) {
            $issuer = '' !== $bundle['issuer'] ? Cloud_Config::normalize($bundle['issuer']) : Cloud_Config::base_url();

            // The refresh token is a long-lived credential; it must never leave
            // for a host the site is not currently configured against. A bundle
            // whose issuer no longer matches is exactly the one live_bundle()
            // already refuses to put on the wire, so refreshing it would ship
            // the strongest half of the credential to the host whose weaker
            // half we declined to send.
            if ('' === $issuer || $issuer !== Cloud_Config::base_url()) {
                return new \WP_Error(
                    'cloud_issuer_mismatch',
                    'The stored cloud token bundle was issued by a different cloud than this site is configured against; reconnect with cloud-connect.'
                );
            }

            if ('' === $bundle['refresh_token']) {
                return new \WP_Error('cloud_no_refresh_token', 'The stored cloud token bundle has no refresh token; reconnect.');
            }

            $tokens = self::token_request($issuer, [
                'grant_type'    => 'refresh_token',
                'client_id'     => self::client_id(),
                'refresh_token' => $bundle['refresh_token'],
            ]);
            if (is_wp_error($tokens)) {
                return $tokens;
            }

            return [
                'access_token'  => $tokens['access_token'],
                'refresh_token' => '' !== $tokens['refresh_token'] ? $tokens['refresh_token'] : $bundle['refresh_token'],
                'expires_at'    => $tokens['expires_at'],
                'issuer'        => $issuer,
            ];
        }, $stale_access_token);
    }

    /**
     * POST the token endpoint and normalize the response.
     *
     * @param array<string,string> $body
     * @return array{access_token:string,refresh_token:string,expires_at:int}|\WP_Error
     */
    private static function token_request(string $cloud_url, array $body)
    {
        $response = wp_remote_post(
            rtrim($cloud_url, '/') . '/wpmcp-cloud/v1/oauth/token',
            [
                'timeout' => 20,
                'headers' => [
                    // RFC 6749 section 4.1.3, carried forward by OAuth 2.1: the
                    // token endpoint takes form encoding, not JSON. Passing the
                    // array lets WP_Http build the body.
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept'       => 'application/json',
                ],
                'body'    => $body,
            ]
        );

        if (is_wp_error($response)) {
            return new \WP_Error('cloud_unreachable', 'Could not reach the WP MCP Cloud token endpoint: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($data)) {
            $data = [];
        }

        if ($code < 200 || $code >= 300) {
            $message = isset($data['error_description']) ? (string) $data['error_description'] : (string) ($data['error'] ?? "HTTP {$code}");
            return new \WP_Error('cloud_oauth_failed', 'WP MCP Cloud refused the token request: ' . $message, ['status' => $code]);
        }

        $access = (string) ($data['access_token'] ?? '');
        if ('' === $access) {
            return new \WP_Error('cloud_oauth_failed', 'The WP MCP Cloud token response carried no access_token.');
        }

        $expires_in = (int) ($data['expires_in'] ?? 0);

        return [
            'access_token'  => $access,
            'refresh_token' => (string) ($data['refresh_token'] ?? ''),
            'expires_at'    => $expires_in > 0 ? time() + $expires_in : 0,
        ];
    }

    /**
     * This site's identity as a public OAuth client. Home URL rather than a
     * registered id: there is no dynamic-registration step in the contract
     * yet, and the cloud already keys accounts by site URL.
     */
    private static function client_id(): string
    {
        return home_url('/');
    }

    private static function redirect_uri(): string
    {
        return admin_url('admin.php?page=wpmcp-cloud-callback');
    }

    /** BASE64URL random token with no padding. */
    private static function random_token(int $bytes): string
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- BASE64URL is the RFC 7636 wire form for verifiers and state; not obfuscation.
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
