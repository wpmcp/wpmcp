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
 */
class Backup_Job_Store
{
    public const OPTION = 'wpmcp_backup_jobs';

    /**
     * Seconds a job may stay "running" without an update before it is marked
     * failed. Filter: wpmcp_backup_job_stale_after (never below a minute).
     */
    public const STALE_RUNNING_AFTER = 2 * HOUR_IN_SECONDS;

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

    /** Seconds after which a running job counts as dead (filterable, at least 60). */
    public static function stale_after(): int
    {
        return max(MINUTE_IN_SECONDS, (int) apply_filters('wpmcp_backup_job_stale_after', self::STALE_RUNNING_AFTER));
    }

    private static function is_stale(array $job, int $now, int $limit): bool
    {
        return 'running' === ($job['status'] ?? '') && $now - (int) ($job['updated_at'] ?? 0) > $limit;
    }

    /**
     * Mark every job that has been running past the limit as failed. Cheap
     * when there is none: the cached copy is checked first, and only a hit
     * takes the lock and writes.
     */
    private static function fail_stale_jobs(): void
    {
        $now   = self::now();
        $limit = self::stale_after();

        $any = false;
        foreach (self::load()['jobs'] as $job) {
            if (is_array($job) && self::is_stale($job, $now, $limit)) {
                $any = true;
                break;
            }
        }
        if (! $any) {
            return;
        }

        self::locked(static function (array $stored) use ($now, $limit): void {
            $changed = false;
            foreach ($stored['jobs'] as $id => $job) {
                if (! is_array($job) || ! self::is_stale($job, $now, $limit)) {
                    continue;
                }
                $stored['jobs'][ $id ] = array_merge($job, [
                    'status'     => 'failed',
                    'error'      => sprintf(
                        'Marked failed: the job was still running %d minutes after its last update, so its run most likely stopped (a PHP timeout or fatal error). Start the backup again.',
                        intdiv($now - (int) ($job['updated_at'] ?? 0), MINUTE_IN_SECONDS)
                    ),
                    'updated_at' => $now,
                ]);
                $changed = true;
            }
            if ($changed) {
                self::save($stored);
            }
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
