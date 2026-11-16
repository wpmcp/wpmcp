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
    /** The site's switch for run-updraftplus-backup (issue #694), default off. */
    public const RUN_FILTER = 'wpmcp_enable_backup_plugin_run';

    /** UpdraftPlus's own backup-now action (class-updraftplus.php, backup_all). */
    public const RUN_HOOK = 'updraft_backupnow_backup_all';

    /** One run per this many seconds. */
    public const RATE_LIMIT = 600;

    /** Refuse a run when the content directory has less free space than this. */
    public const MIN_FREE_BYTES = 524288000;

    private const RATE_TRANSIENT = 'wpmcp_updraftplus_run_lock';

    private const MAX_LIST = 50;

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        $requires = static fn () => Ops_Status_Packs::presence(self::is_active(), 'updraftplus_inactive', 'UpdraftPlus');

        return [
            'get-updraftplus-status'   => Ops_Status_Packs::read_op(
                'UpdraftPlus status: last backup (time, success, components), files and database schedule with next run, and storage destination ids. No credentials',
                $requires,
                static fn (): array => self::status()
            ),
            'list-updraftplus-backups' => Ops_Status_Packs::read_op(
                'List UpdraftPlus backup sets, newest first: id (timestamp), date, components with sizes, storage ids. No file names or links',
                $requires,
                static fn (array $args): array => self::list_backups((int) ($args['limit'] ?? 20)),
                [ 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIST ] ]
            ),
            'run-updraftplus-backup'   => [
                'mode'               => 'destructive',
                'capability'         => 'manage_options',
                'description'        => 'Queue an UpdraftPlus backup now (files and database). Local only unless include_remote:true. Retention may prune older sets. Not reversible. Needs confirm:true; disabled by default (wpmcp_enable_backup_plugin_run filter)',
                'enabled_by_default' => (bool) apply_filters('wpmcp_enable_backup_plugin_run', false),
                'opt_in_filter'      => self::RUN_FILTER,
                'undoable'           => false,
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'include_remote' => [ 'type' => 'boolean' ],
                        'only_database'  => [ 'type' => 'boolean' ],
                    ],
                ],
                'requires'           => $requires,
                'validate'           => static fn (): ?array => self::run_refusal(),
                'confirm_preview'    => static fn (array $args): array => self::run_preview($args),
                'handler'            => static fn (array $args): array => self::run($args),
            ],
        ];
    }

    /**
     * Backup sets from updraft_backup_history (keyed by Unix time), newest
     * first. Each set reports its components and their sizes, never the
     * archive file names, nonce or job ids.
     *
     * @return array{backups:array<int,array<string,mixed>>,total:int}
     */
    private static function list_backups(int $limit): array
    {
        $history = get_option('updraft_backup_history', []);
        $history = is_array($history) ? $history : [];
        krsort($history, SORT_NUMERIC);

        $out = [];
        foreach ($history as $time => $set) {
            if (count($out) >= max(1, min(self::MAX_LIST, $limit))) {
                break;
            }
            if (! is_array($set) || null === Ops_Status_Packs::iso_time($time)) {
                continue;
            }
            $components = [];
            foreach ($set as $key => $value) {
                $key = (string) $key;
                if (1 !== preg_match('/^(db|plugins|themes|uploads|others|mu-plugins|wpcore|more)$/', $key)) {
                    continue;
                }
                $size = $set[ $key . '-size' ] ?? null;
                if (null === $size && is_array($value)) {
                    $size = 0;
                    foreach (array_keys($value) as $i) {
                        $size += is_numeric($set[ $key . $i . '-size' ] ?? null) ? (int) $set[ $key . $i . '-size' ] : 0;
                    }
                }
                $components[ $key ] = is_numeric($size) ? (int) $size : null;
            }
            $services = [];
            foreach ((array) ($set['service'] ?? []) as $service) {
                $service = is_string($service) ? sanitize_key($service) : '';
                if ('' !== $service && 'none' !== $service) {
                    $services[] = $service;
                }
            }
            $out[] = [
                'id'         => (int) $time,
                'date'       => Ops_Status_Packs::iso_time($time),
                'components' => $components,
                'size'       => array_sum(array_filter($components, 'is_int')),
                'storage'    => array_values(array_unique($services)),
                'source'     => ! empty($set['native']) || ! isset($set['native']) ? 'local' : 'imported',
            ];
        }

        return [ 'backups' => $out, 'total' => count($history) ];
    }

    /** @return array{code:string,message:string}|null */
    private static function run_refusal(): ?array
    {
        if (false !== get_transient(self::RATE_TRANSIENT)) {
            return [ 'code' => 'backup_rate_limited', 'message' => 'An UpdraftPlus backup was started through WP MCP in the last 10 minutes; wait before starting another.' ];
        }
        if (self::queued() || (bool) apply_filters('wpmcp_updraftplus_backup_running', false)) {
            return [ 'code' => 'backup_running', 'message' => 'An UpdraftPlus backup is already queued or running.' ];
        }
        $free = self::free_bytes();
        if (null !== $free && $free < self::MIN_FREE_BYTES) {
            return [ 'code' => 'backup_low_disk', 'message' => 'Less than 500 MB of free disk space; UpdraftPlus backup refused.' ];
        }
        return null;
    }

    /** Whether a backup-now event is already waiting on WP-Cron. */
    private static function queued(): bool
    {
        $crons = _get_cron_array();
        foreach (is_array($crons) ? $crons : [] as $events) {
            if (! empty($events[ self::RUN_HOOK ])) {
                return true;
            }
        }
        return false;
    }

    private static function free_bytes(): ?int
    {
        $free = apply_filters('wpmcp_updraftplus_free_bytes', null);
        if (null === $free && function_exists('disk_free_space')) {
            $free = is_dir(WP_CONTENT_DIR) ? disk_free_space(WP_CONTENT_DIR) : false;
        }
        return is_numeric($free) ? (int) $free : null;
    }

    /** @return array{message:string,data:array<string,mixed>} */
    private static function run_preview(array $args): array
    {
        $remote  = ! empty($args['include_remote']);
        $db_only = ! empty($args['only_database']);
        $storage = self::storage();
        $retain  = get_option($db_only ? 'updraft_retain_db' : 'updraft_retain', null);

        return [
            'message' => sprintf(
                'This queues an UpdraftPlus backup of %s, %s. When it finishes UpdraftPlus keeps %s sets and may delete older ones. It cannot be undone.',
                $db_only ? 'the database' : 'files and database',
                $remote && [] !== $storage ? 'uploaded to ' . implode(', ', $storage) . ' (site data leaves this server)' : 'stored on this server only',
                is_numeric($retain) ? (string) (int) $retain : 'its configured number of'
            ),
            'data'    => [
                'components'     => $db_only ? [ 'db' ] : [ 'db', 'plugins', 'themes', 'uploads', 'others' ],
                'remote_storage' => $remote ? $storage : [],
                'retain'         => is_numeric($retain) ? (int) $retain : null,
                'free_bytes'     => self::free_bytes(),
                'recoverable'    => false,
            ],
        ];
    }

    /**
     * Queue UpdraftPlus's own backup-now action on WP-Cron, so the backup
     * runs in UpdraftPlus's resumable job rather than inside this request.
     *
     * @return array<string,mixed>
     */
    private static function run(array $args): array
    {
        $remote  = ! empty($args['include_remote']);
        $db_only = ! empty($args['only_database']);
        $options = [
            'nocloud'          => $remote ? 0 : 1,
            'use_nonce'        => substr(md5(wp_generate_password(20, false)), 0, 12),
            'backupnow_nodb'   => 0,
            'backupnow_nofiles' => $db_only ? 1 : 0,
        ];
        $when = time();
        wp_schedule_single_event($when, self::RUN_HOOK, [ $options ]);
        set_transient(self::RATE_TRANSIENT, $when, self::RATE_LIMIT);

        return [
            'queued'         => true,
            'queued_at'      => Ops_Status_Packs::iso_time($when),
            'remote_storage' => $remote,
            'components'     => $db_only ? [ 'db' ] : [ 'db', 'plugins', 'themes', 'uploads', 'others' ],
            'next'           => 'Runs on the next WP-Cron tick. Poll list-updraftplus-backups or get-updraftplus-status for the new set.',
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
