<?php

namespace WPMCP\Cloud;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Settings sync over a curated allowlist (issue #135, phase B step 2).
 *
 * The payload is the site's governance posture: safety toggles, tool/domain
 * enablement, exposure mode. Secrets (API keys, tokens, identities'
 * credentials) are never part of the allowlist, so they can never leave the
 * site through this path even if the export code regresses.
 *
 * apply() re-filters the incoming blob against the same allowlist, so a
 * tampered or stale cloud payload cannot smuggle arbitrary options into
 * update_option. Settings sync is the paid-cloud entitlement (Pro\Gate).
 */
class Settings_Sync
{
    /**
     * Options that may sync. Curated by hand; add here only after checking the
     * value contains no secret material.
     */
    private const ALLOWLIST = [
        'wpmcp_enable_db_writes',
        'wpmcp_enable_fs_writes',
        'wpmcp_enable_rest_writes',
        'wpmcp_enable_option_write',
        'wpmcp_enable_acf_write',
        'wpmcp_enable_run_cron_event',
        'wpmcp_enable_delete_product',
        'wpmcp_enable_delete_menu',
        'wpmcp_allow_php_exec',
        'wpmcp_allow_wp_cli',
        'wpmcp_wp_cli_allowlist',
        'wpmcp_db_allow_user_table_reads',
        'wpmcp_tool_exposure_mode',
        'wpmcp_ability_enabled',
        'wpmcp_domain_enabled',
        'wpmcp_operation_enabled',
        'wpmcp_skills_enabled',
        'wpmcp_remote_media_allowed_hosts',
    ];

    /**
     * Export the current governance posture as an allowlisted key => value map.
     * Options that are unset on this site are omitted rather than defaulted,
     * so applying an export never silently resets a target site's choices.
     *
     * @return array<string,mixed>
     */
    public static function export(): array
    {
        $payload  = [];
        $sentinel = new \stdClass();
        foreach (self::ALLOWLIST as $option) {
            $value = get_option($option, $sentinel);
            if ($value !== $sentinel) {
                $payload[$option] = $value;
            }
        }
        return $payload;
    }

    /**
     * Apply a synced payload, re-filtered against the allowlist. Returns the
     * list of option names actually applied.
     *
     * TODO(#135): wire the paid-cloud entitlement check (Pro\Gate) and the
     * cloud-side GET/PUT /settings endpoints in Cloud_Client callers before
     * exposing an apply tool; only the read-only preview is registered so far.
     *
     * @param array<string,mixed> $payload
     * @return string[]
     */
    public static function apply(array $payload): array
    {
        $applied = [];
        foreach ($payload as $option => $value) {
            if (! in_array($option, self::ALLOWLIST, true)) {
                continue; // Tampered or unknown key: drop silently.
            }
            update_option($option, $value);
            $applied[] = $option;
        }
        return $applied;
    }

    /** @return string[] */
    public static function allowlist(): array
    {
        return self::ALLOWLIST;
    }
}
