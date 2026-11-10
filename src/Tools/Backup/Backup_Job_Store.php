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
 *
 * Concurrency (issue #607). Each job runs in its own WP-Cron request, so
 * several requests can change this one option at once. Every write (create,
 * update, transition and the stale sweep) therefore runs under a MySQL named
 * lock and starts from the value in the database, not from this request's
 * option cache, which another request's save never reaches. Without both, the
 * later of two saves put back a stale copy of the other job's record, and a
 * job that had written its archive stayed "running" forever.
 *
 * A job still "running" STALE_RUNNING_AFTER seconds after its last update is
 * marked failed the next time the store is read: its cron request died (a PHP
 * timeout or fatal error) and nothing else will ever move it. If that run
 * does finish after all, its own final update still records the archive.
 *
 * A running archive job reports progress through heartbeat() (issue #771):
 * Run_Backup_Job writes the builder's progress (phase, files added, dump
 * bytes, the partial archive's path) at least every HEARTBEAT_SECONDS while
 * the build is alive. A job carrying progress is therefore held to the much
 * shorter STALE_HEARTBEAT_AFTER: one that has not reported in that long is
 * dead (the request hit max_execution_time or the memory limit, which skips
 * every catch block), it is marked failed with the last progress in the
 * reason, and its partial archive is removed. Jobs without progress (the WXR
 * export, records written by older versions) keep the long limit.
 */
class Backup_Job_Store
{
    public const OPTION = 'wpmcp_backup_jobs';

    /**
     * Seconds a job without progress reports may stay "running" without an
     * update before it is marked failed. Filter: wpmcp_backup_job_stale_after
     * (never below a minute; the job record is the second argument).
     */
    public const STALE_RUNNING_AFTER = 2 * HOUR_IN_SECONDS;

    /**
     * Seconds a job that reports progress may go without a heartbeat before
     * it is marked failed. Heartbeats come every HEARTBEAT_SECONDS while the
     * build runs, including during the archive's final write (ZipArchive's
     * progress callback), so ten minutes of silence is a dead request, not a
     * slow one. Same filter as above.
     */
    public const STALE_HEARTBEAT_AFTER = 10 * MINUTE_IN_SECONDS;

    /** Seconds between heartbeat writes while a job is producing its archive. */
    public const HEARTBEAT_SECONDS = 15;

    /** Seconds a write waits for the store lock before going ahead without it. */
    private const LOCK_WAIT = 10;

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

    /**
     * load(), from the database rather than this request's option cache:
     * another cron request may have saved the option since it was cached.
     */
    private static function load_fresh(): array
    {
        wp_cache_delete(self::OPTION, 'options');

        $alloptions = wp_cache_get('alloptions', 'options');
        if (is_array($alloptions) && array_key_exists(self::OPTION, $alloptions)) {
            unset($alloptions[ self::OPTION ]);
            wp_cache_set('alloptions', $alloptions, 'options');
        }

        $notoptions = wp_cache_get('notoptions', 'options');
        if (is_array($notoptions) && array_key_exists(self::OPTION, $notoptions)) {
            unset($notoptions[ self::OPTION ]);
            wp_cache_set('notoptions', $notoptions, 'options');
        }

        return self::load();
    }

    private static function save(array $stored): void
    {
        update_option(self::OPTION, $stored);
    }

    /**
     * The store lock's name: one per site's options table, scoped to this
     * install because GET_LOCK names are server-wide. Public so a test (or a
     * diagnosis) can look at the lock from another connection.
     */
    public static function lock_name(): string
    {
        global $wpdb;

        return 'wpmcp_bjobs_' . substr(md5((defined('DB_NAME') ? DB_NAME : '') . '|' . $wpdb->options), 0, 16);
    }

    /**
     * Run a read, change and write of the option under the store lock, on a
     * fresh copy of it. A lock that cannot be had within LOCK_WAIT seconds
     * (a database without GET_LOCK, or a stuck holder) does not block the
     * write: a backup that has finished must still be recorded, and the
     * fresh read keeps the window small.
     *
     * @template T
     * @param callable(array): T $work receives the freshly loaded option
     * @return T
     */
    private static function locked(callable $work)
    {
        global $wpdb;

        $name = self::lock_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL named lock; no API exists.
        $held = '1' === (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, self::LOCK_WAIT));

        try {
            return $work(self::load_fresh());
        } finally {
            if ($held) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL named lock; no API exists.
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
            }
        }
    }

    /**
     * Seconds after which a running job counts as dead (filterable, at least
     * 60). A job that reports progress gets the heartbeat limit; one that
     * does not (or when no record is given) gets the long one.
     */
    public static function stale_after(array $job = []): int
    {
        $default = is_array($job['progress'] ?? null) ? self::STALE_HEARTBEAT_AFTER : self::STALE_RUNNING_AFTER;

        return max(MINUTE_IN_SECONDS, (int) apply_filters('wpmcp_backup_job_stale_after', $default, $job));
    }

    private static function is_stale(array $job, int $now): bool
    {
        return 'running' === ($job['status'] ?? '') && $now - (int) ($job['updated_at'] ?? 0) > self::stale_after($job);
    }

    /**
     * Mark every job that has been running past its limit as failed, and
     * remove the partial archive its progress names. Cheap when there is
     * none: the cached copy is checked first, and only a hit takes the lock
     * and writes.
     */
    private static function fail_stale_jobs(): void
    {
        $now = self::now();

        $any = false;
        foreach (self::load()['jobs'] as $job) {
            if (is_array($job) && self::is_stale($job, $now)) {
                $any = true;
                break;
            }
        }
        if (! $any) {
            return;
        }

        $failed = self::locked(static function (array $stored) use ($now): array {
            $failed = [];
            foreach ($stored['jobs'] as $id => $job) {
                if (! is_array($job) || ! self::is_stale($job, $now)) {
                    continue;
                }
                $stored['jobs'][ $id ] = array_merge($job, [
                    'status'     => 'failed',
                    'error'      => self::stale_error($job, $now),
                    'updated_at' => $now,
                ]);
                $failed[] = $job;
            }
            if ([] !== $failed) {
                self::save($stored);
            }
            return $failed;
        });

        // Outside the lock: file deletion is slow next to an option write,
        // and the record is already final.
        foreach ($failed as $job) {
            if (is_array($job['progress'] ?? null)) {
                Site_Archive_Builder::discard_partial($job['progress']);
            }
        }
    }

    /** The reason recorded on a job the stale sweep marks failed. */
    private static function stale_error(array $job, int $now): string
    {
        $minutes = intdiv($now - (int) ($job['updated_at'] ?? 0), MINUTE_IN_SECONDS);

        if (! is_array($job['progress'] ?? null)) {
            return sprintf(
                'Marked failed: the job was still running %d minutes after its last update, so its run most likely stopped (a PHP timeout or fatal error). Start the backup again.',
                $minutes
            );
        }

        return sprintf(
            'Marked failed: the job reported no progress for %d minutes (last: %s), so its run stopped (the PHP time or memory limit ended the cron request). The partial archive was removed. Start the backup again; a database-only backup (scope database) stays within one request on most hosts.',
            $minutes,
            Site_Archive_Builder::describe_progress($job['progress'])
        );
    }

    /**
     * Record a running job's progress, bumping updated_at so the stale sweep
     * knows the run is alive. Returns the updated record, or null when the
     * job is no longer running or failed: canceled from another request
     * (cancel-backup-job on a running job, issue #771), completed, or gone.
     * The caller stops the build on null.
     *
     * A job the stale sweep marked failed is put back to running: the
     * heartbeat is proof the sweep was wrong, and the final update would
     * have recorded the archive on it anyway.
     */
    public static function heartbeat(int $id, array $progress): ?array
    {
        return self::locked(static function (array $stored) use ($id, $progress): ?array {
            $current = $stored['jobs'][ $id ] ?? null;
            if (! is_array($current) || ! in_array($current['status'] ?? null, ['running', 'failed'], true)) {
                return null;
            }

            $job = array_merge($current, [
                'status'     => 'running',
                'error'      => null,
                'progress'   => $progress,
                'updated_at' => self::now(),
            ]);

            $stored['jobs'][ $id ] = $job;
            self::save($stored);

            return $job;
        });
    }

    /**
     * Record a run's outcome, unless the job was canceled while it ran: a
     * canceled job keeps that status and null is returned, so the caller
     * knows to discard what it produced. Otherwise update(): a job the stale
     * sweep marked failed still records the archive its run produced.
     */
    public static function finish(int $id, array $fields): ?array
    {
        return self::locked(static function (array $stored) use ($id, $fields): ?array {
            $current = $stored['jobs'][ $id ] ?? null;
            if (! is_array($current) || 'canceled' === ($current['status'] ?? null)) {
                return null;
            }

            $job                   = array_merge($current, $fields);
            $job['updated_at']     = self::now();
            $stored['jobs'][ $id ] = $job;
            self::save($stored);

            return $job;
        });
    }

    /**
     * Create a new job in 'queued' status and persist it. Returns the
     * created job record. $extra adds fields such as the schedule that
     * queued it (Backup_Schedule); it cannot override the core fields.
     */
    public static function create(string $type, string $scope, array $extra = []): array
    {
        return self::locked(static function (array $stored) use ($type, $scope, $extra): array {
            return self::create_in($stored, $type, $scope, $extra);
        });
    }

    /**
     * create(), unless a job of the same type is already queued or running:
     * then nothing is written and that job is returned as 'blocking'. The
     * check and the write happen under the same lock, so two callers cannot
     * both see "none" and both create one. A queued restore (issue #727)
     * uses it so two restores can never be queued side by side.
     *
     * @return array{created: ?array, blocking: ?array}
     */
    public static function create_exclusive(string $type, string $scope, array $extra = []): array
    {
        self::fail_stale_jobs();

        return self::locked(static function (array $stored) use ($type, $scope, $extra): array {
            foreach ($stored['jobs'] as $job) {
                if (is_array($job) && $type === ($job['type'] ?? null) && in_array($job['status'] ?? null, ['queued', 'running'], true)) {
                    return ['created' => null, 'blocking' => $job];
                }
            }

            return ['created' => self::create_in($stored, $type, $scope, $extra), 'blocking' => null];
        });
    }

    /**
     * Write a whole job record back under its own id, keeping next_id past
     * it. A restore replaces the options table this store lives in; when it
     * stops before putting the job history back, the restore job's own
     * record is written back with this so its outcome is not lost.
     */
    public static function put(array $job): array
    {
        return self::locked(static function (array $stored) use ($job): array {
            $id                    = (int) $job['id'];
            $job['updated_at']     = self::now();
            $stored['jobs'][ $id ] = $job;
            $stored['next_id']     = max((int) $stored['next_id'], $id + 1);
            self::save($stored);

            return $job;
        });
    }

    /**
     * Record a finished job for an archive that is on disk but no longer in
     * the store (issue #814): a restore that died after replacing the
     * options table leaves the backup's copy of this store behind, which
     * has never heard of the pre-restore safety archive. The record goes
     * back under $preferred_id when that id is free, so advice that named
     * it stays true and the id is never handed out again; otherwise it gets
     * the next id. One locked step, so no other job can take the id between
     * the check and the write.
     *
     * @param array<string, mixed> $fields The record without id, type, scope or timestamps.
     */
    public static function relist(int $preferred_id, string $type, string $scope, array $fields): array
    {
        return self::locked(static function (array $stored) use ($preferred_id, $type, $scope, $fields): array {
            $id  = $preferred_id > 0 && ! isset($stored['jobs'][ $preferred_id ]) ? $preferred_id : (int) $stored['next_id'];
            $now = self::now();
            $job = ['id' => $id, 'type' => $type, 'scope' => $scope, 'created_at' => $now, 'updated_at' => $now] + $fields;

            $stored['jobs'][ $id ] = $job;
            $stored['next_id']     = max((int) $stored['next_id'], $id + 1);
            self::save($stored);

            return $job;
        });
    }

    private static function create_in(array $stored, string $type, string $scope, array $extra): array
    {
        $id  = $stored['next_id'];
        $now = self::now();

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
        self::fail_stale_jobs();
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
        self::fail_stale_jobs();
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
        return self::locked(static function (array $stored) use ($id, $fields): ?array {
            if (! isset($stored['jobs'][ $id ])) {
                return null;
            }

            $job                   = array_merge($stored['jobs'][ $id ], $fields);
            $job['updated_at']     = self::now();
            $stored['jobs'][ $id ] = $job;
            self::save($stored);

            return $job;
        });
    }

    /**
     * update(), only when the job's status is still $from, checked and
     * written under the lock. Returns the updated record, or null when the
     * job does not exist or has another status (nothing is written then).
     * Run_Backup_Job claims a queued job with it, so two deliveries of the
     * same event cannot both run it.
     */
    public static function transition(int $id, string $from, array $fields): ?array
    {
        return self::locked(static function (array $stored) use ($id, $from, $fields): ?array {
            $current = $stored['jobs'][ $id ] ?? null;
            if (! is_array($current) || $from !== ($current['status'] ?? null)) {
                return null;
            }

            $job                   = array_merge($current, $fields);
            $job['updated_at']     = self::now();
            $stored['jobs'][ $id ] = $job;
            self::save($stored);

            return $job;
        });
    }
}
