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

    /** The store's clock, shared with Backup_Schedule. */
    public static function now(): int
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

    /**
     * Create a new job in 'queued' status and persist it. Returns the
     * created job record. $extra adds fields such as the schedule that
     * queued it (Backup_Schedule); it cannot override the core fields.
     */
    public static function create(string $type, string $scope, array $extra = []): array
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
        ] + $extra;

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

    /**
     * All jobs, newest (highest id) first. When $status is given, only jobs
     * whose 'status' matches are returned; an empty string (the default)
     * returns every job regardless of status.
     */
    public static function list(string $status = ''): array
    {
        $stored = self::load();
        $jobs   = array_values($stored['jobs']);

        if ('' !== $status) {
            $jobs = array_values(array_filter(
                $jobs,
                static fn(array $job): bool => $status === $job['status']
            ));
        }

        usort($jobs, static fn(array $a, array $b): int => $b['id'] <=> $a['id']);

        return $jobs;
    }

    /**
     * Merge $fields into the stored job identified by $id, always bumping
     * updated_at to the current clock, and persist it. Returns the updated
     * job record, or null if $id does not exist (nothing is written in that
     * case).
     */
    public static function update(int $id, array $fields): ?array
    {
        $stored = self::load();
        if (! isset($stored['jobs'][ $id ])) {
            return null;
        }

        $job                    = array_merge($stored['jobs'][ $id ], $fields);
        $job['updated_at']      = self::now();
        $stored['jobs'][ $id ]  = $job;
        self::save($stored);

        return $job;
    }
}
