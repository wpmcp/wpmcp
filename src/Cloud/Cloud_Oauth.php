<?php

namespace WPMCP\Cloud;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * PKCE OAuth connect to WP MCP Cloud (issue #135, phase B step 1).
 *
 * The site acts as an OAuth 2.1 public client against the cloud's
 * authorization server. S256 only, mirroring what our own Auth\PKCE enforces
 * server-side. The resulting token bundle is sealed in Token_Vault; refresh
 * goes through Token_Vault::with_refresh_lock() so rotation races resolve as
 * treat-loser-as-success.
 *
 * TODO(#135): cloud-side /oauth/authorize and /oauth/token endpoints, the
 * admin redirect handler that completes exchange(), and switching
 * cloud-connect from API key to this flow once the backend ships them.
 */
class Cloud_Oauth
{
    private const STATE_OPTION = 'wpmcp_cloud_oauth_state';

    /**
     * Begin the flow: generate verifier + S256 challenge, persist them with a
     * state nonce, and return the authorize URL the admin must visit.
     *
     * @return array{url:string,state:string}|\WP_Error
     */
    public static function begin(string $cloud_url)
    {
        $cloud_url = rtrim($cloud_url, '/');
        if ('' === $cloud_url) {
            return new \WP_Error('missing_cloud_url', 'A cloud url is required to start the OAuth connect.');
        }

        $verifier  = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state     = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

        update_option(self::STATE_OPTION, [
            'verifier' => $verifier,
            'state'    => $state,
            'url'      => $cloud_url,
            'created'  => time(),
        ], false);

        $url = add_query_arg([
            'response_type'         => 'code',
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
            'state'                 => $state,
            'redirect_uri'          => admin_url('admin.php?page=wpmcp-cloud-callback'),
        ], $cloud_url . '/wpmcp-cloud/v1/oauth/authorize');

        return ['url' => $url, 'state' => $state];
    }

    /**
     * Complete the flow: exchange the authorization code for a token bundle
     * and seal it in the vault.
     *
     * TODO(#135): implement the token POST via Cloud_Client once the cloud
     * exposes /oauth/token; until then this returns not-implemented.
     *
     * @return true|\WP_Error
     */
    public static function exchange(string $code, string $state)
    {
        $pending = get_option(self::STATE_OPTION, null);
        if (! is_array($pending) || ! hash_equals((string) ($pending['state'] ?? ''), $state)) {
            return new \WP_Error('oauth_state_mismatch', 'OAuth state does not match the pending connect; start over with cloud-connect.');
        }

        return new \WP_Error('not_implemented', 'PKCE code exchange lands with the cloud /oauth/token endpoint (issue #135).');
    }
}
