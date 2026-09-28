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
 * Each row is { timestamp, tool, client, user_id, ok, error_code,
 * duration_ms, operation_id }, plus 'args' and 'error_message' only while
 * argument capture is switched on.
 *
 * query() filters rows by date, user, tool and outcome, and to_csv() exports
 * them for review (issue #303) with secrets redacted a second time, so rows
 * captured under an older redaction rule cannot leak through an export.
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
     *                                    operation_id, args, secret_args
     *                                    (argument names to redact whatever
     *                                    their key looks like).
     */
    public static function record(array $entry): void
    {
        $ok  = ! empty($entry['ok']);
        $row = [
            'timestamp'    => self::now(),
            'tool'         => (string) ($entry['tool'] ?? ''),
            'client'       => (string) ($entry['client'] ?? ''),
            'user_id'      => get_current_user_id(),
            'ok'           => $ok,
            'error_code'   => $ok ? '' : (string) ($entry['error_code'] ?? 'unknown_error'),
            'duration_ms'  => max(0, (int) ($entry['duration_ms'] ?? 0)),
            'operation_id' => (string) ($entry['operation_id'] ?? ''),
        ];

        if (self::is_capturing_arguments()) {
            $args = is_array($entry['args'] ?? null) ? $entry['args'] : [];
            foreach ((array) ($entry['secret_args'] ?? []) as $secret) {
                if (array_key_exists((string) $secret, $args)) {
                    $args[ (string) $secret ] = self::REDACTED;
                }
            }
            $row['args'] = self::redact($args);
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

    /**
     * Newest-first rows matching every given filter, limited to $limit.
     * Filters: date_from and date_to (Y-m-d, UTC, both days inclusive),
     * user_id, tool (case-insensitive substring) and outcome ('ok' or
     * 'error'). A malformed filter value is ignored rather than matching
     * nothing.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function query(array $filters, int $limit = self::CAP): array
    {
        $from    = self::day_start($filters['date_from'] ?? '');
        $to      = self::day_start($filters['date_to'] ?? '');
        $to      = null === $to ? null : $to + DAY_IN_SECONDS - 1;
        $user_id = is_numeric($filters['user_id'] ?? '') ? (int) $filters['user_id'] : 0;
        $tool    = strtolower(trim((string) ($filters['tool'] ?? '')));
        $outcome = in_array($filters['outcome'] ?? '', [ 'ok', 'error' ], true) ? $filters['outcome'] : '';

        $out = [];
        foreach (self::list(PHP_INT_MAX) as $row) {
            $time = (int) ($row['timestamp'] ?? 0);
            if ((null !== $from && $time < $from) || (null !== $to && $time > $to)) {
                continue;
            }
            if ($user_id > 0 && ! self::row_is_user($row, $user_id)) {
                continue;
            }
            if ('' !== $tool && false === strpos(strtolower((string) ($row['tool'] ?? '')), $tool)) {
                continue;
            }
            if ('' !== $outcome && ('ok' === $outcome) !== ! empty($row['ok'])) {
                continue;
            }
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Top-level argument names an input schema marks secret (writeOnly, or a
     * password format), redacted on capture whatever their key looks like.
     *
     * @param array<string, mixed> $schema
     * @return string[]
     */
    public static function secret_fields(array $schema): array
    {
        $out = [];
        foreach ((array) ($schema['properties'] ?? []) as $name => $property) {
            $property = (array) $property;
            if (! empty($property['writeOnly']) || 'password' === ($property['format'] ?? '')) {
                $out[] = (string) $name;
            }
        }
        return $out;
    }

    /**
     * CSV of $rows for handing to a reviewer: argument keys that look secret
     * and token-shaped values (bearer tokens, JWTs, application passwords,
     * long opaque strings) are redacted, and a cell that a spreadsheet would
     * run as a formula is prefixed with a quote.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public static function to_csv(array $rows): string
    {
        $lines = [ [ 'time_utc', 'tool', 'user_id', 'client', 'outcome', 'error_code', 'error_message', 'duration_ms', 'operation_id', 'args' ] ];
        foreach ($rows as $row) {
            $args    = is_array($row['args'] ?? null) ? self::scrub(self::redact($row['args'])) : null;
            $lines[] = [
                gmdate('Y-m-d H:i:s', (int) ($row['timestamp'] ?? 0)),
                (string) ($row['tool'] ?? ''),
                isset($row['user_id']) ? (string) (int) $row['user_id'] : '',
                (string) ($row['client'] ?? ''),
                empty($row['ok']) ? 'error' : 'ok',
                (string) ($row['error_code'] ?? ''),
                self::scrub_tokens((string) ($row['error_message'] ?? '')),
                (string) (int) ($row['duration_ms'] ?? 0),
                (string) ($row['operation_id'] ?? ''),
                null === $args ? '' : (string) wp_json_encode($args),
            ];
        }
        $csv = '';
        foreach ($lines as $line) {
            $csv .= implode(',', array_map(static fn (string $cell): string => self::csv_cell($cell), $line)) . "\r\n";
        }
        return $csv;
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

    /** A Y-m-d day as its UTC midnight timestamp, or null when malformed. */
    private static function day_start($day): ?int
    {
        if (! is_string($day) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return null;
        }
        $time = strtotime($day . ' 00:00:00 UTC');
        return false === $time ? null : $time;
    }

    /** Whether a row was made by $user_id; rows from before user_id was recorded match by client. */
    private static function row_is_user(array $row, int $user_id): bool
    {
        if (isset($row['user_id'])) {
            return (int) $row['user_id'] === $user_id;
        }
        return 'user:' . $user_id === (string) ($row['client'] ?? '');
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function scrub(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[ $key ] = self::scrub($item);
            } elseif (is_string($item)) {
                $value[ $key ] = self::scrub_tokens($item);
            }
        }
        return $value;
    }

    /** $text with token-shaped substrings replaced by REDACTED. */
    private static function scrub_tokens(string $text): string
    {
        $text = (string) preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer ' . self::REDACTED, $text);
        $text = (string) preg_replace('/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*/', self::REDACTED, $text);
        $text = (string) preg_replace('/\b[A-Za-z0-9]{4}(?: [A-Za-z0-9]{4}){5}\b/', self::REDACTED, $text);
        return (string) preg_replace_callback(
            '/(?<![A-Za-z0-9_-])(?=[A-Za-z0-9_-]*\d)(?=[A-Za-z0-9_-]*[A-Za-z])[A-Za-z0-9_-]{32,}/',
            static function (array $m): string {
                $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
                return 1 === preg_match($uuid, $m[0]) ? $m[0] : self::REDACTED;
            },
            $text
        );
    }

    /** One CSV field: formula-leading cells defused, quoted when needed. */
    private static function csv_cell(string $value): string
    {
        if ('' !== $value && false !== strpos("=+-@\t\r", $value[0])) {
            $value = "'" . $value;
        }
        if (1 === preg_match('/[",\r\n]/', $value)) {
            $value = '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
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
