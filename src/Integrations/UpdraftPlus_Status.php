<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * UpdraftPlus status (issue #300): the last backup, the files and database
 * schedules and the remote storage destinations, read from the plugin's own
 * options (updraft_last_backup, updraft_interval, updraft_interval_database,
 * updraft_retain, updraft_retain_db, updraft_service, updraft_backup_history)
 * and its two cron hooks.
 *
 * Storage is reported by service id only (s3, dropbox, googledrive and so
 * on). The per-service options that hold access keys and tokens are never
 * read, and the last backup reports which components it covered, never its
 * file names, nonce or error text (which can carry server paths).
 */
final class UpdraftPlus_Status
{
    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        return [
            'get-updraftplus-status' => Ops_Status_Packs::read_op(
                'UpdraftPlus status: last backup (time, success, components), files and database schedule with next run, and storage destination ids. No credentials',
                static fn () => Ops_Status_Packs::presence(self::is_active(), 'updraftplus_inactive', 'UpdraftPlus'),
                static fn (): array => self::status()
            ),
        ];
    }

    /** Whether UpdraftPlus is loaded, filterable through wpmcp_updraftplus_active. */
    public static function is_active(): bool
    {
        return (bool) apply_filters('wpmcp_updraftplus_active', defined('UPDRAFTPLUS_DIR'));
    }

    /** @return array<string,mixed> */
    private static function status(): array
    {
        $history = get_option('updraft_backup_history', []);

        return [
            'last_backup' => self::last_backup(),
            'schedule'    => [
                'files'    => self::schedule('updraft_interval', 'updraft_retain', 'updraft_backup'),
                'database' => self::schedule('updraft_interval_database', 'updraft_retain_db', 'updraft_backup_database'),
            ],
            'storage'     => self::storage(),
            'backup_sets' => is_array($history) ? count($history) : 0,
        ];
    }

    /** @return array<string,mixed>|null */
    private static function last_backup(): ?array
    {
        $last = get_option('updraft_last_backup', []);
        if (! is_array($last) || null === Ops_Status_Packs::iso_time($last['backup_time'] ?? null)) {
            return null;
        }

        $components = [];
        foreach (array_keys((array) ($last['backup_array'] ?? [])) as $key) {
            $key = (string) $key;
            if (1 === preg_match('/^[a-z][a-z0-9-]*$/', $key) && '-size' !== substr($key, -5)) {
                $components[] = $key;
            }
        }

        return [
            'time'        => Ops_Status_Packs::iso_time($last['backup_time']),
            'status'      => empty($last['success']) ? 'failed' : 'success',
            'error_count' => is_array($last['errors'] ?? null) ? count($last['errors']) : 0,
            'components'  => array_values(array_unique($components)),
        ];
    }

    /** @return array{interval:string,retain:int|null,next_run:string|null} */
    private static function schedule(string $interval_option, string $retain_option, string $hook): array
    {
        $interval = sanitize_key((string) get_option($interval_option, ''));
        $retain   = get_option($retain_option, null);

        return [
            'interval' => '' === $interval ? 'manual' : $interval,
            'retain'   => is_numeric($retain) ? (int) $retain : null,
            'next_run' => Ops_Status_Packs::iso_time(wp_next_scheduled($hook)),
        ];
    }

    /** @return array<int,string> remote storage service ids, 'none' dropped. */
    private static function storage(): array
    {
        $out = [];
        foreach ((array) get_option('updraft_service', []) as $service) {
            $service = is_string($service) ? sanitize_key($service) : '';
            if ('' !== $service && 'none' !== $service) {
                $out[] = $service;
            }
        }
        return array_values(array_unique($out));
    }
}
