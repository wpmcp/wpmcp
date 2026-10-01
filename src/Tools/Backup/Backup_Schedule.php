<?php

namespace WPMCP\Tools\Backup;

use WPMCP\Governance\Governance_Audit_Log;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Recurring backups with retention (issue #455): trigger-backup's every and
 * keep arguments.
 *
 * A schedule is one recurring WP-Cron event per backup type (self::HOOK,
 * passed the type), using core's daily or weekly recurrence and first
 * firing at the requested site-local time. Each run queues exactly the job
 * trigger-backup queues for that type (same type and scope, same
 * Run_Backup_Job event), with the job marked by the schedule that made it.
 * Only archive types can be scheduled: a content export is a WXR file
 * outside the site-backup directory, which delete-backup-archive (and so
 * retention) cannot reach.
 *
 * Retention runs from Run_Backup_Job once a scheduled job has finished. A
 * completed run deletes that schedule's completed archives beyond keep,
 * oldest first, through Delete_Backup_Archive (its containment check, and
 * the deleted flag it leaves on the job record). A failed run deletes
 * nothing. Manual archives are never candidates, keep is at least 1 so the
 * run that just completed always survives, and the newest complete archive
 * on the site is skipped regardless.
 *
 * Records live in one option: type => { type, scope, every, recurrence,
 * day, time, keep, set_at, set_by, last_job_id, last_run_at, pruned }. The
 * last run's status and error are read live from its job record, so the
 * report cannot drift from the job history. Times come from
 * Backup_Job_Store's injectable clock.
 */
class Backup_Schedule
{
    public const OPTION = 'wpmcp_backup_schedules';
    public const HOOK   = 'wpmcp_run_backup_schedule';

    public const DEFAULT_KEEP = 7;
    public const MAX_KEEP     = 100;

    private const ABILITY      = 'wpmcp/trigger-backup';
    private const DEFAULT_TIME = '03:00';
    private const DEFAULT_DAY  = 'sun';
    private const DAYS         = [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ];
    private const INTERVALS    = [ 'daily' => DAY_IN_SECONDS, 'weekly' => WEEK_IN_SECONDS ];

    /**
     * Set, replace or remove (every = off) the schedule for one backup type.
     *
     * @return array{schedule: array<string,mixed>}|\WP_Error
     */
    public static function set(string $type, string $scope, string $every, ?int $keep)
    {
        if (! current_user_can('manage_options')) {
            Governance_Audit_Log::record_quietly(self::ABILITY, false, 'backup-schedule:refused-capability');
            return new \WP_Error('wpmcp_backup_schedule_forbidden', 'Changing a backup schedule requires the manage_options capability.');
        }

        if (! in_array($type, Run_Backup_Job::ARCHIVE_TYPES, true)) {
            return new \WP_Error(
                'wpmcp_backup_schedule_invalid',
                sprintf('Only %s backups can be scheduled.', implode(', ', Run_Backup_Job::ARCHIVE_TYPES))
            );
        }

        $parsed = self::parse($every);
        if (null === $parsed) {
            return new \WP_Error(
                'wpmcp_backup_schedule_invalid',
                'every must be "off", "daily [HH:MM]" or "weekly [mon-sun] [HH:MM]" (site time).'
            );
        }

        $keep = $keep ?? self::DEFAULT_KEEP;
        if ($keep < 1 || $keep > self::MAX_KEEP) {
            return new \WP_Error('wpmcp_backup_schedule_invalid', sprintf('keep must be between 1 and %d.', self::MAX_KEEP));
        }

        wp_clear_scheduled_hook(self::HOOK, [ $type ]);
        $schedules = self::load();

        if ('off' === $parsed['recurrence']) {
            unset($schedules[ $type ]);
            self::save($schedules);
            Governance_Audit_Log::record_quietly(self::ABILITY, true, 'backup-schedule:' . $type . ':off');
            return [ 'schedule' => [ 'type' => $type, 'every' => 'off' ] ];
        }

        $previous = $schedules[ $type ] ?? [];
        $first    = self::first_run($parsed);
        if (true !== wp_schedule_event($first, $parsed['recurrence'], self::HOOK, [ $type ], true)) {
            return new \WP_Error('wpmcp_backup_schedule_failed', 'WP-Cron refused the schedule.');
        }

        $schedules[ $type ] = [
            'type'        => $type,
            'scope'       => $scope,
            'every'       => $parsed['every'],
            'recurrence'  => $parsed['recurrence'],
            'day'         => $parsed['day'],
            'time'        => $parsed['time'],
            'keep'        => $keep,
            'set_at'      => Backup_Job_Store::now(),
            'set_by'      => get_current_user_id(),
            'last_job_id' => $previous['last_job_id'] ?? null,
            'last_run_at' => $previous['last_run_at'] ?? null,
            'pruned'      => $previous['pruned'] ?? [],
        ];
        self::save($schedules);

        Governance_Audit_Log::record_quietly(self::ABILITY, true, sprintf('backup-schedule:%s:%s:keep=%d', $type, $parsed['every'], $keep));

        return [ 'schedule' => self::view($schedules[ $type ]) ];
    }

    /**
     * Put every stored schedule back on WP-Cron, at its configured day and
     * time (plugin activation, issue #468: deactivation clears the events
     * but keeps this option). A schedule that already has its event is left
     * alone, and a record whose every no longer parses is skipped.
     *
     * @return string[] The types that were rescheduled.
     */
    public static function restore(): array
    {
        $restored = [];
        foreach (self::load() as $type => $schedule) {
            $parsed = self::parse((string) ($schedule['every'] ?? ''));
            if (null === $parsed || 'off' === $parsed['recurrence'] || false !== wp_next_scheduled(self::HOOK, [ $type ])) {
                continue;
            }
            if (true === wp_schedule_event(self::first_run($parsed), $parsed['recurrence'], self::HOOK, [ $type ], true)) {
                $restored[] = (string) $type;
            }
        }

        return $restored;
    }

    /**
     * Parse an every value into its normalized form, or null when invalid.
     *
     * @return array{every:string,recurrence:string,day:?string,time:?string}|null
     */
    public static function parse(string $every): ?array
    {
        $tokens = preg_split('/\s+/', strtolower(trim($every)), -1, PREG_SPLIT_NO_EMPTY);
        $kind   = array_shift($tokens);

        if ('off' === $kind && [] === $tokens) {
            return [ 'every' => 'off', 'recurrence' => 'off', 'day' => null, 'time' => null ];
        }
        if (! isset(self::INTERVALS[ $kind ])) {
            return null;
        }

        $day = null;
        if ('weekly' === $kind) {
            $day = self::DEFAULT_DAY;
            if (isset($tokens[0]) && in_array($tokens[0], self::DAYS, true)) {
                $day = array_shift($tokens);
            }
        }

        $time = self::DEFAULT_TIME;
        if (isset($tokens[0])) {
            if (1 !== preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $tokens[0], $m)) {
                return null;
            }
            $time = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
            array_shift($tokens);
        }
        if ([] !== $tokens) {
            return null;
        }

        return [
            'every'      => implode(' ', array_filter([ $kind, $day, $time ])),
            'recurrence' => $kind,
            'day'        => $day,
            'time'       => $time,
        ];
    }

    /** The next occurrence of the parsed day and time, in the site timezone, after now. */
    private static function first_run(array $parsed): int
    {
        $tz  = wp_timezone();
        $now = (new \DateTimeImmutable('@' . Backup_Job_Store::now()))->setTimezone($tz);

        [ $hour, $minute ] = array_map('intval', explode(':', (string) $parsed['time']));
        $run = $now->setTime($hour, $minute);

        if ('weekly' === $parsed['recurrence']) {
            $target = array_search($parsed['day'], self::DAYS, true) + 1;
            $run    = $run->modify('+' . (($target - (int) $now->format('N') + 7) % 7) . ' days');
            if ($run <= $now) {
                $run = $run->modify('+7 days');
            }
        } elseif ($run <= $now) {
            $run = $run->modify('+1 day');
        }

        return $run->getTimestamp();
    }

    /**
     * The WP-Cron callback: queue this type's backup exactly as
     * trigger-backup does. An event whose schedule was removed by other
     * means (the option cleared) unschedules itself and runs nothing.
     */
    public static function run(string $type): void
    {
        $schedules = self::load();
        if (! isset($schedules[ $type ])) {
            wp_clear_scheduled_hook(self::HOOK, [ $type ]);
            return;
        }

        $schedule = $schedules[ $type ];
        $job      = Backup_Job_Store::create($type, (string) $schedule['scope'], [ 'schedule' => $type ]);
        wp_schedule_single_event(time(), Run_Backup_Job::HOOK, [ $job['id'] ]);

        $schedules[ $type ]['last_job_id'] = $job['id'];
        $schedules[ $type ]['last_run_at'] = Backup_Job_Store::now();
        $schedules[ $type ]['pruned']      = [];
        self::save($schedules);
    }

    /**
     * Called by Run_Backup_Job once a job has finished. A completed
     * scheduled job prunes that schedule's archives beyond keep; anything
     * else (a manual job, a failed run) prunes nothing.
     */
    public static function after_job(int $job_id): void
    {
        $job  = Backup_Job_Store::get($job_id);
        $type = is_array($job) ? (string) ($job['schedule'] ?? '') : '';

        $schedules = self::load();
        if ('' === $type || ! isset($schedules[ $type ]) || 'completed' !== $job['status']) {
            return;
        }

        $pruned = self::prune($type, (int) $schedules[ $type ]['keep']);

        $schedules = self::load();
        if (isset($schedules[ $type ]) && $job_id === (int) ($schedules[ $type ]['last_job_id'] ?? 0)) {
            $schedules[ $type ]['pruned'] = $pruned;
            self::save($schedules);
        }
        if ([] !== $pruned) {
            Governance_Audit_Log::record_quietly(self::ABILITY, true, 'backup-schedule:' . $type . ':pruned=' . implode(',', $pruned));
        }
    }

    /**
     * Delete the type's scheduled archives beyond the newest $keep.
     *
     * @return int[] Pruned job ids, oldest first.
     */
    private static function prune(string $type, int $keep): array
    {
        $keep     = max(1, $keep);
        $complete = array_values(array_filter(
            Backup_Job_Store::list('completed'),
            static fn(array $job): bool => is_array($job['result'] ?? null)
                && '' !== (string) ($job['result']['file'] ?? '')
                && empty($job['result']['deleted'])
        ));
        $newest   = (int) ($complete[0]['id'] ?? 0);

        $scheduled = array_values(array_filter(
            $complete,
            static fn(array $job): bool => $type === ($job['schedule'] ?? null)
        ));

        $pruned = [];
        foreach (array_reverse(array_slice($scheduled, $keep)) as $job) {
            $id = (int) $job['id'];
            if ($id === $newest) {
                continue;
            }
            try {
                (new Delete_Backup_Archive())->handle([ 'job_id' => $id ]);
                $pruned[] = $id;
            } catch (\Throwable $e) {
                // An archive already gone by other means is recorded as
                // deleted so it stops counting against keep; any other
                // failure leaves it for the next run.
                if (! is_file((string) $job['result']['file'])) {
                    $result            = $job['result'];
                    $result['deleted'] = true;
                    Backup_Job_Store::update($id, [ 'result' => $result ]);
                }
            }
        }

        return $pruned;
    }

    /**
     * Every active schedule, for list-backup-jobs.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function report(): array
    {
        return array_values(array_map([ self::class, 'view' ], self::load()));
    }

    /** One schedule as reported: settings, next run, overdue flag and last run. */
    private static function view(array $schedule): array
    {
        $type     = (string) $schedule['type'];
        $next     = wp_next_scheduled(self::HOOK, [ $type ]);
        $interval = self::INTERVALS[ $schedule['recurrence'] ] ?? DAY_IN_SECONDS;
        $since    = (int) ($schedule['last_run_at'] ?? $schedule['set_at'] ?? 0);

        $last = null;
        $job  = null === ($schedule['last_job_id'] ?? null) ? null : Backup_Job_Store::get((int) $schedule['last_job_id']);
        if (null !== $job) {
            $last = [
                'job_id' => (int) $job['id'],
                'status' => (string) $job['status'],
                'at'     => (int) ($schedule['last_run_at'] ?? $job['created_at']),
                'error'  => $job['error'] ?? null,
                'pruned' => array_map('intval', (array) ($schedule['pruned'] ?? [])),
            ];
        }

        return [
            'type'     => $type,
            'scope'    => (string) $schedule['scope'],
            'every'    => (string) $schedule['every'],
            'keep'     => (int) $schedule['keep'],
            'next_run' => false === $next ? null : (int) $next,
            'next_run_local' => false === $next ? null : wp_date('Y-m-d H:i T', (int) $next),
            'overdue'  => Backup_Job_Store::now() - $since > 2 * $interval,
            'last_run' => $last,
        ];
    }

    /** @return array<string, array<string,mixed>> */
    private static function load(): array
    {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? array_filter($stored, 'is_array') : [];
    }

    private static function save(array $schedules): void
    {
        update_option(self::OPTION, $schedules, false);
    }
}
