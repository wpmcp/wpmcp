<?php

namespace WPMCP\Tools\Security;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Security & Malware Scanner orchestrator.
 *
 * resolve_checks(), summarize(), and group_by_category() are pure. scan() wires
 * the four audits together (added once the audits exist). Read-only: this class
 * never writes, never mutates, and never executes scanned content.
 */
class Security_Scanner
{
    private const CRITICAL_WEIGHT   = 20;
    private const WARNING_WEIGHT    = 5;
    private const CATEGORY_CRIT_CAP = 60;
    private const TOP_RECS          = 8;

    private const ALL_CHECKS = ['malware', 'integrity', 'hardening', 'software'];

    private Malware_Audit $malware;
    private Integrity_Audit $integrity;
    private Hardening_Audit $hardening;
    private Software_Audit $software;

    public function __construct(
        ?Malware_Audit $malware = null,
        ?Integrity_Audit $integrity = null,
        ?Hardening_Audit $hardening = null,
        ?Software_Audit $software = null
    ) {
        $this->malware   = $malware ?: new Malware_Audit();
        $this->integrity = $integrity ?: new Integrity_Audit();
        $this->hardening = $hardening ?: new Hardening_Audit();
        $this->software  = $software ?: new Software_Audit();
    }

    /**
     * Live: run the requested audits and assemble the scored report.
     *
     * @param array $input { checks?, deep?, max_files?, max_seconds? }
     * @return array { summary, sections, scan_meta, top_recommendations }
     */
    public function scan(array $input): array
    {
        $checks = $this->resolve_checks(
            isset($input['checks']) && is_array($input['checks']) ? $input['checks'] : null
        );
        $deep = ! empty($input['deep']);

        $max_files   = $this->clamp((int) ($input['max_files'] ?? Malware_Audit::MAX_FILES), 1, Malware_Audit::MAX_FILES_CEILING);
        $max_seconds = $this->clamp((int) ($input['max_seconds'] ?? Malware_Audit::TIME_BUDGET), 1, Malware_Audit::TIME_BUDGET_CEILING);

        $started   = microtime(true);
        $findings  = [];
        $scan_meta = [
            'files_scanned'      => 0,
            'files_skipped_size' => 0,
            'truncated'          => false,
            'truncated_reason'   => null,
            'deep'               => $deep,
            'checks_run'         => $checks,
            'integrity_api'      => ['ok' => false, 'error' => 'not_run'],
            'headers_fetch'      => ['ok' => false, 'error' => 'not_run'],
            'elapsed_ms'         => 0,
        ];

        if (in_array('malware', $checks, true)) {
            $malware                         = $this->malware->run($deep, $max_files, $max_seconds);
            $findings                        = array_merge($findings, $malware['findings']);
            $scan_meta['files_scanned']      = (int) $malware['stats']['files_scanned'];
            $scan_meta['files_skipped_size'] = (int) $malware['stats']['files_skipped_size'];
            $scan_meta['truncated']          = (bool) $malware['stats']['truncated'];
            $scan_meta['truncated_reason']   = $malware['stats']['truncated_reason'];
        }
        if (in_array('integrity', $checks, true)) {
            $integrity                  = $this->integrity->run();
            $findings                   = array_merge($findings, $integrity['findings']);
            $scan_meta['integrity_api'] = $integrity['api'];
        }
        if (in_array('hardening', $checks, true)) {
            $hardening                  = $this->hardening->run();
            $findings                   = array_merge($findings, $hardening['findings']);
            $scan_meta['headers_fetch'] = $hardening['headers_fetch'];
        }
        if (in_array('software', $checks, true)) {
            $findings = array_merge($findings, $this->software->run());
        }

        $summary                 = $this->summarize($findings);
        $scan_meta['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);

        return [
            'summary'             => [
                'score'  => $summary['score'],
                'grade'  => $summary['grade'],
                'counts' => $summary['counts'],
            ],
            'sections'            => $this->group_by_category($findings),
            'scan_meta'           => $scan_meta,
            'top_recommendations' => $summary['top_recommendations'],
        ];
    }

    /**
     * Pure: normalize the requested checks to a canonical-ordered valid subset.
     *
     * @param string[]|null $requested
     * @return string[]
     */
    public function resolve_checks(?array $requested): array
    {
        if (empty($requested)) {
            return self::ALL_CHECKS;
        }
        $valid = [];
        foreach (self::ALL_CHECKS as $check) {
            if (in_array($check, $requested, true)) {
                $valid[] = $check;
            }
        }
        return empty($valid) ? self::ALL_CHECKS : $valid;
    }

    /**
     * Pure: counts, score (per-category critical cap), grade, ranked recs.
     *
     * @param array $findings Finding[]
     * @return array { counts, score, grade, top_recommendations }
     */
    public function summarize(array $findings): array
    {
        $counts       = ['critical' => 0, 'warning' => 0, 'pass' => 0, 'info' => 0];
        $cat_crit_pen = [];
        $warn_penalty = 0;

        foreach ($findings as $finding) {
            $status = (string) ($finding['status'] ?? 'info');
            if (isset($counts[ $status ])) {
                $counts[ $status ]++;
            }
            if ('critical' === $status) {
                $category                  = (string) ($finding['category'] ?? 'malware');
                $cat_crit_pen[ $category ] = min(
                    self::CATEGORY_CRIT_CAP,
                    ($cat_crit_pen[ $category ] ?? 0) + self::CRITICAL_WEIGHT
                );
            } elseif ('warning' === $status) {
                $warn_penalty += self::WARNING_WEIGHT;
            }
        }

        $score = 100 - array_sum($cat_crit_pen) - $warn_penalty;
        $score = max(0, min(100, $score));

        if ($score >= 90) {
            $grade = 'A';
        } elseif ($score >= 80) {
            $grade = 'B';
        } elseif ($score >= 70) {
            $grade = 'C';
        } elseif ($score >= 60) {
            $grade = 'D';
        } else {
            $grade = 'F';
        }

        return [
            'counts'              => $counts,
            'score'               => $score,
            'grade'               => $grade,
            'top_recommendations' => $this->rank_recommendations($findings),
        ];
    }

    /**
     * Pure: bucket findings by their category, always returning all four keys.
     *
     * @param array $findings Finding[]
     * @return array category => Finding[]
     */
    public function group_by_category(array $findings): array
    {
        $sections = ['malware' => [], 'integrity' => [], 'hardening' => [], 'software' => []];
        foreach ($findings as $finding) {
            $category = (string) ($finding['category'] ?? 'malware');
            if (! isset($sections[ $category ])) {
                $sections[ $category ] = [];
            }
            $sections[ $category ][] = $finding;
        }
        return $sections;
    }

    /**
     * @param array $findings Finding[]
     * @return string[]
     */
    private function rank_recommendations(array $findings): array
    {
        $critical = [];
        $warning  = [];
        foreach ($findings as $finding) {
            $recommendation = trim((string) ($finding['recommendation'] ?? ''));
            if ('' === $recommendation) {
                continue;
            }
            $line = sprintf('[%s] %s', (string) ($finding['label'] ?? ''), $recommendation);
            if ('critical' === ($finding['status'] ?? '')) {
                $critical[] = $line;
            } elseif ('warning' === ($finding['status'] ?? '')) {
                $warning[] = $line;
            }
        }
        return array_slice(array_merge($critical, $warning), 0, self::TOP_RECS);
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
