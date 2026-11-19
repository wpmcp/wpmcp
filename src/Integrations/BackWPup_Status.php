<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * BackWPup backups (issue #694): list the backup jobs and the archives each
 * job's destinations hold, and start a job through BackWPup's own run-now
 * request.
 *
 * Read from BackWPup's own storage: the backwpup_jobs site option (one
 * entry per job), the backwpup_<jobid>_<dest> site transient each remote
 * destination keeps as its file list (no remote call is made), and the
 * job's backupdir for the local folder destination. Jobs report id, name,
 * types, destinations, schedule and last run only, never their settings
 * (they hold storage credentials, mail addresses and log paths). Archives
 * report name, job, destination, size and date, never a path, bucket or
 * download link.
 *
 * BackWPup's jobs and backups are network wide, so on multisite both ops
 * need manage_network_options.
 */
final class BackWPup_Status
{
    /** The site's switch for backup plugin runs (shared with UpdraftPlus), default off. */
    public const RUN_FILTER = 'wpmcp_enable_backup_plugin_run';

    /** One run per this many seconds. */
    public const RATE_LIMIT = 600;

    /** Refuse a run when the content directory has less free space than this. */
    public const MIN_FREE_BYTES = 524288000;

    private const RATE_TRANSIENT = 'wpmcp_backwpup_run_lock';

    private const MAX_LIST = 50;

    /** The archive extensions BackWPup lists (BackWPup_Destinations::EXTENSIONS). */
    private const EXTENSIONS = [ '.tar.gz', '.tar', '.zip' ];

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        $requires   = static fn () => Ops_Status_Packs::presence(self::is_active(), 'backwpup_inactive', 'BackWPup');
        $capability = is_multisite() ? 'manage_network_options' : 'manage_options';

        $list = Ops_Status_Packs::read_op(
            'List BackWPup jobs (id, name, types, destinations, schedule, last run) and their backup archives, newest first: name, job, destination, size, date. No paths, links or settings',
            $requires,
            static fn (array $args): array => self::list_backups((int) ($args['limit'] ?? 20), (int) ($args['job_id'] ?? 0)),
            [
                'limit'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIST ],
                'job_id' => [ 'type' => 'integer', 'minimum' => 1 ],
            ]
        );
        $list['capability'] = $capability;

        return [
            'list-backwpup-backups' => $list,
            'run-backwpup-backup'   => [
                'mode'               => 'destructive',
                'capability'         => $capability,
                'description'        => 'Start a BackWPup job now (job_id from list-backwpup-backups). It writes to every destination the job has, remote ones included, and retention may delete older archives. Not reversible. Needs confirm:true; disabled by default (wpmcp_enable_backup_plugin_run filter)',
                'enabled_by_default' => (bool) apply_filters('wpmcp_enable_backup_plugin_run', false),
                'opt_in_filter'      => self::RUN_FILTER,
                'undoable'           => false,
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [ 'job_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'job_id' ],
                ],
                'requires'           => $requires,
                'validate'           => static fn (array $args): ?array => self::run_refusal((int) ($args['job_id'] ?? 0)),
                'confirm_preview'    => static fn (array $args): array => self::run_preview((int) ($args['job_id'] ?? 0)),
                'handler'            => static fn (array $args): array => self::run((int) ($args['job_id'] ?? 0)),
            ],
        ];
    }

    /** Whether BackWPup is loaded, filterable through wpmcp_backwpup_active. */
    public static function is_active(): bool
    {
        return (bool) apply_filters('wpmcp_backwpup_active', defined('BACKWPUP_PLUGIN_LOADED'));
    }

    /**
     * BackWPup's jobs keyed by job id, temporary backup-now jobs left out.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function jobs(): array
    {
        $jobs = get_site_option('backwpup_jobs', []);
        $out  = [];
        foreach (is_array($jobs) ? $jobs : [] as $job) {
            if (! is_array($job) || ! isset($job['jobid']) || ! is_numeric($job['jobid']) || ! empty($job['tempjob'])) {
                continue;
            }
            $out[ (int) $job['jobid'] ] = $job;
        }
        ksort($out);
        return $out;
    }

    /** @return list<string> the job's destination ids, upper case. */
    private static function destinations(array $job): array
    {
        $out = [];
        foreach ((array) ($job['destinations'] ?? []) as $dest) {
            $dest = is_string($dest) ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $dest)) : '';
            if ('' !== $dest) {
                $out[] = $dest;
            }
        }
        return array_values(array_unique($out));
    }

    /** @return list<string> */
    private static function types(array $job): array
    {
        $out = [];
        foreach ((array) ($job['type'] ?? []) as $type) {
            $type = is_string($type) ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $type)) : '';
            if ('' !== $type) {
                $out[] = $type;
            }
        }
        return $out;
    }

    /**
     * @return array{jobs:array<int,array<string,mixed>>,backups:array<int,array<string,mixed>>,total:int,running:bool}
     */
    private static function list_backups(int $limit, int $job_id): array
    {
        $jobs = [];
        $rows = [];
        $seen = [];
        foreach (self::jobs() as $id => $job) {
            $jobs[] = [
                'id'           => $id,
                'name'         => sanitize_text_field((string) ($job['name'] ?? '')),
                'enabled'      => 'wpcron' === ($job['activetype'] ?? ''),
                'types'        => self::types($job),
                'destinations' => self::destinations($job),
                'cron'         => is_string($job['cron'] ?? null) ? sanitize_text_field($job['cron']) : null,
                'next_run'     => Ops_Status_Packs::iso_time(wp_next_scheduled('backwpup_cron', [ 'arg' => $id ])),
                'last_run'     => Ops_Status_Packs::iso_time($job['lastrun'] ?? null),
            ];
            if ($job_id > 0 && $job_id !== $id) {
                continue;
            }
            foreach (self::destinations($job) as $dest) {
                $items = 'FOLDER' === $dest ? self::folder_items($job, $id) : self::remote_items($id, $dest);
                foreach ($items as $item) {
                    $key = $dest . '|' . $item['_where'] . '|' . $item['name'];
                    if (isset($seen[ $key ])) {
                        continue;
                    }
                    $seen[ $key ] = true;
                    $rows[]       = [
                        'name'        => $item['name'],
                        'job_id'      => $id,
                        'destination' => $dest,
                        'size'        => $item['size'],
                        'date'        => Ops_Status_Packs::iso_time($item['time']),
                        '_t'          => $item['time'],
                    ];
                }
            }
        }

        usort($rows, static fn (array $a, array $b): int => $b['_t'] <=> $a['_t']);
        $total = count($rows);
        $rows  = array_map(static function (array $row): array {
            unset($row['_t']);
            return $row;
        }, array_slice($rows, 0, max(1, min(self::MAX_LIST, $limit))));

        return [ 'jobs' => $jobs, 'backups' => $rows, 'total' => $total, 'running' => self::running() ];
    }

    /**
     * The archives in the job's local backup folder.
     *
     * @return list<array{name:string,size:int|null,time:int,_where:string}>
     */
    private static function folder_items(array $job, int $id): array
    {
        $dir = self::backup_dir($job, $id);
        if ('' === $dir || ! is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach ((array) scandir($dir) as $file) {
            $path = trailingslashit($dir) . $file;
            if (! is_string($file) || ! self::is_archive($file) || ! is_file($path) || is_link($path)) {
                continue;
            }
            $out[] = [
                'name'   => sanitize_file_name($file),
                'size'   => (int) filesize($path),
                'time'   => (int) filemtime($path),
                '_where' => $dir,
            ];
        }
        return $out;
    }

    /** The job's backupdir as BackWPup resolves it (relative to wp-content). */
    private static function backup_dir(array $job, int $id): string
    {
        $dir = is_string($job['backupdir'] ?? null) ? $job['backupdir'] : '';
        if ('' !== $dir && class_exists('BackWPup_File') && method_exists('BackWPup_File', 'get_absolute_path')) {
            $dir = (string) \BackWPup_File::get_absolute_path($dir);
        } elseif ('' !== $dir && ! path_is_absolute($dir)) {
            $dir = trailingslashit(WP_CONTENT_DIR) . ltrim($dir, '/');
        }
        return (string) apply_filters('wpmcp_backwpup_backup_dir', untrailingslashit($dir), $id);
    }

    /**
     * The file list a remote destination keeps after each run (site transient,
     * or the legacy site option of the same name). No remote call is made.
     *
     * @return list<array{name:string,size:int|null,time:int,_where:string}>
     */
    private static function remote_items(int $id, string $dest): array
    {
        $key  = 'backwpup_' . strtolower($id . '_' . $dest);
        $list = get_site_transient($key);
        if (false === $list) {
            $list = get_site_option($key, []);
        }
        $out = [];
        foreach (is_array($list) ? $list : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $file = (string) ($item['filename'] ?? '');
            if ('' === $file) {
                $file = basename((string) ($item['file'] ?? ''));
            }
            if ('' === $file || ! self::is_archive($file)) {
                continue;
            }
            $out[] = [
                'name'   => sanitize_file_name(basename($file)),
                'size'   => is_numeric($item['filesize'] ?? null) ? (int) $item['filesize'] : null,
                'time'   => is_numeric($item['time'] ?? null) ? (int) $item['time'] : 0,
                '_where' => '',
            ];
        }
        return $out;
    }

    private static function is_archive(string $file): bool
    {
        foreach (self::EXTENSIONS as $extension) {
            if (substr($file, -strlen($extension)) === $extension) {
                return true;
            }
        }
        return false;
    }

    /** Whether a BackWPup job is running now, filterable through wpmcp_backwpup_backup_running. */
    private static function running(): bool
    {
        $running = false;
        if (class_exists('BackWPup_Job') && method_exists('BackWPup_Job', 'get_working_data')) {
            $running = (bool) \BackWPup_Job::get_working_data();
        }
        return (bool) apply_filters('wpmcp_backwpup_backup_running', $running);
    }

    /** @return array{code:string,message:string}|null */
    private static function run_refusal(int $job_id): ?array
    {
        if (! isset(self::jobs()[ $job_id ])) {
            return [ 'code' => 'invalid_args', 'message' => sprintf('No BackWPup job with id %d; list-backwpup-backups lists the jobs.', $job_id) ];
        }
        if (false !== get_site_transient(self::RATE_TRANSIENT)) {
            return [ 'code' => 'backup_rate_limited', 'message' => 'A BackWPup backup was started through WP MCP in the last 10 minutes; wait before starting another.' ];
        }
        if (self::running()) {
            return [ 'code' => 'backup_running', 'message' => 'A BackWPup job is already running.' ];
        }
        $free = self::free_bytes();
        if (null !== $free && $free < self::MIN_FREE_BYTES) {
            return [ 'code' => 'backup_low_disk', 'message' => 'Less than 500 MB of free disk space; BackWPup backup refused.' ];
        }
        return null;
    }

    private static function free_bytes(): ?int
    {
        $free = apply_filters('wpmcp_backwpup_free_bytes', null);
        if (null === $free && function_exists('disk_free_space')) {
            $free = is_dir(WP_CONTENT_DIR) ? disk_free_space(WP_CONTENT_DIR) : false;
        }
        return is_numeric($free) ? (int) $free : null;
    }

    /** @return array<string,int> archives kept per destination, as the job sets it. */
    private static function retention(array $job): array
    {
        $out = [];
        foreach (self::destinations($job) as $dest) {
            $key = 'FOLDER' === $dest ? 'maxbackups' : ('SUGARSYNC' === $dest ? 'sugarmaxbackups' : strtolower($dest) . 'maxbackups');
            if (is_numeric($job[ $key ] ?? null)) {
                $out[ $dest ] = (int) $job[ $key ];
            }
        }
        return $out;
    }

    /** @return array{message:string,data:array<string,mixed>} */
    private static function run_preview(int $job_id): array
    {
        $job    = self::jobs()[ $job_id ] ?? [];
        $dests  = self::destinations($job);
        $remote = array_values(array_diff($dests, [ 'FOLDER' ]));
        $retain = self::retention($job);

        return [
            'message' => sprintf(
                'This starts the BackWPup job "%s" (%s), %s. BackWPup keeps the newest archives per destination as the job sets it and may delete older ones. It cannot be undone.',
                sanitize_text_field((string) ($job['name'] ?? '')),
                implode(', ', self::types($job)),
                [] !== $remote ? 'stored to ' . implode(', ', $dests) . ' (site data leaves this server)' : 'stored on this server only'
            ),
            'data'    => [
                'job_id'         => $job_id,
                'types'          => self::types($job),
                'destinations'   => $dests,
                'remote_storage' => $remote,
                'retain'         => $retain,
                'free_bytes'     => self::free_bytes(),
                'recoverable'    => false,
            ],
        ];
    }

    /**
     * Start the job through BackWPup's own run-now request (a loopback to
     * wp-cron.php, the path BackWPup's Run now button takes), so the backup
     * runs in BackWPup's resumable job rather than inside this request.
     *
     * @return array<string,mixed>
     */
    private static function run(int $job_id): array
    {
        $job    = self::jobs()[ $job_id ] ?? [];
        $result = apply_filters('wpmcp_backwpup_start_job', null, $job_id);
        if (null === $result) {
            $result = class_exists('BackWPup_Job') && method_exists('BackWPup_Job', 'get_jobrun_url')
                ? \BackWPup_Job::get_jobrun_url('runnow', $job_id)
                : new \WP_Error('backwpup_unavailable', 'BackWPup is not loaded.');
        }
        // The loopback is fire and forget (a 0.01 s timeout), so a timeout
        // is the normal answer, not a failure.
        if (is_wp_error($result) && false === stripos($result->get_error_message(), 'timed out')) {
            $message = esc_html('BackWPup could not start the job: ' . sanitize_text_field($result->get_error_message()));
            throw new Operation_Error('backup_start_failed', $message, [ 'job_id' => (int) $job_id ]); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is escaped above; the data array is not output.
        }
        $when = time();
        set_site_transient(self::RATE_TRANSIENT, $when, self::RATE_LIMIT);

        return [
            'started'      => true,
            'job_id'       => $job_id,
            'job_name'     => sanitize_text_field((string) ($job['name'] ?? '')),
            'started_at'   => Ops_Status_Packs::iso_time($when),
            'destinations' => self::destinations($job),
            'next'         => 'BackWPup runs the job in the background. Poll list-backwpup-backups (running, and the new archive) for the result.',
        ];
    }
}
