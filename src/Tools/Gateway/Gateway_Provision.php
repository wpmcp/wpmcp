<?php

namespace WPMCP\Tools\Gateway;

use WPMCP\Cloud\Gateway_Credential;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Provision (or rotate) the site-local gateway credential (issue #142).
 *
 * Requires confirm: true because the response contains plaintext shown
 * exactly once and never recoverable, and because provisioning rotates:
 * any previously issued gateway credential (client secret, refresh token,
 * and every access token minted from it) stops working the moment this
 * succeeds.
 *
 * NOT WRAPPED IN Safe_Mutation, deliberately. The repo's snapshot-before-
 * every-write rule exists so a site owner can undo a destructive content
 * change; it does not fit credential material. A snapshot of
 * wpmcp_oauth_clients holds only a secret HASH, so restoring it cannot
 * restore a usable credential -- the plaintext half lives client-side and
 * was shown once. Worse, an undo of a revoke would resurrect the token
 * rows a site owner just killed, which is the opposite of what revoking a
 * leaked credential is for. Re-provisioning is the recovery path here, and
 * it costs one tool call.
 */
class Gateway_Provision
{
    public function handle(array $args)
    {
        if (true !== ($args['confirm'] ?? false)) {
            return new \WP_Error(
                'confirmation_required',
                'gateway-provision mints a credential shown exactly once and invalidates any previous one. Pass confirm: true to proceed.'
            );
        }

        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return new \WP_Error('no_user', 'gateway-provision requires an authenticated user to bind the credential to.');
        }

        try {
            $credential = Gateway_Credential::issue_for_user($user_id);
        } catch (\RuntimeException $e) {
            // A full clients store is an ordinary operational condition, not
            // a crash; mirror Client_Registration::register()'s handling
            // rather than letting the exception escape as a fatal.
            return new \WP_Error('client_cap_reached', 'The OAuth client store is full; cannot provision a gateway client.');
        }

        return [
            'provisioned'   => true,
            'client_id'     => $credential['client_id'],
            'client_secret' => $credential['client_secret'],
            'refresh_token' => $credential['refresh_token'],
            'scope'         => Gateway_Credential::SCOPE,
            'note'          => 'Store client_secret and refresh_token now: both are shown exactly once and cannot be recovered. The proxy redeems them at the token endpoint with grant_type=refresh_token.',
        ];
    }
}
