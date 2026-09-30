<?php

namespace WPMCP\Governance;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Append-only log of governance-decision outcomes, stored under a single
 * wpmcp_governance_audit_log option as a plain list of entries. This is a
 * separate concern from Tools\List_Operations (the Safety\Snapshot_Store
 * mutation trail): this log records every allow/deny permission-check
 * outcome, not mutations, and has no rollback semantics.
 *
 * Each entry is { ability (string), identity (string, 'none' when no
 * identity is active), allowed (bool), timestamp (int), reason (string,
 * empty unless a specific rule produced the outcome, e.g.
 * "memory-block:42", the id of the published project-memory entry that
 * denied the call, issue #131), source (string, the entry point the call
 * came in by, see Call_Source, issue #412) }. Site-wide rows for calls made
 * outside the endpoint also carry duration_ms. Inputs and outputs are never
 * stored.
 *
 * The log is capped at CAP entries (500): once full, the oldest entry is
 * dropped for every new one recorded, so a busy site's option never grows
 * unbounded. 500 was chosen as a reasonable balance between "enough history
 * to audit recent activity" and "small enough that a single option row
 * stays cheap to read/write on every permission check."
 *
 * Timestamps come from an injectable clock (set_clock_for_tests), mirroring
 * Backup_Job_Store, for deterministic tests; production falls back to
 * time().
 */
class Governance_Audit_Log
{
    public const OPTION = 'wpmcp_governance_audit_log';
    public const CAP    = 500;

    private static ?int $clock_override = null;

    public static function set_clock_for_tests(?int $timestamp): void
    {
        self::$clock_override = $timestamp;
    }

    private static function now(): int
    {
        return self::$clock_override ?? time();
    }

    /**
     * Append a new entry, evicting the oldest one if the log is at capacity.
     *
     * $reason is an optional machine-readable marker for WHY a decision came
     * out the way it did, for the cases where the ability name alone does not
     * say. It defaults to '' so every pre-existing call site keeps recording
     * exactly the entry it always did.
     *
     * $extra may override 'source' (default: Call_Source::current()) and add
     * 'duration_ms'; any other key is ignored, so nothing else can reach a
     * row.
     *
     * @param array{source?: string, duration_ms?: int} $extra
     */
    public static function record(string $ability, string $identity, bool $allowed, string $reason = '', array $extra = []): void
    {
        $entries = self::load();

        $entry = [
            'ability'   => $ability,
            'identity'  => $identity,
            'allowed'   => $allowed,
            'timestamp' => self::now(),
            'reason'    => $reason,
            'source'    => isset($extra['source']) ? (string) $extra['source'] : Call_Source::current(),
        ];
        if (isset($extra['duration_ms'])) {
            $entry['duration_ms'] = (int) $extra['duration_ms'];
        }
        $entries[] = $entry;

        if (count($entries) > self::CAP) {
            $entries = array_slice($entries, -self::CAP);
        }

        update_option(self::OPTION, $entries);
    }

    /**
     * record() for tool handlers: fills in the active identity ('none' when
     * there is none) and swallows any failure, because auditing must never
     * break or block the outcome it observes. Handlers that audit their own
     * allow/deny decisions (add-custom-js, run-php-snippet) call this rather
     * than each carrying the same try/catch.
     */
    public static function record_quietly(string $ability, bool $allowed, string $reason = ''): void
    {
        try {
            self::record($ability, \WPMCP\Identity\Identity_Context::current() ?? 'none', $allowed, $reason);
        } catch (\Throwable $e) {
            // Deliberately empty: see the docblock.
            unset($e);
        }
    }

    /**
     * Newest-first entries, limited to $limit (default: the entire log),
     * optionally only those recorded with $source. Rows written before
     * sources existed have none and match no source filter.
     */
    public static function list(int $limit = self::CAP, string $source = ''): array
    {
        $entries = array_reverse(self::load());
        if ('' !== $source) {
            $entries = array_values(array_filter(
                $entries,
                static fn ($entry) => is_array($entry) && $source === ($entry['source'] ?? null)
            ));
        }
        return array_slice($entries, 0, $limit);
    }

    private static function load(): array
    {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? $stored : [];
    }
}
