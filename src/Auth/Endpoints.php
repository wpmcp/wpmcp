<?php

namespace WPMCP\Auth;

use WPMCP\MCP\Transport_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registers every OAuth 2.1 + Dynamic Client Registration endpoint (issue
 * #43), but ONLY when OAuth_Config::is_enabled() is true (default false).
 * When disabled, register() is a complete no-op: none of these endpoints
 * exist at all, so an existing install is unaffected and cannot even detect
 * the feature exists via route probing.
 *
 * Two delivery mechanisms, because register_rest_route() refuses an empty
 * namespace (WordPress requires every REST route be namespaced, logging an
 * "incorrect usage" notice otherwise) and RFC 8414/9728 mandate the metadata
 * documents live at true top-level /.well-known/ paths, not under
 * /wp-json/{namespace}/:
 *
 *  - GET  /.well-known/oauth-authorization-server   (RFC 8414) via parse_request
 *  - GET  /.well-known/oauth-protected-resource      (RFC 9728) via parse_request,
 *    also at its path-suffixed form for the MCP endpoint
 *    (/.well-known/oauth-protected-resource/wp-json/mcp/wpmcp-server)
 *  - POST /wp-json/wpmcp/v1/oauth/register            (RFC 7591 DCR) via register_rest_route
 *  - POST /wp-json/wpmcp/v1/oauth/authorize             (authorization_code, PKCE;
 *    a client_id that is an https URL is a Client ID Metadata Document, #388)
 *  - POST /wp-json/wpmcp/v1/oauth/token                  (authorization_code exchange)
 *
 * permission_callback is '__return_true' on every REST route: each handler
 * does its own request-shaped authorization (DCR is open registration by
 * design, per RFC 7591; authorize requires a logged-in WP user, checked
 * inside Authorization_Grant; token exchange is validated entirely by the
 * presented code/verifier, checked inside Token_Grant).
 *
 * A GET to the authorization endpoint is a browser sent by a client: it is
 * redirected to the consent screen (OAuth_Consent_Page, issue #454), where
 * the signed-in user allows or denies the client and picks its access
 * level. Scopes (`mcp`, `mcp:read`) are enforced by Client_Access.
 *
 * See .superpowers/sdd/issue-43-report.md for the original architecture
 * writeup. Refresh tokens (#133), the consent screen and scope enforcement
 * (#454) came later; there is still no revocation endpoint.
 */
class Endpoints
{
    public const NAMESPACE = 'wpmcp/v1';

    public function register(): void
    {
        if (! OAuth_Config::is_enabled()) {
            return;
        }

        add_action('parse_request', [$this, 'maybe_serve_well_known']);

        register_rest_route(self::NAMESPACE, '/oauth/register', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => [$this, 'register_client'],
        ]);

        register_rest_route(self::NAMESPACE, '/oauth/authorize', [
            [
                'methods'             => 'POST',
                'permission_callback' => '__return_true',
                'callback'            => [$this, 'authorize'],
            ],
            [
                'methods'             => 'GET',
                'permission_callback' => '__return_true',
                'callback'            => [$this, 'authorize_redirect'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/oauth/token', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => [$this, 'token'],
        ]);
    }

    /** Test seam: when true, maybe_serve_well_known() returns its payload instead of echoing + exiting. */
    private static bool $test_mode = false;

    public static function set_test_mode(bool $enabled): void
    {
        self::$test_mode = $enabled;
    }

    /**
     * Only called once OAuth_Config::is_enabled() has already gated
     * register() (see above): the parse_request action is never even
     * attached when the subsystem is disabled, so there is no separate
     * enabled-check needed here.
     *
     * @return array|null The payload when self::$test_mode is enabled (so
     *                     tests can assert on it without exit() tearing down
     *                     the process); null (after echoing + exit()) in
     *                     production, and null when the path does not match
     *                     either well-known document.
     */
    public function maybe_serve_well_known(): ?array
    {
        $path = self::request_path();

        if ('/.well-known/oauth-authorization-server' === $path) {
            return self::respond(Authorization_Server_Metadata::build(self::issuer()));
        }
        if (Mcp_Resource::WELL_KNOWN_PATH === $path || Mcp_Resource::WELL_KNOWN_PATH . Mcp_Resource::relative_path() === untrailingslashit($path)) {
            return self::respond(Protected_Resource_Metadata::build(Mcp_Resource::canonical(), self::issuer()));
        }

        return null;
    }

    /** The current request's path, with the site's own base path (subdirectory installs) stripped. */
    private static function request_path(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        if ('' === $uri) {
            return '';
        }

        $path = (string) wp_parse_url($uri, PHP_URL_PATH);

        $base = (string) wp_parse_url(home_url(), PHP_URL_PATH);
        $base = '' === $base ? '' : rtrim($base, '/');
        if ('' !== $base && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        return $path;
    }

    private static function respond(array $payload): ?array
    {
        if (self::$test_mode) {
            return $payload;
        }

        // The well-known documents are served outside the REST stack (see
        // the class docblock), so Transport_Guard's rest_pre_serve hook
        // never sees them. They still must not be cached: a proxy holding
        // an old authorization-server document after a domain change points
        // every new client at endpoints that no longer exist (issue #133).
        Transport_Guard::send_no_store_headers();
        header('Content-Type: application/json');
        echo wp_json_encode($payload);
        exit;
    }

    public function register_client(\WP_REST_Request $request): \WP_REST_Response
    {
        $result = Client_Registration::register($request->get_params(), self::client_key());

        if (is_wp_error($result)) {
            return self::error_response($result, 400);
        }

        return new \WP_REST_Response($result, 201);
    }

    public function authorize(\WP_REST_Request $request): \WP_REST_Response
    {
        $result = Authorization_Grant::authorize($request->get_params());

        if (is_wp_error($result)) {
            $status = match ($result->get_error_code()) {
                'login_required' => 401,
                'access_denied'  => 403,
                default          => 400,
            };
            return self::error_response($result, $status);
        }

        return new \WP_REST_Response($result, 200);
    }

    /**
     * A browser arriving at the authorization endpoint (RFC 6749 4.1.1) is
     * sent on to the consent screen in wp-admin, which handles signing in
     * and asks the user. Nothing is validated or issued here; the consent
     * screen vets the request before it shows or redirects anywhere.
     */
    public function authorize_redirect(\WP_REST_Request $request): \WP_REST_Response
    {
        $response = new \WP_REST_Response(null, 302);
        $response->header('Location', \WPMCP\Admin\OAuth_Consent_Page::url($request->get_query_params()));

        return $response;
    }

    public function token(\WP_REST_Request $request): \WP_REST_Response
    {
        $result = Token_Grant::exchange($request->get_params());

        if (is_wp_error($result)) {
            return self::error_response($result, 400);
        }

        return new \WP_REST_Response($result, 200);
    }

    private static function error_response(\WP_Error $error, int $status): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'error'             => $error->get_error_code(),
            'error_description' => $error->get_error_message(),
        ], $status);
    }

    /** The site's own origin, used as the OAuth issuer. The protected resource is Mcp_Resource::canonical(). */
    private static function issuer(): string
    {
        return untrailingslashit(home_url());
    }

    /** Caller identity key for the DCR rate limiter: remote IP, matching Rate_Limiter's anonymous-caller convention. */
    private static function client_key(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        return 'ip:' . $ip;
    }
}
