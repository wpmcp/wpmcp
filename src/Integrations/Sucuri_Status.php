<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Sucuri Security status (issue #695): whether the website firewall and the
 * API service are connected, the last file integrity check, a summary of the
 * last malware scan and the count of recorded failed logins.
 *
 * Sucuri keeps its settings and caches in flat files under its data folder
 * (wp-content/uploads/sucuri, or SUCURI_DATA_STORAGE): sucuri-settings.php
 * holds an exit line and one JSON line, sucuri-sitecheck.php the cached
 * remote scan as "// key=value;" headers and "scan_results:{json}", and
 * sucuri-failedlogins.php one JSON line per failed attempt. The firewall key
 * lives in the sucuriscan_secret_cloudproxy_apikey option (encrypted in its
 * _enc twin). This reads those files directly instead of calling
 * SucuriScanOption::getOption(), which writes a default back to the
 * settings file when a key is missing.
 *
 * Keys are reported as connected or not, never returned. Scan findings are
 * counted, never listed (they carry URLs and payload names). Failed logins
 * are counted, never read (each line holds the user name, the password tried
 * and the IP address).
 */
final class Sucuri_Status
{
    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        return [
            'get-sucuri-status' => Ops_Status_Packs::read_op(
                'Sucuri Security status: firewall and API connected, last integrity check, last malware scan (time, malware and blocklist counts), failed login count, two factor mode, XML-RPC blocked. No keys, IPs or URLs',
                static fn () => Ops_Status_Packs::presence(self::is_active(), 'sucuri_inactive', 'Sucuri Security'),
                static fn (): array => self::status()
            ),
        ];
    }

    /** Whether Sucuri Security is loaded, filterable through wpmcp_sucuri_active. */
    public static function is_active(): bool
    {
        return (bool) apply_filters('wpmcp_sucuri_active', defined('SUCURISCAN_VERSION'));
    }

    /** @return array<string,mixed> */
    private static function status(): array
    {
        $settings = self::settings();

        return [
            'firewall_connected'   => self::firewall_connected($settings),
            'api_registered'       => ! empty(self::setting($settings, 'sucuriscan_api_key', false)),
            'last_integrity_check' => Ops_Status_Packs::iso_time(self::setting($settings, 'sucuriscan_runtime', 0)),
            'last_malware_scan'    => self::last_malware_scan(),
            'failed_logins'        => self::failed_logins(),
            'two_factor_mode'      => sanitize_key((string) self::setting($settings, 'sucuriscan_twofactor_mode', 'disabled')),
            'xmlrpc_blocked'       => 'enabled' === self::setting($settings, 'sucuriscan_hardening_xmlrpc', 'disabled'),
        ];
    }

    /** Sucuri's data folder, filterable through wpmcp_sucuri_data_dir. */
    private static function data_dir(): string
    {
        $dir = defined('SUCURI_DATA_STORAGE') ? (string) constant('SUCURI_DATA_STORAGE') : WP_CONTENT_DIR . '/uploads/sucuri';
        return untrailingslashit((string) apply_filters('wpmcp_sucuri_data_dir', $dir));
    }

    /** Contents of one file in the data folder, or null when it is not there. */
    private static function read_file(string $name): ?string
    {
        $path = self::data_dir() . '/' . $name;
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $content = file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file in Sucuri's own data folder.
        return is_string($content) ? $content : null;
    }

    /** @return array<string,mixed> The settings file's JSON line, or [] */
    private static function settings(): array
    {
        $content = self::read_file('sucuri-settings.php');
        $lines   = null === $content ? [] : explode("\n", $content, 2);
        $data    = isset($lines[1]) ? json_decode($lines[1], true) : null;

        return is_array($data) ? $data : [];
    }

    /**
     * One setting: the settings file first, then the option an older install
     * kept in the database, then Sucuri's default.
     *
     * @param array<string,mixed> $settings
     * @param mixed               $fallback
     * @return mixed
     */
    private static function setting(array $settings, string $key, $fallback)
    {
        if (array_key_exists($key, $settings)) {
            return $settings[ $key ];
        }
        $stored = get_option($key, null);
        return null === $stored ? $fallback : $stored;
    }

    /** @param array<string,mixed> $settings */
    private static function firewall_connected(array $settings): bool
    {
        return ! empty(get_option('sucuriscan_secret_cloudproxy_apikey_enc', ''))
            || ! empty(get_option('sucuriscan_secret_cloudproxy_apikey', ''))
            || ! empty(self::setting($settings, 'sucuriscan_cloudproxy_apikey', ''));
    }

    /** @return array{time:string|null,malware_findings:int,blocklist_warnings:int}|null */
    private static function last_malware_scan(): ?array
    {
        $content = self::read_file('sucuri-sitecheck.php');
        if (null === $content) {
            return null;
        }

        $updated = 0;
        $results = null;
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if (1 === preg_match('/^\/\/ updated_on=(\d+);$/', $line, $m)) {
                $updated = (int) $m[1];
            } elseif (0 === strpos($line, 'scan_results:')) {
                $results = json_decode(substr($line, strlen('scan_results:')), true);
            }
        }
        if (! is_array($results)) {
            return null;
        }

        $malware   = $results['MALWARE']['WARN'] ?? [];
        $blocklist = $results['BLACKLIST']['WARN'] ?? [];

        return [
            'time'               => Ops_Status_Packs::iso_time($updated),
            'malware_findings'   => is_array($malware) ? count($malware) : 0,
            'blocklist_warnings' => is_array($blocklist) ? count($blocklist) : 0,
        ];
    }

    /** Failed login attempts recorded since the last report (JSON lines only, never decoded). */
    private static function failed_logins(): int
    {
        $content = self::read_file('sucuri-failedlogins.php');
        if (null === $content) {
            return 0;
        }

        $count = 0;
        foreach (explode("\n", $content) as $line) {
            if (0 === strpos(ltrim($line), '{')) {
                ++$count;
            }
        }
        return $count;
    }
}
