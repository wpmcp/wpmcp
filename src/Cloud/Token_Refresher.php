<?php

namespace WPMCP\Cloud;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Rotation-safe access-token refresh engine (issue #141, phase 1 of #135).
 *
 * The cloud rotates the refresh token on every use, so two concurrent
 * requests must never both present the same refresh token: the loser would
 * burn a token the winner already rotated and look like a revocation. The
 * engine therefore:
 *
 *  1. takes a MySQL GET_LOCK mutex, scoped to this install (injectable seam
 *     for tests). A lock subsystem that cannot answer at all (GET_LOCK
 *     returning NULL: no privilege, a non-MySQL drop-in) is not treated as
 *     "someone else is refreshing", which would wedge the connection into
 *     never refreshing; the refresh proceeds unlocked and the race-loser rule
 *     below keeps a lost race non-destructive;
 *  2. re-reads the stored bundle FROM THE DATABASE after acquiring the lock
 *     and short-circuits when another request already refreshed. The re-read
 *     must bypass the options object cache, which this request populated
 *     before the lock and which would otherwise hide the winner's write and
 *     make the whole mutex decorative;
 *  3. when the lock is NOT acquired, bails WITHOUT presenting the refresh
 *     token, returning the stored access token only when it is actually
 *     fresh (handing back a known-expired token would turn a recoverable
 *     state into a guaranteed 401 and skip the API-key fallback);
 *  4. on an auth rejection, treats the attempt as success when the stored
 *     refresh token has rotated or the access token is fresh again (we lost a
 *     race, the winner's bundle is valid); only an un-raced rejection marks
 *     the connection unhealthy;
 *  5. leaves the stored bundle untouched on every failure that is not a
 *     definite rejection: network errors, 5xx, rate limits, and 2xx bodies
 *     that do not actually carry a token. Only a well-formed grant response
 *     is allowed to overwrite credentials;
 *  6. merges a successful response onto the freshest stored bundle.
 *
 * The unhealthy marker is not decorative either: while it is set the engine
 * backs off instead of re-presenting a refresh token the cloud has already
 * rejected, cloud-status reports it, and cloud-connect clears it.
 *
 * Transient failures get their own, much shorter backoff (RETRY_TRANSIENT).
 * Without it a cloud that is simply down turns every single cloud tool call
 * into a 5s GET_LOCK plus a 20s HTTP POST before the real request, forever,
 * and concurrent workers serialize on that lock. The marker is fingerprinted
 * on the refresh token it failed for, so a reconnect is never made to wait.
 */
class Token_Refresher
{
    private const LOCK_TIMEOUT = 5;

    /** Seconds of remaining validity below which a token counts as stale. */
    public const FRESHNESS_MARGIN = 60;

    public const HEALTH_OPTION = 'wpmcp_cloud_unhealthy';

    /** Seconds to stop re-presenting a refresh token the cloud rejected. */
    public const UNHEALTHY_BACKOFF = 900;

    /** Short backoff after a transient refresh failure (cloud down, 5xx, invalid_client). */
    public const RETRY_TRANSIENT = 'wpmcp_cloud_refresh_retry';

    public const RETRY_BACKOFF = 60;

    /** @var callable(string,int):?bool acquire a named mutex; null when the lock subsystem is unusable */
    private $lock;

    /** @var callable(string):void release the named mutex */
    private $unlock;

    /** @var callable(string,array):(array|\WP_Error) POST the refresh grant; returns decoded body or WP_Error */
    private $transport;

    public function __construct(?callable $lock = null, ?callable $unlock = null, ?callable $transport = null)
    {
        $this->lock      = $lock ?? [self::class, 'mysql_lock'];
        $this->unlock    = $unlock ?? [self::class, 'mysql_unlock'];
        $this->transport = $transport ?? [self::class, 'http_transport'];
    }

    public static function is_fresh(array $bundle): bool
    {
        return '' !== (string) ($bundle['access_token'] ?? '')
            && (int) ($bundle['access_expires_at'] ?? 0) > time() + self::FRESHNESS_MARGIN;
    }

    /**
     * True while the last refresh attempt was an un-raced rejection recent
     * enough that retrying would just burn another request. Cleared by a
     * successful refresh and by cloud-connect.
     */
    public static function is_unhealthy(): bool
    {
        $marker = get_option(self::HEALTH_OPTION, false);
        if (! is_array($marker)) {
            return false;
        }
        return (int) ($marker['rejected_at'] ?? 0) + self::UNHEALTHY_BACKOFF > time();
    }

    /**
     * Reset every refresh-failure state. Called whenever a new credential set
     * is stored (Cloud_Credentials::replace()) and on a successful refresh, so
     * a fresh bundle never inherits the old one's backoff.
     */
    public static function clear_health(): void
    {
        delete_option(self::HEALTH_OPTION);
        delete_transient(self::RETRY_TRANSIENT);
    }

    /**
     * True while a recent TRANSIENT failure for this exact refresh token is
     * still inside its short backoff. Distinct from is_unhealthy(): that one
     * means "the cloud says this token is dead", this one means "asking again
     * right now is just latency".
     */
    private static function is_backed_off(array $bundle): bool
    {
        $marker = get_transient(self::RETRY_TRANSIENT);
        return is_string($marker) && '' !== $marker && hash_equals($marker, self::fingerprint($bundle));
    }

    /** Identifies the bundle a failure belongs to without storing the secret. */
    private static function fingerprint(array $bundle): string
    {
        return hash('sha256', (string) ($bundle['refresh_token'] ?? ''));
    }

    /**
     * Ensure a usable access token, refreshing if stale. Returns the access
     * token, or null when no token auth is available (caller falls back to
     * the API key).
     */
    public function ensure_fresh_access_token(): ?string
    {
        $bundle = Cloud_Credentials::all();
        if ('' === (string) ($bundle['refresh_token'] ?? '')) {
            return null;
        }
        if (self::is_fresh($bundle)) {
            return (string) $bundle['access_token'];
        }
        if (self::is_unhealthy() || self::is_backed_off($bundle)) {
            // Either the cloud already rejected this refresh token, or the
            // last attempt failed transiently seconds ago. Do not take the
            // lock and do not spend another 20s HTTP timeout on every request
            // until the backoff expires or the site reconnects.
            return null;
        }

        $acquired = ($this->lock)(self::lock_name(), self::LOCK_TIMEOUT);

        if (false === $acquired) {
            // Lock timeout: another request is refreshing. Never present the
            // refresh token here, and only hand back a token that is actually
            // usable; otherwise let the caller fall back to the API key.
            $fresh = Cloud_Credentials::all(true);
            return self::is_fresh($fresh) ? (string) $fresh['access_token'] : null;
        }

        try {
            // Double-checked re-read, forced past the options cache: the
            // previous holder may have refreshed in another process.
            $bundle = Cloud_Credentials::all(true);
            if (self::is_fresh($bundle)) {
                return (string) $bundle['access_token'];
            }
            return $this->refresh($bundle);
        } finally {
            if (true === $acquired) {
                ($this->unlock)(self::lock_name());
            }
        }
    }

    /** @return string|null new access token, or null when refresh failed */
    private function refresh(array $bundle)
    {
        $presented = (string) ($bundle['refresh_token'] ?? '');
        $grant     = [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $presented,
            'client_id'     => (string) ($bundle['client_id'] ?? ''),
        ];
        // Every client this plugin's own token endpoint knows about is a
        // confidential one (Auth\Client_Store::create() has no public-client
        // mode and Auth\Token_Grant::exchange() verifies the secret before it
        // dispatches to the refresh branch), so a stored secret must go on the
        // wire or the grant comes back invalid_client. It is omitted, rather
        // than sent empty, when the phase 2 connect flow registers this site
        // as a public PKCE client.
        $secret = (string) ($bundle['client_secret'] ?? '');
        if ('' !== $secret) {
            $grant['client_secret'] = $secret;
        }

        $response = ($this->transport)((string) ($bundle['base_url'] ?? ''), $grant);

        if (is_wp_error($response) || ! is_array($response)) {
            // Transient (network error, 5xx, rate limit, an OAuth error that
            // is about the request rather than the token). Bundle untouched,
            // connection not marked unhealthy, but backed off briefly so the
            // next cloud call does not pay the timeout again.
            self::back_off($bundle);
            return null;
        }

        if (! empty($response['auth_rejected'])) {
            $stored = Cloud_Credentials::all(true);
            if ((string) ($stored['refresh_token'] ?? '') !== $presented || self::is_fresh($stored)) {
                // Race loser: the winner rotated the bundle. Their result is valid.
                return self::is_fresh($stored) ? (string) $stored['access_token'] : null;
            }
            // Un-raced rejection: the refresh token is genuinely revoked.
            update_option(self::HEALTH_OPTION, ['rejected_at' => time()], false);
            return null;
        }

        $access     = (string) ($response['access_token'] ?? '');
        $expires_in = (int) ($response['expires_in'] ?? 0);
        if ('' === $access || $expires_in <= 0) {
            // A 2xx that is not actually a grant. Treat as transient rather
            // than merging an empty token over working credentials.
            self::back_off($bundle);
            return null;
        }

        // Coalesce the rotated refresh token on emptiness, not just on null:
        // a grant that carries refresh_token: "" would otherwise overwrite the
        // working token with nothing and permanently disable refresh.
        $rotated = (string) ($response['refresh_token'] ?? '');
        if ('' === $rotated) {
            $rotated = $presented;
        }

        // Success: merge onto the FRESHEST stored bundle, not our stale copy.
        $persisted = Cloud_Credentials::merge([
            'access_token'      => $access,
            'refresh_token'     => $rotated,
            'access_expires_at' => time() + $expires_in,
        ]);
        if (! $persisted) {
            // The cloud has already burned the presented refresh token and we
            // could not store its replacement. Reporting success would hand
            // out an access token nobody stored and re-present the dead token
            // on the next request; back off and report failure instead.
            self::back_off($bundle);
            return null;
        }

        self::clear_health();
        return $access;
    }

    /** Mark a short backoff for this bundle after a transient failure. */
    private static function back_off(array $bundle): void
    {
        set_transient(self::RETRY_TRANSIENT, self::fingerprint($bundle), self::RETRY_BACKOFF);
    }

    /**
     * GET_LOCK names are global to the MySQL server, so an unscoped name would
     * serialize unrelated installs that share a database server against each
     * other. Scope it to this site's table prefix and home URL. MySQL caps
     * lock names at 64 characters, hence the hash.
     */
    private static function lock_name(): string
    {
        global $wpdb;
        return 'wpmcp_cloud_token_refresh_' . substr(md5((string) $wpdb->prefix . '|' . home_url()), 0, 16);
    }

    /** @return bool|null true acquired, false timed out, null lock subsystem unusable */
    private static function mysql_lock(string $name, int $timeout): ?bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- GET_LOCK is a MySQL advisory mutex with no WP API and, by definition, nothing cacheable.
        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, $timeout));
        if (null === $result) {
            return null;
        }
        return '1' === (string) $result;
    }

    private static function mysql_unlock(string $name): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releases the advisory mutex taken by mysql_lock(); nothing cacheable.
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }

    /**
     * Default transport: an RFC 6749 refresh_token grant, form-encoded, to the
     * token endpoint named by Cloud_Client so the wire format stays in one
     * place.
     *
     * Only an unambiguous revocation is reported as a rejection: an OAuth
     * `invalid_grant`, or a bare 401 with no OAuth error code. Everything else
     * (403, 404, 429, 5xx, and the `invalid_client` / `invalid_request` /
     * `unsupported_grant_type` errors, which are about how we asked rather
     * than about the token) is transient, because marking the connection
     * unhealthy on those would take a client-configuration bug and turn it
     * into a permanent disconnect.
     *
     * @return array|\WP_Error decoded body, ['auth_rejected' => true] on a revocation, WP_Error on transient failure
     */
    private static function http_transport(string $base_url, array $body)
    {
        $response = wp_remote_post($base_url . Cloud_Client::TOKEN_PATH, [
            'timeout' => 20,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept'       => 'application/json',
            ],
            'body'    => $body,
        ]);
        if (is_wp_error($response)) {
            return $response;
        }

        $code  = (int) wp_remote_retrieve_response_code($response);
        $data  = json_decode((string) wp_remote_retrieve_body($response), true);
        $data  = is_array($data) ? $data : [];
        $error = isset($data['error']) ? (string) $data['error'] : '';

        if (200 === $code) {
            // refresh() decides whether this is actually a usable grant.
            return $data;
        }
        if ('invalid_grant' === $error || (401 === $code && '' === $error)) {
            return ['auth_rejected' => true];
        }
        return new \WP_Error('cloud_token_refresh_failed', "HTTP {$code}" . ('' === $error ? '' : " ({$error})"));
    }
}
