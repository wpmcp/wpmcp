<?php

namespace WPMCP\Tools\Redirects;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Progress records for background broken-link scans (issue #128).
 *
 * A single wpmcp_broken_link_scans option holding a 'next_id' sequence and a
 * map of scan id => record:
 *   { id, status, post_types, limit, batch_size, offset, scanned, total,
 *     findings, truncated, created_at, updated_at, error }
 * status is one of queued|running|completed|failed|canceled (canceled only
 * when the plugin is deactivated mid-scan, issue #468).
 *
 * Ids are a deterministic incrementing integer and timestamps come from an
 * injectable clock, for exactly the reason Backup_Job_Store documents: the
 * WP-Cron executor and this store are exercised together, and a random or
 * time-derived id would make those tests non-repeatable.
 *
 * Two caps keep an option-backed queue from becoming a liability on a large
 * site: MAX_FINDINGS bounds one scan's payload (past it the record is marked
 * truncated rather than growing without limit), and MAX_SCANS bounds how many
 * finished scans are retained.
 */
class Broken_Link_Scan_Store
{
    public const OPTION = 'wpmcp_broken_link_scans';

    /** Findings retained per scan before the record is marked truncated. */
    public const MAX_FINDINGS = 200;

    /** Scan records retained; the oldest are dropped. */
    public const MAX_SCANS = 10;

    private static ?int $clock_override = null;

    /** Override the clock used for created_at/updated_at. Pass null to restore time(). */
    public static function set_clock_for_tests(?int $timestamp): void
    {
        self::$clock_override = $timestamp;
    }

    private static function now(): int
    {
        return self::$clock_override ?? time();
    }

    private static function load(): array
    {
        $stored = get_option(self::OPTION, []);
        if (! is_array($stored)) {
            $stored = [];
        }
        $stored['next_id'] = (int) ($stored['next_id'] ?? 1);
        $stored['scans']   = is_array($stored['scans'] ?? null) ? $stored['scans'] : [];
        return $stored;
    }

    private static function save(array $stored): void
    {
        update_option(self::OPTION, $stored, false);
    }

    /**
     * @param string[] $post_types
     * @return array<string,mixed> The created, queued scan record.
     */
    public static function create(array $post_types, int $limit, int $batch_size, int $total): array
    {
        $stored = self::load();
        $id     = $stored['next_id'];
        $now    = self::now();

        $scan = [
            'id'         => $id,
            'status'     => 'queued',
            'post_types' => array_values($post_types),
            'limit'      => $limit,
            'batch_size' => $batch_size,
            'offset'     => 0,
            'scanned'    => 0,
            'total'      => $total,
            'findings'   => [],
            'truncated'  => false,
            'error'      => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $stored['scans'][ $id ] = $scan;
        $stored['next_id']      = $id + 1;

        // Keep only the most recent MAX_SCANS records.
        if (count($stored['scans']) > self::MAX_SCANS) {
            ksort($stored['scans']);
            $stored['scans'] = array_slice($stored['scans'], -self::MAX_SCANS, null, true);
        }

        self::save($stored);

        return $scan;
    }

    /** @return array<string,mixed>|null */
    public static function get(int $id): ?array
    {
        $stored = self::load();
        return $stored['scans'][ $id ] ?? null;
    }

    /** @return array<int, array<string,mixed>> Newest (highest id) first. */
    public static function all(): array
    {
        $stored = self::load();
        $scans  = array_values($stored['scans']);
        usort($scans, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);
        return $scans;
    }

    /**
     * Merge $fields into a scan record, always bumping updated_at.
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>|null The updated record, or null if unknown.
     */
    public static function update(int $id, array $fields): ?array
    {
        $stored = self::load();
        if (! isset($stored['scans'][ $id ])) {
            return null;
        }

        $scan                   = array_merge($stored['scans'][ $id ], $fields);
        $scan['updated_at']     = self::now();
        $stored['scans'][ $id ] = $scan;
        self::save($stored);

        return $scan;
    }

    /**
     * Append a batch's findings, honoring MAX_FINDINGS.
     *
     * @param array<int, array<string,mixed>> $findings
     * @return array<string,mixed>|null The updated record, or null if unknown.
     */
    public static function append_findings(int $id, array $findings): ?array
    {
        $scan = self::get($id);
        if (null === $scan) {
            return null;
        }

        $merged   = array_merge((array) $scan['findings'], $findings);
        $truncated = (bool) $scan['truncated'];
        if (count($merged) > self::MAX_FINDINGS) {
            $merged    = array_slice($merged, 0, self::MAX_FINDINGS);
            $truncated = true;
        }

        return self::update($id, ['findings' => $merged, 'truncated' => $truncated]);
    }
}
