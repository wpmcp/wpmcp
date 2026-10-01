<?php

namespace WPMCP\Auth;

use WPMCP\MCP\Server;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The single source of truth for the OAuth audience of every token this
 * plugin issues: the MCP endpoint itself (RFC 8707 resource indicator).
 *
 * Tokens used to carry no audience at all, and Bearer_Auth honoured them on
 * every request the site served, so an access token minted for an MCP client
 * was also a credential for the core REST API, admin-ajax and anything else
 * that resolves the current user. Binding every code, access token and
 * refresh token to this URI, and honouring a token only on requests that
 * target it, keeps a leaked MCP token scoped to MCP.
 *
 * The route is derived from the constants the MCP server is mounted with
 * (Server::NAMESPACE, Server::SERVER_ID) and the site's REST prefix, so
 * there is nothing here to keep in sync by hand.
 */
class Mcp_Resource
{
    /**
     * The scopes advertised in both metadata documents: `mcp` (full access
     * as the token's user) and `mcp:read` (read abilities only). Enforced
     * by Client_Access (issue #454).
     */
    public const SCOPES_SUPPORTED = Client_Access::SCOPES_SUPPORTED;

    public const WELL_KNOWN_PATH = '/.well-known/oauth-protected-resource';

    /** The REST route the MCP server is mounted on, e.g. /mcp/wpmcp-server. */
    public static function route(): string
    {
        return '/' . Server::NAMESPACE . '/' . Server::SERVER_ID;
    }

    /** The MCP endpoint's path relative to the site home, e.g. /wp-json/mcp/wpmcp-server. */
    public static function relative_path(): string
    {
        return '/' . trim(rest_get_url_prefix(), '/') . self::route();
    }

    /** The canonical resource URI: the absolute MCP endpoint URL, no trailing slash. */
    public static function canonical(): string
    {
        return untrailingslashit(home_url(self::relative_path()));
    }

    /**
     * The RFC 9728 metadata URL for this resource, in its path-suffixed
     * form (the well-known segment inserted before the resource path),
     * relative to the site home so subdirectory installs still route it
     * through WordPress.
     */
    public static function metadata_url(): string
    {
        return home_url(self::WELL_KNOWN_PATH . self::relative_path());
    }

    /**
     * Whether $uri names this resource. Comparison is on the normalized
     * form (case-insensitive scheme and host, default port dropped,
     * trailing slash ignored); a fragment or query component never matches.
     */
    public static function matches(string $uri): bool
    {
        $normalized = self::normalize($uri);

        return null !== $normalized && $normalized === self::normalize(self::canonical());
    }

    /**
     * Resolve a client-supplied `resource` parameter (absent, a string, or
     * a list of strings per RFC 8707) to the canonical URI, or null when it
     * names anything else. Absent means the canonical resource, for clients
     * that predate resource indicators.
     *
     * @param mixed $resource
     */
    public static function resolve_requested($resource): ?string
    {
        if (null === $resource || '' === $resource || [] === $resource) {
            return self::canonical();
        }

        $values = is_array($resource) ? $resource : [$resource];
        foreach ($values as $value) {
            if (! is_string($value) || ! self::matches($value)) {
                return null;
            }
        }

        return self::canonical();
    }

    /**
     * Whether the current HTTP request targets the MCP endpoint.
     *
     * Read from the raw request rather than from WP's parsed query vars,
     * because determine_current_user can run before parse_request. WordPress
     * lets a rest_route GET/POST parameter override the pretty-permalink
     * path, so when one is present it alone decides the route; otherwise the
     * path (with a subdirectory install's base stripped) must be the
     * endpoint exactly.
     */
    public static function request_targets_resource(): bool
    {
        $overrides = [];
        // phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Read-only routing check, no state change.
        foreach ([$_GET, $_POST] as $source) {
            if (isset($source['rest_route'])) {
                $overrides[] = is_string($source['rest_route']) ? sanitize_text_field(wp_unslash($source['rest_route'])) : '';
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

        if ([] !== $overrides) {
            foreach ($overrides as $route) {
                if (! self::is_route($route)) {
                    return false;
                }
            }
            return true;
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        if ('' === $uri) {
            return false;
        }

        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        $base = rtrim((string) wp_parse_url(home_url(), PHP_URL_PATH), '/');
        if ('' !== $base) {
            if (! str_starts_with($path, $base . '/')) {
                return false;
            }
            $path = substr($path, strlen($base));
        }

        return untrailingslashit($path) === self::relative_path();
    }

    /** Whether a REST route string (as WP_REST_Request::get_route() reports it) is the MCP route. */
    public static function is_route(string $route): bool
    {
        return '/' . trim($route, '/') === self::route();
    }

    private static function normalize(string $uri): ?string
    {
        $parts = wp_parse_url(trim($uri));
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        if (isset($parts['fragment']) || isset($parts['query']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if (('https' === $scheme && 443 === $port) || ('http' === $scheme && 80 === $port)) {
            $port = null;
        }

        $path = untrailingslashit($parts['path'] ?? '');

        return $scheme . '://' . $host . (null !== $port ? ':' . $port : '') . $path;
    }
}
