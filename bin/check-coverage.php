#!/usr/bin/env php
<?php
/**
 * Coverage floor gate for CI (issue #55).
 *
 * Reads a clover XML report and fails (exit 1) when statement coverage is
 * below the given floor percentage.
 *
 * Usage: php bin/check-coverage.php <clover.xml> <min-percent>
 *
 * The floor is a ratchet: it starts at the measured coverage at the time it
 * was introduced and should only ever be raised, never lowered, as coverage
 * improves.
 */

$file = $argv[1] ?? 'coverage/clover.xml';
$min  = isset($argv[2]) ? (float) $argv[2] : 0.0;

if (! is_readable($file)) {
    fwrite(STDERR, "check-coverage: clover report not found or unreadable: {$file}\n");
    exit(1);
}

$xml = simplexml_load_file($file);
if (false === $xml || ! isset($xml->project->metrics)) {
    fwrite(STDERR, "check-coverage: could not parse clover report: {$file}\n");
    exit(1);
}

$metrics = $xml->project->metrics;
$covered = (int) $metrics['coveredstatements'];
$total   = (int) $metrics['statements'];
$percent = $total > 0 ? 100.0 * $covered / $total : 0.0;

printf(
    "Statement coverage: %.2f%% (%d of %d statements), configured floor: %.2f%%\n",
    $percent,
    $covered,
    $total,
    $min
);

// Small epsilon so a floor set to exactly the measured value cannot fail on
// float rounding.
if ($percent + 0.0001 < $min) {
    fwrite(STDERR, "check-coverage: FAIL — coverage fell below the floor.\n");
    exit(1);
}

echo "check-coverage: OK\n";
exit(0);
