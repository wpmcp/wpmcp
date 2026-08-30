<?php

declare(strict_types=1);

namespace WPMCP\Lint;

use RuntimeException;

/**
 * The comparison half of the WordPressCS ratchet (issue #185), kept out of
 * bin/check-phpcs-baseline.php so it can be unit tested without shelling out
 * to phpcs.
 *
 * The baseline is keyed per sniff code, not by two aggregate totals. Totals
 * cannot express "new violations fail the build": a change that fixes one
 * escaping error and adds one unprepared query nets zero, and deleting a file
 * carrying ten findings buys ten units of headroom for brand new ones. Every
 * code is therefore compared on its own and any rise fails.
 */
final class Phpcs_Baseline
{
    /**
     * Collapse a phpcs --report=json payload into a sorted map of sniff code
     * to occurrence count.
     *
     * @param array<string, mixed> $report
     * @return array<string, int>
     */
    public static function counts_from_report(array $report): array
    {
        $counts = [];

        foreach (($report['files'] ?? []) as $file) {
            foreach (($file['messages'] ?? []) as $message) {
                $source = (string) ($message['source'] ?? '');
                if ($source === '') {
                    $source = 'Unknown.Sniff.Code';
                }
                $counts[$source] = ($counts[$source] ?? 0) + 1;
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * Read the committed baseline file's decoded contents.
     *
     * @param mixed $decoded
     * @return array<string, int>
     */
    public static function counts_from_baseline($decoded): array
    {
        if (! is_array($decoded) || ! isset($decoded['sniffs']) || ! is_array($decoded['sniffs'])) {
            throw new RuntimeException(
                '.phpcs-baseline.json is not in the sniff-keyed format; regenerate it with '
                . 'composer lint:wpcs:update-baseline'
            );
        }

        $counts = [];
        foreach ($decoded['sniffs'] as $code => $count) {
            $counts[(string) $code] = (int) $count;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * Serialize a sniff map for the committed baseline file.
     *
     * @param array<string, int> $counts
     */
    public static function encode(array $counts): string
    {
        ksort($counts);

        return json_encode(
            ['sniffs' => (object) $counts],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ) . "\n";
    }

    /**
     * Compare current counts against the baseline.
     *
     * @param array<string, int> $baseline
     * @param array<string, int> $current
     * @return array{ok: bool, regressions: array<string, array{baseline: int, current: int}>, improvements: array<string, array{baseline: int, current: int}>, total: int, baseline_total: int}
     */
    public static function compare(array $baseline, array $current): array
    {
        $regressions  = [];
        $improvements = [];

        foreach (array_keys($baseline + $current) as $code) {
            $was = (int) ($baseline[$code] ?? 0);
            $now = (int) ($current[$code] ?? 0);

            if ($now > $was) {
                $regressions[$code] = ['baseline' => $was, 'current' => $now];
            } elseif ($now < $was) {
                $improvements[$code] = ['baseline' => $was, 'current' => $now];
            }
        }

        ksort($regressions);
        ksort($improvements);

        return [
            'ok'             => $regressions === [],
            'regressions'    => $regressions,
            'improvements'   => $improvements,
            'total'          => array_sum($current),
            'baseline_total' => array_sum($baseline),
        ];
    }

    /**
     * Decide whether --update may overwrite the committed baseline. Writing a
     * higher count turns a red build green, so it needs an explicit --force.
     *
     * @param array<string, int>|null $existing null when no baseline is committed yet
     * @param array<string, int>      $current
     * @return array{allowed: bool, regressions: array<string, array{baseline: int, current: int}>}
     */
    public static function guard_update(?array $existing, array $current, bool $force): array
    {
        if ($existing === null) {
            return ['allowed' => true, 'regressions' => []];
        }

        $comparison = self::compare($existing, $current);

        return [
            'allowed'     => $force || $comparison['ok'],
            'regressions' => $comparison['regressions'],
        ];
    }
}
