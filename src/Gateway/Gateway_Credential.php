<?php

namespace WPMCP\Gateway;

use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Auth\Token_Store;

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
 * work with the cloud unreachable. Cloud upload and consent are phase 3.
 *
 * WHY NOT src/Cloud. It reads like cloud code, but the build strips say
 * otherwise: scripts/build-woo-release.sh deletes src/Cloud wholesale from
 * the WooCommerce zip, and the 'gateway' ability group ships on every
 * flavor (Plugin::FLAVOR_GROUPS) precisely so a credential can always be
 * revoked locally. Keeping this class in src/Cloud would make all three
 * gateway tools a class-not-found fatal on that build.
 *
 * WHAT A CREDENTIAL IS. The proxy redeems it at the token endpoint, and
 * Token_Grant::exchange() authenticates EVERY grant type (refresh_token
 * included) with client_id + client_secret, because Client_Store has no
 * public-client mode. So the credential is the triple
 * {client_id, client_secret, refresh_token}, not the pair: handing back
 * only the refresh token would ship something that can never be redeemed.
 * All three plaintext values appear exactly once, in issue_for_user()'s
 * return value, and none of them is recoverable afterwards (Client_Store
 * keeps a secret hash, Refresh_Token_Store a token hash).
 *
 * Idempotency invariants:
 *  - ensure_client() never grows the clients store on repeat calls: it
 *    resolves the existing gateway client (by stored id, then by
 *    non-creating fingerprint lookup) before it will ever create one.
 *  - issue_for_user() rotates the WHOLE credential: the client secret is
 *    re-minted and every access and refresh token bound to the client is
 *    evicted before the new refresh token is issued, so a previously
 *    issued credential is dead immediately rather than surviving until its
 *    access tokens lapse.
 *  - deprovision() is safe to call repeatedly and converges to fully dead
 *    (no client row, no bound tokens) even from a half-revoked state, and
 *    even when more than one matching client row exists.
 *
 * BINDING. The refresh token is stamped with the user's password
 * fingerprint (Refresh_Token_Store, via Token_Store::pass_fingerprint), so
 * the credential dies on a password change or account deletion without any
 * network round trip.
 *
 * SCOPE. The 'gateway' scope string is recorded on the token and carried
 * onto the access tokens minted from it, but nothing in the request path
 * enforces it today: Bearer_Auth performs no ability or domain scope
 * check, so a gateway access token authorises whatever its bound user can
 * do. Scope enforcement is tracked separately; do not read SCOPE as a
 * restriction.
 */
class Gateway_Credential
{
    /** Option holding the provisioned gateway client_id. */
    public const OPTION = 'wpmcp_gateway_client_id';

    /** Registration identity of the gateway client; used for non-creating lookups. */
    public const CLIENT_NAME  = 'WPMCP Gateway';
    public const REDIRECT_URI = 'urn:wpmcp:gateway';

    /**
     * Registrar key folded into the gateway client's registration
     * fingerprint. A server-side constant on purpose: the public DCR
     * endpoint keys registrations by remote IP, so no anonymous caller can
     * produce a row with this fingerprint and have it adopted as the site's
     * gateway client.
     */
    public const REGISTRAR_KEY = 'wpmcp-gateway-local';

    /** Scope stamped on gateway refresh tokens and the access tokens they mint. */
    public const SCOPE = 'gateway';

    /**
     * The gateway client record, provisioning it if absent. Idempotent:
     * repeat calls always resolve to the same client and never grow the
     * clients store.
     *
     * @return array The stored client record (never the plaintext secret).
     *
     * @throws \RuntimeException When the clients store is full (Client_Store
     *         MAX_CLIENTS) or the just-created record cannot be read back.
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

        $existing = self::find_registered();
        if (null !== $existing) {
            update_option(self::OPTION, (string) $existing['client_id']);
            Client_Store::protect((string) $existing['client_id']);
            return $existing;
        }

        $created = Client_Store::create([self::CLIENT_NAME], [self::REDIRECT_URI], self::REGISTRAR_KEY);
        update_option(self::OPTION, $created['client_id']);
        Client_Store::protect((string) $created['client_id']);

        $record = Client_Store::get($created['client_id']);
        if (null === $record) {
            // Unreachable in practice. Failing loudly beats the alternative
            // of handing back create()'s {client_id, client_secret} payload,
            // which is a different shape AND would leak the plaintext secret
            // through a method documented never to return one.
            throw new \RuntimeException('Gateway client could not be read back after creation.');
        }

        return $record;
    }

    /**
     * Provision (or rotate) the gateway credential for a user.
     *
     * All three plaintext values appear in this return value exactly once
     * and nowhere else; callers surface them to the user and must not store
     * them.
     *
     * @return array{client_id: string, client_secret: string, refresh_token: string}
     *
     * @throws \RuntimeException When the clients store is full.
     */
    public static function issue_for_user(int $user_id): array
    {
        $client    = self::ensure_client();
        $client_id = (string) $client['client_id'];

        // Converge on ONE gateway client before rotating. create()'s dedup
        // only recycles a row that holds no tokens, so a store can hold
        // more than one row carrying the gateway fingerprint (deprovision()
        // sweeps all of them for the same reason). Rotating only the row
        // ensure_client() happened to resolve would leave the twin alive
        // with its old secret and its old tokens, which is exactly the
        // "a previously issued credential is dead immediately" property
        // this method promises.
        foreach (Client_Store::find_all_by_registration(self::CLIENT_NAME, [self::REDIRECT_URI], self::REGISTRAR_KEY) as $record) {
            $other = (string) $record['client_id'];
            if ($other === $client_id) {
                continue;
            }
            Client_Store::revoke($other);
        }

        // Rotate rather than accumulate: any previously issued gateway
        // credential is dead the moment a new one is provisioned. That
        // means all three parts -- the access tokens already minted from
        // the old refresh token (Token_Store), the old refresh token
        // itself, and the client secret the proxy authenticates with.
        Token_Store::revoke_for_client($client_id);
        Refresh_Token_Store::revoke_for_client($client_id);

        $client_secret = Client_Store::rotate_secret($client_id);
        if (null === $client_secret) {
            throw new \RuntimeException('Gateway client disappeared while provisioning.');
        }

        $refresh_token = Refresh_Token_Store::issue($client_id, $user_id, self::SCOPE);

        return [
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
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

        return self::find_registered();
    }

    /**
     * Kill the gateway credential locally: every matching client row plus
     * every access and refresh token bound to it. Local-only (no network
     * access needed), idempotent, safe when nothing is provisioned.
     *
     * Converges rather than doing one pass. Two states force that:
     *  - half-revoked (the client row is already gone but tokens bound to
     *    the recorded client_id linger); deleting the option first would
     *    destroy the only pointer that can still find those tokens, so the
     *    id is read and swept BEFORE the option goes;
     *  - duplicate rows for the same registration, which create()'s dedup
     *    permits once a row holds tokens; tearing down only the first match
     *    would leave a live gateway client behind.
     *
     * @return bool True when this call removed something, false when there
     *              was nothing left to remove.
     */
    public static function deprovision(): bool
    {
        $removed = false;

        // Sweep the recorded id first, while we still have it.
        $client_id = (string) get_option(self::OPTION, '');
        if ('' !== $client_id) {
            $removed = Client_Store::revoke($client_id) || $removed;
        }
        delete_option(self::OPTION);

        // Then every remaining row that carries the gateway registration
        // fingerprint. Bounded by the store size; each revoke removes the
        // row it matched, so this terminates.
        foreach (Client_Store::find_all_by_registration(self::CLIENT_NAME, [self::REDIRECT_URI], self::REGISTRAR_KEY) as $record) {
            $removed = Client_Store::revoke((string) $record['client_id']) || $removed;
        }

        return $removed;
    }

    /** Non-creating fingerprint lookup of the gateway client row. */
    private static function find_registered(): ?array
    {
        return Client_Store::find_by_registration(
            self::CLIENT_NAME,
            [self::REDIRECT_URI],
            self::REGISTRAR_KEY
        );
    }
}
