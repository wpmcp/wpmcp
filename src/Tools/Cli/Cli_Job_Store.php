<?php

namespace WPMCP\Tools\Cli;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * CRUD plus stale-record cleanup over a single wpmcp_cli_jobs option (issue
 * #84), deliberately shaped like Backup_Job_Store so the two async job
 * surfaces read the same way: an array with a 'next_id' sequence counter and
 * a 'jobs' map of job id => job record.
 *
 * A job record is { id, command, argv, timeout, status, created_at,
 * updated_at, result, error } where status is one of
 * queued|running|completed|failed|canceled and result (once the job has run)
 * is { stdout, stderr, exit_code, timed_out, truncated, output_bytes }.
 *
 * Job ids are a deterministic incrementing integer sequence, and timestamps
 * come from an injectable clock (set_clock_for_tests), for the same
 * repeatability reason as Backup_Job_Store: the store and its WP-Cron
 * executor are exercised together, and a random or wall-clock-derived value
 * would make those tests non-deterministic.
 *
 * UNLIKE the governance audit log, a job record DOES retain the command and
 * its captured output: an agent that dispatched a job has to be able to read
 * back what it dispatched and what came out, which is the entire point of
 * the poll tool. That is a deliberate, bounded trade-off rather than an
 * oversight, and it is why purge_stale() exists: records are dropped on a
 * retention TTL and a hard record cap, so captured output (which may include
 * whatever the command printed) does not accumulate in wp_options forever.
 */
class Cli_Job_Store
{
    public const OPTION = 'wpmcp_cli_jobs';

    /** Terminal statuses: nothing further will happen to a job in one of these. */
    public const TERMINAL_STATUSES = ['completed', 'failed', 'canceled'];

    /** Default seconds a terminal job record is kept before purge_stale() drops it. */
    public const DEFAULT_RETENTION_SECONDS = 86400;

    /** Default maximum number of job records retained, oldest terminal jobs trimmed first. */
    public const DEFAULT_MAX_RECORDS = 50;

    /**
     * Grace period added to a job's own timeout before a still-"running"
     * record is treated as abandoned. A cron request that fataled mid-run
     * (OOM, PHP fatal, killed worker) leaves its job stuck in 'running'
     * forever with nothing left to flip it; the grace period keeps a
     * legitimately slow job from being declared dead a second too early.
     */
    public const ABANDONED_GRACE_SECONDS = 60;

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
        $stored['jobs']    = is_array($stored['jobs'] ?? null) ? $stored['jobs'] : [];
        return $stored;
    }

    private static function save(array $stored): void
    {
        update_option(self::OPTION, $stored);
    }

    /** Seconds a terminal job record is retained. Filterable, floored at 0. */
    public static function retention_seconds(): int
    {
        $seconds = (int) apply_filters('wpmcp_cli_job_retention_seconds', self::DEFAULT_RETENTION_SECONDS);
        return max(0, $seconds);
    }

    /** Maximum retained job records. Filterable, floored at 1 so the store never trims to empty. */
    public static function max_records(): int
    {
        $max = (int) apply_filters('wpmcp_cli_job_max_records', self::DEFAULT_MAX_RECORDS);
        return max(1, $max);
    }

    /**
     * Create a new job in 'queued' status and persist it.
     *
     * @param string[] $argv Guarded wp-cli argv WITHOUT the binary.
     *
     * @return array The created job record.
     */
    public static function create(array $argv, int $timeout): array
    {
        $stored = self::load();
        $id     = $stored['next_id'];
        $now    = self::now();

        $job = [
            'id'         => $id,
            'command'    => implode(' ', $argv),
            'argv'       => array_values($argv),
            'timeout'    => $timeout,
            'status'     => 'queued',
            // Who queued it: Run_Cli_Job re-checks this user against the
            // multisite network gate at run time (issue #562).
            'user_id'    => get_current_user_id(),
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

        $job                   = array_merge($stored['jobs'][ $id ], $fields);
        $job['updated_at']     = self::now();
        $stored['jobs'][ $id ] = $job;
        self::save($stored);

        return $job;
    }

    /**
     * Number of jobs that have not reached a terminal status: queued plus
     * running. This is the queue depth dispatch-cli-job caps.
     */
    public static function in_flight_count(): int
    {
        $count = 0;
        foreach (self::load()['jobs'] as $job) {
            if (! in_array($job['status'] ?? '', self::TERMINAL_STATUSES, true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Flip any job still 'running' past its own timeout plus the grace
     * period to 'failed'. Its cron request is gone (a fatal, an OOM kill, a
     * redeployed worker) and nothing else will ever move it, so an honest
     * terminal failure beats a record that polls as "running" indefinitely
     * and an in-flight slot that is never released.
     *
     * Deliberately separate from purge_stale(): this only CORRECTS a status
     * that has become a lie, it never deletes a record, so the read-only
     * poll tools can call it without a read turning into data loss.
     *
     * @return int How many records were corrected.
     */
    public static function reap_abandoned(): int
    {
        $stored = self::load();
        $now    = self::now();
        $reaped = 0;

        foreach ($stored['jobs'] as $id => $job) {
            if ('running' !== ($job['status'] ?? '')) {
                continue;
            }
            $deadline = (int) ($job['updated_at'] ?? 0)
                + (int) ($job['timeout'] ?? 0)
                + self::ABANDONED_GRACE_SECONDS;
            if ($now <= $deadline) {
                continue;
            }

            $job['status']         = 'failed';
            $job['error']          = 'The job was still running past its timeout with no result recorded, so it was marked failed. Its process most likely died with the request that started it.';
            $job['updated_at']     = $now;
            $stored['jobs'][ $id ] = $job;
            $reaped++;
        }

        if ($reaped > 0) {
            self::save($stored);
        }

        return $reaped;
    }

    /**
     * Bound the store, for the write paths (dispatch and post-run). Reaps
     * abandoned records first, then:
     *
     *  1. expired: terminal records older than retention_seconds() are
     *     dropped, which is what keeps captured command output from living
     *     in wp_options forever.
     *  2. trimmed: if more than max_records() remain, the oldest TERMINAL
     *     records are dropped until the cap is met. Queued and running jobs
     *     are never trimmed: an in-flight job's record is the only handle
     *     anyone has on it.
     *
     * Retention of 0 seconds is honored literally (drop every terminal
     * record) rather than treated as "disabled", so the filter cannot be
     * misread as an off switch that silently retains everything.
     *
     * @return array{abandoned:int, expired:int, trimmed:int}
     */
    public static function purge_stale(): array
    {
        $abandoned = self::reap_abandoned();

        $stored    = self::load();
        $now       = self::now();
        $retention = self::retention_seconds();
        $max       = self::max_records();

        $expired = 0;
        foreach ($stored['jobs'] as $id => $job) {
            if (! in_array($job['status'] ?? '', self::TERMINAL_STATUSES, true)) {
                continue;
            }
            if (($now - (int) ($job['updated_at'] ?? 0)) < $retention) {
                continue;
            }
            unset($stored['jobs'][ $id ]);
            $expired++;
        }

        $trimmed = 0;
        if (count($stored['jobs']) > $max) {
            $terminal_ids = [];
            foreach ($stored['jobs'] as $id => $job) {
                if (in_array($job['status'] ?? '', self::TERMINAL_STATUSES, true)) {
                    $terminal_ids[] = (int) $id;
                }
            }
            sort($terminal_ids);

            foreach ($terminal_ids as $id) {
                if (count($stored['jobs']) <= $max) {
                    break;
                }
                unset($stored['jobs'][ $id ]);
                $trimmed++;
            }
        }

        if ($expired > 0 || $trimmed > 0) {
            self::save($stored);
        }

        return [
            'abandoned' => $abandoned,
            'expired'   => $expired,
            'trimmed'   => $trimmed,
        ];
    }
}
