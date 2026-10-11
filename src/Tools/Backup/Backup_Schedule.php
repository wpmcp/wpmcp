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
 *
 * Multisite (issue #541): whole-database backups are network
 * administration (issue #525, Network_Scope), but a schedule runs on
 * WP-Cron with no signed-in user, which Network_Scope lets through. So a
 * run checks the schedule's creator (set_by, stored since #455) instead:
 * when that user no longer exists or lacks manage_network_options, or no
 * creator is recorded at all (fail closed), the run queues nothing and
 * the schedule is disabled. A disabled schedule keeps its record with a
 * disabled { reason, at, set_by } entry, its event is removed, the
 * completed archives it made are flagged with a review note (left on disk
 * for a network admin to judge), and the same reason goes into a network
 * option that list-backup-jobs reports on every site of the network, so a
 * network admin sees it without visiting each sub-site. A job such a
 * schedule queued before the upgrade is canceled by Run_Backup_Job
 * (job_refusal()), and plugin activation does not bring the schedule
 * back. A network admin setting the schedule again replaces the record
 * and clears the network entry. Single site is unchanged.
 */
class Backup_Schedule
{
    public const OPTION = 'wpmcp_backup_schedules';
    public const HOOK   = 'wpmcp_run_backup_schedule';

    /** Network option: schedules disabled on multisite, keyed "blog_id:type" (issue #541). */
    public const NETWORK_DISABLED_OPTION = 'wpmcp_disabled_backup_schedules';

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
        if (! current_user_can(Network_Scope::capability())) {
            Governance_Audit_Log::record_quietly(self::ABILITY, false, 'backup-schedule:refused-capability');
            return new \WP_Error('wpmcp_backup_schedule_forbidden', sprintf('Changing a backup schedule requires the %s capability.', Network_Scope::capability()));
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
        self::forget_network_entry($type);

        if ('off' === $parsed['recurrence']) {
            unset($schedules[ $type ]);
            self::save($schedules);
            Governance_Audit_Log::record_quietly(self::ABILITY, true, 'backup-schedule:' . $type . ':off');
            return [ 'schedule' => [ 'type' => $type, 'every' => 'off' ] ];
        }

        $previous = $schedules[ $type ] ?? [];
        unset($previous['disabled']);
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
            if (null !== self::vet((string) $type)) {
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

        if (null !== self::vet($type)) {
            wp_clear_scheduled_hook(self::HOOK, [ $type ]);
            return;
        }

        $schedule = $schedules[ $type ];
        $job      = Backup_Job_Store::create($type, (string) $schedule['scope'], [
            'schedule' => $type,
            'set_by'   => (int) ($schedule['set_by'] ?? 0),
        ]);
        wp_schedule_single_event(time(), Run_Backup_Job::HOOK, [ $job['id'] ]);

        $schedules[ $type ]['last_job_id'] = $job['id'];
        $schedules[ $type ]['last_run_at'] = Backup_Job_Store::now();
        $schedules[ $type ]['pruned']      = [];
        self::save($schedules);
    }

    /**
     * Why a schedule's creator may not run whole-database backups here, or
     * null when they may. Always null on single site. On multisite the
     * creator must exist and hold manage_network_options; a missing
     * creator is refused (fail closed).
     */
    private static function creator_refusal(?int $user_id): ?string
    {
        if (! is_multisite()) {
            return null;
        }

        if (null === $user_id || $user_id <= 0) {
            $who = 'the schedule has no recorded creator';
        } elseif (false === get_userdata($user_id)) {
            $who = sprintf('the schedule was set by user #%d, who no longer exists', $user_id);
        } elseif (! user_can($user_id, Network_Scope::NETWORK_CAPABILITY)) {
            $who = sprintf('the schedule was set by user #%d, who is not a network administrator', $user_id);
        } else {
            return null;
        }

        return sprintf(
            'Disabled on this multisite network: %s. A backup here holds every site\'s tables and the network user table, so a scheduled run needs a creator with %s. Archives it already made are kept and flagged for review; a network administrator can set the schedule again.',
            $who,
            Network_Scope::NETWORK_CAPABILITY
        );
    }

    /**
     * Check the stored schedule for $type, disabling it when its creator
     * may not run it. Returns the reason it may not run (an existing
     * disabled reason included), or null when it may or no schedule exists.
     */
    private static function vet(string $type): ?string
    {
        $schedules = self::load();
        if (! isset($schedules[ $type ])) {
            return null;
        }
        if (is_array($schedules[ $type ]['disabled'] ?? null)) {
            return (string) $schedules[ $type ]['disabled']['reason'];
        }

        $set_by = isset($schedules[ $type ]['set_by']) ? (int) $schedules[ $type ]['set_by'] : null;
        $reason = self::creator_refusal($set_by);
        if (null !== $reason) {
            self::disable($type, $reason);
        }

        return $reason;
    }

    /**
     * Run_Backup_Job's check before it runs a job a schedule queued: the
     * reason to cancel it instead, or null to run it. A manual job, and any
     * job on single site, is never refused here. A job records the creator
     * of its schedule; one queued before that was recorded is judged by the
     * schedule as stored now, and refused when that schedule is gone.
     */
    public static function job_refusal(array $job): ?string
    {
        $type = (string) ($job['schedule'] ?? '');
        if ('' === $type || ! is_multisite()) {
            return null;
        }

        if (array_key_exists('set_by', $job)) {
            $reason = self::creator_refusal((int) $job['set_by']);
            if (null !== $reason) {
                self::vet($type);
            }
            return $reason;
        }

        return isset(self::load()[ $type ]) ? self::vet($type) : self::creator_refusal(null);
    }

    /**
     * Disable a schedule: remove its event, record the reason on it and in
     * the network list, flag the archives it made, and audit log it.
     */
    private static function disable(string $type, string $reason): void
    {
        wp_clear_scheduled_hook(self::HOOK, [ $type ]);

        $schedules = self::load();
        if (! isset($schedules[ $type ])) {
            return;
        }
        $schedule = $schedules[ $type ];
        $set_by   = isset($schedule['set_by']) ? (int) $schedule['set_by'] : null;
        $now      = Backup_Job_Store::now();

        $flagged = [];
        foreach (Backup_Job_Store::list('completed') as $job) {
            if ($type !== ($job['schedule'] ?? null)
                || (int) $job['created_at'] < (int) ($schedule['set_at'] ?? 0)
                || (array_key_exists('set_by', $job) && (int) $job['set_by'] !== (int) $set_by)
                || isset($job['review'])
            ) {
                continue;
            }
            Backup_Job_Store::update((int) $job['id'], [ 'review' => $reason ]);
            $flagged[] = (int) $job['id'];
        }

        $schedules[ $type ]['disabled'] = [ 'reason' => $reason, 'at' => $now, 'set_by' => $set_by ];
        self::save($schedules);

        $network = self::network_entries();
        $network[ get_current_blog_id() . ':' . $type ] = [
            'blog_id' => get_current_blog_id(),
            'type'    => $type,
            'set_by'  => $set_by,
            'reason'  => $reason,
            'at'      => $now,
            'flagged' => $flagged,
        ];
        update_site_option(self::NETWORK_DISABLED_OPTION, $network);

        Governance_Audit_Log::record_quietly(self::ABILITY, false, 'backup-schedule:' . $type . ':disabled-needs-network-admin');
    }

    /** @return array<string, array<string,mixed>> */
    private static function network_entries(): array
    {
        $stored = get_site_option(self::NETWORK_DISABLED_OPTION, []);
        return is_array($stored) ? array_filter($stored, 'is_array') : [];
    }

    private static function forget_network_entry(string $type): void
    {
        if (! is_multisite()) {
            return;
        }
        $network = self::network_entries();
        $key     = get_current_blog_id() . ':' . $type;
        if (isset($network[ $key ])) {
            unset($network[ $key ]);
            update_site_option(self::NETWORK_DISABLED_OPTION, $network);
        }
    }

    /**
     * Every schedule disabled across the network (issue #541), for
     * list-backup-jobs on multisite.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function network_report(): array
    {
        return array_values(self::network_entries());
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
        $disabled = is_array($schedule['disabled'] ?? null) ? $schedule['disabled'] : null;

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
            'overdue'  => null === $disabled && Backup_Job_Store::now() - $since > 2 * $interval,
            'last_run' => $last,
            'disabled' => $disabled,
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
