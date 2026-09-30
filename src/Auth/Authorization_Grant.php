<?php

namespace WPMCP\Auth;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The programmatic core behind the OAuth 2.1 authorization endpoint (RFC
 * 6749 4.1.1): binds the currently logged-in WP user's consent to a
 * single-use authorization code, after validating everything the
 * authorization step is responsible for.
 *
 * The interactive part, the consent screen where a user sees the client
 * and allows or denies it, is OAuth_Consent_Page (issue #454); it calls
 * validate() to vet the request before showing anything and authorize()
 * once the user allows. What is implemented here is the binding step:
 * given a validated request and a WP user already logged in via ordinary
 * WP auth (cookie auth), issue the code that represents that user's
 * authorization.
 *
 * ACCESS LEVEL (issue #454). The optional `access` parameter is the
 * approving user's choice: 'full', 'read', or 'identity:<name>' (site
 * owners only, and the identity must exist). The code carries `mcp:read`
 * when the client asked only for that or the user chose read-only, `mcp`
 * otherwise; the choice is recorded in Client_Access for this client and
 * user. The reserved `gateway` scope is refused here with invalid_scope.
 *
 * PKCE is validated here (S256 mandatory, method must be exactly 'S256', a
 * missing or 'plain' code_challenge_method is rejected) rather than only at
 * token exchange, per OAuth 2.1 guidance to reject a malformed/insecure PKCE
 * request as early as possible instead of accepting it now and only
 * discovering the problem later.
 */
class Authorization_Grant
{
    /**
     * @param array $params response_type, client_id, redirect_uri,
     *                       code_challenge, code_challenge_method, scope,
     *                       and optionally resource (RFC 8707). A resource
     *                       must name the MCP endpoint (Mcp_Resource); when
     *                       absent it defaults to it, so clients that
     *                       predate resource indicators keep working.
     * @return array{code: string}|\WP_Error
     */
    public static function authorize(array $params): array|\WP_Error
    {
        $request = self::validate($params);
        if (is_wp_error($request)) {
            return $request;
        }

        $client_id = $request['client_id'];
        $user_id   = get_current_user_id();

        $access = self::access_choice($params, $client_id);
        if (is_wp_error($access)) {
            return $access;
        }
        [$level, $identity] = $access;

        $scope = Client_Access::granted_scope($request['scope'], $level);
        if (Client_Access::SCOPE_READ === $scope && Client_Access::FULL === $level) {
            $level = Client_Access::READ;
        }

        $code = Code_Store::issue([
            'client_id'             => $client_id,
            'user_id'               => $user_id,
            'redirect_uri'          => $request['redirect_uri'],
            'code_challenge'        => $request['code_challenge'],
            'code_challenge_method' => $request['code_challenge_method'],
            'scope'                 => $scope,
            'resource'              => $request['resource'],
        ]);

        Client_Access::set($client_id, $user_id, $level, $identity);

        self::audit(true, $client_id);

        return ['code' => $code];
    }

    /**
     * Vet an authorization request without issuing anything: the user is
     * logged in, the client is known (or its metadata document resolves
     * and it is admitted), the redirect_uri is one it registered, PKCE is
     * S256, the resource is this server's and the scope is not reserved.
     * Only after this succeeds may the consent screen show the client or
     * redirect anywhere.
     *
     * @return array{client_id: string, client_name: string, redirect_uri: string, code_challenge: string, code_challenge_method: string, scope: string, resource: string}|\WP_Error
     */
    public static function validate(array $params): array|\WP_Error
    {
        $response_type = (string) ($params['response_type'] ?? '');
        if ('code' !== $response_type) {
            return new \WP_Error('unsupported_response_type', 'Only the "code" response_type is supported.');
        }

        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return self::deny('login_required', 'A logged-in WordPress user is required to authorize a client.', '');
        }

        $client_id = (string) ($params['client_id'] ?? '');
        $client    = Client_Store::get($client_id);
        if (null === $client && Client_Metadata_Document::looks_like_url($client_id)) {
            // Issue #388: a client_id that is an https URL names a Client ID
            // Metadata Document. The document supplies the redirect_uris,
            // and a site that requires approval holds new clients here.
            $client = Client_Metadata_Document::resolve($client_id);
            if (is_wp_error($client)) {
                return self::deny('invalid_client', $client->get_error_message(), $client_id);
            }
            $admitted = Client_Metadata_Document::admit($client);
            if (is_wp_error($admitted)) {
                return self::deny($admitted->get_error_code(), $admitted->get_error_message(), $client_id);
            }
        }
        if (null === $client) {
            return self::deny('invalid_client', 'Unknown client_id.', $client_id);
        }

        $redirect_uri = (string) ($params['redirect_uri'] ?? '');
        if (! in_array($redirect_uri, $client['redirect_uris'], true)) {
            return self::deny('invalid_request', 'redirect_uri does not match a redirect_uri registered for this client.', $client_id);
        }

        $code_challenge        = (string) ($params['code_challenge'] ?? '');
        $code_challenge_method = (string) ($params['code_challenge_method'] ?? '');
        if ('' === $code_challenge || 'S256' !== $code_challenge_method) {
            return self::deny('invalid_request', 'A code_challenge with code_challenge_method=S256 is required.', $client_id);
        }

        $resource = Mcp_Resource::resolve_requested($params['resource'] ?? null);
        if (null === $resource) {
            return self::deny('invalid_target', 'The requested resource is not served by this authorization server.', $client_id);
        }

        // The 'gateway' scope is reserved for the locally minted gateway
        // credential (issue #142); no interactive grant may carry it.
        $scope = is_string($params['scope'] ?? null) ? (string) $params['scope'] : '';
        if (Refresh_Token_Store::is_gateway_scope($scope)) {
            return self::deny('invalid_scope', 'The requested scope is reserved.', $client_id);
        }

        return [
            'client_id'             => $client_id,
            'client_name'           => (string) ($client['client_name'] ?? ''),
            'redirect_uri'          => $redirect_uri,
            'code_challenge'        => $code_challenge,
            'code_challenge_method' => $code_challenge_method,
            'scope'                 => $scope,
            'resource'              => $resource,
        ];
    }

    /**
     * The approving user's access choice as [level, identity]. Absent means
     * full (still narrowed to read-only by an `mcp:read` request). Binding
     * to a scoped identity is a site-owner decision, since identities are
     * managed by site owners.
     *
     * @return array{0: string, 1: string}|\WP_Error
     */
    private static function access_choice(array $params, string $client_id): array|\WP_Error
    {
        $raw = $params['access'] ?? null;
        if (null === $raw || '' === $raw) {
            return [Client_Access::FULL, ''];
        }

        $parsed = is_string($raw) ? Client_Access::parse($raw) : null;
        if (null === $parsed) {
            return self::deny('invalid_request', 'access must be full, read, or identity:<name>.', $client_id);
        }

        if (Client_Access::IDENTITY === $parsed[0]) {
            if (! current_user_can('manage_options')) {
                return self::deny('access_denied', 'Only a site owner can bind a client to a scoped identity.', $client_id);
            }
            if (null === Identity_Store::get($parsed[1])) {
                return self::deny('invalid_request', 'That scoped identity does not exist.', $client_id);
            }
        }

        return $parsed;
    }

    private static function deny(string $error_code, string $message, string $client_id): \WP_Error
    {
        self::audit(false, $client_id);

        return new \WP_Error($error_code, $message);
    }

    private static function audit(bool $allowed, string $client_id): void
    {
        try {
            Governance_Audit_Log::record('oauth/authorize', 'client:' . $client_id, $allowed);
        } catch (\Throwable $e) {
            // Auditing must never break the authorization outcome it is observing.
        }
    }
}
