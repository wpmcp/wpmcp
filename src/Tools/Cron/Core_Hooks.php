<?php

namespace WPMCP\Tools\Cron;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The core-critical WP-Cron hooks whose recurrence WordPress itself manages
 * and which must not be re-scheduled (added/duplicated) by a caller: doing so
 * could double-run version checks, plugin/theme update checks, the core
 * auto-updater (which drives Plugin_Upgrader/Theme_Upgrader unattended), or
 * transient and expired-data cleanup. Unscheduling these is a legitimate, common admin
 * action and is deliberately NOT restricted here.
 *
 * Extend the denylist via the wpmcp_cron_protected_hooks filter.
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
    ];

    public static function is_protected(string $hook): bool
    {
        $protected = apply_filters('wpmcp_cron_protected_hooks', self::PROTECTED);
        return in_array($hook, (array) $protected, true);
    }
}
