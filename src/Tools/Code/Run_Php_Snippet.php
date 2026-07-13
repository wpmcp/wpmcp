<?php

namespace WPMCP\Tools\Code;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Context;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The run-php-snippet tool handler (issue #45): evaluates an arbitrary PHP
 * snippet and returns its return value, echoed output, and any thrown
 * error. This is the single most dangerous feature in the plugin: PHP
 * execution is remote code execution by definition, and it CANNOT be
 * sandboxed in-process or made undoable. This class composes every guard,
 * in order, and is the only place that decides whether a snippet reaches
 * the evaluator:
 *
 *  1. Php_Snippet_Guard::is_enabled()             - default OFF
 *  2. Php_Snippet_Guard::is_allowed_on_environment() - fail-closed refusal
 *                                                      of production AND
 *                                                      unknown/empty
 *  3. Php_Snippet_Validator::validate()           - pre-exec static
 *                                                    speed-bump (see below)
 *
 * IMPORTANT, do not soft-pedal this: the Php_Snippet_Validator check is a
 * USABILITY speed-bump, NOT a security boundary. It is the same static
 * pattern-matcher issue #22 built for read-only snippet linting, reused
 * here to catch obviously-dangerous snippets (eval-of-request-input,
 * backtick shell execution, obfuscation decoders, ...) before they run. A
 * determined caller who already has this feature enabled and holds
 * manage_options has full, unrestricted RCE by design: capability plus
 * environment plus enablement is the ONLY real gate. The validator only
 * helps an operator or an AI agent avoid an obviously bad snippet by
 * accident; it does not, and cannot, stop a deliberate attacker who is
 * already an authorized caller.
 *
 * This tool is the ONE explicit escape hatch outside this plugin's
 * snapshot/rollback safety model (Safety\Snapshot_Store, Safe_Mutation,
 * Rollback_Service): a snippet's effects are not captured before it runs
 * and are not undoable afterward, because there is no generic before-image
 * to snapshot for "whatever arbitrary PHP does." The product's "AI
 * physically can't wreck your site" promise holds ONLY because this tool is
 * default-off (Php_Snippet_Guard::is_enabled()), refuses production and any
 * unknown environment, and must be deliberately, explicitly enabled by an
 * operator who accepts that risk. Enabling it grants RCE to anyone who can
 * call it while holding manage_options.
 *
 * Every attempt, allowed or denied, is recorded via Governance_Audit_Log,
 * same as the wp-cli tool's audit trail: only the ability name, active
 * identity, and allow/deny outcome are logged, NEVER the snippet source and
 * NEVER its output, since either could contain secrets (API keys, database
 * credentials, ...) the snippet reads or prints.
 *
 * The actual eval() call is injected as a callable (default:
 * Php_Snippet_Runner::run), so tests can supply a fake that records the
 * code/timeout it was called with and returns a canned result, without ever
 * evaluating anything. This is the seam the guard-behavior tests in
 * tests/free/Code/RunPhpSnippetTest.php exercise; a small separate set of
 * tests (tests/free/Code/PhpSnippetRunnerTest.php) exercises
 * Php_Snippet_Runner directly with the REAL evaluator to prove the happy
 * path and error-capture guarantees.
 */
class Run_Php_Snippet
{
    /** @var callable */
    private $evaluator;

    public function __construct(?callable $evaluator = null)
    {
        $this->evaluator = $evaluator ?? [Php_Snippet_Runner::class, 'run'];
    }

    public function handle(array $args): array
    {
        $code = isset($args['code']) ? (string) $args['code'] : '';
        if ('' === trim($code)) {
            throw new \InvalidArgumentException('A PHP code snippet is required.');
        }

        try {
            $this->guard($code);
        } catch (\RuntimeException $e) {
            $this->audit(false);
            throw $e;
        }

        $result = ($this->evaluator)($code, Php_Snippet_Runner::DEFAULT_TIMEOUT_SECONDS);

        $this->audit(true);

        return [
            'return_value' => $result['return_value'] ?? null,
            'output'       => (string) ($result['output'] ?? ''),
            'error'        => $result['error'] ?? null,
        ];
    }

    /**
     * Run every guard in order, throwing a RuntimeException with the
     * relevant message on the first one that fails. Never calls the
     * evaluator.
     */
    private function guard(string $code): void
    {
        if (! Php_Snippet_Guard::is_enabled()) {
            throw new \RuntimeException(
                'PHP execution is disabled. Enable it with the WPMCP_ALLOW_PHP_EXEC constant or the wpmcp_allow_php_exec filter. This grants remote code execution to any manage_options caller; only enable it on a development or staging environment you control.'
            );
        }

        if (! Php_Snippet_Guard::is_allowed_on_environment()) {
            throw new \RuntimeException(
                'PHP execution is refused on this environment. Production and any unrecognized/unknown environment are refused by default (fail closed); set WPMCP_ALLOW_PHP_EXEC_ON_PRODUCTION or the wpmcp_allow_php_exec_on_production filter to override.'
            );
        }

        $validation = Php_Snippet_Validator::validate($code);
        if (! $validation['safe']) {
            throw new \RuntimeException(
                'The PHP snippet validator flagged this snippet as unsafe and it was rejected before execution. This is a usability speed-bump, not a security boundary: it can be bypassed by whoever is already authorized to call this tool. Review the "warnings" from validate-php-snippet for details.'
            );
        }
    }

    /**
     * Record this attempt to Governance_Audit_Log. Deliberately logs only
     * the ability name, active identity, and allow/deny outcome: NEVER the
     * snippet source and NEVER any output it produced, either of which may
     * contain secrets.
     */
    private function audit(bool $allowed): void
    {
        try {
            $identity = Identity_Context::current() ?? 'none';
            Governance_Audit_Log::record('wpmcp/run-php-snippet', $identity, $allowed);
        } catch (\Throwable $e) {
            // Auditing must never break (or block) the outcome it observes.
        }
    }
}
