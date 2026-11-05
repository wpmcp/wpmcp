<?php

namespace WPMCP\Tools\Cli;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The WP-Cron executor for a queued CLI job (issue #84), mirroring
 * Run_Backup_Job's shape: Dispatch_Cli_Job schedules a single event on
 * self::HOOK with the job id as its only argument, and Plugin::boot() hooks
 * self::HOOK to [new Run_Cli_Job(), 'handle'] so WordPress invokes it on the
 * next cron run.
 *
 * Three things this does that a fire-and-forget detached spawn cannot:
 *
 *  1. RE-GUARDS before executing. The stored argv is re-run through the full
 *     Wp_Cli_Guard_Chain here, not trusted because it passed at dispatch
 *     time. Between queueing and this run the operator may have closed the
 *     wpmcp_allow_wp_cli gate, the environment may now report production, or
 *     the allowlist may have been narrowed. Revoking permission has to stop
 *     already-queued work, otherwise the queue is a way to outlive the gate.
 *     A now-refused job is recorded as failed with the guard's own message.
 *  2. Captures output under a hard size cap, so a chatty command cannot
 *     inflate an option row (and the poll response) without bound.
 *  3. Records a denied/allowed audit entry for the actual execution, not
 *     just for the dispatch, following the run-wp-cli convention: ability
 *     name, identity, and outcome only, never the command.
 *
 * A job that is not in 'queued' status when this fires is left alone: it was
 * canceled, already ran, or is a duplicate cron delivery, and re-running a
 * command because WP-Cron fired twice would be worse than skipping. An
 * unknown job id is a silent no-op for the same reason as Run_Backup_Job:
 * there is no record to flip and cron has no caller to report to.
 *
 * The subprocess run is a constructor-injected callable defaulting to the
 * real Wp_Cli_Executor::run, exactly as in Run_Wp_Cli, so tests exercise the
 * status transitions and output handling without spawning a process.
 */
class Run_Cli_Job
{
    public const HOOK = 'wpmcp_run_cli_job';

    public const ABILITY = 'wpmcp/dispatch-cli-job';

    /** Default per-stream cap on captured output, in bytes. */
    public const DEFAULT_OUTPUT_LIMIT_BYTES = 65536;

    /** @var callable */
    private $executor;

    public function __construct(?callable $executor = null)
    {
        $this->executor = $executor ?? [Wp_Cli_Executor::class, 'run'];
    }

    public function handle(int $job_id): void
    {
        $job = Cli_Job_Store::get($job_id);
        if (null === $job) {
            return;
        }

        if ('queued' !== ($job['status'] ?? '')) {
            return;
        }

        $argv = is_array($job['argv'] ?? null) ? array_values($job['argv']) : [];

        try {
            // The multisite gate asks about the user who queued the job,
            // not the (absent) cron user; a record with no owner fails it.
            Wp_Cli_Guard_Chain::assert_allowed($argv, (int) ($job['user_id'] ?? 0));
            $binary    = Wp_Cli_Guard_Chain::resolve_binary_or_throw();
            $site_args = Wp_Cli_Guard_Chain::site_args();
        } catch (\RuntimeException $e) {
            Wp_Cli_Guard_Chain::audit(self::ABILITY, false);
            Cli_Job_Store::update($job_id, [
                'status' => 'failed',
                'result' => null,
                'error'  => $e->getMessage(),
            ]);
            return;
        }

        Cli_Job_Store::update($job_id, ['status' => 'running']);

        $timeout = (int) ($job['timeout'] ?? Dispatch_Cli_Job::DEFAULT_TIMEOUT_SECONDS);
        $timeout = max(1, min(Dispatch_Cli_Job::MAX_TIMEOUT_SECONDS, $timeout));

        try {
            $result = ($this->executor)(array_merge([$binary], $argv, $site_args), $timeout);
            Wp_Cli_Guard_Chain::audit(self::ABILITY, true);
            Cli_Job_Store::update($job_id, [
                'status' => 'completed',
                'result' => self::normalize_result(is_array($result) ? $result : []),
                'error'  => null,
            ]);
        } catch (\Throwable $e) {
            // An executor throwing is not an expected path (the real one
            // returns an error result rather than throwing), but a job left
            // in 'running' forever with no way to see what went wrong would
            // be worse than an honest 'failed'.
            Cli_Job_Store::update($job_id, [
                'status' => 'failed',
                'result' => null,
                'error'  => $e->getMessage(),
            ]);
        }

        Cli_Job_Store::purge_stale();
    }

    /** Per-stream output cap in bytes. Filterable, floored at 0 (capture nothing). */
    public static function output_limit_bytes(): int
    {
        $limit = (int) apply_filters('wpmcp_cli_job_output_limit', self::DEFAULT_OUTPUT_LIMIT_BYTES);
        return max(0, $limit);
    }

    /**
     * Shape an executor result into the stored 'result' payload, capping
     * each stream independently and reporting the original combined byte
     * count so a caller can tell how much was dropped.
     *
     * @return array{stdout:string, stderr:string, exit_code:int, timed_out:bool, truncated:bool, output_bytes:int}
     */
    private static function normalize_result(array $result): array
    {
        $stdout = (string) ($result['stdout'] ?? '');
        $stderr = (string) ($result['stderr'] ?? '');
        $limit  = self::output_limit_bytes();

        $capped_stdout = self::cap($stdout, $limit);
        $capped_stderr = self::cap($stderr, $limit);

        return [
            'stdout'       => $capped_stdout,
            'stderr'       => $capped_stderr,
            'exit_code'    => (int) ($result['exit_code'] ?? -1),
            'timed_out'    => (bool) ($result['timed_out'] ?? false),
            'truncated'    => $capped_stdout !== $stdout || $capped_stderr !== $stderr,
            'output_bytes' => strlen($stdout) + strlen($stderr),
        ];
    }

    /**
     * Keep the first $limit bytes and append a marker naming how many were
     * dropped. The head is kept rather than the tail because wp-cli reports
     * what it is doing as it goes: the beginning of a long run says what the
     * command actually started doing, and the dropped-byte count tells the
     * caller to re-run narrower rather than guess.
     */
    private static function cap(string $output, int $limit): string
    {
        if (strlen($output) <= $limit) {
            return $output;
        }

        $dropped = strlen($output) - $limit;

        return substr($output, 0, $limit)
            . "\n[wpmcp: output truncated, {$dropped} further byte(s) dropped]";
    }
}
