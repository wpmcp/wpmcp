<?php

namespace WPMCP\Freemius;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Decides whether a site's addresses mark it as a staging copy.
 *
 * The rule: a whole host label or a whole path segment, compared without
 * case, that reads "staging" or "stage", optionally followed by a number
 * ("staging2", "staging-2", "stage_3"). Whole labels and segments only, so
 * backstage.com, stagecoach.example.com, /stagecoach/ and staging-old.* do
 * not count; a numbered copy is a staging marker, a word after the hyphen is
 * not. The last two host labels are never looked at: they are the
 * registrable domain, and a business that owns stage.com is a live site.
 *
 * Pure on purpose (no state, no options, no hooks), so the rule is
 * unit-testable on its own. Staging_Copy reads the real addresses and acts
 * on the answer.
 */
class Staging_Detector
{
    private const MARKER = '/^(?:staging|stage)(?:[-_]?\d+)?$/';

    /**
     * True when either WordPress address setting (Site Address or WordPress
     * Address) is a staging URL.
     */
    public static function is_staging(string $home_url, string $site_url): bool
    {
        return self::is_staging_url($home_url) || self::is_staging_url($site_url);
    }

    public static function is_staging_url(string $url): bool
    {
        $parts = wp_parse_url(strtolower(trim($url)));
        if (! is_array($parts) || empty($parts['host'])) {
            return false;
        }

        $labels = explode('.', $parts['host']);
        foreach (array_slice($labels, 0, max(0, count($labels) - 2)) as $label) {
            if (1 === preg_match(self::MARKER, $label)) {
                return true;
            }
        }

        foreach (explode('/', $parts['path'] ?? '') as $segment) {
            if (1 === preg_match(self::MARKER, $segment)) {
                return true;
            }
        }

        return false;
    }
}
