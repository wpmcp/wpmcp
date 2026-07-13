<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * CRUD over a single wpmcp_backup_jobs option: an array with a 'next_id'
 * sequence counter and a 'jobs' map of job id => job record. A job record is
 * { id, type, scope, status, created_at, updated_at, result, error }, where
 * status is one of queued|running|completed|failed|canceled.
 *
 * Job ids are a deterministic incrementing integer sequence (the option's
 * own 'next_id' counter), never wp_generate_uuid4() or any other source of
 * randomness: WP-Cron's executor and this store are exercised together in
 * tests, and a random/time-derived id would make those tests non-repeatable.
 *
 * Timestamps come from an injectable clock (set_clock_for_tests) rather than
 * time() directly, for the same determinism reason; the default (no
 * override) falls back to time() so production behavior is unaffected.
 */
class Backup_Job_Store
{
    public const OPTION = 'wpmcp_backup_jobs';

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
        $stored['jobs']     = is_array($stored['jobs'] ?? null) ? $stored['jobs'] : [];
        return $stored;
    }

    private static function save(array $stored): void
    {
        update_option(self::OPTION, $stored);
    }

    /** Create a new job in 'queued' status and persist it. Returns the created job record. */
    public static function create(string $type, string $scope): array
    {
        $stored = self::load();
        $id     = $stored['next_id'];
        $now    = self::now();

        $job = [
            'id'         => $id,
            'type'       => $type,
            'scope'      => $scope,
            'status'     => 'queued',
            'created_at' => $now,
            'updated_at' => $now,
            'result'     => null,
            'error'      => null,
        ];

        $stored['jobs'][ $id ] = $job;
        $stored['next_id']     = $id + 1;
        self::save($stored);

        return $job;
    }

    /** Fetch a job by id, or null if it does not exist. */
    public static function get(int $id): ?array
    {
        $stored = self::load();
        return $stored['jobs'][ $id ] ?? null;
    }
}
