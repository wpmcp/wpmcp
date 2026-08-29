<?php

namespace WPMCP\Tools\Cli;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The ONLY class in this plugin that actually spawns a wp-cli process. Runs
 * a fully-resolved, already-guarded argv (binary + subcommand words) via
 * proc_open with the command given as an ARRAY, never a shell string: PHP's
 * proc_open bypasses the shell entirely when given an array, so there is no
 * shell metacharacter interpretation to worry about here (Wp_Cli_Guard's
 * character check is defense-in-depth on top of this, not the only guard).
 *
 * Enforces a wall-clock timeout, killing the process if it runs over, and
 * always returns stdout/stderr/exit code separately rather than a merged
 * blob. Run_Wp_Cli depends on this only through the injectable callable it
 * accepts (default [self::class, 'run']), so tests can assert the argv that
 * WOULD run without ever actually spawning a process.
 */
class Wp_Cli_Executor
{
    public const DEFAULT_TIMEOUT_SECONDS = 30;

    /**
     * @param string[] $argv Full argv INCLUDING the binary as element 0.
     *
     * @return array{stdout: string, stderr: string, exit_code: int, timed_out: bool}
     */
    public static function run(array $argv, int $timeout_seconds = self::DEFAULT_TIMEOUT_SECONDS): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Array form: proc_open never invokes a shell to parse this, each
        // element is passed to the child process as a literal argv entry.
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- array argv, no shell involved; pro-only file stripped from the wp.org directory build.
        $process = proc_open($argv, $descriptors, $pipes);

        if (! is_resource($process)) {
            return [
                'stdout'    => '',
                'stderr'    => 'Failed to start the wp-cli process.',
                'exit_code' => -1,
                'timed_out' => false,
            ];
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- proc_open pipe handle; WP_Filesystem has no API for process pipes.
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout    = '';
        $stderr    = '';
        $timed_out = false;
        $start     = microtime(true);

        while (true) {
            $status = proc_get_status($process);

            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            if (! $status['running']) {
                break;
            }

            if ((microtime(true) - $start) >= $timeout_seconds) {
                $timed_out = true;
                proc_terminate($process, 9);
                $status = proc_get_status($process);
                self::kill_process_group((int) $status['pid']);
                break;
            }

            usleep(20000);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- proc_open pipe handle; WP_Filesystem has no API for process pipes.
        fclose($pipes[1]);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- proc_open pipe handle; WP_Filesystem has no API for process pipes.
        fclose($pipes[2]);

        $exit_code = $timed_out ? -1 : (int) proc_close($process);
        if ($timed_out) {
            proc_close($process);
        }

        return [
            'stdout'    => $stdout,
            'stderr'    => $timed_out ? ($stderr . "\nProcess timed out after {$timeout_seconds} second(s) and was terminated.") : $stderr,
            'exit_code' => $exit_code,
            'timed_out' => $timed_out,
        ];
    }

    /**
     * Best-effort defense-in-depth (issue #44 security review, M2) against
     * grandchild-process leaks on timeout: proc_terminate() above only
     * signals the direct wp-cli child, so a wp-cli invocation that itself
     * spawns further children (e.g. an `ssh` process from a command using
     * --ssh, or a package subprocess) can otherwise survive the timeout
     * kill and accumulate across repeated calls.
     *
     * This is intentionally conservative rather than a full process-group
     * kill: a proc_open() child on this platform inherits the SAME process
     * group as the calling PHP process when no new session/group was
     * started for it (verified: killing that shared group would kill the
     * PHP process, and whatever spawned it, instead of just the wp-cli
     * subtree). So the group signal is only sent when the child's process
     * group can be positively confirmed to differ from this process's own
     * group; otherwise this is a no-op and the direct-child
     * proc_terminate() above remains the only kill. Also a no-op wherever
     * the posix extension is unavailable (e.g. Windows), which is why the
     * $get_pgid/$kill seams default to null there rather than erroring.
     *
     * @param callable|null $get_pgid Test seam: (int $pid): int|false. Defaults to posix_getpgid().
     * @param callable|null $kill     Test seam: (int $pid, int $signal): bool. Defaults to posix_kill().
     * @param bool          $posix_available Test seam: force posix-unavailable behavior.
     */
    public static function kill_process_group(
        int $pid,
        ?callable $get_pgid = null,
        ?callable $kill = null,
        bool $posix_available = true
    ): void {
        if (! $posix_available || ! function_exists('posix_getpgid') || ! function_exists('posix_kill')) {
            return;
        }

        $get_pgid ??= 'posix_getpgid';
        $kill     ??= 'posix_kill';

        $child_pgid = $get_pgid($pid);
        if (false === $child_pgid || null === $child_pgid) {
            return;
        }

        $own_pgid = $get_pgid(getmypid());
        if (false === $own_pgid || null === $own_pgid) {
            return;
        }

        if ($child_pgid === $own_pgid) {
            return;
        }

        $kill(-$child_pgid, 9);
    }
}
