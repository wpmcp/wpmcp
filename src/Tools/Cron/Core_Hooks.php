<?php

namespace WPMCP\Tools\Cron;

use WPMCP\Cron_Registry;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The WP-Cron hooks an agent must not queue.
 *
 * Core-critical hooks whose recurrence WordPress itself manages must not be
 * re-scheduled (added/duplicated) by a caller: doing so could double-run
 * version checks, plugin/theme update checks, the core auto-updater (which
 * drives Plugin_Upgrader/Theme_Upgrader unattended), recovery-mode key and
 * temp-backup cleanup, site health checks, or transient and expired-data
 * cleanup. Unscheduling these is a legitimate, common admin action and is
 * deliberately NOT restricted here. Extend this list via the
 * wpmcp_cron_protected_hooks filter.
 *
 * WP MCP's own hooks (issue #563) are refused as well, and NOT through that
 * filter, so it cannot reopen them: every hook in Cron_Registry moves a
 * background job or runs a backup schedule, takes a job or schedule id as
 * its argument, and is only ever queued by the tool that created that job
 * and passed that tool's own gates. Queued from schedule-event it would run
 * internal work out of band, with arguments the caller chose.
 */
class Core_Hooks
{
    private const PROTECTED = [
        'wp_version_check',
        'wp_update_plugins',
        'wp_update_themes',
        'wp_maybe_auto_update',
        'wp_scheduled_delete',
        'delete_expired_transients',
        'wp_privacy_delete_old_export_files',
        'recovery_mode_clean_expired_keys',
        'wp_site_health_scheduled_check',
        'wp_update_user_counts',
        'wp_scheduled_auto_draft_delete',
        'wp_delete_temp_updater_backups',
        'wp_https_detection',
    ];

    public static function is_protected(string $hook): bool
    {
        if (self::is_internal($hook)) {
            return true;
        }

        $protected = apply_filters('wpmcp_cron_protected_hooks', self::PROTECTED);
        return in_array($hook, (array) $protected, true);
    }

    /** Whether $hook is one of WP MCP's own cron hooks (Cron_Registry). */
    public static function is_internal(string $hook): bool
    {
        return in_array($hook, Cron_Registry::hooks(), true);
    }
}
