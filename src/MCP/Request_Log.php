<?php

namespace WPMCP\MCP;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Capped ring buffer of MCP request outcomes: one row per ability execution,
 * reads included (issue #134). Stored under a single non-autoloaded option,
 * oldest rows evicted once the cap is reached, so a busy site's log can never
 * grow without bound.
 *
 * This is deliberately a third, separate trail:
 *  - Governance\Governance_Audit_Log records permission allow/deny decisions,
 *    which happen BEFORE execution and say nothing about the result.
 *  - Safety\Snapshot_Store records the mutation trail (what changed, and how
 *    to undo it), and reads never appear in it at all.
 *  - This log records what actually happened when the tool ran: ok or error
 *    code, how long it took, which client asked, and the operation_id of the
 *    undo point when the call took a snapshot.
 *
 * Each row is { timestamp, tool, client, ok, error_code, duration_ms,
 * operation_id }, plus 'args' and 'error_message' only while argument capture
 * is switched on.
 *
 * Tool arguments are NOT recorded by default: they routinely carry post
 * bodies, credentials and API keys, and this option is readable by anything
 * that can read options. Capture is opt-in (the wpmcp_request_log_capture_args
 * option or filter) and even then values whose key looks like a secret are
 * replaced with REDACTED, and every value is truncated, so switching debug on
 * cannot dump a credential or a megabyte of post content into wp_options.
 */
class Request_Log
{
    public const OPTION = 'wpmcp_request_log';

    /** Option (and filter) name for the opt-in argument capture debug mode. */
    public const CAPTURE_OPTION = 'wpmcp_request_log_capture_args';

    /** Default rows kept, before the wpmcp_request_log_cap filter. */
    public const CAP = 200;

    /** Longest captured string value; anything longer is cut and suffixed. */
    public const MAX_VALUE_LENGTH = 200;

    /** How deep captured argument arrays are walked before collapsing. */
    public const MAX_DEPTH = 4;

    public const REDACTED = '[redacted]';

    /** Substrings that mark an argument key as secret-bearing. */
    private const SECRET_KEY_PARTS = [
        'pass',
        'secret',
        'token',
        'key',
        'auth',
        'nonce',
        'credential',
        'cookie',
        'signature',
    ];

    private static ?int $clock_override = null;

    /** Test seam, mirroring Governance_Audit_Log: freeze the recorded timestamp. */
    public static function set_clock_for_tests(?int $timestamp): void
    {
        self::$clock_override = $timestamp;
    }

    private static function now(): int
    {
        return self::$clock_override ?? time();
    }

    /** Rows retained, at least 1 (wpmcp_request_log_cap filter). */
    public static function cap(): int
    {
        return max(1, (int) apply_filters('wpmcp_request_log_cap', self::CAP));
    }

    /**
     * Whether argument (and error message) capture is on. Off by default; the
     * option is the admin-facing switch and the filter lets a site force it
     * either way in code.
     */
    public static function is_capturing_arguments(): bool
    {
        $enabled = (bool) get_option(self::CAPTURE_OPTION, false);
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- self::CAPTURE_OPTION is the literal 'wpmcp_request_log_capture_args', a wpmcp_-prefixed hook name.
        return (bool) apply_filters(self::CAPTURE_OPTION, $enabled);
    }

    /**
     * Append one outcome row, evicting the oldest rows once over the cap.
     *
     * @param array<string, mixed> $entry Keys: tool, client, ok, error_code,
     *                                    error_message, duration_ms,
     *                                    operation_id, args.
     */
    public static function record(array $entry): void
    {
        $ok  = ! empty($entry['ok']);
        $row = [
            'timestamp'    => self::now(),
            'tool'         => (string) ($entry['tool'] ?? ''),
            'client'       => (string) ($entry['client'] ?? ''),
            'ok'           => $ok,
            'error_code'   => $ok ? '' : (string) ($entry['error_code'] ?? 'unknown_error'),
            'duration_ms'  => max(0, (int) ($entry['duration_ms'] ?? 0)),
            'operation_id' => (string) ($entry['operation_id'] ?? ''),
        ];

        if (self::is_capturing_arguments()) {
            $row['args'] = self::redact(is_array($entry['args'] ?? null) ? $entry['args'] : []);
            if (! $ok && '' !== (string) ($entry['error_message'] ?? '')) {
                $row['error_message'] = self::truncate((string) $entry['error_message']);
            }
        }

        $rows   = self::load();
        $rows[] = $row;
        $cap    = self::cap();
        if (count($rows) > $cap) {
            $rows = array_slice($rows, -$cap);
        }

        update_option(self::OPTION, $rows, false);
    }

    /**
     * Newest-first rows, limited to $limit.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function list(int $limit = self::CAP): array
    {
        if ($limit <= 0) {
            return [];
        }
        return array_slice(array_reverse(self::load()), 0, $limit);
    }

    public static function clear(): void
    {
        update_option(self::OPTION, [], false);
    }

    /**
     * Copy of $args with secret-looking values replaced, long values cut, and
     * anything below MAX_DEPTH collapsed to a placeholder.
     *
     * @param array<mixed> $args
     * @return array<mixed>
     */
    public static function redact(array $args, int $depth = 0): array
    {
        $out = [];
        foreach ($args as $key => $value) {
            if (self::is_secret_key((string) $key)) {
                $out[ $key ] = self::REDACTED;
                continue;
            }
            if (is_array($value)) {
                $out[ $key ] = $depth + 1 >= self::MAX_DEPTH
                    ? '[array]'
                    : self::redact($value, $depth + 1);
                continue;
            }
            if (is_scalar($value) || null === $value) {
                $out[ $key ] = is_string($value) ? self::truncate($value) : $value;
                continue;
            }
            $out[ $key ] = '[' . gettype($value) . ']';
        }
        return $out;
    }

    private static function is_secret_key(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::SECRET_KEY_PARTS as $part) {
            if (false !== strpos($key, $part)) {
                return true;
            }
        }
        return false;
    }

    private static function truncate(string $value): string
    {
        if (strlen($value) <= self::MAX_VALUE_LENGTH) {
            return $value;
        }
        return substr($value, 0, self::MAX_VALUE_LENGTH) . '...';
    }

    /** @return array<int, array<string, mixed>> */
    private static function load(): array
    {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? array_values(array_filter($stored, 'is_array')) : [];
    }
}
