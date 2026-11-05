<?php

namespace WPMCP\Tools\Cli;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The run-wp-cli tool handler (issue #44): runs a guarded, allowlisted wp-cli
 * subcommand and returns its stdout/stderr/exit code.
 *
 * The guard chain itself lives in Wp_Cli_Guard_Chain::assert_allowed(), which
 * composes every Wp_Cli_Guard check in order. It was extracted there when the
 * background job dispatcher (issue #84) needed the identical chain: the two
 * entry points share one implementation rather than two copies that could
 * drift, and the shared-guard tests in
 * tests/free/Cli/WpCliGuardChainSharedTest.php drive the same refusal matrix
 * through both to prove it.
 *
 * Every attempt, allowed or denied, is recorded via Governance_Audit_Log,
 * same as the ordinary permission-check audit trail (Registrar::record_audit):
 * only the ability name, active identity, and allow/deny outcome are logged,
 * never the command/argv itself, so a wp-cli invocation touching a secret
 * (e.g. `option get some_api_key`) never leaks that secret into the log.
 *
 * The actual subprocess run is injected as a callable (default: the real
 * Wp_Cli_Executor::run), so tests can supply a fake that records the argv it
 * was called with and returns a canned result, without ever spawning a
 * process. This is the seam the guard-behavior tests in
 * tests/free/Cli/RunWpCliTest.php exercise.
 *
 * Full architecture writeup, including exactly what is CI-tested versus
 * production-only (the real proc_open round-trip) and flags left for the
 * adversarial security review: .superpowers/sdd/issue-44-report.md.
 */
class Run_Wp_Cli
{
    public const ABILITY = 'wpmcp/run-wp-cli';

    /** @var callable */
    private $executor;

    public function __construct(?callable $executor = null)
    {
        $this->executor = $executor ?? [Wp_Cli_Executor::class, 'run'];
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
            // assert_allowed() above already confirmed resolve_binary()
            // succeeds; a failure here would mean it changed between calls
            // (e.g. a filter with side effects), so re-resolve defensively
            // rather than assume the earlier answer still holds.
            $binary    = Wp_Cli_Guard_Chain::resolve_binary_or_throw();
            $site_args = Wp_Cli_Guard_Chain::site_args();
        } catch (\RuntimeException $e) {
            $this->audit(false);
            throw $e;
        }

        $full_argv = array_merge([$binary], $subcommand_argv, $site_args);

        $result = ($this->executor)($full_argv, Wp_Cli_Executor::DEFAULT_TIMEOUT_SECONDS);

        $this->audit(true);

        return [
            'stdout'    => (string) ($result['stdout'] ?? ''),
            'stderr'    => (string) ($result['stderr'] ?? ''),
            'exit_code' => (int) ($result['exit_code'] ?? -1),
            'timed_out' => (bool) ($result['timed_out'] ?? false),
        ];
    }

    /**
     * Record this attempt to Governance_Audit_Log via the shared helper:
     * only the ability name, active identity, and allow/deny outcome, never
     * the command string or argv.
     */
    private function audit(bool $allowed): void
    {
        Wp_Cli_Guard_Chain::audit(self::ABILITY, $allowed);
    }
}
