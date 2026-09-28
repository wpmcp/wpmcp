<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Solid Security status (issue #300): the enabled modules, the last site
 * scan and counts of known vulnerabilities, active lockouts and bans, read
 * from the plugin's own storage: the itsec_active_modules and itsec-storage
 * site options and its {base_prefix}itsec_logs, itsec_lockouts and
 * itsec_bans tables.
 *
 * The last scan is summarised from its log row's code, level and time only.
 * Its data column (the scanner's full response, with URLs and file paths) is
 * never selected, and neither is any host, user or IP column: lockouts and
 * bans are reported as counts. Module settings, which hold the scanner's
 * site keys and the network brute force API key, are never returned.
 */
final class Solid_Security_Status
{
    /** Log row types that mark a scan's process, not its result. */
    private const PROCESS_TYPES = [ 'process-start', 'process-update', 'process-stop' ];

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        return [
            'get-solid-security-status' => Ops_Status_Packs::read_op(
                'Solid Security status: enabled modules, last site scan (time, result, findings) and counts of known vulnerabilities, active lockouts and bans. No keys or IPs',
                static fn () => Ops_Status_Packs::presence(self::is_active(), 'solid_security_inactive', 'Solid Security'),
                static fn (): array => self::status()
            ),
        ];
    }

    /** Whether Solid Security is loaded, filterable through wpmcp_solid_security_active. */
    public static function is_active(): bool
    {
        return (bool) apply_filters('wpmcp_solid_security_active', class_exists('ITSEC_Core', false));
    }

    /** @return array<string,mixed> */
    private static function status(): array
    {
        global $wpdb;

        $storage         = get_site_option('itsec-storage', []);
        $vulnerabilities = is_array($storage) ? ($storage['site-scanner']['vulnerabilities'] ?? []) : [];
        $lockouts        = $wpdb->base_prefix . 'itsec_lockouts';
        $bans            = $wpdb->base_prefix . 'itsec_bans';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Solid Security's own tables have no WP API for counts.
        return [
            'enabled_modules'       => self::enabled_modules(),
            'last_scan'             => self::last_scan(),
            'known_vulnerabilities' => is_array($vulnerabilities) ? count($vulnerabilities) : 0,
            'active_lockouts'       => Ops_Status_Packs::table_exists($lockouts)
                ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE lockout_active = 1 AND lockout_expire_gmt > %s', $lockouts, gmdate('Y-m-d H:i:s')))
                : null,
            'bans'                  => Ops_Status_Packs::table_exists($bans)
                ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $bans))
                : null,
        ];
        // phpcs:enable
    }

    /**
     * Module ids switched on in itsec_active_modules (id => bool), also
     * reading the plugin's older plain-list format, sorted.
     *
     * @return array<int,string>
     */
    private static function enabled_modules(): array
    {
        $stored  = get_site_option('itsec_active_modules', []);
        $modules = [];
        foreach (is_array($stored) ? $stored : [] as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $modules[] = $value;
            } elseif (is_string($key) && true === $value) {
                $modules[] = $key;
            }
        }
        $modules = array_values(array_unique(array_filter(array_map('sanitize_key', $modules))));
        sort($modules);

        return $modules;
    }

    /** @return array{time:string|null,result:string,findings:array<int,string>,level:string}|null */
    private static function last_scan(): ?array
    {
        global $wpdb;

        $logs = $wpdb->base_prefix . 'itsec_logs';
        if (! Ops_Status_Packs::table_exists($logs)) {
            return null;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Solid Security's log table has no WP API; the data column is deliberately not read.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT code, type, timestamp FROM %i WHERE module = %s AND type NOT IN (%s, %s, %s) ORDER BY timestamp DESC, id DESC LIMIT 1',
                $logs,
                'site-scanner',
                self::PROCESS_TYPES[0],
                self::PROCESS_TYPES[1],
                self::PROCESS_TYPES[2]
            ),
            ARRAY_A
        );
        if (! is_array($row)) {
            return null;
        }

        $code = (string) $row['code'];
        if ('clean' === $code) {
            $result = 'clean';
        } elseif ('error' === $code || 0 === strpos($code, 'scan-failure')) {
            $result = 'error';
        } else {
            $result = 'issues';
        }

        return [
            'time'     => Ops_Status_Packs::iso_datetime($row['timestamp']),
            'result'   => $result,
            'findings' => 'clean' === $result ? [] : array_values(array_filter(array_map('sanitize_key', explode('--', $code)))),
            'level'    => sanitize_key((string) $row['type']),
        ];
    }
}
