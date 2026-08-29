<?php

namespace WPMCP\Tools\Cli;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The dispatch-cli-job tool handler (issue #84): queue a guarded, allowlisted
 * wp-cli command as a background job and return its id immediately, so a long
 * import or bulk regeneration is not bounded by a single MCP request/response
 * cycle. Run_Cli_Job (a WP-Cron single event) does the actual execution and
 * flips the job's status; Get_Cli_Job / List_Cli_Jobs poll it; Cancel_Cli_Job
 * withdraws a job that has not started.
 *
 * This adds ZERO new privilege surface over the synchronous run-wp-cli tool.
 * The full Wp_Cli_Guard_Chain runs HERE, at dispatch, before a job record is
 * ever created, so a refused command produces the same RuntimeException the
 * synchronous tool would raise instead of a queued job that fails later for
 * reasons the agent has to poll to discover. The chain then runs a SECOND
 * time inside Run_Cli_Job immediately before execution, because the enable
 * filter, the environment, and the allowlist can all change between queueing
 * and the cron run that executes it: a job queued while the gate was open
 * must not run after it closes.
 *
 * Only the guarded argv is persisted, never the raw command string as typed,
 * and the audit entry follows the run-wp-cli convention exactly: ability
 * name, identity, and allow/deny outcome, never the command itself.
 *
 * Two backpressure limits sit on top of the guard chain, because the ability
 * to leave work behind is a resource the synchronous tool simply does not
 * have: a queue-depth cap (max_in_flight()) and, in the store, a retention
 * TTL plus a record cap. The Registrar's per-client rate limiter bounds call
 * frequency; these bound accumulation.
 */
class Dispatch_Cli_Job
{
    public const ABILITY = 'wpmcp/dispatch-cli-job';

    /**
     * Default wall-clock budget for a background job, an order of magnitude
     * above the synchronous tool's 30s: dispatching exists precisely for
     * commands that cannot finish inside a request.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 300;

    /**
     * Hard ceiling on the requested timeout. A caller-supplied value is
     * clamped into [1, MAX] rather than rejected, so a client that asks for
     * an hour gets the longest run this tool is willing to supervise instead
     * of an error, and no caller can pin a worker indefinitely.
     */
    public const MAX_TIMEOUT_SECONDS = 900;

    /**
     * Default cap on jobs that have not reached a terminal status. The
     * Registrar's rate limiter bounds how fast a client can call this tool,
     * but not how much work it can leave pending: WP-Cron runs all due
     * events in one request, sequentially, so an unbounded queue of jobs
     * each carrying a timeout budget is a self-inflicted denial of service
     * on the cron worker. This cap is the backpressure. Filterable via
     * wpmcp_cli_job_max_in_flight.
     */
    public const DEFAULT_MAX_IN_FLIGHT = 5;

    /** Maximum queued-plus-running jobs. Filterable, floored at 1. */
    public static function max_in_flight(): int
    {
        return max(1, (int) apply_filters('wpmcp_cli_job_max_in_flight', self::DEFAULT_MAX_IN_FLIGHT));
    }

    public function handle(array $args): array
    {
        $command = isset($args['command']) ? trim((string) $args['command']) : '';
        if ('' === $command) {
            throw new \InvalidArgumentException('A wp-cli command is required.');
        }

        $subcommand_argv = Wp_Cli_Guard_Chain::split_command($command);

        try {
            Wp_Cli_Guard_Chain::assert_allowed($subcommand_argv);
        } catch (\RuntimeException $e) {
            Wp_Cli_Guard_Chain::audit(self::ABILITY, false);
            throw $e;
        }

        // Bound the store before adding to it, so a site that dispatches
        // steadily cannot grow wpmcp_cli_jobs without limit and never needs
        // a separate cleanup cron to stay healthy.
        Cli_Job_Store::purge_stale();

        $in_flight = Cli_Job_Store::in_flight_count();
        $max       = self::max_in_flight();
        if ($in_flight >= $max) {
            // Counted AFTER the purge above, so a queue held open only by
            // jobs whose workers died is never what blocks a dispatch.
            Wp_Cli_Guard_Chain::audit(self::ABILITY, false);
            throw new \RuntimeException(sprintf(
                'Too many CLI jobs are already in flight (%d of a maximum %d). Wait for them to finish, cancel them with cancel-cli-job, or raise the wpmcp_cli_job_max_in_flight filter.',
                (int) $in_flight,
                (int) $max
            ));
        }

        $job = Cli_Job_Store::create($subcommand_argv, self::resolve_timeout($args));

        wp_schedule_single_event(time(), Run_Cli_Job::HOOK, [$job['id']]);

        Wp_Cli_Guard_Chain::audit(self::ABILITY, true);

        return [
            'job_id'  => $job['id'],
            'status'  => $job['status'],
            'command' => $job['command'],
            'timeout' => $job['timeout'],
        ];
    }

    /** Caller-supplied timeout, clamped into [1, MAX_TIMEOUT_SECONDS]. */
    private static function resolve_timeout(array $args): int
    {
        if (! isset($args['timeout']) || '' === $args['timeout']) {
            return self::DEFAULT_TIMEOUT_SECONDS;
        }

        return max(1, min(self::MAX_TIMEOUT_SECONDS, (int) $args['timeout']));
    }
}
