<?php

namespace WPMCP\Cloud;

use WPMCP\Auth\Bearer_Auth;
use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Auth\Token_Store;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Provisions and revokes the site's WP MCP Gateway credential (issue #130).
 *
 * The gateway credential is a self-issued OAuth refresh token, bound to a
 * named Identity and to an administrator, riding a stable "WP MCP Gateway"
 * confidential client that is registered idempotently through Client_Store
 * (whose registration fingerprint makes repeated provisioning reuse the same
 * client row instead of accumulating dead ones). The plaintext material
 * exists exactly once, at provision time: it is handed to the caller for a
 * once-only display and a single upload through Cloud_Client, then only
 * hashes remain on the site.
 *
 * THE KILL SWITCH IS TOTAL AND LOCAL. Revocation goes through
 * Refresh_Token_Store::revoke_chain() on the chain_id recorded at provision
 * time, which cascades into Token_Store::revoke_chain(), so the access
 * tokens the gateway already minted die in the same call. That cascade is
 * the whole point: sweeping only the refresh tokens would leave a working
 * bearer token in the gateway's hands for the rest of its hour, which is
 * not a kill switch. Nothing here touches the network, so the switch works
 * with the cloud unreachable; cloud-side deletion is best-effort cleanup,
 * not the mechanism of revocation.
 *
 * Killing the chain first is also what keeps provisioning idempotent:
 * Client_Store::find_reusable() refuses to recycle a client row that still
 * holds tokens, so a re-provision that left live tokens behind would mint a
 * fresh client row every time and walk the store toward MAX_CLIENTS.
 *
 * THE IDENTITY BINDING IS SERVER-SIDE STATE, NOT A SCOPE CLAIM. The scope
 * string carries the identity name for the gateway's own information, but
 * nothing trusts it: Authorization_Grant stores whatever scope a client
 * asks for, so a self-registered DCR client could ask for
 * "wpmcp:gateway identity:<anything>" and a scope-reading enforcement layer
 * would hand it that identity. filter_current_identity() instead looks the
 * request's authenticated client_id up against the client_id stored in this
 * site's own credential record, and only then resolves the identity. A
 * forged scope on any other client resolves to nothing.
 *
 * Once the identity is active, Governance::is_within_identity_scope()
 * narrows every ability to that identity's allowlist inside
 * Registrar::is_permitted(), and the decision lands in
 * Governance_Audit_Log with the identity name recorded, so the gateway
 * token does not inherit the connecting admin's full enabled-tool grid.
 */
class Gateway_Credential
{
    /** Option holding the provisioned credential's bookkeeping (no secrets). */
    public const OPTION = 'wpmcp_gateway_credential';

    /** Stable client name; part of the Client_Store dedup fingerprint. */
    public const CLIENT_NAME = 'WP MCP Gateway';

    /** Registrar key namespacing the fingerprint away from DCR clients. */
    public const REGISTRAR_KEY = 'wpmcp-gateway';

    /** Scope prefix carrying the bound identity name (informational, never trusted). */
    public const SCOPE_PREFIX = 'wpmcp:gateway identity:';

    /**
     * Gateway refresh token lifetime: ten years, filterable via
     * wpmcp_gateway_refresh_ttl.
     *
     * This is a machine credential for an always-on proxy, not a user
     * session, and a gateway that goes quiet for a month must not come back
     * to a dead credential. Refresh_Token_Store's ordinary 30-day ceiling is
     * right for sessions and wrong here, and the site-wide
     * wpmcp_oauth_refresh_ttl filter is the wrong instrument because it
     * would lengthen every user session too, so the lifetime rides on the
     * record itself.
     */
    public const TTL_SECONDS = 315360000; // 10 years.

    /** Wire the server-side identity resolution onto the gateway's bearer tokens. */
    public static function register(): void
    {
        add_filter('wpmcp_current_identity', [self::class, 'filter_current_identity']);
    }

    /**
     * Provision (or re-provision) the gateway credential, bound to $user_id
     * and to a named Identity.
     *
     * Order matters and is deliberate: everything that can refuse refuses
     * BEFORE anything is minted or destroyed, then the previous chain is
     * killed (which is what frees the client row for reuse), then the new
     * client and token are created. Client_Store::create() throws when the
     * registration cap is reached; that becomes a WP_Error rather than a
     * fatal, and the now-meaningless bookkeeping row is cleared so
     * is_provisioned() cannot keep reporting a credential that is gone.
     *
     * @param int    $user_id   The administrator the credential acts as.
     * @param string $identity  Name of an existing Identity_Store identity.
     * @param bool   $consented The disclosed cloud-connect consent checkbox,
     *                          default off at every caller.
     * @return array{client_id: string, client_secret: string, refresh_token: string, scope: string, chain_id: string}|\WP_Error
     *               Plaintext material, returned exactly once. The caller
     *               owns the once-only display and the upload().
     */
    public static function provision(int $user_id, string $identity, bool $consented = false)
    {
        $identity = trim($identity);

        if (! $consented) {
            return new \WP_Error(
                'gateway_consent_required',
                'Provisioning a gateway credential requires explicit consent: it mints a long-lived credential that lets WP MCP Cloud act on this site.'
            );
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('gateway_forbidden', 'Only an administrator can provision a gateway credential.');
        }

        if ($user_id <= 0 || false === get_userdata($user_id)) {
            return new \WP_Error('gateway_unknown_user', 'The user the gateway credential would be bound to does not exist.');
        }

        if (! user_can($user_id, 'manage_options')) {
            return new \WP_Error('gateway_user_not_admin', 'A gateway credential can only be bound to an administrator.');
        }

        if ('' === $identity || null === Identity_Store::get($identity)) {
            self::audit('provision', $identity, false, 'unknown_identity');
            return new \WP_Error(
                'gateway_unknown_identity',
                'The gateway credential must be bound to an existing identity; create it first with identity-create.'
            );
        }

        self::revoke_locally();

        try {
            $client = Client_Store::create([ self::CLIENT_NAME ], [], self::REGISTRAR_KEY);
        } catch (\RuntimeException $e) {
            delete_option(self::OPTION);
            self::audit('provision', $identity, false, 'client_cap_reached');
            return new \WP_Error('gateway_client_cap', $e->getMessage());
        }

        $chain_id      = Refresh_Token_Store::new_chain_id();
        $scope         = self::scope_for($identity);
        $refresh_token = Refresh_Token_Store::issue(
            $client['client_id'],
            $user_id,
            $scope,
            $chain_id,
            self::ttl()
        );

        // Provisioning reuses the client row by design, so client_id alone
        // cannot tell two provisions apart. The chain_id can, and it is not
        // a secret (it names a grant, it does not authenticate one), so it
        // travels back with the payload purely as a version stamp for
        // upload()'s staleness check.
        update_option(self::OPTION, [
            'client_id'      => $client['client_id'],
            'user_id'        => $user_id,
            'identity'       => $identity,
            'chain_id'       => $chain_id,
            'provisioned_at' => time(),
            'uploaded_at'    => 0,
        ]);

        self::audit('provision', $identity, true);

        return [
            'client_id'     => $client['client_id'],
            'client_secret' => $client['client_secret'],
            'refresh_token' => $refresh_token,
            'scope'         => $scope,
            'chain_id'      => $chain_id,
        ];
    }

    /**
     * Upload the freshly provisioned credential to the cloud, once.
     *
     * Refuses over plaintext HTTP: this payload is a long-lived,
     * admin-bound site credential, which is a different class of secret
     * from the /me and /assets traffic, and Cloud_Config::set() does not
     * restrict the scheme. Also refuses a credential that is not the one
     * currently on record, or one that has already been uploaded, so a
     * stale array cannot stamp a newer provision's bookkeeping.
     *
     * @param array $credential The provision() return value.
     * @return true|\WP_Error
     */
    public static function upload(Cloud_Client $client, array $credential)
    {
        $record = self::record();
        if (null === $record) {
            return new \WP_Error('gateway_not_provisioned', 'There is no gateway credential to upload.');
        }

        $same_client = (string) ($credential['client_id'] ?? '') === (string) $record['client_id'];
        $same_chain  = (string) ($credential['chain_id'] ?? '') === (string) ($record['chain_id'] ?? '');
        if (! $same_client || ! $same_chain) {
            return new \WP_Error('gateway_stale_credential', 'That credential is not the one currently provisioned for this site.');
        }

        if ((int) ($record['uploaded_at'] ?? 0) > 0) {
            return new \WP_Error('gateway_already_uploaded', 'This gateway credential has already been uploaded; re-provision to issue a new one.');
        }

        if (! self::cloud_url_is_secure()) {
            return new \WP_Error(
                'gateway_insecure_cloud_url',
                'Refusing to send a gateway credential to a non-https cloud url.'
            );
        }

        // TODO(#130): finalize the /wpmcp-cloud/v1 gateway contract (POST
        // /gateway/credential) with the backend; for now this is the only
        // seam that knows the wire shape, mirroring Cloud_Client's rule.
        $result = $client->post('/gateway/credential', [
            'client_id'     => $credential['client_id'],
            'client_secret' => $credential['client_secret'],
            'refresh_token' => $credential['refresh_token'],
            'scope'         => $credential['scope'],
            'site_url'      => home_url('/'),
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        $record['uploaded_at'] = time();
        update_option(self::OPTION, $record);

        return true;
    }

    /**
     * Locally-first revoke: the credential is dead the moment this returns,
     * regardless of cloud reachability. Cloud-side cleanup is the caller's
     * best-effort follow-up (TODO(#130): DELETE /gateway/credential).
     */
    public static function revoke(): void
    {
        $record = self::record();
        self::revoke_locally();
        delete_option(self::OPTION);

        if (null !== $record) {
            self::audit('revoke', (string) ($record['identity'] ?? ''), true);
        }
    }

    /**
     * Bookkeeping record (never contains secrets), or null.
     *
     * Self-healing: Oauth_Gc's orphan sweep (Client_Store::gc) reaps a
     * client row that holds no tokens past the grace window, so the option
     * can outlive the client it names. Reporting a credential that cannot
     * authenticate anything is worse than reporting none, so a record whose
     * client is gone is cleared here rather than surfaced.
     *
     * @return array|null
     */
    public static function record(): ?array
    {
        $record = get_option(self::OPTION, null);
        if (! is_array($record) || empty($record['client_id'])) {
            return null;
        }

        if (null === Client_Store::get((string) $record['client_id'])) {
            delete_option(self::OPTION);
            return null;
        }

        return $record;
    }

    public static function is_provisioned(): bool
    {
        return null !== self::record();
    }

    /**
     * Resolve the identity for the current request, for the
     * wpmcp_current_identity filter Identity_Context reads.
     *
     * Anchored on the authenticated client_id, never on the token's scope
     * string: see the class docblock. Returns $identity unchanged (so other
     * listeners keep working) whenever this request is not the gateway's.
     *
     * @param string|null $identity Whatever a higher-priority listener resolved.
     * @return string|null
     */
    public static function filter_current_identity($identity)
    {
        $client_id = Bearer_Auth::current_client_id();
        if ('' === $client_id) {
            return $identity;
        }

        $record = self::record();
        if (null === $record || (string) $record['client_id'] !== $client_id) {
            return $identity;
        }

        $name = (string) ($record['identity'] ?? '');

        return '' !== $name ? $name : $identity;
    }

    /** The scope string for a bound identity. The name round-trips exactly. */
    public static function scope_for(string $identity): string
    {
        return self::SCOPE_PREFIX . rawurlencode($identity);
    }

    /**
     * The identity name a gateway scope carries, or null when the scope is
     * not a gateway scope. Informational only: never a grant (see the class
     * docblock), which is why nothing in the enforcement path calls it.
     */
    public static function identity_from_scope(string $scope): ?string
    {
        if (! str_starts_with($scope, self::SCOPE_PREFIX)) {
            return null;
        }

        $name = rawurldecode(substr($scope, strlen(self::SCOPE_PREFIX)));

        return '' !== $name ? $name : null;
    }

    /** Gateway refresh token lifetime in seconds, filterable. Floored at one day. */
    public static function ttl(): int
    {
        return max(DAY_IN_SECONDS, (int) apply_filters('wpmcp_gateway_refresh_ttl', self::TTL_SECONDS));
    }

    /**
     * Kill the recorded chain (refresh tokens AND the access tokens issued
     * along it), then sweep anything else still bound to the client, which
     * covers records provisioned before chain_id was tracked.
     */
    private static function revoke_locally(): void
    {
        $record = get_option(self::OPTION, null);
        if (! is_array($record) || empty($record['client_id'])) {
            return;
        }

        $chain_id = (string) ($record['chain_id'] ?? '');
        if ('' !== $chain_id) {
            Refresh_Token_Store::revoke_chain($chain_id);
        }

        $client_id = (string) $record['client_id'];
        Refresh_Token_Store::revoke_for_client($client_id);
        self::revoke_access_tokens_for_client($client_id);
    }

    /**
     * Drop every access token bound to a client. Token_Store revokes by
     * chain, which is the right granularity for reuse detection but not for
     * a per-client kill switch: an access token minted before chain_id was
     * recorded, or along a chain the option no longer names, would survive.
     */
    private static function revoke_access_tokens_for_client(string $client_id): void
    {
        $stored  = get_option(Token_Store::OPTION, []);
        $stored  = is_array($stored) ? $stored : [];
        $removed = 0;

        foreach ($stored as $key => $record) {
            if ((string) ($record['client_id'] ?? '') === $client_id) {
                unset($stored[ $key ]);
                $removed++;
            }
        }

        if ($removed > 0) {
            update_option(Token_Store::OPTION, $stored);
        }
    }

    private static function cloud_url_is_secure(): bool
    {
        return 'https' === strtolower((string) wp_parse_url(Cloud_Config::base_url(), PHP_URL_SCHEME));
    }

    /**
     * Record the provision/revoke decision. Minting and killing a
     * long-lived admin-bound credential is at least as audit-worthy as the
     * oauth/token and oauth/validate events already logged. Identity and
     * outcome only: no plaintext ever reaches the log.
     */
    private static function audit(string $action, string $identity, bool $allowed, string $reason = ''): void
    {
        try {
            Governance_Audit_Log::record(
                'cloud/gateway-credential-' . $action,
                '' !== $identity ? $identity : 'none',
                $allowed,
                $reason
            );
        } catch (\Throwable $e) {
            // Auditing must never break the outcome it is observing.
        }
    }
}
