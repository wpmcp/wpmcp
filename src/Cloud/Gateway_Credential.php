<?php

namespace WPMCP\Cloud;

use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Refresh_Token_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Site-local gateway credential lifecycle (issue #142, phase 1 of #130).
 *
 * Mints, reports, and kills the credential the self-hosted proxy (#77) uses
 * for revocable token-based multi-site routing: one stable gateway OAuth
 * client in Client_Store plus a rotate-on-use refresh token scoped
 * "gateway". Everything here is locally-first by design; no method in this
 * class performs network I/O, so provisioning and (crucially) revocation
 * work with the cloud unreachable. Identity binding is phase 2; cloud
 * upload and consent are phase 3.
 *
 * Idempotency invariants:
 *  - ensure_client() never grows the clients store on repeat calls: it
 *    resolves the existing gateway client (by stored id, then by
 *    non-creating registration lookup) before it will ever create one.
 *  - issue_for_user() rotates: any prior gateway refresh tokens for the
 *    client are revoked before the new one is minted, so re-provisioning
 *    replaces the credential rather than accumulating live ones.
 *  - deprovision() is safe to call repeatedly and converges to fully dead
 *    (no client row, no bound tokens) even from a half-revoked state.
 *
 * The refresh token plaintext is returned exactly once, from
 * issue_for_user(), and is never persisted (Refresh_Token_Store hashes at
 * rest).
 */
class Gateway_Credential
{
    /** Option holding the provisioned gateway client_id. */
    public const OPTION = 'wpmcp_gateway_client_id';

    /** Registration identity of the gateway client; used for non-creating lookups. */
    public const CLIENT_NAME  = 'WPMCP Gateway';
    public const REDIRECT_URI = 'urn:wpmcp:gateway';

    /** Scope stamped on gateway refresh tokens and the access tokens they mint. */
    public const SCOPE = 'gateway';

    /**
     * The gateway client record, provisioning it if absent. Idempotent:
     * repeat calls always resolve to the same client and never grow the
     * clients store.
     *
     * @return array The stored client record (never the plaintext secret).
     */
    public static function ensure_client(): array
    {
        $client_id = (string) get_option(self::OPTION, '');
        if ('' !== $client_id) {
            $record = Client_Store::get($client_id);
            if (null !== $record) {
                return $record;
            }
            // Stale pointer (client revoked out from under the option).
            delete_option(self::OPTION);
        }

        $existing = Client_Store::find_by_registration(self::CLIENT_NAME, [self::REDIRECT_URI]);
        if (null !== $existing) {
            update_option(self::OPTION, (string) $existing['client_id']);
            return $existing;
        }

        $created = Client_Store::create([self::CLIENT_NAME], [self::REDIRECT_URI], 'wpmcp-gateway-local');
        update_option(self::OPTION, $created['client_id']);

        $record = Client_Store::get($created['client_id']);
        return null !== $record ? $record : $created;
    }

    /**
     * Provision (or rotate) the gateway credential for a user.
     *
     * The refresh token plaintext appears in this return value exactly once
     * and nowhere else; callers surface it to the user and must not store
     * it.
     *
     * @todo (#142) Bind the token to the user's password fingerprint so the
     *       credential dies on password change or account deletion, and
     *       honor wpmcp_gateway_refresh_ttl; both need Refresh_Token_Store
     *       to grow fingerprint + per-issue TTL support.
     *
     * @return array{client_id: string, refresh_token: string}
     */
    public static function issue_for_user(int $user_id): array
    {
        $client    = self::ensure_client();
        $client_id = (string) $client['client_id'];

        // Rotate rather than accumulate: any previously issued gateway
        // credential is dead the moment a new one is provisioned.
        Refresh_Token_Store::revoke_for_client($client_id);

        $refresh_token = Refresh_Token_Store::issue($client_id, $user_id, self::SCOPE);

        return [
            'client_id'     => $client_id,
            'refresh_token' => $refresh_token,
        ];
    }

    /** Whether a gateway client is currently provisioned. */
    public static function is_provisioned(): bool
    {
        return null !== self::current_client();
    }

    /** The provisioned gateway client record, or null. Never token material. */
    public static function current_client(): ?array
    {
        $client_id = (string) get_option(self::OPTION, '');
        if ('' !== $client_id) {
            $record = Client_Store::get($client_id);
            if (null !== $record) {
                return $record;
            }
        }

        return Client_Store::find_by_registration(self::CLIENT_NAME, [self::REDIRECT_URI]);
    }

    /**
     * Kill the gateway credential locally: client row plus every access and
     * refresh token bound to it. Local-only (no network access needed),
     * idempotent, safe when nothing is provisioned.
     *
     * @return bool True when a provisioned credential was removed, false
     *              when there was nothing to remove.
     */
    public static function deprovision(): bool
    {
        $record = self::current_client();
        delete_option(self::OPTION);

        if (null === $record) {
            return false;
        }

        return Client_Store::revoke((string) $record['client_id']);
    }
}
