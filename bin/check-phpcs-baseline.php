<?php
/**
 * Baseline ratchet for the WordPressCS ruleset (phpcs-wporg.xml.dist).
 *
 * Runs phpcs and compares the total error/warning counts against the
 * committed baseline in .phpcs-baseline.json. The build fails only when a
 * count goes UP, so the pre-existing violations recorded when the sniff
 * layer was introduced (issue #185) do not block CI, but every new
 * violation does. When counts go down, lower the baseline in the same PR
 * (ratchet: only ever decrease it).
 *
 * Usage: php bin/check-phpcs-baseline.php [--update]
 *   --update  rewrite .phpcs-baseline.json with the current counts
 */

declare(strict_types=1);

$root     = dirname(__DIR__);
$baseline_file = $root . '/.phpcs-baseline.json';
$phpcs    = $root . '/vendor/bin/phpcs';

if (! is_file($phpcs)) {
    fwrite(STDERR, "vendor/bin/phpcs not found; run composer install first\n");
    exit(2);
}

$cmd = escapeshellarg($phpcs) . ' --standard=' . escapeshellarg($root . '/phpcs-wporg.xml.dist') . ' --report=json -q';
exec($cmd . ' 2>/dev/null', $lines, $exit_code);
$report = json_decode(implode("\n", $lines), true);

if (! is_array($report) || ! isset($report['totals'])) {
    fwrite(STDERR, "could not parse phpcs JSON report (exit code $exit_code)\n");
    exit(2);
}

$errors   = (int) $report['totals']['errors'];
$warnings = (int) $report['totals']['warnings'];

if (in_array('--update', $argv, true)) {
    file_put_contents(
        $baseline_file,
        json_encode(['errors' => $errors, 'warnings' => $warnings], JSON_PRETTY_PRINT) . "\n"
    );
    echo "baseline updated: $errors errors, $warnings warnings\n";
    exit(0);
}

if (! is_file($baseline_file)) {
    fwrite(STDERR, ".phpcs-baseline.json missing; run with --update to record one\n");
    exit(2);
}

$baseline = json_decode((string) file_get_contents($baseline_file), true);
$max_errors   = (int) ($baseline['errors'] ?? 0);
$max_warnings = (int) ($baseline['warnings'] ?? 0);

echo "wpcs sniffs: $errors errors (baseline $max_errors), $warnings warnings (baseline $max_warnings)\n";

if ($errors > $max_errors || $warnings > $max_warnings) {
    fwrite(STDERR, "FAIL: new WordPressCS violations above the baseline.\n");
    fwrite(STDERR, "Run vendor/bin/phpcs --standard=phpcs-wporg.xml.dist to see them.\n");
    exit(1);
}

if ($errors < $max_errors || $warnings < $max_warnings) {
    echo "counts dropped below the baseline; ratchet it down with --update\n";
}

echo "OK\n";
exit(0);
