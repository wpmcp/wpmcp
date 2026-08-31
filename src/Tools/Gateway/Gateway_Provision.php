<?php

namespace WPMCP\Tools\Gateway;

use WPMCP\Auth\Client_Cap_Reached;
use WPMCP\Auth\OAuth_Config;
use WPMCP\Gateway\Gateway_Credential;

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
 * succeeds. The gate throws \InvalidArgumentException rather than
 * returning WP_Error, matching every other confirm-gated tool in the repo
 * (Delete_Post, Delete_Plugin, Delete_File, ...) so the refusal produces
 * one Request_Log outcome shape, not two.
 *
 * REFUSES WHEN OAUTH IS OFF. OAuth_Config::is_enabled() is the master
 * switch for the whole subsystem and defaults to OFF, and every OAuth
 * endpoint has to consult it: with it off Endpoints::register() registers
 * no /oauth/token route and Bearer_Auth::resolve() returns early. Minting
 * there would hand an operator a credential triple that is not merely
 * unused but structurally unredeemable, and gateway-status would then
 * report provisioned: true about it.
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
            throw new \InvalidArgumentException(
                'gateway-provision mints a credential shown exactly once and invalidates any previous one. Pass confirm:true to proceed.'
            );
        }

        if (! OAuth_Config::is_enabled()) {
            return new \WP_Error(
                'oauth_disabled',
                'The OAuth subsystem is off, so a gateway credential could never be redeemed: there is no token endpoint and bearer tokens are not accepted. Enable it (define WPMCP_OAUTH_ENABLED or filter wpmcp_oauth_enabled) before provisioning.'
            );
        }

        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return new \WP_Error('no_user', 'gateway-provision requires an authenticated user to bind the credential to.');
        }

        try {
            $credential = Gateway_Credential::issue_for_user($user_id);
        } catch (Client_Cap_Reached $e) {
            // An ordinary operational condition, not a crash; mirror
            // Client_Registration::register()'s handling rather than
            // letting the exception escape as a fatal.
            return new \WP_Error('client_cap_reached', 'The OAuth client store is full; cannot provision a gateway client.');
        } catch (\RuntimeException $e) {
            // Everything else that Gateway_Credential can throw ("could
            // not be read back after creation", "disappeared while
            // provisioning") is a broken store invariant, not a full
            // store. Reporting it as client_cap_reached would send an
            // operator off to free OAuth client slots for an unrelated
            // bug, so it gets its own code.
            return new \WP_Error('gateway_provision_failed', 'The gateway client could not be provisioned: ' . $e->getMessage());
        }

        return [
            'provisioned'   => true,
            'client_id'     => $credential['client_id'],
            'client_secret' => $credential['client_secret'],
            'refresh_token' => $credential['refresh_token'],
            'scope'         => Gateway_Credential::SCOPE,
            // Said here, not only in the PR that added it: the scope string
            // is recorded, not enforced. Nothing in the request path reads
            // it (Bearer_Auth performs no ability or domain scope check),
            // so this credential is as powerful as the user who minted it.
            'scope_enforced' => false,
            'note'          => 'Store client_secret and refresh_token now: both are shown exactly once and cannot be recovered. The proxy redeems them at the token endpoint with grant_type=refresh_token. The scope value is recorded but NOT enforced yet, so this credential carries the full capabilities of the user who provisioned it; treat it as an admin credential.',
        ];
    }
}
