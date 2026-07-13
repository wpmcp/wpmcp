<?php

namespace WPMCP\Auth;

use WPMCP\Governance\Governance_Audit_Log;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The programmatic core behind the OAuth 2.1 authorization endpoint (RFC
 * 6749 4.1.1): binds the currently logged-in WP user's consent to a
 * single-use authorization code, after validating everything the
 * authorization step is responsible for.
 *
 * SCOPE: this class does not render or drive an interactive consent-screen
 * UI (a page asking a user to click "Allow"/"Deny" for a named client and
 * scope). What is implemented is the binding step: given a validated
 * request and a WP user already logged in via ordinary WP auth (cookie
 * auth), issue the code that represents that user's authorization. Wiring
 * this to an actual consent page (and a way to explicitly deny) is left to
 * the REST layer/future work; see the issue #43 report for the precise
 * boundary.
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
     *                       code_challenge, code_challenge_method, scope.
     * @return array{code: string}|\WP_Error
     */
    public static function authorize(array $params): array|\WP_Error
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

        $code = Code_Store::issue([
            'client_id'             => $client_id,
            'user_id'               => $user_id,
            'redirect_uri'          => $redirect_uri,
            'code_challenge'        => $code_challenge,
            'code_challenge_method' => $code_challenge_method,
            'scope'                 => (string) ($params['scope'] ?? ''),
        ]);

        self::audit(true, $client_id);

        return ['code' => $code];
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
