<?php

namespace WPMCP\MCP;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Transport-level hardening for the MCP and OAuth request paths (issue #133).
 *
 * Everything here protects the *framing* of a response rather than its
 * contents. Three failure modes, all of which present to the user as the
 * same useless symptom ("the connector stopped responding"), and none of
 * which the tool layer can see or fix:
 *
 *  1. CACHING. An MCP endpoint is a POST-per-call RPC channel, but shared
 *     hosting stacks (LiteSpeed in particular) and reverse proxies will
 *     happily buffer or cache a 200 with no explicit cache directive, then
 *     replay a stale JSON-RPC body against a different request id. Every
 *     MCP/OAuth response is therefore stamped no-store, plus the
 *     vendor-specific opt-outs those stacks actually honour
 *     (X-LiteSpeed-Cache-Control, X-Accel-Buffering for nginx/FastCGI
 *     buffering, and a Pragma for ancient intermediaries).
 *
 *  2. STRAY OUTPUT. JSON-RPC framing is destroyed by a single PHP notice
 *     printed into the body, and the client's reaction is to drop the
 *     connection rather than report a parse error. Any plugin on the site
 *     can produce one; WordPress itself emits HTML for _doing_it_wrong().
 *     display_errors is forced off for the remainder of the request as
 *     soon as we know the route is ours. Errors still reach the log: this
 *     suppresses the *display* channel only, and only on our two route
 *     families, so a developer debugging a theme is unaffected.
 *
 *  3. STALE SITE URL. After a domain migration, a connector configured
 *     against the old host keeps working for tools/list (the adapter
 *     answers happily) but every write lands on, or is generated for, the
 *     wrong site. The guard compares the request's Host against home_url()
 *     and fails closed with HTTP 421 Misdirected Request and a structured
 *     error carrying the correct endpoint, so the fix is in the error
 *     message instead of in a support thread.
 *
 * How this differs from the competing implementation we studied: theirs
 * scopes the no-store headers to the MCP route only (leaving OAuth token
 * responses cacheable, which is the more dangerous of the two, since a
 * cached token response is a credential served to the wrong caller), and
 * its host guard returns a bare message. Ours covers both route families,
 * returns a machine-readable `expected_host` / `endpoint` payload the
 * client can act on, and is exercised by the connection self-test so an
 * admin can see the headers land before an agent ever connects.
 *
 * Fail-open where ambiguity would brick the endpoint: a request with no
 * Host header at all (CLI, some proxies) is treated as a match, and the
 * whole guard can be switched off with the wpmcp_host_guard_enabled filter
 * for reverse-proxy setups that legitimately rewrite Host.
 */
class Transport_Guard
{
    /** The REST namespace prefix the MCP adapter mounts every MCP server under. */
    public const MCP_ROUTE_PREFIX = '/mcp/';

    /** This plugin's OAuth 2.1 route prefix (see Auth\Endpoints). */
    public const OAUTH_ROUTE_PREFIX = '/wpmcp/v1/oauth';

    public const MISMATCH_CODE = 'wpmcp_site_url_mismatch';

    /**
     * The no-store header set applied to every MCP and OAuth response.
     * Ordered most-standard-first; the vendor headers are additive opt-outs
     * for stacks that ignore Cache-Control on POST responses.
     *
     * @return array<string, string>
     */
    public static function no_store_headers(): array
    {
        return [
            'Cache-Control'            => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'                   => 'no-cache',
            'X-LiteSpeed-Cache-Control' => 'no-cache',
            'X-Accel-Buffering'        => 'no',
        ];
    }

    public function register(): void
    {
        add_filter('rest_pre_dispatch', [$this, 'filter_pre_dispatch'], 10, 3);
        add_filter('rest_pre_serve_request', [$this, 'filter_pre_serve'], 10, 4);
    }

    /** Whether a REST route belongs to an MCP server mounted by the adapter. */
    public static function is_mcp_route(string $route): bool
    {
        return str_starts_with($route, self::MCP_ROUTE_PREFIX);
    }

    /** Whether a REST route belongs to this plugin's OAuth 2.1 surface. */
    public static function is_oauth_route(string $route): bool
    {
        return str_starts_with($route, self::OAUTH_ROUTE_PREFIX);
    }

    /** Whether a REST route is one this guard is responsible for at all. */
    public static function is_guarded_route(string $route): bool
    {
        return self::is_mcp_route($route) || self::is_oauth_route($route);
    }

    /**
     * Pure host comparison, case- and port- and www-insensitive. An empty
     * request host matches by design: a missing Host header must never be
     * the thing that takes the endpoint down.
     */
    public static function host_matches(string $request_host, string $home_host): bool
    {
        $request = self::normalize_host($request_host);

        if ('' === $request) {
            return true;
        }

        return $request === self::normalize_host($home_host);
    }

    private static function normalize_host(string $host): string
    {
        $host = strtolower(trim($host));
        $host = (string) preg_replace('/:\d+$/', '', $host);
        $host = (string) preg_replace('/^www\./', '', $host);

        return $host;
    }

    /**
     * rest_pre_dispatch: force display_errors off for our routes, then run
     * the host guard on them. Returning a WP_Error short-circuits dispatch,
     * so the route callback never runs on a misdirected request.
     *
     * @param mixed $result Dispatch short-circuit value; passed through untouched unless we reject.
     * @param mixed $server WP_REST_Server (unused).
     * @param mixed $request WP_REST_Request.
     * @return mixed
     */
    public function filter_pre_dispatch($result, $server = null, $request = null)
    {
        $route = self::route_of($request);

        if (null === $route || ! self::is_guarded_route($route)) {
            return $result;
        }

        self::suppress_error_display();

        if (! self::is_mcp_route($route)) {
            return $result;
        }

        /**
         * Disable the site-URL mismatch guard, for reverse-proxy or
         * headless setups where the inbound Host legitimately differs from
         * home_url(). Default true (guard on).
         *
         * @param bool $enabled Whether the host guard runs.
         */
        if (! apply_filters('wpmcp_host_guard_enabled', true)) {
            return $result;
        }

        $request_host = self::request_host($request);
        $home_host    = (string) wp_parse_url(home_url(), PHP_URL_HOST);

        if (self::host_matches($request_host, $home_host)) {
            return $result;
        }

        return self::mismatch_error($home_host);
    }

    /**
     * rest_pre_serve_request: stamp the no-store header set just before the
     * body is written. This hook is the last point at which headers are
     * still mutable, which is exactly why the headers go here rather than
     * on the response object (the adapter may stream its own body).
     *
     * @param bool  $served  Whether the request has already been served.
     * @param mixed $result  WP_REST_Response (unused).
     * @param mixed $request WP_REST_Request.
     * @param mixed $server  WP_REST_Server (unused).
     * @return bool $served, always unmodified.
     */
    public function filter_pre_serve($served, $result = null, $request = null, $server = null)
    {
        $route = self::route_of($request);

        if (null === $route || ! self::is_guarded_route($route)) {
            return $served;
        }

        self::send_no_store_headers();

        return $served;
    }

    /**
     * Emit the no-store header set. Safe to call more than once and safe to
     * call after output has begun (it simply does nothing), so callers never
     * have to guard it themselves.
     */
    public static function send_no_store_headers(): void
    {
        if (headers_sent()) {
            return;
        }

        foreach (self::no_store_headers() as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    /**
     * Force PHP's on-screen error output off for the rest of this request,
     * regardless of WP_DEBUG_DISPLAY. Logging is untouched.
     */
    public static function suppress_error_display(): void
    {
        // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed, WordPress.PHP.NoSilencedErrors.Discouraged -- deliberate: a printed notice corrupts JSON-RPC framing. Errors still log.
        @ini_set('display_errors', '0');
    }

    /**
     * The structured 421 a misdirected connector gets. The payload names
     * the host we expected and the endpoint to reconnect to, so the client
     * (or the human reading the transcript) can fix the config without
     * guessing what changed.
     */
    public static function mismatch_error(string $home_host): \WP_Error
    {
        return new \WP_Error(
            self::MISMATCH_CODE,
            sprintf(
                /* translators: %s: the current MCP endpoint URL. */
                __('Site URL mismatch: this connector is pointed at a host this site no longer answers to. Reconnect using %s.', 'wpmcp'),
                home_url('/wp-json/mcp/wpmcp-server')
            ),
            [
                'status'        => 421,
                'expected_host' => $home_host,
                'endpoint'      => home_url('/wp-json/mcp/wpmcp-server'),
            ]
        );
    }

    /** The route of a duck-typed request object, or null when there is not one. */
    private static function route_of($request): ?string
    {
        if (! is_object($request) || ! method_exists($request, 'get_route')) {
            return null;
        }

        return (string) $request->get_route();
    }

    /** The inbound Host, preferring the parsed request header over the raw server global. */
    private static function request_host($request): string
    {
        if (is_object($request) && method_exists($request, 'get_header')) {
            $header = (string) $request->get_header('host');
            if ('' !== $header) {
                return $header;
            }
        }

        return isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
    }
}
