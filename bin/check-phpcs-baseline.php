<?php
/**
 * Baseline ratchet for the WordPressCS ruleset (phpcs-wporg.xml.dist).
 *
 * Runs phpcs and compares the per-sniff-code counts against the committed
 * baseline in .phpcs-baseline.json. The build fails when ANY individual sniff
 * code goes up, so the pre-existing violations recorded when the sniff layer
 * was introduced (issue #185) do not block CI, but every new violation does,
 * including one that arrives in the same change that removes an old one.
 *
 * The comparison itself lives in tools/lint/src/Phpcs_Baseline.php so it can
 * be unit tested; see tests/free/Lint/PhpcsBaselineTest.php.
 *
 * Usage: php bin/check-phpcs-baseline.php [--update] [--force]
 *   --update  rewrite .phpcs-baseline.json with the current counts. Refuses to
 *             raise any count, which is what makes this a ratchet rather than
 *             a note; --force overrides and must be justified in review.
 */

declare(strict_types=1);

use WPMCP\Lint\Phpcs_Baseline;

$root          = dirname(__DIR__);
$baseline_file = $root . '/.phpcs-baseline.json';
$phpcs         = $root . '/vendor/bin/phpcs';
$autoload      = $root . '/vendor/autoload.php';

if (! is_file($autoload) || ! is_file($phpcs)) {
    fwrite(STDERR, "vendor/ not installed; run composer install first\n");
    exit(2);
}

require_once $autoload;

if (! class_exists(Phpcs_Baseline::class)) {
    fwrite(STDERR, "WPMCP\\Lint\\Phpcs_Baseline not autoloadable; run composer install (dev) first\n");
    exit(2);
}

$cmd = escapeshellarg($phpcs)
    . ' --standard=' . escapeshellarg($root . '/phpcs-wporg.xml.dist')
    . ' --report=json -q';

// phpcs writes its own failures (unregistered standard, ruleset reference
// errors, PHP fatals) to stderr. Discarding them turns every such failure into
// a bare "could not parse phpcs JSON report", so capture stderr separately and
// print it when parsing fails.
$stderr_file = tempnam(sys_get_temp_dir(), 'phpcs-stderr-');
exec($cmd . ' 2>' . escapeshellarg((string) $stderr_file), $lines, $exit_code);
$stderr = is_string($stderr_file) ? (string) file_get_contents($stderr_file) : '';
if (is_string($stderr_file)) {
    @unlink($stderr_file);
}

$report = json_decode(implode("\n", $lines), true);

if (! is_array($report) || ! isset($report['files'])) {
    fwrite(STDERR, "could not parse phpcs JSON report (exit code $exit_code)\n");
    // phpcs reports a bad ruleset ("Referenced sniff ... does not exist") on
    // stdout in place of the JSON, and fatals on stderr, so print both.
    $diagnostics = trim(implode("\n", $lines) . "\n" . $stderr);
    if ($diagnostics !== '') {
        fwrite(STDERR, "phpcs output:\n" . $diagnostics . "\n");
    }
    exit(2);
}

$current = Phpcs_Baseline::counts_from_report($report);
$force   = in_array('--force', $argv, true);

$existing = null;
if (is_file($baseline_file)) {
    try {
        $existing = Phpcs_Baseline::counts_from_baseline(
            json_decode((string) file_get_contents($baseline_file), true)
        );
    } catch (RuntimeException $e) {
        if (! in_array('--update', $argv, true)) {
            fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
            exit(2);
        }
    }
}

if (in_array('--update', $argv, true)) {
    $verdict = Phpcs_Baseline::guard_update($existing, $current, $force);

    if (! $verdict['allowed']) {
        fwrite(STDERR, "refusing to raise the baseline; the ratchet only goes down.\n");
        foreach ($verdict['regressions'] as $code => $delta) {
            fwrite(STDERR, sprintf("  %s: %d -> %d\n", $code, $delta['baseline'], $delta['current']));
        }
        fwrite(STDERR, "Fix the new violations, or pass --force and justify it in review.\n");
        exit(1);
    }

    file_put_contents($baseline_file, Phpcs_Baseline::encode($current));
    printf("baseline updated: %d violations across %d sniff codes\n", array_sum($current), count($current));
    exit(0);
}

if ($existing === null) {
    fwrite(STDERR, ".phpcs-baseline.json missing; run with --update to record one\n");
    exit(2);
}

$result = Phpcs_Baseline::compare($existing, $current);

printf(
    "wpcs sniffs: %d violations across %d codes (baseline %d across %d)\n",
    $result['total'],
    count($current),
    $result['baseline_total'],
    count($existing)
);

if (! $result['ok']) {
    fwrite(STDERR, "FAIL: new WordPressCS violations above the baseline.\n");
    foreach ($result['regressions'] as $code => $delta) {
        fwrite(STDERR, sprintf("  %s: %d -> %d\n", $code, $delta['baseline'], $delta['current']));
    }
    fwrite(STDERR, "Run vendor/bin/phpcs --standard=phpcs-wporg.xml.dist to see them.\n");
    exit(1);
}

if ($result['improvements'] !== []) {
    echo "counts dropped for " . count($result['improvements']) . " sniff code(s); "
        . "ratchet the baseline down with composer lint:wpcs:update-baseline\n";
}

echo "OK\n";
exit(0);
