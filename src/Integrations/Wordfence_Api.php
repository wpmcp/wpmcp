<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The default adapter over Wordfence's own classes (wfConfig, wfFirewall,
 * wfScanner, wfBlock, wfIssues, wfScanEngine, wfUtils), checked against
 * Wordfence 9.1. Every call is behind a class_exists guard, so an install
 * without Wordfence, or one missing a class, answers null or false instead
 * of fataling. Tests replace this object through the wpmcp_wordfence_api
 * filter.
 *
 * The writes go through the same calls Wordfence's own Blocking and Scan
 * screens make, so its hooks fire and its firewall resyncs its copy of the
 * blocks: wfBlock::createIP() for a manual block, wfBlock::unblockIP() plus
 * clearing the login failure counters for an unblock, and
 * wfIssues::updateIssue() then the scan notice recount for an ignore.
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
        if (! class_exists('wfConfig', false)) {
            return null;
        }
        return \wfConfig::get($key);
    }

    /** The firewall mode as Wordfence reports it: enabled, learning-mode or disabled. */
    public function firewall_mode(): ?string
    {
        if (! class_exists('wfFirewall', false) || ! class_exists('wfWAF', false) || ! \wfWAF::getInstance()) {
            return null;
        }
        return (string) (new \wfFirewall())->firewallMode();
    }

    /** Whether a scan holds Wordfence's scan lock right now. */
    public function scan_running(): bool
    {
        if (! class_exists('wfScanner', false)) {
            return false;
        }
        return (bool) \wfScanner::shared()->isRunning();
    }

    /** Whether Wordfence would refuse to block this IP (its allowlist or its own never-block ranges). */
    public function is_allowlisted(string $ip): bool
    {
        if (! class_exists('wfBlock', false)) {
            return false;
        }
        return (bool) \wfBlock::isWhitelisted($ip);
    }

    /** The IP of the request making this call, as Wordfence resolves it. */
    public function own_ip(): ?string
    {
        if (! class_exists('wfUtils', false)) {
            return null;
        }
        $ip = \wfUtils::getIP();
        return is_string($ip) && '' !== $ip ? $ip : null;
    }

    /** A manual block, as Wordfence's Blocking screen creates one (0 seconds: permanent). */
    public function block_ip(string $ip, string $reason, int $duration): void
    {
        if (! class_exists('wfBlock', false)) {
            return;
        }
        \wfBlock::createIP($reason, $ip, $duration > 0 ? $duration : \wfBlock::DURATION_FOREVER, time(), false, 0, \wfBlock::TYPE_IP_MANUAL);
    }

    /** Every block on an IP removed, and its login failure counters cleared, as Wordfence's unblock does. */
    public function unblock_ip(string $ip): void
    {
        if (! class_exists('wfBlock', false)) {
            return;
        }
        \wfBlock::unblockIP($ip);
        if (class_exists('wordfence', false) && method_exists('wordfence', 'clearLockoutCounters')) {
            \wordfence::clearLockoutCounters($ip);
        }
    }

    /** A scan issue set to ignoreP or ignoreC, then the scan notice recounted. */
    public function ignore_issue(int $issue_id, string $how): void
    {
        if (! class_exists('wfIssues', false)) {
            return;
        }
        $issues = \wfIssues::shared();
        $issues->updateIssue($issue_id, $how);
        if (class_exists('wfScanEngine', false)) {
            \wfScanEngine::refreshScanNotification($issues);
        }
    }
}
