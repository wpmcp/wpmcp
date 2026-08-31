<?php

namespace WPMCP\Auth;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * RFC 7591 Dynamic Client Registration record store: a single
 * wpmcp_oauth_clients option, a map of client_id => record.
 *
 * Security properties:
 *  - client_secret is generated with random_bytes() (full entropy, nothing
 *    user-supplied) and only its SHA-256 hash is ever persisted; the
 *    plaintext is returned once, at creation time, and never again. A leaked
 *    option row therefore cannot be replayed as a valid secret. SHA-256 (not
 *    a slow password hash like bcrypt) is the appropriate primitive here
 *    because the secret is already high-entropy random data being looked up
 *    by exact value, not a human-chosen password being brute-force-resisted.
 *  - total registered clients is capped at MAX_CLIENTS so a registration
 *    flood (even one that gets past Client_Registration's rate limit) cannot
 *    grow the store without bound.
 *
 * Issue #133 adds the two pieces of housekeeping that cap needs to stay
 * survivable in production: registration dedup (MCP clients re-run DCR on
 * every connect, so without it the cap is reached by dead rows from one
 * legitimate user reconnecting) and an orphan sweep (Client_Store::gc(),
 * driven by Oauth_Gc). Both are documented in detail at their call sites
 * below; the security-relevant part of dedup is that the fingerprint is
 * bound to the registering caller, because reuse rotates the secret.
 *
 * A record is { client_id, client_secret_hash, client_name, redirect_uris,
 * created_at, fingerprint }, plus reused_at once a registration has been
 * deduped onto it.
 */
class Client_Store
{
    public const OPTION = 'wpmcp_oauth_clients';

    /** Hard cap on total registered clients, filterable via wpmcp_oauth_max_clients. */
    public const MAX_CLIENTS = 100;

    /** The live registration cap. Public so a caller can refuse BEFORE it does something destructive. */
    public static function max_clients(): int
    {
        return (int) apply_filters('wpmcp_oauth_max_clients', self::MAX_CLIENTS);
    }

    private static function load(): array
    {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    private static function save(array $stored): void
    {
        update_option(self::OPTION, $stored);
    }

    public static function count(): int
    {
        return count(self::load());
    }

    /**
     * Register a new client. $client_name and $redirect_uris are arrays only
     * because callers currently pass single-item collections in tests; the
     * public registration entry point (Client_Registration) is responsible
     * for shaping/validating real request input before calling this.
     *
     * @param string[] $client_name  Human-readable name (first element used).
     * @param string[] $redirect_uris Absolute redirect URIs already validated
     *                                by the caller.
     * @return array{client_id: string, client_secret: string} The plaintext
     *               secret, returned exactly once.
     *
     * @throws \RuntimeException When the client cap (MAX_CLIENTS) is reached.
     */
    public static function create(array $client_name, array $redirect_uris, string $registrar_key = ''): array
    {
        $stored = self::load();
        $name   = (string) ($client_name[0] ?? '');
        $uris   = array_values(array_unique(array_map('strval', $redirect_uris)));

        $fingerprint = self::registration_fingerprint($name, $uris, $registrar_key);
        $existing    = self::find_reusable($stored, $fingerprint);

        if (null !== $existing) {
            $client_secret = self::generate_token('secret_');

            $stored[ $existing ]['client_secret_hash'] = self::hash($client_secret);
            $stored[ $existing ]['reused_at']          = time();
            self::save($stored);

            return [
                'client_id'     => $existing,
                'client_secret' => $client_secret,
            ];
        }

        if (count($stored) >= self::max_clients()) {
            throw new \RuntimeException('OAuth client registration cap reached.');
        }

        $client_id     = self::generate_token('client_');
        $client_secret = self::generate_token('secret_');

        $stored[ $client_id ] = [
            'client_id'          => $client_id,
            'client_secret_hash' => self::hash($client_secret),
            'client_name'        => $name,
            'redirect_uris'      => $uris,
            'created_at'         => time(),
            'fingerprint'        => $fingerprint,
        ];

        self::save($stored);

        return [
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
        ];
    }

    /**
     * The dedup key for a registration request (issue #133).
     *
     * MCP clients re-run Dynamic Client Registration on every connect, so
     * without dedup the client store fills with one dead row per connect
     * until MAX_CLIENTS is hit and registration starts failing outright --
     * i.e. the site eventually refuses new connections because of its own
     * bookkeeping.
     *
     * The caller key (the remote IP, as passed down from the registration
     * endpoint) is part of the fingerprint on purpose, and this is where we
     * diverge from the implementation we studied. Theirs dedupes on name
     * plus redirect URIs alone, which is safe for it because its clients
     * are public PKCE clients with no secret to hand back. Ours are
     * confidential clients: reusing a row means minting a fresh secret for
     * an existing client_id, so deduping on publicly guessable metadata
     * alone would let any anonymous caller rotate another client's secret
     * and break its next token exchange. Binding the fingerprint to the
     * registering caller closes that, and the fallback when the caller key
     * differs is simply today's behaviour (a brand new client row).
     */
    private static function registration_fingerprint(string $name, array $uris, string $registrar_key): string
    {
        $sorted = $uris;
        sort($sorted);

        return hash('sha256', $name . "\n" . implode("\n", $sorted) . "\n" . $registrar_key);
    }

    /**
     * The client_id of an existing row this registration may reuse, or null.
     *
     * Reuse additionally requires the candidate to hold no tokens at all.
     * A client with a live access or refresh token is a working connection,
     * and rotating its secret out from under it would break the next
     * refresh; only never-completed or fully-lapsed registrations are
     * recycled.
     */
    private static function find_reusable(array $stored, string $fingerprint): ?string
    {
        foreach ($stored as $client_id => $record) {
            if ((string) ($record['fingerprint'] ?? '') !== $fingerprint) {
                continue;
            }
            $client_id = (string) $client_id;
            if (Token_Store::has_tokens_for_client($client_id)) {
                continue;
            }
            if (Refresh_Token_Store::has_tokens_for_client($client_id)) {
                continue;
            }

            return $client_id;
        }

        return null;
    }

    /**
     * Delete orphan client rows: registrations older than $grace seconds
     * that never produced (or no longer hold) a single token. A
     * just-registered client is protected for the whole grace window so a
     * user who is still sitting on the consent screen is never reaped
     * mid-flow.
     *
     * @return int Number of clients removed.
     */
    public static function gc(int $grace, ?int $now = null): int
    {
        $now     = null === $now ? time() : $now;
        $stored  = self::load();
        $removed = 0;

        foreach ($stored as $client_id => $record) {
            $client_id = (string) $client_id;
            if ((int) ($record['created_at'] ?? 0) > $now - $grace) {
                continue;
            }
            if (Token_Store::has_tokens_for_client($client_id)) {
                continue;
            }
            if (Refresh_Token_Store::has_tokens_for_client($client_id)) {
                continue;
            }

            unset($stored[ $client_id ]);
            $removed++;
        }

        if ($removed > 0) {
            self::save($stored);
        }

        return $removed;
    }

    /** Fetch a client's stored record (never includes the plaintext secret), or null. */
    public static function get(string $client_id): ?array
    {
        $stored = self::load();
        return $stored[ $client_id ] ?? null;
    }

    /** Whether $client_secret is the correct plaintext secret for $client_id. */
    public static function verify_secret(string $client_id, string $client_secret): bool
    {
        $record = self::get($client_id);
        if (null === $record) {
            return false;
        }

        return hash_equals($record['client_secret_hash'], self::hash($client_secret));
    }

    private static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    private static function generate_token(string $prefix): string
    {
        return $prefix . bin2hex(random_bytes(24));
    }
}
