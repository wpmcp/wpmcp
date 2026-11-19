<?php

namespace WPMCP\Tools\Export\Static_Site;

use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Network_Scope;
use WPMCP\Tools\Backup\Run_Backup_Job;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The WP-Cron executor of a static HTML export job (issue #854). The job is
 * an ordinary record in the backup job store (type 'static'), so
 * get-backup-status, list-backup-jobs, cancel-backup-job and
 * delete-backup-archive work on it unchanged; it runs on its own hook
 * because the backup executor's producers build site archives.
 *
 * Always in steps, the same way a chunked backup is (issue #783): each
 * request works until the step budget is spent (Run_Backup_Job::step_limits,
 * filter wpmcp_backup_step_limits), saves the build and schedules the next
 * step. Before working, a step arms a watchdog event; when the host kills
 * the step, that event finds it silent for STEP_IDLE_SECONDS, takes over
 * and carries on from the last saved state. MAX_STEP_ATTEMPTS killed steps
 * in a row fail the job. A canceled or failed job's next event removes the
 * work folder.
 *
 * Every step re-checks what trigger-backup checked: the user who queued it
 * still has the capability, the opt-in is still on and the site is still
 * licensed. A job that no longer passes is canceled with the reason.
 */
class Run_Static_Export
{
    public const HOOK = 'wpmcp_run_static_export';

    public const JOB_TYPE = 'static';

    /** Seconds a running step may stay silent before it counts as dead. */
    public const STEP_IDLE_SECONDS = 90;

    /** Killed steps in a row before the job is failed. */
    public const MAX_STEP_ATTEMPTS = 3;

    public function handle(int $job_id): void
    {
        $job = Backup_Job_Store::get($job_id);
        if (null === $job || self::JOB_TYPE !== ($job['type'] ?? null)) {
            return;
        }

        $build = is_array($job['build'] ?? null) ? $job['build'] : null;
        $status = (string) ($job['status'] ?? '');

        if (null === $build) {
            if ('queued' !== $status) {
                return;
            }
            $refusal = Static_Export::run_refusal($job);
            if (null !== $refusal) {
                Backup_Job_Store::update($job_id, ['status' => 'canceled', 'error' => $refusal]);
                return;
            }
            $claimed = Backup_Job_Store::transition($job_id, 'queued', [
                'status' => 'running',
                'build'  => ['step_running' => true, 'attempts' => 0, 'work_dir' => ''],
            ]);
            if (null === $claimed) {
                return;
            }
            $this->run_step($job_id, $claimed, false);
            return;
        }

        if ('running' !== $status) {
            // Canceled, or failed by the stale sweep, between steps.
            self::discard($build);
            Backup_Job_Store::update($job_id, [], ['build']);
            wp_clear_scheduled_hook(self::HOOK, [$job_id]);
            return;
        }

        $claim = Backup_Job_Store::claim_step($job_id, self::STEP_IDLE_SECONDS);
        if (null === $claim['job']) {
            if ($claim['busy']) {
                self::arm_watchdog($job_id);
            }
            return;
        }

        $this->run_step($job_id, $claim['job'], $claim['died']);
    }

    private function run_step(int $job_id, array $job, bool $died): void
    {
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
        wp_raise_memory_limit('wpmcp_backup');
        self::arm_watchdog($job_id);

        $build   = (array) $job['build'];
        $builder = new Static_Site_Build();
        $state   = null;

        try {
            $refusal = Static_Export::run_refusal($job);
            if (null !== $refusal) {
                throw new \RuntimeException($refusal);
            }

            if ('' === (string) ($build['work_dir'] ?? '')) {
                $options = Url_Discovery::options((array) ($job['options'] ?? []));
                if (is_wp_error($options)) {
                    throw new \RuntimeException($options->get_error_message());
                }
                $found             = Url_Discovery::discover($options);
                $state             = $builder->start($job_id, $found['urls'], $found['skipped'], $options['follow']);
                $build['work_dir'] = $state['work_dir'];
                $build['zip_file'] = $state['zip_file'];
            } else {
                $state = Static_Site_Build::load((string) $build['work_dir']);
            }

            if ($died && (int) ($build['attempts'] ?? 0) > self::MAX_STEP_ATTEMPTS) {
                throw new \RuntimeException(sprintf(
                    'Stopped: the host ended %d steps of this static export in a row before they finished (at %d pages). Lower the step budget (filter wpmcp_backup_step_limits) or raise max_execution_time for WP-Cron requests, and start the export again.',
                    (int) $build['attempts'],
                    (int) $state['pages_done']
                ));
            }

            $build['step_running'] = true;
            if (null === Backup_Job_Store::save_step($job_id, $build, Static_Site_Build::progress($state))) {
                throw new Static_Export_Stopped();
            }

            $limits   = Run_Backup_Job::step_limits($job);
            $deadline = microtime(true) + max(0.0, (float) $limits['seconds']);
            $state    = $builder->step($state, $deadline, self::heartbeat($job_id, $build));

            $build['step_running'] = false;
            $build['attempts']     = 0;
        } catch (Static_Export_Stopped $e) {
            unset($e);
            self::discard($build);
            Backup_Job_Store::update($job_id, [], ['build']);
            wp_clear_scheduled_hook(self::HOOK, [$job_id]);
            return;
        } catch (\Throwable $e) {
            self::discard($build);
            Backup_Job_Store::finish($job_id, [
                'status'   => 'failed',
                'result'   => null,
                'error'    => $e->getMessage(),
                'progress' => null,
            ], ['build']);
            wp_clear_scheduled_hook(self::HOOK, [$job_id]);
            return;
        }

        if ('done' === $state['phase']) {
            $result   = (array) $state['result'];
            $recorded = Backup_Job_Store::finish($job_id, [
                'status'   => 'completed',
                'result'   => $result,
                'error'    => null,
                'progress' => null,
            ], ['build']);
            Static_Site_Build::discard((string) $build['work_dir']);
            if (null === $recorded) {
                // Canceled while the last step ran: no ZIP is kept.
                if (is_file((string) $result['file'])) {
                    wp_delete_file((string) $result['file']);
                }
                Backup_Job_Store::update($job_id, [], ['build']);
            }
            wp_clear_scheduled_hook(self::HOOK, [$job_id]);
            Static_Export::prune($job_id);
            return;
        }

        if (null === Backup_Job_Store::save_step($job_id, $build, Static_Site_Build::progress($state))) {
            self::discard($build);
            Backup_Job_Store::update($job_id, [], ['build']);
            wp_clear_scheduled_hook(self::HOOK, [$job_id]);
            return;
        }

        // The next step in the next WP-Cron request; replaces the watchdog.
        wp_clear_scheduled_hook(self::HOOK, [$job_id]);
        wp_schedule_single_event(time(), self::HOOK, [$job_id]);
    }

    /**
     * Writes the progress to the job at most every HEARTBEAT_SECONDS, and
     * stops the build once the job is no longer running (canceled).
     *
     * @return callable(array): void
     */
    private static function heartbeat(int $job_id, array $build): callable
    {
        $last = null;

        return static function (array $progress) use ($job_id, $build, &$last): void {
            $now = Backup_Job_Store::now();
            if (null !== $last && $now - $last < Backup_Job_Store::HEARTBEAT_SECONDS) {
                return;
            }
            $last = $now;
            if (null === Backup_Job_Store::save_step($job_id, $build, $progress)) {
                throw new Static_Export_Stopped();
            }
        };
    }

    private static function arm_watchdog(int $job_id): void
    {
        wp_clear_scheduled_hook(self::HOOK, [$job_id]);
        wp_schedule_single_event(time() + self::STEP_IDLE_SECONDS + 10, self::HOOK, [$job_id]);
    }

    private static function discard(array $build): void
    {
        $work_dir = (string) ($build['work_dir'] ?? '');
        if ('' !== $work_dir) {
            Static_Site_Build::discard($work_dir, (string) ($build['zip_file'] ?? ''));
        }
    }

    /** The capability the user who queued a job must still hold. */
    public static function capability(): string
    {
        return Network_Scope::capability();
    }
}
