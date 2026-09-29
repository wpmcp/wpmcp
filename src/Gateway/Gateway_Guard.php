<?php

namespace WPMCP\Gateway;

use WPMCP\Auth\Bearer_Auth;
use WPMCP\Auth\Client_Store;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\Identity\Ip_Allowlist;
use WPMCP\MCP\Transport_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * What the gateway credential may do once it authenticates (issue #130, on
 * top of #142's Gateway_Credential).
 *
 * ONLY THE MCP CONNECTION. Bearer_Auth resolves tokens on WordPress's
 * global determine_current_user filter, so without this a gateway token
 * would be a plain administrator on /wp/v2/users, /wp/v2/plugins,
 * admin-ajax and every other entry point, and an identity allowlist (which
 * only narrows abilities inside Registrar::is_permitted) would be a claim
 * rather than a control. The token is refused anywhere except the MCP and
 * OAuth routes, judged twice: early, on the path and query string, when the
 * user is first resolved; and again on parse_request against the route
 * WordPress actually resolved, which is what closes a form-encoded POST
 * rest_route being dispatched instead of the path. Both fail closed.
 *
 * AS ITS BOUND IDENTITY. When the live credential is bound to a named
 * Identity (Gateway_Binding), every gateway request resolves to that
 * identity through the wpmcp_current_identity filter, so
 * Registrar::is_permitted() narrows each ability to the identity's
 * allowlist and records the decision in Governance_Audit_Log under the
 * identity's name. A binding naming an identity that has since been
 * deleted denies everything (Governance default-denies an unknown name).
 *
 * "A gateway token" means a token whose client is the protected gateway
 * client row (Client_Store::is_protected), which only Gateway_Credential
 * creates. That is authoritative and does not depend on the scope string.
 *
 * THE KILL SWITCH. kill() is Gateway_Credential::deprovision() plus the
 * binding, local and offline, and it is reachable as
 * `wp wpmcp gateway-revoke` outside the ability registry, so it survives a
 * build or a configuration in which the gateway abilities are not
 * registered.
 */
class Gateway_Guard
{
    public static function register(): void
    {
        add_filter('wpmcp_current_identity', [self::class, 'filter_current_identity']);
        add_filter('wpmcp_bearer_token_accepted', [self::class, 'filter_bearer_token_accepted'], 10, 2);
        // Priority 1: before rest_api_loaded (parse_request, 10) dispatches.
        add_action('parse_request', [self::class, 'enforce_dispatched_route'], 1);

        if (defined('WP_CLI') && WP_CLI && class_exists('\WP_CLI')) {
            \WP_CLI::add_command('wpmcp gateway-revoke', [self::class, 'cli_revoke']);
        }
    }

    /** Whether a validated token record belongs to the gateway client. */
    public static function is_gateway_token(array $record): bool
    {
        $client_id = (string) ($record['client_id'] ?? '');

        return '' !== $client_id && Client_Store::is_protected($client_id);
    }

    /**
     * wpmcp_bearer_token_accepted: refuse a gateway token off the MCP and
     * OAuth routes, or from outside its bound identity's allowed_ips. May
     * only refuse, never accept.
     *
     * @param bool  $accepted Whether the token authenticates this request.
     * @param array $record   The validated token record.
     */
    public static function filter_bearer_token_accepted($accepted, $record = []): bool
    {
        if (! $accepted) {
            return false;
        }

        if (! is_array($record) || ! self::is_gateway_token($record)) {
            return true;
        }

        if (! self::surface_allows(self::early_rest_route())) {
            return false;
        }

        // Issue #416: a bound identity pinned to other addresses refuses the
        // token here, so the request is anonymous before anything runs.
        $bound    = Gateway_Binding::identity_for_client((string) $record['client_id']);
        $identity = null !== $bound ? Identity_Store::get($bound) : null;

        return null === $identity || ! Ip_Allowlist::refuses($identity);
    }

    /**
     * parse_request: the authoritative half of the surface restriction.
     *
     * The early check can run before WordPress has parsed the request, so it
     * sees only the path and the query string. WordPress then dispatches
     * what WP::parse_request() resolves, and a form-encoded POST rest_route
     * outranks both: a POST to /wp-json/mcp/... carrying
     * rest_route=/wp/v2/users passes the early check and runs core REST.
     * Here the resolved route is known, so a gateway request not headed for
     * the MCP or OAuth routes (or not for REST at all) is logged out before
     * rest_api_loaded dispatches it.
     *
     * @param \WP|mixed $wp The WP instance parse_request passes.
     */
    public static function enforce_dispatched_route($wp): void
    {
        $token = Bearer_Auth::current_token();
        if (! is_array($token) || ! self::is_gateway_token($token)) {
            return;
        }

        $vars  = is_object($wp) && isset($wp->query_vars) && is_array($wp->query_vars) ? $wp->query_vars : [];
        $route = isset($vars['rest_route']) && is_string($vars['rest_route']) && '' !== $vars['rest_route']
            ? '/' . ltrim($vars['rest_route'], '/')
            : null;

        if (self::surface_allows($route)) {
            return;
        }

        Bearer_Auth::forget_current();
        wp_set_current_user(0);
        self::audit('gateway/surface-refused', false, 'dispatched_route:' . (string) $route);
    }

    /**
     * wpmcp_current_identity: a gateway request acts as the identity its
     * live credential is bound to. Any other request, or an unbound
     * credential, passes through unchanged.
     *
     * @param string|null $identity Whatever a higher-priority listener resolved.
     * @return string|null
     */
    public static function filter_current_identity($identity)
    {
        $token = Bearer_Auth::current_token();
        if (! is_array($token) || ! self::is_gateway_token($token)) {
            return $identity;
        }

        return Gateway_Binding::identity_for_client((string) $token['client_id']) ?? $identity;
    }

    /**
     * The kill switch: the credential and its binding, locally. No network.
     *
     * @return bool True when something was removed.
     */
    public static function kill(string $reason): bool
    {
        $removed = Gateway_Credential::deprovision();
        Gateway_Binding::clear();
        self::audit('gateway/credential-revoke', true, $reason . ($removed ? '' : ':nothing_to_revoke'));

        return $removed;
    }

    /** `wp wpmcp gateway-revoke`: WP-CLI is already root-equivalent on the box. */
    public static function cli_revoke(array $args = [], array $assoc = []): void
    {
        $removed = self::kill('cli');
        \WP_CLI::success($removed ? 'Gateway credential revoked.' : 'No gateway credential was provisioned; nothing to revoke.');
    }

    private static function surface_allows(?string $route): bool
    {
        $on = null !== $route && Transport_Guard::is_guarded_route($route);

        /**
         * Whether a gateway credential may authenticate this request. Only
         * for setups that mount the MCP surface somewhere this cannot see;
         * widening it hands the credential the rest of the site.
         *
         * @param bool        $on    Whether the request is on the gateway surface.
         * @param string|null $route The resolved REST route, or null.
         */
        return (bool) apply_filters('wpmcp_gateway_surface', $on, $route);
    }

    /**
     * The REST route of the current request as far as it can be told before
     * WordPress parses it, or null when it is not a REST request. See
     * enforce_dispatched_route() for the authoritative re-check.
     */
    private static function early_rest_route(): ?string
    {
        // Deliberately NOT run through sanitize_text_field(): it strips
        // percent-encoded octets, and a mutated path could be matched as the
        // MCP route while WordPress dispatches the original. These values
        // are only compared against fixed route prefixes, never echoed,
        // stored or used in a query, so the exact bytes are what is judged.
        if (isset($_GET['rest_route'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $route = (string) wp_unslash($_GET['rest_route']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            return '/' . ltrim($route, '/');
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ('' === $uri) {
            return null;
        }

        $path   = (string) wp_parse_url($uri, PHP_URL_PATH);
        $prefix = '/' . trim(rest_get_url_prefix(), '/') . '/';
        $at     = strpos($path, $prefix);
        if (false === $at) {
            return null;
        }

        return '/' . ltrim(substr($path, $at + strlen($prefix)), '/');
    }

    private static function audit(string $event, bool $allowed, string $reason): void
    {
        try {
            Governance_Audit_Log::record($event, Identity_Context::current() ?? 'none', $allowed, $reason);
        } catch (\Throwable $e) {
            // Auditing must never break the outcome it is observing.
        }
    }
}
