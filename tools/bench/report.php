<?php

/**
 * Prints the code mode benchmark table from a results JSON (issue #1130).
 *
 *   php tools/bench/report.php tools/bench/baseline-2026-11-20.json [--markdown]
 *
 * Tokens are bytes divided by the factor recorded in the file (bytes / 4
 * until calibrate-tokens.php has measured one). "total" is tools/list plus
 * every result: what the scenario put in front of the model.
 *
 * REPO-ONLY: plain PHP, no WordPress load.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$file     = $argv[1] ?? '';
$markdown = in_array('--markdown', $argv, true);
$doc      = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
if (! is_array($doc)) {
    fwrite(STDERR, "usage: php tools/bench/report.php RESULTS.json [--markdown]\n");
    exit(2);
}

$factor = (float) ($doc['tokens']['bytes_per_token'] ?? 4.0);
$tok    = static fn(int $bytes): string => number_format((int) round($bytes / $factor));
$num    = static fn(int $n): string => number_format($n);

$head = ['#', 'scenario', 'surface', 'tools/list B', '~tok', 'calls', 'turns', 'result B', '~tok', 'total ~tok', 'ms', 'status'];
$rows = [];
foreach ((array) ($doc['scenarios'] ?? []) as $scenario) {
    foreach (['full', 'compact'] as $surface) {
        $r    = $scenario['surfaces'][ $surface ] ?? null;
        $list = (int) ($doc['tools_list'][ $surface ]['bytes'] ?? 0);
        if (! is_array($r)) {
            continue;
        }
        if ('skipped' === ($r['status'] ?? '')) {
            $rows[] = [(string) $scenario['id'], (string) $scenario['slug'], $surface, $num($list), $tok($list), '-', '-', '-', '-', '-', '-', 'skipped: ' . (string) $r['reason']];
            continue;
        }
        $result = (int) ($r['result_bytes'] ?? 0);
        $status = 'ok' === $r['status'] ? 'ok' : 'FAILED: ' . (string) $r['reason'];
        $rows[] = [
            (string) $scenario['id'],
            (string) $scenario['slug'],
            $surface,
            $num($list),
            $tok($list),
            (string) $r['calls'],
            (string) $r['turns'],
            $num($result),
            $tok($result),
            $tok($list + $result),
            (string) round((float) $r['ms']),
            $status,
        ];
    }
}

printf(
    "%s mode, %s, WordPress %s, wpmcp %s%s\n",
    (string) ($doc['mode'] ?? '?'),
    (string) ($doc['generated_at'] ?? '?'),
    (string) ($doc['wordpress'] ?? '?'),
    (string) ($doc['wpmcp'] ?? '?'),
    '' !== (string) ($doc['commit'] ?? '') ? ' at ' . $doc['commit'] : ''
);
foreach ((array) ($doc['tools_list'] ?? []) as $surface => $list) {
    printf("tools/list %s: %s tools, %s bytes, %s\n", $surface, $num((int) $list['tools']), $num((int) $list['bytes']), (string) $list['source']);
}
printf("tokens: %s\n\n", (string) ($doc['tokens']['method'] ?? 'bytes / 4'));

if ($markdown) {
    echo '| ' . implode(' | ', $head) . " |\n";
    echo '|' . str_repeat(' --- |', count($head)) . "\n";
    foreach ($rows as $row) {
        echo '| ' . implode(' | ', array_map(static fn($c) => str_replace('|', '\\|', $c), $row)) . " |\n";
    }
    exit(0);
}

$widths = array_map('strlen', $head);
foreach ($rows as $row) {
    foreach ($row as $i => $cell) {
        if ($i < count($head) - 1) {
            $widths[ $i ] = max($widths[ $i ], strlen($cell));
        }
    }
}
$line = static function (array $cells) use ($widths): string {
    $out = [];
    foreach ($cells as $i => $cell) {
        $out[] = $i === count($cells) - 1 ? $cell : str_pad($cell, $widths[ $i ], ' ', $i >= 3 ? STR_PAD_LEFT : STR_PAD_RIGHT);
    }
    return implode('  ', $out) . "\n";
};
echo $line($head);
foreach ($rows as $row) {
    echo $line($row);
}
