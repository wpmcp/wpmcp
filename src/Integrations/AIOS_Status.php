<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * All In One WP Security status (issue #695): which protections are on, the
 * last scheduled file change scan, and counts of active lockouts, permanent
 * blocks and recorded failed logins, read from the plugin's own storage: the
 * aio_wp_security_configs option and its {prefix}aiowps_login_lockdown,
 * aiowps_permanent_block and aiowps_failed_logins tables.
 *
 * Protections are reported as on or off from an allowlist of setting keys,
 * so the renamed login page slug, the blocked IP lists, the reCAPTCHA and
 * unlock secrets and the alert addresses are never returned. Lockouts,
 * blocks and failed logins are counted; no IP, user name or unlock key
 * column is ever selected.
 */
final class AIOS_Status
{
    /** Reported name => aio_wp_security_configs key. */
    private const FEATURES = [
        'login_lockout'            => 'aiowps_enable_login_lockdown',
        'brute_force_prevention'   => 'aiowps_enable_brute_force_attack_prevention',
        'rename_login_page'        => 'aiowps_enable_rename_login_page',
        'basic_firewall'           => 'aiowps_enable_basic_firewall',
        'advanced_firewall'        => 'aiowps_enable_6g_firewall',
        'pingback_firewall'        => 'aiowps_enable_pingback_firewall',
        'xmlrpc_pingback_disabled' => 'aiowps_disable_xmlrpc_pingback_methods',
        'ip_blocklist'             => 'aiowps_enable_blacklisting',
        'ip_allowlist'             => 'aiowps_enable_whitelisting',
        'scheduled_file_scan'      => 'aiowps_enable_automated_fcd_scan',
    ];

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        return [
            'get-aios-status' => Ops_Status_Packs::read_op(
                'All In One WP Security status: protections on or off (lockout, brute force, firewall, XML-RPC, IP lists, file scan), last file change scan, counts of active lockouts, permanent blocks and failed logins. No IPs, user names or secrets',
                static fn () => Ops_Status_Packs::presence(self::is_active(), 'aios_inactive', 'All In One WP Security'),
                static fn (): array => self::status()
            ),
        ];
    }

    /** Whether All In One WP Security is loaded, filterable through wpmcp_aios_active. */
    public static function is_active(): bool
    {
        return (bool) apply_filters('wpmcp_aios_active', defined('AIO_WP_SECURITY_VERSION'));
    }

    /** @return array<string,mixed> */
    private static function status(): array
    {
        global $wpdb;

        $configs = get_option('aio_wp_security_configs', []);
        $configs = is_array($configs) ? $configs : [];

        $features = [];
        foreach (self::FEATURES as $name => $key) {
            $features[ $name ] = ! empty($configs[ $key ]);
        }

        $lockouts = $wpdb->prefix . 'aiowps_login_lockdown';
        $blocks   = $wpdb->prefix . 'aiowps_permanent_block';
        $failed   = $wpdb->prefix . 'aiowps_failed_logins';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- All In One WP Security's own tables have no WP API for counts.
        return [
            'features'         => $features,
            'last_file_scan'   => self::last_file_scan($configs),
            'active_lockouts'  => Ops_Status_Packs::table_exists($lockouts)
                ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE released > %d', $lockouts, time()))
                : null,
            'permanent_blocks' => Ops_Status_Packs::table_exists($blocks)
                ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $blocks))
                : null,
            'failed_logins'    => Ops_Status_Packs::table_exists($failed)
                ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $failed))
                : null,
        ];
        // phpcs:enable
    }

    /**
     * The last scheduled file change scan, stored as a Unix time (or a date
     * string by older versions).
     *
     * @param array<string,mixed> $configs
     * @return array{time:string|null,changes_detected:bool}|null
     */
    private static function last_file_scan(array $configs): ?array
    {
        $time = $configs['aiowps_last_fcd_scan_time'] ?? null;
        if (is_string($time) && ! is_numeric($time)) {
            $time = strtotime($time . ' UTC');
        }
        $iso = Ops_Status_Packs::iso_time($time);
        if (null === $iso) {
            return null;
        }

        return [
            'time'             => $iso,
            'changes_detected' => ! empty($configs['aiowps_fcds_change_detected']),
        ];
    }
}
