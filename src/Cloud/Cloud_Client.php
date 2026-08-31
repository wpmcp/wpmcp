<?php

namespace WPMCP\Cloud;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * HTTP client for the WP MCP Cloud REST contract (/wpmcp-cloud/v1), the single
 * seam between the plugin and the cloud backend.
 *
 * Cloud_Config::base_url() is the cloud's REST ROOT (for the phase A
 * WordPress-backed cloud, https://cloud.example/wp-json), because that is what
 * API_BASE has always been appended to. TOKEN_PATH follows the same
 * convention: every path constant here is relative to that one base, and none
 * of them carries a /wp-json prefix of its own.
 *
 * Contract (v1), Bearer-authenticated with the site's API key:
 *   GET  /me                → { account: { id, email, plan } }
 *   GET  /assets            → { assets: [ { id, type, name, title, spec } ] }
 *   POST /assets { type, name, title, spec } → { asset: { id, ... } }
 *
 * Keeping this the ONLY place that knows the wire format means the backend can
 * be swapped (WordPress → a scalable service) without changing any tool.
 */
class Cloud_Client
{
    private const API_BASE = '/wpmcp-cloud/v1';

    /**
     * OAuth token endpoint, used by Token_Refresher for the refresh_token
     * grant. It lives here, next to API_BASE, so this class stays the only
     * place that knows where the backend answers, and it is relative to the
     * same REST root: the cloud runs this plugin, so its token route is the
     * plugin's own wpmcp/v1 route (see Auth\Endpoints) under that root. Phase
     * 2 confirms it against the PKCE connect flow, which is also what decides
     * whether the site is registered as a public or a confidential client.
     */
    public const TOKEN_PATH = '/wpmcp/v1/oauth/token';

    /** @return array|\WP_Error decoded JSON body, or an error. */
    public function get(string $path)
    {
        return $this->request('GET', $path);
    }

    /** @return array|\WP_Error */
    public function post(string $path, array $body)
    {
        return $this->request('POST', $path, $body);
    }

    /** @return array|\WP_Error */
    private function request(string $method, string $path, ?array $body = null)
    {
        if (! Cloud_Config::is_configured()) {
            return new \WP_Error('cloud_not_configured', 'Connect to WP MCP Cloud first with cloud-connect (URL + API key).');
        }

        $credential = $this->auth_credential();
        if (null === $credential) {
            // A token-only connection whose refresh failed or is inside its
            // backoff. Sending "Bearer " with nothing after it would come back
            // as an opaque HTTP 401; say what actually has to happen instead.
            return new \WP_Error(
                'cloud_not_authenticated',
                'WP MCP Cloud rejected or could not refresh this site\'s token. Re-run cloud-connect.'
            );
        }

        $url  = Cloud_Config::base_url() . self::API_BASE . $path;
        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $credential,
                'Accept'        => 'application/json',
            ],
        ];
        if (null !== $body) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body']                    = (string) wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return new \WP_Error('cloud_unreachable', 'Could not reach WP MCP Cloud: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $message = is_array($data) && isset($data['message']) ? (string) $data['message'] : "HTTP {$code}";
            return new \WP_Error('cloud_error', 'WP MCP Cloud returned an error: ' . $message, ['status' => $code]);
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Auth resolution (issue #141): prefer a fresh access token from the
     * vault, invoke Token_Refresher when stale, fall back to the API key
     * (phase A connections have no token bundle yet).
     *
     * Returns null when nothing resolves, which is reachable now that
     * is_configured() admits a token-only connection: the caller must error
     * rather than put an empty bearer on the wire.
     */
    private function auth_credential(): ?string
    {
        $bundle = Cloud_Credentials::all();
        if (Token_Refresher::is_fresh($bundle)) {
            return (string) $bundle['access_token'];
        }
        if ('' !== (string) ($bundle['refresh_token'] ?? '')) {
            $token = (new Token_Refresher())->ensure_fresh_access_token();
            if (null !== $token && '' !== $token) {
                return $token;
            }
        }
        $key = Cloud_Config::api_key();
        return '' === $key ? null : $key;
    }
}
