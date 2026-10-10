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
 *  - GET  /.well-known/openid-configuration          (OpenID Connect Discovery
 *    form of the same metadata) via parse_request
 *  - GET  /.well-known/oauth-protected-resource      (RFC 9728) via parse_request,
 *    also at its path-suffixed form for the MCP endpoint
 *    (/.well-known/oauth-protected-resource/wp-json/mcp/wpmcp-server)
 *
 * All of these are relative to the site's home, so they reach WordPress on
 * every install shape. On a site whose home has a path (a subdirectory
 * install or a subdirectory multisite subsite) the issuer has that path
 * too, and the MCP authorization spec has clients try, in order:
 * /.well-known/oauth-authorization-server/<path> and
 * /.well-known/openid-configuration/<path> at the domain root, then
 * <path>/.well-known/openid-configuration. The last one is served here on
 * every install; the root forms are answered by the main site of a
 * multisite network for its subsites (Network_Sites, issue #473), and on a
 * single site in a subdirectory they are outside WordPress, so discovery
 * relies on the path-appended form.
 *
 * The well-known handler is attached at plugin load (register_well_known()),
 * not on rest_api_init: that action only fires when something builds the
 * REST server, which a plain GET for /.well-known/... never does.
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
     * Attach the well-known handler. Called at plugin load on every install:
     * the handler itself answers nothing while OAuth is off, for this site
     * or for the network site a path-insertion URL names.
     */
    public static function register_well_known(): void
    {
        add_action('parse_request', [self::class, 'maybe_serve_well_known'], 1);
    }

    /**
     * Serve the OAuth discovery document the current request asks for:
     * this site's own, at a path relative to its home, or (on a multisite
     * main site) another network site's, at a path-insertion URL.
     *
     * @return array|null The payload when self::$test_mode is enabled (so
     *                     tests can assert on it without exit() tearing down
     *                     the process); null (after echoing + exit()) in
     *                     production, and null when the path names no
     *                     document this site can answer.
     */
    public static function maybe_serve_well_known(): ?array
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $raw = '' === $uri ? '' : untrailingslashit((string) wp_parse_url($uri, PHP_URL_PATH));
        if (! str_contains($raw, '/.well-known/')) {
            return null;
        }

        $local = self::site_relative_path($raw);
        if (null !== $local && OAuth_Config::is_enabled()) {
            $payload = self::document_for($local);
            if (null !== $payload) {
                return self::respond($payload);
            }
        }

        $payload = self::network_document_for($raw);

        return null === $payload ? null : self::respond($payload);
    }

    /** This site's own discovery document at $path (relative to its home), or null. */
    private static function document_for(string $path): ?array
    {
        if ('/.well-known/oauth-authorization-server' === $path) {
            return Authorization_Server_Metadata::build(self::issuer());
        }
        if ('/.well-known/openid-configuration' === $path) {
            return Authorization_Server_Metadata::build_openid(self::issuer());
        }
        if (Mcp_Resource::WELL_KNOWN_PATH === $path || Mcp_Resource::WELL_KNOWN_PATH . Mcp_Resource::relative_path() === $path) {
            return Protected_Resource_Metadata::build(Mcp_Resource::canonical(), self::issuer());
        }

        return null;
    }

    /**
     * Another network site's document at a path-insertion URL: the
     * well-known segment at the domain root, followed by that site's path
     * (and, for protected resource metadata, its MCP endpoint path).
     */
    private static function network_document_for(string $path): ?array
    {
        $host     = Network_Sites::current_host();
        $as_forms = [
            '/.well-known/oauth-authorization-server' => 'build',
            '/.well-known/openid-configuration'       => 'build_openid',
        ];
        foreach ($as_forms as $prefix => $builder) {
            if (str_starts_with($path, $prefix . '/')) {
                $site_path = substr($path, strlen($prefix));
                return Network_Sites::run_as($host, $site_path, static function () use ($builder, $host, $site_path): ?array {
                    if (! OAuth_Config::is_enabled() || ! self::home_is($host, $site_path)) {
                        return null;
                    }
                    return Authorization_Server_Metadata::$builder(self::issuer());
                });
            }
        }

        $prefix = Mcp_Resource::WELL_KNOWN_PATH . '/';
        $suffix = Mcp_Resource::relative_path();
        if (str_starts_with($path, $prefix) && str_ends_with($path, $suffix)) {
            $site_path = substr($path, strlen(Mcp_Resource::WELL_KNOWN_PATH), -strlen($suffix));
            if ('' === trim($site_path, '/')) {
                return null;
            }
            return Network_Sites::run_as($host, $site_path, static function () use ($host, $site_path, $suffix): ?array {
                if (! OAuth_Config::is_enabled() || ! self::home_is($host, $site_path) || Mcp_Resource::relative_path() !== $suffix) {
                    return null;
                }
                return Protected_Resource_Metadata::build(Mcp_Resource::canonical(), self::issuer());
            });
        }

        return null;
    }

    /**
     * Whether the current site's home is exactly $host + $path, so the
     * issuer a path-insertion URL on $host was built from is this site's.
     * A site whose home moved to another host (domain mapping) has an
     * issuer the requesting host does not own, and is never answered for.
     */
    private static function home_is(string $host, string $path): bool
    {
        $home = (array) wp_parse_url(home_url());

        return strtolower((string) ($home['host'] ?? '')) === strtolower($host)
            && '/' . trim((string) ($home['path'] ?? ''), '/') === '/' . trim($path, '/');
    }

    /**
     * $path relative to the site's home: the home's own path (a subdirectory
     * install or subsite) stripped, or null when $path lies outside it.
     */
    private static function site_relative_path(string $path): ?string
    {
        $base = rtrim((string) wp_parse_url(home_url(), PHP_URL_PATH), '/');
        if ('' === $base) {
            return $path;
        }
        if (! str_starts_with($path, $base . '/')) {
            return null;
        }

        return substr($path, strlen($base));
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
