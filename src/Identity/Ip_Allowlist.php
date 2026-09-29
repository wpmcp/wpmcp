<?php

namespace WPMCP\Identity;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\MCP\Transport_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Pins a scoped identity to network addresses (issue #416).
 *
 * An identity record may carry 'allowed_ips': single IPv4/IPv6 addresses and
 * CIDR ranges. When the list is non-empty, a request acting as that identity
 * from any other client address is refused before any ability runs:
 *
 *  - Governance::is_within_identity_scope() denies every ability, which
 *    covers every HTTP path that resolves an identity (application
 *    passwords, OAuth, the gateway credential, call-tool dispatch);
 *  - filter_pre_dispatch() answers the MCP route itself with a generic 403
 *    before the adapter handles initialize or tools/list;
 *  - Gateway_Guard refuses a bound gateway token outright at authentication.
 *
 * The client never learns which check failed: the refusal looks like any
 * other permission denial. The admin sees one 'identity/ip-refused' row per
 * request in the governance audit log, with the address that was refused.
 *
 * CLIENT ADDRESS. REMOTE_ADDR, always, unless the site owner names trusted
 * proxies through the wpmcp_trusted_proxies filter (a list of addresses and
 * CIDR ranges). Only when REMOTE_ADDR is one of them is X-Forwarded-For read,
 * right to left, skipping further trusted proxies; the first other hop is the
 * client. A malformed hop, or no usable REMOTE_ADDR, resolves to no address,
 * which a pinned identity refuses. Forwarded headers are never trusted by
 * default, so they cannot be spoofed past the list.
 *
 * WHAT IT NEVER TOUCHES. Only requests acting as a pinned identity. The admin
 * screens run with no identity, so an admin cannot lock themselves out of
 * wp-admin with this. The WP-CLI stdio transport is exempt: it has no network
 * peer, and its caller already has a shell on the server.
 */
final class Ip_Allowlist
{
    /** Entries kept per identity, so one option row stays small. */
    public const MAX_ENTRIES = 100;

    public const AUDIT_EVENT = 'identity/ip-refused';

    private static ?bool $cli_override = null;

    /** @var array<string, true> identities already audited this request */
    private static array $audited = [];

    /** @var string|null|false false until resolved for this request */
    private static $client = false;

    public static function register(): void
    {
        add_filter('rest_pre_dispatch', [self::class, 'filter_pre_dispatch'], 1, 3);
    }

    /**
     * Strict normalization for create-identity: every entry must be a valid
     * address or CIDR range, or the whole list is refused.
     *
     * @param mixed $entries
     * @return string[] canonical entries, de-duplicated, in the given order.
     */
    public static function validate($entries): array
    {
        if (! is_array($entries)) {
            throw new \InvalidArgumentException('allowed_ips must be a list of IP addresses or CIDR ranges.');
        }

        $out = [];
        foreach ($entries as $entry) {
            $canonical = is_string($entry) ? self::canonical($entry) : null;
            if (null === $canonical) {
                throw new \InvalidArgumentException(sprintf(
                    'allowed_ips entry "%s" is not an IPv4/IPv6 address or CIDR range.',
                    esc_html(is_scalar($entry) ? (string) $entry : gettype($entry))
                ));
            }
            $out[ $canonical ] = true;
        }

        if (count($out) > self::MAX_ENTRIES) {
            throw new \InvalidArgumentException(sprintf('allowed_ips holds at most %d entries.', (int) self::MAX_ENTRIES));
        }

        return array_keys($out);
    }

    /**
     * Lenient normalization for stored and synced records: invalid entries
     * are dropped rather than refused.
     *
     * @param mixed $entries
     * @return string[]
     */
    public static function sanitize($entries): array
    {
        if (! is_array($entries)) {
            return [];
        }

        $out = [];
        foreach ($entries as $entry) {
            $canonical = is_string($entry) ? self::canonical($entry) : null;
            if (null !== $canonical) {
                $out[ $canonical ] = true;
            }
        }

        return array_slice(array_keys($out), 0, self::MAX_ENTRIES);
    }

    /** Whether $ip falls inside any entry of $list. */
    public static function contains(array $list, string $ip): bool
    {
        $packed = self::pack($ip);
        if (null === $packed) {
            return false;
        }

        foreach ($list as $entry) {
            $range = is_string($entry) ? self::range($entry) : null;
            if (null !== $range && self::in_range($packed, $range[0], $range[1])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The client address of this request (see the class docblock), or null
     * when it cannot be determined.
     */
    public static function client_ip(): ?string
    {
        if (false !== self::$client) {
            return self::$client;
        }

        // Compared as exact bytes, never echoed unescaped; sanitize_text_field
        // would not make an address any more valid than the check below.
        $remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string) wp_unslash($_SERVER['REMOTE_ADDR'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if (null === self::pack($remote)) {
            return self::$client = null;
        }

        /**
         * Reverse proxies whose X-Forwarded-For header wpmcp may believe when
         * matching a scoped identity's allowed_ips. Empty by default, so the
         * header is ignored and REMOTE_ADDR is the client address. List only
         * proxies you run (load balancer, CDN edge ranges): a listed address
         * can claim any client address.
         *
         * @param string[] $proxies IPv4/IPv6 addresses and CIDR ranges.
         */
        $proxies = self::sanitize(apply_filters('wpmcp_trusted_proxies', []));
        if (! $proxies || ! self::contains($proxies, $remote)) {
            return self::$client = $remote;
        }

        $header = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? (string) wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $client = $remote;
        $hops   = '' === trim($header) ? [] : array_reverse(explode(',', $header));
        foreach ($hops as $hop) {
            $hop = trim($hop);
            if (null === self::pack($hop)) {
                return self::$client = null;
            }
            $client = $hop;
            if (! self::contains($proxies, $hop)) {
                break;
            }
        }

        return self::$client = $client;
    }

    /**
     * Whether $identity (a stored record) refuses this request. False when
     * the record has no allowed_ips or the request came through WP-CLI.
     */
    public static function refuses(array $identity): bool
    {
        $list = isset($identity['allowed_ips']) && is_array($identity['allowed_ips']) ? $identity['allowed_ips'] : [];
        if (! $list || self::is_cli()) {
            return false;
        }

        $ip = self::client_ip();
        if (null !== $ip && self::contains($list, $ip)) {
            return false;
        }

        self::audit((string) ($identity['name'] ?? ''), $ip);
        return true;
    }

    /**
     * rest_pre_dispatch: a request to the MCP route acting as a pinned
     * identity from another address gets a generic 403 before the adapter
     * sees it. Any other route, and any request with no active identity, is
     * left alone.
     *
     * @param mixed            $result  An earlier filter's response, if any.
     * @param mixed            $server  The REST server.
     * @param \WP_REST_Request $request The request.
     * @return mixed
     */
    public static function filter_pre_dispatch($result, $server, $request)
    {
        if (null !== $result || ! $request instanceof \WP_REST_Request) {
            return $result;
        }
        if (! Transport_Guard::is_mcp_route((string) $request->get_route())) {
            return $result;
        }

        $name = Identity_Context::current();
        if (null === $name) {
            return $result;
        }
        $identity = Identity_Store::get($name);
        if (null === $identity || ! self::refuses($identity)) {
            return $result;
        }

        return new \WP_Error('rest_forbidden', __('Sorry, you are not allowed to do that.', 'wpmcp'), ['status' => 403]);
    }

    public static function set_cli_for_tests(?bool $cli): void
    {
        self::$cli_override = $cli;
    }

    /** Forget the per-request client address, audit memo and CLI override. */
    public static function reset_for_tests(): void
    {
        self::$cli_override = null;
        self::$audited      = [];
        self::$client       = false;
    }

    private static function is_cli(): bool
    {
        if (null !== self::$cli_override) {
            return self::$cli_override;
        }
        return defined('WP_CLI') && WP_CLI;
    }

    private static function audit(string $identity, ?string $ip): void
    {
        if (isset(self::$audited[ $identity ])) {
            return;
        }
        self::$audited[ $identity ] = true;

        try {
            Governance_Audit_Log::record(self::AUDIT_EVENT, '' === $identity ? 'none' : $identity, false, 'client:' . ($ip ?? 'unknown'));
        } catch (\Throwable $e) {
            // Auditing must never break the refusal it is observing.
        }
    }

    /** The canonical text of an entry ("addr" or "addr/bits"), or null when invalid. */
    private static function canonical(string $entry): ?string
    {
        $entry = trim($entry);
        $range = self::range($entry);
        if (null === $range) {
            return null;
        }

        $parts = explode('/', $entry, 2);
        $addr  = (string) inet_ntop((string) inet_pton($parts[0]));

        return isset($parts[1]) ? $addr . '/' . (int) $parts[1] : $addr;
    }

    /**
     * An entry as [packed network, prefix bits] in the family contains()
     * compares in (IPv4-mapped IPv6 folds to IPv4), or null when invalid.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function range(string $entry): ?array
    {
        $parts = explode('/', $entry, 2);
        if (false === filter_var($parts[0], FILTER_VALIDATE_IP)) {
            return null;
        }

        $raw = (string) inet_pton($parts[0]);
        $max = strlen($raw) * 8;
        if (! isset($parts[1])) {
            $bits = $max;
        } elseif ('' !== $parts[1] && ctype_digit($parts[1]) && (int) $parts[1] <= $max) {
            $bits = (int) $parts[1];
        } else {
            return null;
        }

        if (16 === strlen($raw) && self::is_mapped($raw)) {
            return $bits >= 96 ? [substr($raw, 12), $bits - 96] : [$raw, $bits];
        }

        return [$raw, $bits];
    }

    /** A valid address as packed bytes (IPv4-mapped IPv6 folds to IPv4), or null. */
    private static function pack(string $ip): ?string
    {
        if ('' === $ip || false === filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        $raw = (string) inet_pton($ip);

        return 16 === strlen($raw) && self::is_mapped($raw) ? substr($raw, 12) : $raw;
    }

    private static function is_mapped(string $raw): bool
    {
        return str_repeat("\0", 10) . "\xff\xff" === substr($raw, 0, 12);
    }

    private static function in_range(string $ip, string $network, int $bits): bool
    {
        if (strlen($ip) !== strlen($network)) {
            return false;
        }

        $whole = intdiv($bits, 8);
        if (substr($ip, 0, $whole) !== substr($network, 0, $whole)) {
            return false;
        }

        $rest = $bits % 8;
        if (0 === $rest) {
            return true;
        }

        $mask = (0xff << (8 - $rest)) & 0xff;

        return (ord($ip[ $whole ]) & $mask) === (ord($network[ $whole ]) & $mask);
    }
}
