<?php

namespace WPMCP\Auth;

use WPMCP\Tools\Media\Remote_Image_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * OAuth Client ID Metadata Documents (issue #388), the registration method
 * the MCP authorization spec prefers over dynamic client registration.
 *
 * The client_id is an https URL. When /authorize sees one that is not a
 * dynamically registered client, this class fetches the JSON document at
 * that URL, requires its client_id to equal the URL exactly, and takes the
 * client's name and redirect_uris from it. The client is public: it has no
 * secret, and the token endpoint accepts it on PKCE alone
 * (token_endpoint_auth_method "none"). Documents declaring any other
 * authentication method, or carrying a client_secret, are refused.
 *
 * Fetching a caller-chosen URL is an SSRF surface, so the request is held to
 * the same rules the remote readers use: https only, the default port, no
 * credentials, no fragment or dot segments, a host that is not and does not
 * resolve to a loopback, private or reserved address
 * (Remote_Image_Guard::assert_public_host), then wp_safe_remote_get with
 * redirects off, a short timeout and a small byte cap. Only a logged-in
 * user reaching /authorize can trigger a fetch, and results are cached
 * (successes per Cache-Control, failures briefly) so repeat authorizations
 * cost nothing.
 *
 * Every client that passes validation is recorded in a small registry an
 * administrator sees on the Connection screen. Approval of newly seen
 * clients is off by default; when a site owner turns it on, clients already
 * seen are approved and any new client is held as pending until approved
 * there. A denied client is
 * refused whatever the setting, and denying one revokes its tokens.
 */
class Client_Metadata_Document
{
    public const REGISTRY_OPTION = 'wpmcp_oauth_cimd_clients';
    public const APPROVAL_OPTION = 'wpmcp_oauth_cimd_require_approval';

    /** The draft recommends servers cap documents at 5 KB. */
    public const DEFAULT_MAX_BYTES = 5120;
    public const TIMEOUT           = 5;
    public const DEFAULT_TTL       = 3600;
    public const MAX_TTL           = 86400;
    public const FAILURE_TTL       = 60;

    /** Registry size cap; the oldest non-approved entries are dropped first. */
    public const MAX_CLIENTS = 100;

    private const CACHE_PREFIX = 'wpmcp_oauth_cimd_';

    public static function is_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_oauth_cimd_enabled', true);
    }

    /** Whether $client_id is shaped like a metadata document URL at all (https scheme). */
    public static function looks_like_url(string $client_id): bool
    {
        return 0 === stripos($client_id, 'https://') || 0 === stripos($client_id, 'http://');
    }

    /**
     * Fetch (or read from cache) and validate the document behind $client_id.
     *
     * @return array{client_id: string, client_name: string, redirect_uris: string[]}|\WP_Error
     */
    public static function resolve(string $client_id): array|\WP_Error
    {
        if (! self::is_enabled()) {
            return new \WP_Error('invalid_client', 'Unknown client_id.');
        }

        try {
            self::assert_safe_url($client_id);
        } catch (\InvalidArgumentException $e) {
            // A refusal costs no request, so it is not cached.
            return new \WP_Error('invalid_client', 'The client_id is not an acceptable client metadata document URL: ' . $e->getMessage());
        }

        $key    = self::cache_key($client_id);
        $cached = get_transient($key);
        if (is_array($cached) && isset($cached['ok'])) {
            return $cached['ok'] && is_array($cached['client'] ?? null)
                ? $cached['client']
                : new \WP_Error('invalid_client', (string) ($cached['error'] ?? 'The client metadata document could not be used.'));
        }

        $fetched = self::fetch($client_id);
        if (is_wp_error($fetched)) {
            set_transient($key, ['ok' => false, 'error' => $fetched->get_error_message()], self::FAILURE_TTL);
            return $fetched;
        }

        [$client, $ttl] = $fetched;
        if ($ttl > 0) {
            set_transient($key, ['ok' => true, 'client' => $client], $ttl);
        }

        return $client;
    }

    public static function flush_cache(string $client_id): void
    {
        delete_transient(self::cache_key($client_id));
    }

    /**
     * Cache lifetime for a successful fetch, from its Cache-Control header:
     * no-store, no-cache and max-age=0 mean do not cache; max-age is
     * honoured up to MAX_TTL; no header means DEFAULT_TTL.
     */
    public static function cache_ttl(string $cache_control): int
    {
        $header = strtolower($cache_control);
        if (1 === preg_match('/(?:^|[\s,])(no-store|no-cache)(?:$|[\s,=])/', $header)) {
            return 0;
        }
        if (1 === preg_match('/(?:^|[\s,])max-age\s*=\s*"?(\d+)/', $header, $m)) {
            return (int) min(self::MAX_TTL, (int) $m[1]);
        }

        return self::DEFAULT_TTL;
    }

    /**
     * URL rules from the metadata document draft plus the SSRF guard.
     *
     * @throws \InvalidArgumentException
     */
    private static function assert_safe_url(string $url): void
    {
        $parts = wp_parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            throw new \InvalidArgumentException('it could not be parsed.');
        }
        if ('https' !== strtolower((string) ($parts['scheme'] ?? ''))) {
            throw new \InvalidArgumentException('it must use https.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('it must not embed credentials.');
        }
        if (isset($parts['port']) && 443 !== (int) $parts['port']) {
            throw new \InvalidArgumentException('it must use the default https port.');
        }
        if (isset($parts['fragment']) || false !== strpos($url, '#')) {
            throw new \InvalidArgumentException('it must not contain a fragment.');
        }

        $path = (string) ($parts['path'] ?? '');
        if ('' === $path || '/' === $path) {
            throw new \InvalidArgumentException('it must contain a path.');
        }
        foreach (explode('/', $path) as $segment) {
            if ('.' === $segment || '..' === $segment) {
                throw new \InvalidArgumentException('it must not contain dot path segments.');
            }
        }

        Remote_Image_Guard::assert_public_host((string) $parts['host']);
    }

    private static function max_bytes(): int
    {
        return max(512, (int) apply_filters('wpmcp_oauth_cimd_max_bytes', self::DEFAULT_MAX_BYTES));
    }

    /** @return array{0: array{client_id: string, client_name: string, redirect_uris: string[]}, 1: int}|\WP_Error */
    private static function fetch(string $client_id): array|\WP_Error
    {
        $max      = self::max_bytes();
        $response = wp_safe_remote_get($client_id, [
            'timeout'             => self::TIMEOUT,
            'redirection'         => 0,
            'limit_response_size' => $max + 1,
            'user-agent'          => 'WPMCP-OAuth-Client-Metadata/1.0',
            'headers'             => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            return self::invalid('the client metadata document could not be fetched.');
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 300 && $code < 400) {
            return self::invalid('the client metadata document URL redirected; redirects are not followed.');
        }
        if (200 !== $code) {
            return self::invalid(sprintf('the client metadata document URL answered HTTP %d.', $code));
        }

        $declared = wp_remote_retrieve_header($response, 'content-length');
        $body     = (string) wp_remote_retrieve_body($response);
        if (('' !== (string) $declared && (int) $declared > $max) || strlen($body) > $max) {
            return self::invalid(sprintf('the client metadata document is larger than %d bytes.', $max));
        }

        $document = json_decode($body, true, 16);
        if (! is_array($document) || array_is_list($document)) {
            return self::invalid('the client metadata document is not a JSON object.');
        }

        $client = self::validate_document($document, $client_id);
        if (is_wp_error($client)) {
            return $client;
        }

        $cache_control = wp_remote_retrieve_header($response, 'cache-control');
        $cache_control = is_array($cache_control) ? implode(', ', $cache_control) : (string) $cache_control;

        return [$client, self::cache_ttl($cache_control)];
    }

    /** @return array{client_id: string, client_name: string, redirect_uris: string[]}|\WP_Error */
    private static function validate_document(array $document, string $client_id): array|\WP_Error
    {
        if (! isset($document['client_id']) || $client_id !== $document['client_id']) {
            return self::invalid('the document\'s client_id does not match its URL.');
        }

        $name = $document['client_name'] ?? null;
        if (! is_string($name) || '' === trim($name)) {
            return self::invalid('the document has no client_name.');
        }

        $uris = $document['redirect_uris'] ?? null;
        if (! is_array($uris) || [] === $uris) {
            return self::invalid('the document has no redirect_uris.');
        }
        foreach ($uris as $uri) {
            if (! is_string($uri) || ! Redirect_Uri_Validator::is_valid($uri)) {
                return self::invalid('the document lists a redirect_uri that is not a valid absolute HTTPS (or loopback) URI.');
            }
        }

        // Public clients only: a shared secret cannot be published in a
        // document anyone can read, and private_key_jwt is not supported.
        $method = $document['token_endpoint_auth_method'] ?? 'none';
        if ('none' !== $method) {
            return self::invalid('only token_endpoint_auth_method "none" is supported for client metadata documents.');
        }
        if (array_key_exists('client_secret', $document) || array_key_exists('client_secret_expires_at', $document)) {
            return self::invalid('a client metadata document must not carry a client secret.');
        }

        return [
            'client_id'     => $client_id,
            'client_name'   => sanitize_text_field(substr($name, 0, 200)),
            'redirect_uris' => array_values(array_unique($uris)),
        ];
    }

    private static function invalid(string $reason): \WP_Error
    {
        return new \WP_Error('invalid_client', 'The client metadata document was refused: ' . $reason);
    }

    private static function cache_key(string $client_id): string
    {
        return self::CACHE_PREFIX . md5($client_id);
    }

    // Approval registry.

    public static function requires_approval(): bool
    {
        return (bool) apply_filters('wpmcp_oauth_cimd_require_approval', (bool) get_option(self::APPROVAL_OPTION, false));
    }

    /**
     * Turning approval on grandfathers every client already seen, so the
     * switch holds back new clients without cutting off connections that
     * were working a moment ago.
     */
    public static function set_requires_approval(bool $required): void
    {
        update_option(self::APPROVAL_OPTION, $required, false);

        if (! $required) {
            return;
        }

        $clients = self::clients();
        foreach ($clients as $client_id => $client) {
            if ('seen' === ($client['status'] ?? '')) {
                $clients[ $client_id ]['status'] = 'approved';
            }
        }
        self::save($clients);
    }

    /**
     * Every recorded client, keyed by client_id: { client_id, client_name,
     * redirect_hosts, status, first_seen, last_seen }.
     *
     * @return array<string, array>
     */
    public static function clients(): array
    {
        $stored = get_option(self::REGISTRY_OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    /**
     * 'approved' or 'denied' (an administrator decided), 'pending' (seen
     * while approval was required, waiting), 'seen' (used while approval
     * was off), or '' for a client never seen.
     */
    public static function status(string $client_id): string
    {
        return (string) (self::clients()[ $client_id ]['status'] ?? '');
    }

    /**
     * Record a validated client and decide whether it may authorize now.
     * Called by /authorize after the document validated.
     *
     * @param array{client_id: string, client_name: string, redirect_uris: string[]} $client
     */
    public static function admit(array $client): true|\WP_Error
    {
        $client_id = $client['client_id'];
        $clients   = self::clients();
        $status    = (string) ($clients[ $client_id ]['status'] ?? '');
        $required  = self::requires_approval();

        if ('' === $status) {
            $status = $required ? 'pending' : 'seen';
        }

        $clients[ $client_id ] = [
            'client_id'      => $client_id,
            'client_name'    => $client['client_name'],
            'redirect_hosts' => self::redirect_hosts($client['redirect_uris']),
            'status'         => $status,
            'first_seen'     => (int) ($clients[ $client_id ]['first_seen'] ?? time()),
            'last_seen'      => time(),
        ];
        self::save(self::trim($clients));

        return self::admitted_status($status, $required)
            ? true
            : self::refusal($status);
    }

    /** Whether a CIMD client_id may redeem codes and refresh tokens right now. */
    public static function is_admitted(string $client_id): bool
    {
        if (! self::is_enabled() || ! self::looks_like_url($client_id)) {
            return false;
        }

        return self::admitted_status(self::status($client_id), self::requires_approval());
    }

    public static function approve(string $client_id): bool
    {
        return self::set_status($client_id, 'approved');
    }

    /** Deny a client and revoke every token it holds. */
    public static function deny(string $client_id): bool
    {
        $changed = self::set_status($client_id, 'denied');
        Token_Store::revoke_for_client($client_id);
        Refresh_Token_Store::revoke_for_client($client_id);

        return $changed;
    }

    /** Drop a client from the registry, so it is treated as newly seen next time. */
    public static function forget(string $client_id): bool
    {
        $clients = self::clients();
        if (! isset($clients[ $client_id ])) {
            return false;
        }
        unset($clients[ $client_id ]);
        self::save($clients);
        self::flush_cache($client_id);

        return true;
    }

    private static function admitted_status(string $status, bool $required): bool
    {
        if ('denied' === $status) {
            return false;
        }

        return 'approved' === $status || ! $required;
    }

    private static function refusal(string $status): \WP_Error
    {
        return 'denied' === $status
            ? new \WP_Error('access_denied', 'A site administrator has blocked this client.')
            : new \WP_Error('access_denied', 'This client is waiting for a site administrator to approve it on the WP MCP Connection screen.');
    }

    private static function set_status(string $client_id, string $status): bool
    {
        $clients = self::clients();
        if (! isset($clients[ $client_id ])) {
            if ('denied' !== $status || ! self::looks_like_url($client_id)) {
                return false;
            }
            $clients[ $client_id ] = [
                'client_id'      => $client_id,
                'client_name'    => '',
                'redirect_hosts' => [],
                'first_seen'     => time(),
                'last_seen'      => time(),
            ];
        }

        $clients[ $client_id ]['status'] = $status;
        self::save(self::trim($clients));

        return true;
    }

    /** @return string[] */
    private static function redirect_hosts(array $uris): array
    {
        $hosts = [];
        foreach ($uris as $uri) {
            $host = (string) wp_parse_url((string) $uri, PHP_URL_HOST);
            $hosts[] = '' === $host ? (string) wp_parse_url((string) $uri, PHP_URL_SCHEME) . ':' : strtolower(trim($host, '[]'));
        }

        return array_values(array_unique($hosts));
    }

    /** Keep the registry bounded by dropping the least recently seen undecided entries, never an administrator's decision. */
    private static function trim(array $clients): array
    {
        if (count($clients) <= self::MAX_CLIENTS) {
            return $clients;
        }

        $pending = array_filter($clients, static fn (array $c): bool => in_array($c['status'] ?? '', ['pending', 'seen'], true));
        uasort($pending, static fn (array $a, array $b): int => (int) $a['last_seen'] <=> (int) $b['last_seen']);
        foreach (array_keys($pending) as $client_id) {
            if (count($clients) <= self::MAX_CLIENTS) {
                break;
            }
            unset($clients[ $client_id ]);
        }

        return $clients;
    }

    private static function save(array $clients): void
    {
        update_option(self::REGISTRY_OPTION, $clients, false);
    }
}
