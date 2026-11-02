<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The default adapter over Wordfence's static classes (wfConfig, wfBlock,
 * wfIssues, wordfence). Every call is behind a class_exists guard so an
 * install without Wordfence, or an older Wordfence missing a method, answers
 * null or false instead of fataling. Tests replace this object through the
 * wpmcp_wordfence_api filter.
 */
class Wordfence_Api
{
    public function version(): ?string
    {
        return defined('WORDFENCE_VERSION') ? (string) WORDFENCE_VERSION : null;
    }

    /** @return mixed */
    public function config(string $key)
    {
        if (! class_exists('wfConfig', false) || ! method_exists('wfConfig', 'get')) {
            return null;
        }
        return \wfConfig::get($key);
    }

    public function waf_status(): ?string
    {
        if (! class_exists('wfConfig', false)) {
            return null;
        }
        return class_exists('wfWAF', false) && method_exists('wfWAF', 'getInstance')
            ? (string) \wfWAF::getInstance()->getStorageEngine()->getConfig('wafStatus', 'disabled')
            : null;
    }

    /** @return array{by_severity:array<string,int>,new:int,ignored:int} */
    public function issue_summary(): array
    {
        $summary = [ 'by_severity' => [], 'new' => 0, 'ignored' => 0 ];
        if (! class_exists('wfIssues', false)) {
            return $summary;
        }
        $issues = \wfIssues::shared();
        $new    = $issues->getIssues(0, 1000, 0, 1000);
        foreach ((array) ($new['new'] ?? []) as $issue) {
            $summary['new']++;
            $sev                               = (string) ($issue['severity'] ?? 0);
            $summary['by_severity'][ $sev ] = ($summary['by_severity'][ $sev ] ?? 0) + 1;
        }
        $summary['ignored'] = count((array) ($new['ignoredP'] ?? [])) + count((array) ($new['ignoredC'] ?? []));
        return $summary;
    }

    /** @return array<int,array<string,mixed>> */
    public function issues(?string $status, int $limit): array
    {
        if (! class_exists('wfIssues', false)) {
            return [];
        }
        $all = \wfIssues::shared()->getIssues(0, $limit, 0, $limit);
        $map = [ 'new' => 'new', 'ignoreP' => 'ignoredP', 'ignoreC' => 'ignoredC' ];
        if (null !== $status && isset($map[ $status ])) {
            return array_slice((array) ($all[ $map[ $status ] ] ?? []), 0, $limit);
        }
        $out = [];
        foreach ($map as $key) {
            $out = array_merge($out, (array) ($all[ $key ] ?? []));
        }
        return array_slice($out, 0, $limit);
    }

    public function block_ip(string $ip, string $reason, int $duration): void
    {
        if (! class_exists('wfBlock', false)) {
            return;
        }
        \wfBlock::createIP($reason, $ip, $duration > 0 ? $duration : \wfBlock::DURATION_FOREVER);
    }

    public function unblock_ip(string $ip): int
    {
        if (! class_exists('wfBlock', false)) {
            return 0;
        }
        $blocks = \wfBlock::allBlocks(false);
        $ids    = [];
        foreach ($blocks as $block) {
            if (method_exists($block, 'ip') && $block->ip() === $ip) {
                $ids[] = $block->id();
            }
        }
        if ([] !== $ids) {
            \wfBlock::removeBlockIDs($ids);
        }
        return count($ids);
    }

    public function ignore_issue(int $issue_id, string $how): bool
    {
        if (! class_exists('wfIssues', false)) {
            return false;
        }
        return (bool) \wfIssues::shared()->updateIssue($issue_id, $how);
    }
}
