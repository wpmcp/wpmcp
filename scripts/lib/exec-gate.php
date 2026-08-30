<?php
/**
 * Token-level execution-construct gate shared by the release builds
 * (issue #167).
 *
 * The directory builds physically remove the two execution call sites
 * (eval in Php_Snippet_Runner, proc_open in Wp_Cli_Executor); this gate
 * re-checks the staged tree from scratch so a strip or prune that silently
 * no-ops can never produce a zip. Token-level on purpose: strings and
 * comments (e.g. Malware_Audit's pattern descriptions) must not
 * false-positive, and a method or property that happens to share a name
 * with a listed function is not the global function.
 *
 * Usage:
 *
 *   php scripts/lib/exec-gate.php <path> [<path>...]
 *
 * Each path may be a file or a directory (walked recursively, .php only).
 * Prints every finding to STDERR and exits 1 if any listed construct
 * survives; exits 2 on a path that does not exist, so a build that points
 * the gate at a directory it forgot to stage fails loudly too.
 */

declare(strict_types=1);

const BANNED_FUNCTIONS = [
    'proc_open',
    'shell_exec',
    'passthru',
    'popen',
    'exec',
    'system',
    'pcntl_exec',
    'create_function',
    'str_rot13',
    'move_uploaded_file',
    'assert',
];

$paths = array_slice($argv, 1);
if (array() === $paths) {
    fwrite(STDERR, "usage: exec-gate.php <path> [<path>...]\n");
    exit(2);
}

/**
 * Collect the offending tokens in one file.
 *
 * @return string[] "path:line construct" findings.
 */
function scan_file(string $path): array
{
    $bad    = array();
    $tokens = token_get_all((string) file_get_contents($path));
    foreach ($tokens as $i => $t) {
        if (! is_array($t)) {
            continue;
        }
        if (T_EVAL === $t[0]) {
            $bad[] = $path . ':' . $t[2] . ' eval';
            continue;
        }
        if (T_STRING !== $t[0] || ! in_array(strtolower($t[1]), BANNED_FUNCTIONS, true)) {
            continue;
        }
        // A method, property or declaration of the same name is not the
        // global function.
        $prev = $tokens[$i - 1] ?? null;
        if (is_array($prev) && in_array($prev[0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR), true)) {
            continue;
        }
        $bad[] = $path . ':' . $t[2] . ' ' . $t[1];
    }
    return $bad;
}

$findings = array();
foreach ($paths as $path) {
    if (is_file($path)) {
        $findings = array_merge($findings, scan_file($path));
        continue;
    }
    if (! is_dir($path)) {
        fwrite(STDERR, "exec-gate: no such path: $path\n");
        exit(2);
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ('php' !== $f->getExtension()) {
            continue;
        }
        $findings = array_merge($findings, scan_file($f->getPathname()));
    }
}

if (array() !== $findings) {
    fwrite(STDERR, implode("\n", $findings) . "\n");
    exit(1);
}
