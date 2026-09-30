<?php

namespace WPMCP\Tools\Media;

use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A background optimize-media run (issue #432): the job store and its
 * WP-Cron executor, on the same pattern as trigger-backup's
 * Backup_Job_Store + Run_Backup_Job and the broken-link scan's one batch
 * per run.
 *
 * optimize-media with background:true creates a job here and schedules a
 * single event on self::HOOK. Each run of the event hands one batch to
 * Optimize_Media::handle() at the job's cursor (so the batch size and time
 * budget that keep a synchronous call short keep every run short too),
 * records the progress, and reschedules itself until the library is done.
 * An interrupted run resumes from its cursor.
 *
 * The job runs as the user who started it, so the per-attachment
 * edit_post check and the snapshots' user are that user's, never a cron
 * request's anonymous one. Every change goes under the job's session id, so
 * one rollback-session undoes the whole run: each batch runs with
 * snapshot pruning held, and while the run is active other writes do not
 * prune either (holds_pruning), so nothing prunes its undo points.
 *
 * Records live in one option, like the backup jobs: { id, user_id, status
 * (queued|running|completed|failed|canceled), args, session_id, progress
 * {done, total, percent}, totals, errors, cursor, created_at, updated_at,
 * error }. Job ids are an incrementing sequence.
 */
class Optimize_Media_Job
{
    public const OPTION = 'wpmcp_optimize_media_jobs';
    public const HOOK   = 'wpmcp_run_optimize_media_job';

    /** Finished job records kept; older ones are dropped as new jobs start. */
    private const KEEP_FINISHED = 20;

    private const ACTIVE = ['queued', 'running'];

    /** Seconds without progress after which a job stops holding pruning. */
    private const STALE_AFTER = HOUR_IN_SECONDS;

    /** @return array{next_id:int,jobs:array<int,array<string,mixed>>} */
    private static function load(): array
    {
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        return [
            'next_id' => max(1, (int) ($stored['next_id'] ?? 1)),
            'jobs'    => is_array($stored['jobs'] ?? null) ? $stored['jobs'] : [],
        ];
    }

    private static function save(array $stored): void
    {
        update_option(self::OPTION, $stored, false);
    }

    /**
     * Queue a run and schedule its first batch.
     *
     * @param array<string,mixed> $args the optimize-media arguments each batch runs with
     */
    public static function create(array $args, int $user_id, int $total): array
    {
        $stored = self::load();
        $id     = $stored['next_id'];
        $now    = time();

        $session = (string) ($args['session_id'] ?? '');
        if ('' === $session || 'default' === $session) {
            $session = 'optimize-media-job-' . $id;
        }
        $args['session_id'] = $session;

        $stored['jobs'][ $id ] = [
            'id'         => $id,
            'user_id'    => $user_id,
            'status'     => 'queued',
            'args'       => $args,
            'session_id' => $session,
            'cursor'     => 0,
            'progress'   => [ 'done' => 0, 'total' => $total, 'percent' => 0 ],
            'totals'     => [ 'before_bytes' => 0, 'after_bytes' => 0, 'saved_bytes' => 0, 'generated_bytes' => 0 ],
            'errors'     => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'error'      => null,
        ];
        $stored['next_id'] = $id + 1;
        $stored['jobs']    = self::trim($stored['jobs']);
        self::save($stored);

        wp_schedule_single_event(time(), self::HOOK, [ $id ]);

        return $stored['jobs'][ $id ];
    }

    /** Drop the oldest finished records beyond KEEP_FINISHED; active ones always stay. */
    private static function trim(array $jobs): array
    {
        krsort($jobs);
        $finished = 0;
        foreach ($jobs as $id => $job) {
            if (! in_array($job['status'] ?? '', self::ACTIVE, true) && ++$finished > self::KEEP_FINISHED) {
                unset($jobs[ $id ]);
            }
        }
        ksort($jobs);
        return $jobs;
    }

    public static function get(int $id): ?array
    {
        return self::load()['jobs'][ $id ] ?? null;
    }

    /** The job still queued or running, if there is one. */
    public static function active(): ?array
    {
        foreach (self::load()['jobs'] as $job) {
            if (in_array($job['status'] ?? '', self::ACTIVE, true)) {
                return $job;
            }
        }
        return null;
    }

    /**
     * wpmcp_snapshot_prune_held callback: hold snapshot pruning while a run
     * is active, so a write elsewhere on the site between two of its cron
     * runs cannot prune the run's first undo points. A job not updated for
     * STALE_AFTER (cron stopped firing) no longer holds, so a stuck record
     * cannot stop pruning for good.
     *
     * @param mixed $held
     */
    public static function holds_pruning($held): bool
    {
        if (true === $held) {
            return true;
        }
        $active = self::active();
        return null !== $active && (time() - (int) $active['updated_at']) < self::STALE_AFTER;
    }

    public static function update(int $id, array $fields): ?array
    {
        $stored = self::load();
        if (! isset($stored['jobs'][ $id ])) {
            return null;
        }
        $stored['jobs'][ $id ]               = array_merge($stored['jobs'][ $id ], $fields);
        $stored['jobs'][ $id ]['updated_at'] = time();
        self::save($stored);
        return $stored['jobs'][ $id ];
    }

    /**
     * The job as a caller sees it, for its owner or a site administrator.
     *
     * @throws \InvalidArgumentException for an unknown job or someone else's
     */
    public static function read(int $id): array
    {
        $job = self::get($id);
        if (null === $job || ! self::visible($job)) {
            // One message for both, so a job id says nothing about who owns it.
            throw new \InvalidArgumentException(sprintf('Unknown optimize-media job: %d', absint($id)));
        }
        unset($job['user_id'], $job['cursor']);
        return $job;
    }

    /** Stop a queued or running job; a running one stops before its next batch. */
    public static function cancel(int $id): array
    {
        self::read($id);
        $job = (array) self::get($id);
        if (in_array($job['status'], self::ACTIVE, true)) {
            wp_clear_scheduled_hook(self::HOOK, [ $id ]);
            self::update($id, [ 'status' => 'canceled' ]);
        }
        return self::read($id);
    }

    private static function visible(array $job): bool
    {
        return (int) ($job['user_id'] ?? 0) === get_current_user_id() || current_user_can('manage_options');
    }

    /** WP-Cron callback: one batch of job $job_id. */
    public function handle(int $job_id): void
    {
        $job = self::get($job_id);
        if (null === $job || ! in_array($job['status'], self::ACTIVE, true)) {
            return;
        }

        $previous_user = get_current_user_id();
        wp_set_current_user((int) $job['user_id']);
        try {
            if (! current_user_can('upload_files')) {
                self::update($job_id, [ 'status' => 'failed', 'error' => 'The user who started this run can no longer upload files.' ]);
                return;
            }
            self::update($job_id, [ 'status' => 'running' ]);
            $this->batch($job);
        } catch (\Throwable $e) {
            self::update($job_id, [ 'status' => 'failed', 'error' => $e->getMessage() ]);
            wp_clear_scheduled_hook(self::HOOK, [ $job_id ]);
        } finally {
            wp_set_current_user($previous_user);
        }
    }

    private function batch(array $job): void
    {
        $id   = (int) $job['id'];
        $args = [ 'cursor' => (int) $job['cursor'] ] + (array) $job['args'];
        $out  = Snapshot_Store::hold_pruning(static fn (): array => (new Optimize_Media())->handle($args));

        if (isset($out['deferred_to'])) {
            self::update($id, [
                'status' => 'failed',
                'error'  => 'Stopped: an image-optimization plugin became active (' . implode(', ', $out['deferred_to']) . '). Start again with force:true to run anyway.',
            ]);
            wp_clear_scheduled_hook(self::HOOK, [ $id ]);
            return;
        }

        // A cancel that landed while this batch ran wins over the progress write.
        $current = self::get($id);
        if (null === $current || 'canceled' === $current['status']) {
            return;
        }

        $totals = (array) $current['totals'];
        foreach (array_keys($totals) as $key) {
            $totals[ $key ] += (int) ($out['totals'][ $key ] ?? 0);
        }
        $done   = (int) $current['progress']['done'] + count($out['items']);
        $total  = max($done, $done + (int) ($out['remaining'] ?? 0));
        $errors = (int) $current['errors'] + count(array_filter($out['items'], static fn ($item) => isset($item['error'])));
        $next   = $out['next_cursor'] ?? null;

        self::update($id, [
            'status'   => null === $next ? 'completed' : 'running',
            'cursor'   => null === $next ? (int) $current['cursor'] : (int) $next,
            'progress' => [ 'done' => $done, 'total' => $total, 'percent' => $total > 0 ? (int) floor($done * 100 / $total) : 100 ],
            'totals'   => $totals,
            'errors'   => $errors,
        ]);

        if (null === $next) {
            wp_clear_scheduled_hook(self::HOOK, [ $id ]);
        } else {
            wp_schedule_single_event(time(), self::HOOK, [ $id ]);
        }
    }
}
