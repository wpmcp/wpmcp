<?php

namespace WPMCP\Tools\Code;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The ONLY class in this plugin that actually eval()s a PHP snippet,
 * mirroring Wp_Cli_Executor being the only class that spawns a wp-cli
 * process. This is real, unsandboxed remote code execution: whatever the
 * snippet does, it does with the full privileges of the PHP process running
 * WordPress. Nothing in this class provides a security boundary; it only
 * bounds the EXECUTION so a snippet's return value, echoed output, and any
 * thrown Throwable are all captured as structured data instead of one of
 * them escaping as an unhandled fatal/500.
 *
 * Run_Php_Snippet depends on this only through the injectable evaluator
 * callable it accepts (default [self::class, 'run']), so guard-behavior
 * tests can supply a fake and never actually eval anything, while a small
 * set of tests exercise this class directly with the REAL evaluator to
 * prove the happy path and the error-capture guarantees.
 */
class Php_Snippet_Runner
{
    public const DEFAULT_TIMEOUT_SECONDS = 30;

    /**
     * Evaluate $code and return its outcome as structured data. Wraps
     * execution in output buffering (captures echoed output separately from
     * the return value) and a wall-clock timeout via set_time_limit(), and
     * catches \Throwable so a fatal error or uncaught exception in the
     * snippet can never escape as an unhandled 500: it is returned in the
     * 'error' key instead.
     *
     * @return array{return_value: mixed, output: string, error: ?string}
     */
    public static function run(string $code, int $timeout_seconds = self::DEFAULT_TIMEOUT_SECONDS): array
    {
        // set_time_limit() only bounds CPU/wall time for the CURRENT PHP
        // process (this request); it cannot interrupt a blocking syscall
        // (e.g. a stuck network read) and has no effect at all when PHP runs
        // with the CLI SAPI or safe mode remnants disable it. This is a
        // best-effort bound, not a hard sandbox: there is no way to sandbox
        // in-process PHP execution.
        set_time_limit($timeout_seconds);

        ob_start();
        $return_value = null;
        $error         = null;

        try {
            $return_value = self::evaluate($code);
        } catch (\Throwable $e) {
            $error = sprintf(
                '%s: %s in %s on line %d',
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );
        }

        $output = ob_get_clean();
        $output = false === $output ? '' : $output;

        return [
            'return_value' => $error === null ? $return_value : null,
            'output'       => $output,
            'error'        => $error,
        ];
    }

    /**
     * Isolated so the eval() call site is a single, greppable location.
     * Deliberately NOT wrapped in a try/catch here: Throwable propagates to
     * run()'s catch block so output buffered before the throw is still
     * captured.
     *
     * @return mixed
     */
    private static function evaluate(string $code)
    {
        return eval($code);
    }
}
