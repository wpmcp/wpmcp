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
 *  1. takes a MySQL GET_LOCK mutex (injectable seam for tests);
 *  2. re-reads the stored bundle after acquiring the lock and short-circuits
 *     when another request already refreshed (double-checked locking);
 *  3. when the lock is NOT acquired, bails WITHOUT presenting the refresh
 *     token, returning whatever access token is stored;
 *  4. on an auth rejection, treats the attempt as success when the stored
 *     refresh token has rotated or the access token is fresh again (we lost a
 *     race, the winner's bundle is valid); only an un-raced rejection marks
 *     the connection unhealthy;
 *  5. leaves the stored bundle untouched on network errors and 5xx;
 *  6. merges a successful response onto the freshest stored bundle.
 */
class Token_Refresher
{
    private const LOCK_NAME    = 'wpmcp_cloud_token_refresh';
    private const LOCK_TIMEOUT = 5;

    /** Seconds of remaining validity below which a token counts as stale. */
    public const FRESHNESS_MARGIN = 60;

    public const HEALTH_OPTION = 'wpmcp_cloud_unhealthy';

    /** @var callable(string,int):bool acquire a named mutex */
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

        if (! ($this->lock)(self::LOCK_NAME, self::LOCK_TIMEOUT)) {
            // Lock timeout: another request is refreshing. Never present the
            // refresh token here; return whatever is stored (possibly stale).
            $token = (string) (Cloud_Credentials::get('access_token') ?? '');
            return '' !== $token ? $token : null;
        }

        try {
            // Double-checked re-read: the previous holder may have refreshed.
            $bundle = Cloud_Credentials::all();
            if (self::is_fresh($bundle)) {
                return (string) $bundle['access_token'];
            }
            return $this->refresh($bundle);
        } finally {
            ($this->unlock)(self::LOCK_NAME);
        }
    }

    /** @return string|null new access token, or null when refresh failed */
    private function refresh(array $bundle)
    {
        $presented = (string) ($bundle['refresh_token'] ?? '');
        $response  = ($this->transport)((string) ($bundle['base_url'] ?? ''), [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $presented,
            'client_id'     => (string) ($bundle['client_id'] ?? ''),
        ]);

        if (is_wp_error($response)) {
            // Network error / 5xx: transient. Bundle untouched, not unhealthy.
            return null;
        }

        if (isset($response['auth_rejected']) && $response['auth_rejected']) {
            $stored = Cloud_Credentials::all();
            if ((string) ($stored['refresh_token'] ?? '') !== $presented || self::is_fresh($stored)) {
                // Race loser: the winner rotated the bundle. Their result is valid.
                return self::is_fresh($stored) ? (string) $stored['access_token'] : null;
            }
            // Un-raced rejection: the refresh token is genuinely revoked.
            update_option(self::HEALTH_OPTION, ['rejected_at' => time()], false);
            return null;
        }

        // Success: merge onto the FRESHEST stored bundle, not our stale copy.
        Cloud_Credentials::merge([
            'access_token'      => (string) ($response['access_token'] ?? ''),
            'refresh_token'     => (string) ($response['refresh_token'] ?? $presented),
            'access_expires_at' => time() + (int) ($response['expires_in'] ?? 0),
        ]);
        delete_option(self::HEALTH_OPTION);
        return (string) ($response['access_token'] ?? '') ?: null;
    }

    private static function mysql_lock(string $name, int $timeout): bool
    {
        global $wpdb;
        return '1' === $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, $timeout));
    }

    private static function mysql_unlock(string $name): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }

    /**
     * Default transport. TODO(#141 phase 2): the token endpoint path is fixed
     * when the PKCE OAuth connect flow lands; /oauth/token is the placeholder
     * agreed in the #135 delivery plan.
     *
     * @return array|\WP_Error decoded body, ['auth_rejected' => true] on 400/401, WP_Error on transient failure
     */
    private static function http_transport(string $base_url, array $body)
    {
        $response = wp_remote_post($base_url . '/oauth/token', [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body'    => (string) wp_json_encode($body),
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 500) {
            return new \WP_Error('cloud_unavailable', "HTTP {$code}");
        }
        if (400 === $code || 401 === $code) {
            return ['auth_rejected' => true];
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        return is_array($data) ? $data : new \WP_Error('cloud_bad_response', 'Malformed token response');
    }
}
