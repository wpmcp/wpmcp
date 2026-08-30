<?php

namespace WPMCP\Cloud;

use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Refresh_Token_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Provisions and revokes the site's WP MCP Gateway credential (issue #130).
 *
 * The gateway credential is a self-issued OAuth refresh token, bound to the
 * connecting admin and to a stable "WP MCP Gateway" confidential client that
 * is registered idempotently through Client_Store (whose registration
 * fingerprint makes repeated provisioning reuse the same client row instead
 * of accumulating dead ones). The plaintext token exists exactly once, at
 * provision time: it is handed to the caller for a once-only display and a
 * single upload through Cloud_Client, then only its hash remains on the site.
 *
 * Revocation is locally-first by design: revoking kills the refresh token
 * chain and the client's tokens in the local stores before any network call,
 * so the kill switch works with the cloud unreachable. The cloud-side
 * deletion is best-effort cleanup, not the mechanism of revocation.
 *
 * Differentiator vs the design we studied: the credential's scope embeds a
 * named Identity, so every gateway call resolves through Identity_Context to
 * a per-identity ability allowlist enforced by Registrar::is_permitted and
 * recorded in Governance_Audit_Log. The gateway token never inherits the
 * full enabled-tool grid.
 */
class Gateway_Credential
{
    /** Option holding the provisioned credential's bookkeeping (no secrets). */
    public const OPTION = 'wpmcp_gateway_credential';

    /** Stable client name; part of the Client_Store dedup fingerprint. */
    public const CLIENT_NAME = 'WP MCP Gateway';

    /** Registrar key namespacing the fingerprint away from DCR clients. */
    public const REGISTRAR_KEY = 'wpmcp-gateway';

    /** Scope prefix carrying the bound identity name. */
    public const SCOPE_PREFIX = 'wpmcp:gateway identity:';

    /**
     * Provision (or re-provision) the gateway credential for the connecting
     * admin, bound to a named Identity.
     *
     * Idempotent at the client level: Client_Store::create dedupes on the
     * fingerprint (name + registrar key), so repeat calls reuse the same
     * client_id and rotate its secret. A fresh refresh token chain is
     * started on every call; the previous chain (if any) is revoked first
     * so at most one live gateway credential exists per site.
     *
     * @param int    $user_id  The connecting admin, recorded on the token.
     * @param string $identity Named Identity the credential is scoped to.
     * @return array{client_id: string, client_secret: string, refresh_token: string, scope: string}
     *               Plaintext material, returned exactly once. The caller
     *               owns the once-only display and the Cloud_Client upload.
     */
    public static function provision(int $user_id, string $identity): array
    {
        // TODO(#130): reject provisioning unless the cloud-connect consent
        // checkbox (default off) was checked for this request.

        self::revoke_locally();

        $client = Client_Store::create(
            [ self::CLIENT_NAME ],
            [],
            self::REGISTRAR_KEY
        );

        $scope         = self::SCOPE_PREFIX . sanitize_key($identity);
        $refresh_token = Refresh_Token_Store::issue($client['client_id'], $user_id, $scope);

        update_option(self::OPTION, [
            'client_id'      => $client['client_id'],
            'user_id'        => $user_id,
            'identity'       => sanitize_key($identity),
            'provisioned_at' => time(),
            'uploaded_at'    => 0,
        ]);

        return [
            'client_id'     => $client['client_id'],
            'client_secret' => $client['client_secret'],
            'refresh_token' => $refresh_token,
            'scope'         => $scope,
        ];
    }

    /**
     * Upload the freshly provisioned credential to the cloud, once.
     *
     * @param array $credential The provision() return value.
     * @return true|\WP_Error
     */
    public static function upload(Cloud_Client $client, array $credential)
    {
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

        $record = self::record();
        if (null !== $record) {
            $record['uploaded_at'] = time();
            update_option(self::OPTION, $record);
        }

        return true;
    }

    /**
     * Locally-first revoke: the credential is dead the moment this returns,
     * regardless of cloud reachability. Cloud-side cleanup is the caller's
     * best-effort follow-up (TODO(#130): DELETE /gateway/credential).
     */
    public static function revoke(): void
    {
        self::revoke_locally();
        delete_option(self::OPTION);
    }

    /** @return array|null Bookkeeping record (never contains secrets). */
    public static function record(): ?array
    {
        $record = get_option(self::OPTION, null);
        return is_array($record) ? $record : null;
    }

    public static function is_provisioned(): bool
    {
        return null !== self::record();
    }

    private static function revoke_locally(): void
    {
        $record = self::record();
        if (null === $record || empty($record['client_id'])) {
            return;
        }
        Refresh_Token_Store::revoke_for_client((string) $record['client_id']);
    }
}
