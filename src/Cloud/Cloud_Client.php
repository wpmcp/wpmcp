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

        // Never "Bearer " with nothing after it (an opaque HTTP 401): when no
        // credential resolves, say which of the distinct reasons applies.
        $credential = $this->auth_credential();
        if (is_wp_error($credential)) {
            return $credential;
        }

        $url  = Cloud_Config::base_url() . self::API_BASE . $path;
        $args = [
            'method'      => $method,
            'timeout'     => 20,
            // Never replay the Authorization header to wherever a 30x points.
            'redirection' => 0,
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
            // Transport and backend text is scrubbed of every stored secret
            // before it reaches an MCP client (issue #141).
            return new \WP_Error('cloud_unreachable', 'Could not reach WP MCP Cloud: ' . Cloud_Credentials::redact($response->get_error_message()));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code >= 300 && $code < 400) {
            // Redirects are deliberately not followed (the Authorization
            // header would be replayed to wherever Location points), so name
            // the cause instead of a bare "HTTP 301".
            $location = (string) wp_remote_retrieve_header($response, 'location');
            $target   = (string) wp_parse_url($location, PHP_URL_HOST);
            $scheme   = (string) wp_parse_url($location, PHP_URL_SCHEME);
            $hint     = '' === $target ? '' : ' to ' . Cloud_Credentials::redact(('' === $scheme ? '' : $scheme . '://') . $target);
            return new \WP_Error(
                'cloud_redirect_not_followed',
                sprintf(
                    'WP MCP Cloud answered HTTP %d with a redirect%s. Redirects are not followed, so this site\'s credentials are never sent to another address. Re-run cloud-connect with the canonical https URL.',
                    $code,
                    $hint
                ),
                ['status' => $code]
            );
        }

        if ($code < 200 || $code >= 300) {
            $message = is_array($data) && isset($data['message']) ? Cloud_Credentials::redact((string) $data['message']) : "HTTP {$code}";
            return new \WP_Error('cloud_error', 'WP MCP Cloud returned an error: ' . $message, ['status' => $code]);
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Where the cloud really lives, found BEFORE any credential is stored or
     * sent: an unauthenticated GET of the /me route with redirects off, and
     * when it answers 30x with a Location that is the same route under
     * another base (http to https, bare to www, a trailing slash), that base
     * instead, up to three hops. Only a Location that ends in the probed
     * route is followed, and never from https down to http, so a redirect to
     * some unrelated page cannot become the stored cloud URL. Anything else
     * (an error, a 200, a 401, an unrelated Location) keeps the URL as typed
     * and lets the authenticated probe report what is wrong.
     */
    public static function canonical_base_url(string $url): string
    {
        $base  = rtrim(trim($url), '/');
        $route = self::API_BASE . '/me';
        for ($hop = 0; $hop < 3 && '' !== $base; $hop++) {
            $response = wp_remote_get($base . $route, [
                'timeout'     => 10,
                'redirection' => 0,
                'headers'     => ['Accept' => 'application/json'],
            ]);
            if (is_wp_error($response)) {
                return $base;
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code < 300 || $code >= 400) {
                return $base;
            }
            $location = trim((string) wp_remote_retrieve_header($response, 'location'));
            if ('' !== $location && '/' === $location[0] && (strlen($location) < 2 || '/' !== $location[1])) {
                $location = self::origin($base) . $location;
            }
            $path = (string) strtok($location, '?#');
            if ('' === $path || substr($path, -strlen($route)) !== $route) {
                return $base;
            }
            $next   = rtrim(substr($path, 0, -strlen($route)), '/');
            $scheme = strtolower((string) wp_parse_url($next, PHP_URL_SCHEME));
            if (! in_array($scheme, ['http', 'https'], true) || '' === (string) wp_parse_url($next, PHP_URL_HOST)) {
                return $base;
            }
            if ('https' === strtolower((string) wp_parse_url($base, PHP_URL_SCHEME)) && 'https' !== $scheme) {
                return $base;
            }
            if ($next === $base) {
                return $base;
            }
            $base = $next;
        }
        return $base;
    }

    /** scheme://host[:port] of $url, for resolving a root-relative Location. */
    private static function origin(string $url): string
    {
        $parts = wp_parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return '';
        }
        return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Auth resolution (issue #141): prefer a fresh access token from the
     * vault, invoke Token_Refresher when stale, fall back to the API key
     * (phase A connections have no token bundle yet).
     *
     * When nothing resolves (reachable now that is_configured() admits a
     * token-only connection) the answer is a WP_Error naming the actual
     * reason, because the remedies differ: re-running cloud-connect is right
     * for a rejected token and wrong for one that is waiting out a 60s
     * backoff, which it would throw away.
     *
     * @return string|\WP_Error
     */
    private function auth_credential()
    {
        $bundle     = Cloud_Credentials::all();
        $has_bundle = '' !== (string) ($bundle['access_token'] ?? '') || '' !== (string) ($bundle['refresh_token'] ?? '');
        $failure    = ['reason' => Token_Refresher::FAILURE_REJECTED, 'retry_after' => 0];

        if ($has_bundle) {
            // The OAuth bundle is only ever presented over https. A phase A
            // connection on a plain http URL keeps working on its API key, as
            // it always has, but it does not get to leak a bearer token too.
            $secure = 'https' === strtolower((string) wp_parse_url(Cloud_Config::base_url(), PHP_URL_SCHEME));
            if (! $secure) {
                $failure = ['reason' => Token_Refresher::FAILURE_INSECURE_URL, 'retry_after' => 0];
            } elseif (Token_Refresher::is_fresh($bundle)) {
                return (string) $bundle['access_token'];
            } elseif ('' !== (string) ($bundle['refresh_token'] ?? '')) {
                $refresher = new Token_Refresher();
                $token     = $refresher->ensure_fresh_access_token();
                if (null !== $token && '' !== $token) {
                    return $token;
                }
                $failure = $refresher->last_failure();
            }
        }

        $key = Cloud_Config::api_key();
        if ('' !== $key) {
            return $key;
        }
        return self::auth_error($failure);
    }

    /** @param array{reason:string,retry_after:int} $failure */
    private static function auth_error(array $failure): \WP_Error
    {
        switch ($failure['reason']) {
            case Token_Refresher::FAILURE_INSECURE_URL:
                return new \WP_Error(
                    'cloud_insecure_url',
                    'This site\'s WP MCP Cloud connection uses an OAuth token, which is only ever sent over https, and the stored cloud URL is not https. Re-run cloud-connect with the https URL.'
                );
            case Token_Refresher::FAILURE_UNAVAILABLE:
                $retry = max(1, (int) $failure['retry_after']);
                return new \WP_Error(
                    'cloud_temporarily_unavailable',
                    sprintf('WP MCP Cloud could not refresh this site\'s token right now. Try again in %d seconds. The connection is kept, so do not re-run cloud-connect.', $retry),
                    ['retry_after' => $retry]
                );
            default:
                return new \WP_Error(
                    'cloud_not_authenticated',
                    'WP MCP Cloud rejected this site\'s token. Re-run cloud-connect.'
                );
        }
    }
}
