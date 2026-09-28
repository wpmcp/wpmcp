<?php

namespace WPMCP\Auth;

use WPMCP\Governance\Governance_Audit_Log;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Wires Token_Store::validate() (the OAuth bearer-token-validation helper,
 * issue #43) into WordPress's own determine_current_user filter -- the same
 * seam WP core's Application Passwords feature uses to authenticate REST
 * requests from a header instead of a cookie. Registrar's existing
 * permission_callback already calls current_user_can($a->capability); once
 * this filter resolves an OAuth Bearer token to its bound WP user id, that
 * check (and every other current_user_can()/wp_get_current_user() call for
 * the rest of the request) sees that user automatically, with no change to
 * Registrar itself.
 *
 * Gated by OAuth_Config::is_enabled(): resolve() reads the Authorization
 * header at all ONLY when the subsystem is enabled, so a default (disabled)
 * install's unauthenticated-in-dev behavior -- and every other auth path --
 * is completely unaffected.
 *
 * Audience binding (RFC 8707): a token is honoured ONLY on requests that
 * target the MCP endpoint it was issued for (Mcp_Resource). On any other
 * request (the core REST API, admin-ajax, the front end) the header is not
 * even looked up and the incoming user id passes through unchanged, so a
 * leaked MCP token is not a credential for the rest of the site. In-process
 * dispatch during an MCP request (the adapter's own tool calls, any
 * rest_do_request passthrough) runs after this filter has already resolved
 * the user for that request, so it is unaffected.
 *
 * Unauthenticated (401) responses from the MCP route carry a
 * `WWW-Authenticate: Bearer resource_metadata="..."` challenge (RFC 9728
 * section 5.1) so a client can discover the authorization server from the
 * failure itself.
 *
 * Scope note: this wires user IDENTITY resolution only. Token_Store::validate()
 * also returns the client's granted scope, but no ability/domain-level scope
 * enforcement consults it yet (see the issue #43 report for why that is
 * explicitly out of scope for this pass); a Bearer-authenticated caller is
 * subject to exactly the same capability + Governance gates as any other WP
 * user, nothing more permissive and nothing scope-restricted yet.
 */
class Bearer_Auth
{
    public function register(): void
    {
        add_filter('determine_current_user', [self::class, 'resolve'], 20);
        add_filter('rest_post_dispatch', [self::class, 'add_challenge_header'], 10, 3);
    }

    /**
     * @param int|false $incoming_user_id Whatever a higher-priority
     *                                    determine_current_user listener (or
     *                                    WordPress's own cookie auth) has
     *                                    already resolved; passed through
     *                                    unchanged whenever this filter has
     *                                    nothing to add.
     * @return int|false
     */
    public static function resolve($incoming_user_id)
    {
        if (! OAuth_Config::is_enabled()) {
            return $incoming_user_id;
        }

        if (! Mcp_Resource::request_targets_resource()) {
            return $incoming_user_id;
        }

        $token = self::bearer_token_from_request();
        if (null === $token) {
            return $incoming_user_id;
        }

        $record = Token_Store::validate($token);
        if (null === $record || ! Mcp_Resource::matches((string) $record['resource'])) {
            self::audit(false);
            return $incoming_user_id;
        }

        self::audit(true);

        return $record['user_id'];
    }

    /**
     * rest_post_dispatch: attach the RFC 9728 resource-metadata challenge to
     * a 401 from the MCP route, so a client that connected without (or with
     * an expired) token learns where to authorize.
     *
     * @param mixed            $response
     * @param mixed            $server
     * @param \WP_REST_Request $request
     * @return mixed
     */
    public static function add_challenge_header($response, $server, $request)
    {
        if (! $response instanceof \WP_HTTP_Response || 401 !== $response->get_status()) {
            return $response;
        }
        if (! is_object($request) || ! method_exists($request, 'get_route') || ! Mcp_Resource::is_route((string) $request->get_route())) {
            return $response;
        }
        if (! OAuth_Config::is_enabled()) {
            return $response;
        }

        $response->header('WWW-Authenticate', 'Bearer resource_metadata="' . Mcp_Resource::metadata_url() . '"');

        return $response;
    }

    private static function bearer_token_from_request(): ?string
    {
        $header = isset($_SERVER['HTTP_AUTHORIZATION'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_AUTHORIZATION']))
            : '';
        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, strlen('Bearer ')));

        return '' === $token ? null : $token;
    }

    private static function audit(bool $allowed): void
    {
        try {
            Governance_Audit_Log::record('oauth/validate', 'none', $allowed);
        } catch (\Throwable $e) {
            // Auditing must never break the authentication outcome it is observing.
        }
    }
}
