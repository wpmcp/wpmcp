<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * LMS course structure and enrollments (issue #394) on the plugin-data pair,
 * paid-tier, so they add no top-level tools: for Tutor LMS (LMS_Tutor),
 * LifterLMS (LMS_LifterLMS) and LearnDash (LMS_LearnDash, which also adds
 * learner enrollment and progress ops), each op prefixed with the plugin
 * (tutor-get-course, lifterlms-add-lesson, learndash-enroll-user, ...). What
 * every LMS shares, the op catalog, the checks and the snapshot discipline,
 * is LMS_Adapter.
 *
 * Presence is filterable per plugin (wpmcp_tutor_active,
 * wpmcp_lifterlms_active, wpmcp_learndash_active); an op whose plugin is not
 * loaded stays documented in list-operations (dependency_met:false) and
 * answers <plugin>_inactive.
 */
final class LMS_Pack
{
    /** @return LMS_Adapter[] */
    public static function adapters(): array
    {
        return [ new LMS_Tutor(), new LMS_LifterLMS(), new LMS_LearnDash() ];
    }

    /** Whether a supported LMS is loaded and this install may run the paid tier. */
    public static function active(): bool
    {
        if (! Registrar::tier_permitted('pro')) {
            return false;
        }
        foreach (self::adapters() as $adapter) {
            if ($adapter->active()) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $ops = [];
        foreach (self::adapters() as $adapter) {
            $ops = array_merge($ops, $adapter->operations());
        }
        return $ops;
    }
}
