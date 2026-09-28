<?php

namespace WPMCP\Cloud;

use WPMCP\Auth\OAuth_Config;
use WPMCP\Gateway\Gateway_Binding;
use WPMCP\Gateway\Gateway_Credential;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The cloud-facing layer over the site's one gateway credential
 * (issue #130). It adds nothing to the credential itself, which is #142's
 * Gateway_Credential with #142's token rules; it adds the two things the
 * hosted gateway needs on top:
 *
 *  - an identity binding: the credential is minted bound to an existing
 *    scoped Identity (Gateway_Binding), so the hosted gateway acts inside
 *    that identity's allowlist rather than with the admin's full grid;
 *  - a one-time upload through Cloud_Client, gated by the cloud-connect
 *    consent (Gateway_Consent, default off).
 *
 * Revocation is not here on purpose: it is the free gateway-revoke ability
 * and Gateway_Guard::kill(), which work on every build and offline.
 */
class Gateway_Cloud
{
    /**
     * Why provisioning must not happen, or null when it may. Checked BEFORE
     * anything is minted or destroyed, and every refusal is audited.
     */
    public static function refusal(int $user_id, string $identity, bool $consented, bool $replace): ?\WP_Error
    {
        $identity = trim($identity);

        if (! $consented) {
            return self::refuse($identity, 'gateway_consent_required', 'Provisioning a gateway credential requires explicit consent (consent=true): it mints a credential that lets the WP MCP Gateway act on this site.');
        }
        if (! current_user_can('manage_options')) {
            return self::refuse($identity, 'gateway_forbidden', 'Only an administrator can provision a gateway credential.');
        }
        if (! OAuth_Config::is_enabled()) {
            return self::refuse($identity, 'gateway_oauth_disabled', 'The OAuth subsystem is off, so a gateway credential could never be redeemed. Enable it (WPMCP_OAUTH_ENABLED or the wpmcp_oauth_enabled filter) first.');
        }
        if ($user_id <= 0 || ! user_can($user_id, 'manage_options')) {
            return self::refuse($identity, 'gateway_user_not_admin', 'A gateway credential can only be bound to an administrator.');
        }
        if ('' === $identity || null === Identity_Store::get($identity)) {
            return self::refuse($identity, 'gateway_unknown_identity', 'The gateway credential must be bound to an existing identity; create it first with identity-create.');
        }
        if (! $replace && Gateway_Credential::is_provisioned()) {
            return self::refuse($identity, 'gateway_already_provisioned', 'This site already has a gateway credential. Re-provisioning kills it irreversibly (the proxy or gateway using it stops working until the new one is installed); pass replace=true to do it anyway.');
        }

        return null;
    }

    /**
     * Mint (rotating any previous credential) and bind. Call only after
     * refusal() returned null.
     *
     * @return array{client_id: string, client_secret: string, refresh_token: string, scope: string, identity: string, generation: string}
     *         Plaintext, exactly once.
     * @throws \RuntimeException From Gateway_Credential (a full client store included).
     */
    public static function mint(int $user_id, string $identity): array
    {
        $identity   = trim($identity);
        $credential = Gateway_Credential::issue_for_user($user_id);
        $binding    = Gateway_Binding::bind($identity, $user_id);

        self::audit('gateway/credential-provision', true, 'identity:' . $identity);

        return [
            'client_id'     => $credential['client_id'],
            'client_secret' => $credential['client_secret'],
            'refresh_token' => $credential['refresh_token'],
            'scope'         => Gateway_Credential::SCOPE,
            'identity'      => $identity,
            // Not a secret: it names this issuance, so upload() can refuse a
            // stale payload.
            'generation'    => $binding['generation'],
        ];
    }

    /**
     * Upload the freshly minted credential to the cloud, once.
     *
     * Refuses over plaintext HTTP, never follows a redirect (the Requests
     * library re-sends a POST body on a 30x, and an http Location would
     * replay the secret in cleartext), refuses a payload that is not the
     * live credential, a second upload, and a site whose owner has not
     * ticked the cloud-connect gateway consent.
     *
     * @param array $credential The mint() return value.
     * @return true|\WP_Error
     */
    public static function upload(Cloud_Client $client, array $credential)
    {
        $binding = Gateway_Binding::current();
        if (null === $binding) {
            return new \WP_Error('gateway_not_provisioned', 'There is no identity-bound gateway credential to upload.');
        }
        $same_generation = (string) ($credential['generation'] ?? '') === (string) $binding['generation'];
        $same_client     = (string) ($credential['client_id'] ?? '') === (string) $binding['client_id'];
        if (! $same_generation || ! $same_client) {
            return new \WP_Error('gateway_stale_credential', 'That credential is not the one currently provisioned for this site.');
        }
        if ((int) ($binding['uploaded_at'] ?? 0) > 0) {
            return new \WP_Error('gateway_already_uploaded', 'This gateway credential has already been uploaded; re-provision to issue a new one.');
        }
        if (! Gateway_Consent::granted()) {
            return new \WP_Error('gateway_cloud_consent_required', 'The site owner has not consented to the WP MCP Gateway. Re-run cloud-connect with gateway_consent=true to allow uploading this credential; until then it stays local to this site.');
        }
        if (! Cloud_Config::is_configured()) {
            return new \WP_Error('gateway_cloud_not_configured', 'This site is not connected to WP MCP Cloud; connect it with cloud-connect before uploading a gateway credential.');
        }
        if ('https' !== strtolower((string) wp_parse_url(Cloud_Config::base_url(), PHP_URL_SCHEME))) {
            return new \WP_Error('gateway_insecure_cloud_url', 'Refusing to send a gateway credential to a non-https cloud url.');
        }

        // TODO(#130): the /wpmcp-cloud/v1 gateway contract (POST
        // /gateway/credential) is backend scope; this is the one seam that
        // knows the wire shape.
        $result = $client->post(
            '/gateway/credential',
            [
                'client_id'     => $credential['client_id'],
                'client_secret' => $credential['client_secret'],
                'refresh_token' => $credential['refresh_token'],
                'scope'         => $credential['scope'],
                'identity'      => $credential['identity'],
                'site_url'      => home_url('/'),
            ],
            [
                'redirection' => 0,
                'sslverify'   => true,
            ]
        );
        if (is_wp_error($result)) {
            return $result;
        }

        Gateway_Binding::mark_uploaded();

        return true;
    }

    private static function refuse(string $identity, string $code, string $message): \WP_Error
    {
        self::audit('gateway/credential-provision', false, $code . ('' !== $identity ? ' identity:' . $identity : ''));

        return new \WP_Error($code, $message);
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
