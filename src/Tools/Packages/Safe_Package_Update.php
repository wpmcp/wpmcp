<?php

namespace WPMCP\Tools\Packages;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Safety\File_Backup;
use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Backup\Db_Dumper;
use WPMCP\Tools\Backup\Sql_Import_Policy;
use WPMCP\Tools\Backup\Sql_Importer;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Safe plugin and theme updates, served by manage-updates type=plugins or
 * type=themes (issue #843).
 *
 *  - action=plan (read only) lists the pending updates with the installed
 *    and offered versions, the package's size on disk and whether this host
 *    can snapshot it, and why not when it cannot.
 *  - action=run updates up to MAX_PER_RUN packages, one at a time. For each
 *    one it archives the package folder (File_Backup::backup_directory(), the
 *    undo point install-package-from-zip already uses, so rollback-operation
 *    puts the files back too), dumps the tables named after the package,
 *    keeps the site's options in memory, checks a few of the site's own
 *    pages, runs the update, puts the package's active state back, and
 *    checks the pages again. When the update fails or a page that was fine
 *    before now fails, the folder, the tables and the options are put back
 *    and the result names the package and the reason.
 *  - action=status reports a run's per-package progress (it is saved after
 *    every step, so another request can watch a long run), or lists the
 *    recent runs.
 *  - action=restore rolls a finished update back by hand while its snapshot
 *    is kept: the folder, the tables and the options the update changed.
 *
 * What a snapshot covers, and what it cannot: the package folder; the
 * tables whose names start with the package's folder name (wp_<slug>_*,
 * dashes as underscores; wpmcp_safe_update_owned_tables adds others); and
 * this site's options, except transients, cron and WP MCP's own. Rows a
 * package keeps in core tables (posts, meta) or in tables named after
 * something else are not covered. On multisite only the current site's
 * options are kept.
 *
 * Snapshots of a kept update are removed after the retention window
 * (wpmcp_safe_update_retention, one day by default), checked on every
 * plugins/themes call; Snapshot_Store::prune() also removes the folder
 * archive with its undo point.
 *
 * run and restore are off until the site opts in (OPT_IN_FILTER, code only,
 * like update-plugin) and need confirm:true. They refuse when file changes
 * are not allowed here (DISALLOW_FILE_MODS) or the filesystem is not
 * directly writable. Maintenance mode is on only while core's upgrader
 * swaps an active package. Every package's result is written to the Audit
 * Log.
 *
 * The upgrader and the page probe are constructor-injected so tests never
 * download a package; production uses core's upgraders and
 * Update_Health_Check.
 */
class Safe_Package_Update
{
    public const OPT_IN_FILTER = 'wpmcp_enable_safe_updates';

    /** Runs, newest last: run id => record. */
    public const RUNS_OPTION = 'wpmcp_safe_update_runs';

    public const DEFAULT_RETENTION = DAY_IN_SECONDS;

    /** Packages one run call updates; the rest are reported as remaining. */
    public const MAX_PER_RUN = 5;

    public const MAX_RUNS_KEPT = 20;

    /** Owned tables larger than this are not snapshotted (the package is refused). */
    public const DEFAULT_MAX_TABLE_BYTES = 67108864;

    /** Options larger than this in total are not kept in memory (every package is refused). */
    public const DEFAULT_MAX_OPTION_BYTES = 16777216;

    /** Before-values of the options an update changed, kept for restore, up to this size. */
    public const MAX_KEPT_OPTION_BYTES = 2097152;

    private const ABILITY = 'wpmcp/manage-updates';

    private const TABLES_DUMP = 'tables.sql';

    /** @var callable(string, string): mixed */
    private $upgrader;

    /** @var callable(string): array */
    private $probe;

    private ?Update_Health_Check $check;

    private static ?int $clock = null;

    /**
     * @param callable(string, string): mixed|null $upgrader ($kind, $item) => true, or a WP_Error / false on failure.
     * @param callable(string): array|null         $probe    $url => ['url', 'ok', 'status', 'reason'?].
     */
    public function __construct(?callable $upgrader = null, ?callable $probe = null, ?Update_Health_Check $check = null)
    {
        $this->check    = $check ?? new Update_Health_Check();
        $this->upgrader = $upgrader ?? [ self::class, 'upgrade_package' ];
        $this->probe    = $probe ?? [ $this->check, 'probe' ];
    }

    public static function is_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_safe_updates', false);
    }

    /** Pin "now" for retention tests. Tests only. */
    public static function set_clock_for_tests(?int $timestamp): void
    {
        self::$clock = $timestamp;
    }

    private static function now(): int
    {
        return self::$clock ?? time();
    }

    public static function retention(): int
    {
        return max(0, (int) apply_filters('wpmcp_safe_update_retention', self::DEFAULT_RETENTION));
    }

    /** @param string $type plugins or themes */
    public function handle(string $type, array $args): array
    {
        $kind = 'themes' === $type ? 'theme' : 'plugin';
        $cap  = 'theme' === $kind ? 'update_themes' : 'update_plugins';
        if (! current_user_can($cap)) {
            // Core withholds the update capabilities while file changes are
            // disabled, so say that rather than blame the user's role.
            if (! wp_is_file_mod_allowed('wpmcp_safe_update')) {
                throw new \RuntimeException('Refusing: file changes are disabled on this site (DISALLOW_FILE_MODS), so plugins and themes cannot be updated.');
            }
            throw new \RuntimeException(sprintf('Updating %ss requires the %s capability.', esc_html($kind), esc_html($cap)));
        }

        self::prune();

        $action = isset($args['action']) ? (string) $args['action'] : 'plan';
        switch ($action) {
            case 'plan':
                return $this->plan($kind, $args);
            case 'run':
                return $this->run($kind, $args);
            case 'status':
                return $this->status($kind, $args);
            case 'restore':
                return $this->restore($kind, $args);
        }

        throw new \InvalidArgumentException('action must be plan, run, status or restore.');
    }

    private function plan(string $kind, array $args): array
    {
        $pending = self::pending($kind);
        $wanted  = self::items($args);
        $global  = self::global_blocker();
        $rows    = [];

        foreach ($pending as $item => $info) {
            if ([] !== $wanted && ! in_array($item, $wanted, true)) {
                continue;
            }
            $dir     = self::package_dir($kind, $item);
            $tables  = null === $dir ? [] : self::owned_tables($kind, $item);
            $blocker = $global ?? self::blocker($kind, $item, $tables);
            $rows[]  = [
                'item'        => $item,
                'name'        => $info['name'],
                'version'     => $info['version'],
                'new_version' => $info['new_version'],
                'size_bytes'  => null === $dir ? 0 : self::dir_size($dir),
                'snapshot'    => null === $blocker
                    ? [ 'possible' => true, 'tables' => $tables ]
                    : [ 'possible' => false, 'reason' => $blocker, 'tables' => $tables ],
            ];
        }

        return [
            'type'              => $kind . 's',
            'enabled'           => self::is_enabled(),
            'enable_filter'     => self::OPT_IN_FILTER,
            'packages'          => $rows,
            'checks'            => $this->check instanceof Update_Health_Check ? $this->check->urls() : [],
            'retention_seconds' => self::retention(),
            'max_per_run'       => self::MAX_PER_RUN,
        ];
    }

    private function run(string $kind, array $args): array
    {
        self::assert_writes_allowed('Running safe updates', $args);

        $pending = self::pending($kind);
        $wanted  = self::items($args);
        if ([] === $wanted) {
            $wanted = array_keys($pending);
        }
        $remaining = array_slice($wanted, self::MAX_PER_RUN);
        $wanted    = array_slice($wanted, 0, self::MAX_PER_RUN);

        $run_id = wp_generate_uuid4();
        $run    = [
            'run_id'     => $run_id,
            'type'       => $kind . 's',
            'status'     => 'running',
            'started_at' => self::now(),
            'user_id'    => get_current_user_id(),
            'packages'   => [],
        ];
        foreach ($wanted as $item) {
            $run['packages'][ $item ] = [ 'item' => $item, 'status' => 'pending' ];
        }
        self::save_run($run);

        $session = (string) ($args['session_id'] ?? 'default');
        foreach ($wanted as $item) {
            $package = $this->update_one($kind, $item, $pending[ $item ] ?? null, $session, $args, $run);
            $run['packages'][ $item ] = $package;
            self::save_run($run);
            Governance_Audit_Log::record_quietly(
                self::ABILITY,
                in_array($package['status'], [ 'updated' ], true),
                sprintf('safe_update:%s:%s:%s', $kind, $item, $package['status'])
            );
        }

        $run['status']      = 'done';
        $run['finished_at'] = self::now();
        self::save_run($run);

        $out = self::view($run);
        if ([] !== $remaining) {
            $out['remaining'] = array_values($remaining);
        }
        return $out;
    }

    /**
     * Snapshot, update, check and, on a failure, put back one package.
     *
     * @param array{name: string, version: ?string, new_version: ?string}|null $info
     */
    private function update_one(string $kind, string $item, ?array $info, string $session, array $args, array &$run): array
    {
        $package = [ 'item' => $item ];

        if (null === $info) {
            return $package + [ 'status' => 'skipped', 'message' => sprintf('%s has no pending update.', $item) ];
        }
        $package['from_version'] = $info['version'];
        $package['to_version']   = $info['new_version'];

        $dir     = self::package_dir($kind, $item);
        $tables  = null === $dir ? [] : self::owned_tables($kind, $item);
        $blocker = self::global_blocker() ?? self::blocker($kind, $item, $tables);
        if (null !== $blocker || null === $dir) {
            return $package + [ 'status' => 'skipped', 'message' => sprintf('%s was not updated: %s', $item, $blocker ?? 'no package folder') ];
        }

        $progress = function (string $status) use (&$run, $item, &$package): void {
            $run['packages'][ $item ] = $package + [ 'status' => $status ];
            self::save_run($run);
        };

        // Snapshot: the folder archive, the tables dump, the options.
        $progress('snapshotting');
        $operation_id = wp_generate_uuid4();
        $slug         = self::slug($kind, $item);
        if (! File_Backup::backup_directory($operation_id, $dir)) {
            return $package + [ 'status' => 'skipped', 'message' => sprintf('%s was not updated: its folder could not be archived.', $item) ];
        }
        $dump = '';
        if ([] !== $tables) {
            try {
                $dump = self::dump_tables($operation_id, $tables);
            } catch (\Throwable $e) {
                File_Backup::delete_backup_dir($operation_id);
                return $package + [ 'status' => 'skipped', 'message' => sprintf('%s was not updated: its tables could not be saved (%s).', $item, $e->getMessage()) ];
            }
        }
        $options_before = self::capture_options();
        $active         = self::active_state($kind, $item);

        // The pages that load now; only these are held against the update.
        $progress('checking');
        $baseline = [];
        foreach ($this->check_urls() as $url) {
            $result = ($this->probe)($url);
            if (! empty($result['ok'])) {
                $baseline[] = $url;
            }
        }
        if ([] === $baseline) {
            File_Backup::delete_backup_dir($operation_id);
            return $package + [ 'status' => 'skipped', 'message' => sprintf('%s was not updated: none of the site checks loaded before the update (is loopback blocked on this host?), so a broken update could not be detected.', $item) ];
        }

        $progress('updating');
        $upgrade_error = '';
        $checks        = [];
        try {
            $out = Safe_Mutation::run(
                [
                    'object_type'         => 'package_install',
                    'object_id'           => $kind . ':' . $slug,
                    'object_ref'          => $item,
                    'session_id'          => $session,
                    'tool_name'           => 'manage-updates',
                    'args'                => $args,
                    'operation_id'        => $operation_id,
                    'extra_snapshot_data' => [ 'backup_operation_id' => $operation_id ],
                ],
                function () use ($kind, $item, $active, &$upgrade_error) {
                    $result = ($this->upgrader)($kind, $item);
                    if (true !== $result) {
                        $upgrade_error = is_wp_error($result) ? $result->get_error_message() : 'the upgrader reported a failure';
                        return false;
                    }
                    self::reactivate($kind, $item, $active);
                    return true;
                },
                function ($result) use ($baseline, &$checks): bool {
                    if (true !== $result) {
                        return false;
                    }
                    $ok = true;
                    foreach ($baseline as $url) {
                        $check    = ($this->probe)($url);
                        $checks[] = $check;
                        $ok       = $ok && ! empty($check['ok']);
                    }
                    return $ok;
                }
            );
        } catch (Mutation_Failed $e) {
            // Safe_Mutation has put the folder back from the archive.
            $restore = self::put_back($kind, $item, $dump, $tables, $options_before, $active);
            File_Backup::delete_backup_dir($operation_id);
            $step    = '' !== $upgrade_error ? 'update' : 'check';
            $failed  = array_values(array_filter($checks, static fn (array $c): bool => empty($c['ok'])));
            $why     = 'update' === $step
                ? 'the update itself failed: ' . $upgrade_error
                : 'after the update ' . implode('; ', array_map(static fn (array $c): string => $c['url'] . ' ' . ($c['reason'] ?? 'failed'), $failed));
            return $package + [
                'status'      => 'rolled_back',
                'failed_step' => $step,
                'checks'      => $checks,
                'warnings'    => $restore,
                'message'     => sprintf('Update of %s was rolled back: %s. The previous version, its tables and the site options are back.', $item, $why),
            ];
        } catch (\Throwable $e) {
            // The upgrader threw: Safe_Mutation does not restore on a throw,
            // so the folder is put back through its undo point here.
            $files   = Rollback_Service::restore_operation($operation_id);
            $restore = self::put_back($kind, $item, $dump, $tables, $options_before, $active);
            return $package + [
                'status'       => $files ? 'rolled_back' : 'failed',
                'failed_step'  => 'update',
                'operation_id' => $operation_id,
                'warnings'     => $restore,
                'message'      => $files
                    ? sprintf('Update of %s was rolled back: the update itself failed: %s. The previous version, its tables and the site options are back.', $item, $e->getMessage())
                    : sprintf('Update of %s failed: %s. Its tables and options were put back, but its files could not be; roll back operation %s.', $item, $e->getMessage(), $operation_id),
            ];
        }

        $changed = self::changed_options($options_before);
        unset($options_before);

        return $package + [
            'status'       => 'updated',
            'to_version'   => self::installed_version($kind, $item) ?? $info['new_version'],
            'operation_id' => $out['operation_id'],
            'checks'       => $checks,
            'tables'       => $tables,
            'tables_dump'  => '' !== $dump,
            'active'       => $active,
            'options'      => $changed,
            'expires_at'   => self::now() + self::retention(),
            'restorable'   => true,
        ];
    }

    private function status(string $kind, array $args): array
    {
        $runs   = self::runs();
        $run_id = isset($args['run_id']) ? (string) $args['run_id'] : '';

        if ('' !== $run_id) {
            if (! isset($runs[ $run_id ])) {
                throw new \RuntimeException(sprintf('No safe update run "%s" was found.', esc_html($run_id)));
            }
            return self::view($runs[ $run_id ]);
        }

        $list = [];
        foreach (array_reverse($runs) as $run) {
            if (($run['type'] ?? '') !== $kind . 's') {
                continue;
            }
            $counts = [];
            foreach ((array) ($run['packages'] ?? []) as $package) {
                $status            = (string) ($package['status'] ?? 'pending');
                $counts[ $status ] = ($counts[ $status ] ?? 0) + 1;
            }
            $list[] = [
                'run_id'     => (string) $run['run_id'],
                'status'     => (string) $run['status'],
                'started_at' => gmdate('c', (int) $run['started_at']),
                'counts'     => $counts,
            ];
        }

        return [ 'type' => $kind . 's', 'runs' => $list ];
    }

    private function restore(string $kind, array $args): array
    {
        self::assert_writes_allowed('Restoring a safe update', $args);

        $runs   = self::runs();
        $run_id = isset($args['run_id']) ? (string) $args['run_id'] : '';
        if ('' === $run_id || ! isset($runs[ $run_id ])) {
            throw new \InvalidArgumentException('run_id of a safe update run is required (see action=status).');
        }
        $run = $runs[ $run_id ];
        if (($run['type'] ?? '') !== $kind . 's') {
            throw new \InvalidArgumentException(sprintf('Run %s updated %s, not %ss.', esc_html($run_id), esc_html((string) ($run['type'] ?? '')), esc_html($kind)));
        }

        $items = self::items($args);
        if ([] === $items) {
            throw new \InvalidArgumentException('items (the packages to restore) is required.');
        }

        foreach ($items as $item) {
            $package = $run['packages'][ $item ] ?? null;
            if (null === $package) {
                throw new \RuntimeException(sprintf('Run %s did not update %s.', esc_html($run_id), esc_html($item)));
            }
            if ('restored' === ($package['status'] ?? '')) {
                throw new \RuntimeException(sprintf('%s was already restored.', esc_html($item)));
            }
            if ('updated' !== ($package['status'] ?? '') || empty($package['restorable'])) {
                throw new \RuntimeException(sprintf('%s cannot be restored: %s', esc_html($item), 'updated' === ($package['status'] ?? '') ? 'its snapshot is no longer kept.' : 'it was not updated in this run.'));
            }
            if (! File_Backup::has_directory_backup((string) $package['operation_id'])) {
                throw new \RuntimeException(sprintf('%s cannot be restored: the archive of its previous version is gone.', esc_html($item)));
            }
        }

        foreach ($items as $item) {
            $package      = $run['packages'][ $item ];
            $operation_id = (string) $package['operation_id'];
            $warnings     = [];

            $files_back = Rollback_Service::restore_operation($operation_id);
            $warnings   = array_merge($warnings, Rollback_Service::take_warnings());
            if (! $files_back) {
                $row        = Snapshot_Store::get_by_operation($operation_id);
                $files_back = is_array($row) && null !== Snapshot_Store::rolled_back_at($row);
            }
            if (! $files_back) {
                $run['packages'][ $item ]['warnings'] = $warnings;
                self::save_run($run);
                throw new \RuntimeException(sprintf('%s could not be restored: %s', esc_html($item), esc_html(implode(' ', $warnings))));
            }

            $dump = ! empty($package['tables_dump']) ? File_Backup::operation_dir($operation_id) . '/' . self::TABLES_DUMP : '';
            $warnings = array_merge($warnings, self::restore_tables($kind, $item, $dump, (array) ($package['tables'] ?? [])));
            if (is_array($package['options']['values'] ?? null)) {
                self::put_options((array) $package['options']['values']);
            } elseif (! empty($package['options']['names'])) {
                $warnings[] = 'The options this update changed were too large to keep, so they were not put back.';
            }
            self::reactivate($kind, $item, (array) ($package['active'] ?? []));

            $package['status']      = 'restored';
            $package['restorable']  = false;
            $package['restored_at'] = self::now();
            $package['warnings']    = $warnings;
            unset($package['options']['values']);
            File_Backup::delete_backup_dir($operation_id);
            $run['packages'][ $item ] = $package;
            self::save_run($run);
            Governance_Audit_Log::record_quietly(self::ABILITY, true, sprintf('safe_update:%s:%s:restored', $kind, $item));
        }

        $view             = self::view($run);
        $view['packages'] = array_values(array_filter($view['packages'], static fn (array $p): bool => in_array($p['item'], $items, true)));
        return $view;
    }

    /** Opt-in, confirm, and the host allowing file changes. */
    private static function assert_writes_allowed(string $what, array $args): void
    {
        if (! self::is_enabled()) {
            throw new \WPMCP\MCP\Opt_In_Required(
                esc_html(sprintf('%s is disabled.', $what)),
                esc_html(self::OPT_IN_FILTER),
                '',
                'It replaces plugin or theme files on this site.'
            );
        }
        if (true !== ($args['confirm'] ?? null)) {
            throw \WPMCP\MCP\Confirmation_Required::in_arguments('A safe update replaces package files (and rolls them back on a failure). Pass confirm:true to proceed.');
        }
        $blocker = self::global_blocker();
        if (null !== $blocker) {
            throw new \RuntimeException(esc_html('Refusing: ' . $blocker));
        }
    }

    /** Why nothing can be snapshotted and updated on this host, or null. */
    private static function global_blocker(): ?string
    {
        if (! wp_is_file_mod_allowed('wpmcp_safe_update')) {
            return 'file changes are disabled on this site (DISALLOW_FILE_MODS).';
        }
        if (! Package_Guard::filesystem_ready()) {
            return 'the plugin and theme folders are not directly writable on this host.';
        }
        if (! class_exists('ZipArchive')) {
            return 'PHP\'s ZipArchive is missing, so the package folder cannot be archived.';
        }
        $max = (int) apply_filters('wpmcp_safe_update_max_option_bytes', self::DEFAULT_MAX_OPTION_BYTES);
        if (self::options_bytes() > $max) {
            return 'the options table is too large to keep a copy of during the update.';
        }
        return null;
    }

    /**
     * Why this one package cannot be snapshotted, or null.
     *
     * @param string[] $tables
     */
    private static function blocker(string $kind, string $item, array $tables): ?string
    {
        if ('plugin' === $kind && Package_Guard::is_protected_plugin($item)) {
            return Package_Guard::protected_plugin_refusal('update', $item);
        }
        $dir = self::package_dir($kind, $item);
        if (null === $dir) {
            return 'plugin' === $kind
                ? 'it is a single-file plugin with no folder of its own, so its files cannot be archived apart from other plugins.'
                : 'its folder is not a plain directory under the theme root.';
        }
        if (is_link($dir)) {
            return 'its folder is a symlink.';
        }
        if ('plugin' === $kind && is_multisite()) {
            if (! function_exists('is_plugin_active_for_network')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            if (is_plugin_active_for_network($item) && ! current_user_can('manage_network_plugins')) {
                return 'it is network-activated, so only a network administrator can update it.';
            }
        }
        if ([] !== $tables) {
            $max = (int) apply_filters('wpmcp_safe_update_max_table_bytes', self::DEFAULT_MAX_TABLE_BYTES);
            if (self::tables_bytes($tables) > $max) {
                return 'its own tables are too large to snapshot before the update.';
            }
        }
        return null;
    }

    /**
     * Pending updates from core's update transients.
     *
     * @return array<string, array{name: string, version: ?string, new_version: ?string}>
     */
    private static function pending(string $kind): array
    {
        $out = [];
        if ('plugin' === $kind) {
            if (! function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $installed = get_plugins();
            $transient = get_site_transient('update_plugins');
            foreach ((array) (is_object($transient) ? ($transient->response ?? []) : []) as $file => $data) {
                $file = (string) $file;
                if (! isset($installed[ $file ])) {
                    continue;
                }
                $out[ $file ] = [
                    'name'        => (string) ($installed[ $file ]['Name'] ?? $file),
                    'version'     => isset($installed[ $file ]['Version']) ? (string) $installed[ $file ]['Version'] : null,
                    'new_version' => self::offered_version($data),
                ];
            }
            return $out;
        }

        $transient = get_site_transient('update_themes');
        foreach ((array) (is_object($transient) ? ($transient->response ?? []) : []) as $stylesheet => $data) {
            $theme = wp_get_theme((string) $stylesheet);
            if (! $theme->exists()) {
                continue;
            }
            $out[ (string) $stylesheet ] = [
                'name'        => (string) $theme->get('Name'),
                'version'     => (string) $theme->get('Version'),
                'new_version' => self::offered_version($data),
            ];
        }
        return $out;
    }

    /** @param mixed $data */
    private static function offered_version($data): ?string
    {
        $version = is_object($data) ? ($data->new_version ?? null) : (is_array($data) ? ($data['new_version'] ?? null) : null);
        return null === $version ? null : (string) $version;
    }

    /** @return string[] */
    private static function items(array $args): array
    {
        $items = $args['items'] ?? [];
        if (is_string($items)) {
            $items = [ $items ];
        }
        return array_values(array_unique(array_filter(array_map('strval', (array) $items), static fn (string $i): bool => '' !== $i)));
    }

    /** The package's folder name: the plugin's directory or the theme's stylesheet. */
    private static function slug(string $kind, string $item): string
    {
        return 'plugin' === $kind ? (string) strtok($item, '/') : $item;
    }

    /** The package folder, or null when the package has no folder of its own the undo point can name. */
    private static function package_dir(string $kind, string $item): ?string
    {
        if ('plugin' === $kind) {
            if (false === strpos($item, '/')) {
                return null;
            }
            $slug = self::slug($kind, $item);
            $root = WP_PLUGIN_DIR;
        } else {
            $slug = $item;
            $root = get_theme_root();
            if (untrailingslashit((string) get_theme_root($item)) !== untrailingslashit((string) $root)) {
                return null;
            }
        }
        if (1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $slug)) {
            return null;
        }
        $dir = trailingslashit($root) . $slug;
        return is_dir($dir) ? $dir : null;
    }

    private static function dir_size(string $dir): int
    {
        $bytes = 0;
        try {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($items as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && ! $file->isLink()) {
                    $bytes += (int) $file->getSize();
                }
            }
        } catch (\Throwable $e) {
            unset($e);
        }
        return $bytes;
    }

    private static function installed_version(string $kind, string $item): ?string
    {
        if ('plugin' === $kind) {
            wp_clean_plugins_cache(false);
            $plugins = get_plugins();
            return isset($plugins[ $item ]['Version']) ? (string) $plugins[ $item ]['Version'] : null;
        }
        wp_clean_themes_cache();
        $theme = wp_get_theme($item);
        return $theme->exists() ? (string) $theme->get('Version') : null;
    }

    /** @return string[] */
    private function check_urls(): array
    {
        return $this->check instanceof Update_Health_Check ? $this->check->urls() : [ home_url('/') ];
    }

    /**
     * Tables named after the package folder on this site's prefix
     * (wp_<slug>, wp_<slug>_*), never a core table or one of WP MCP's.
     *
     * @return string[]
     */
    private static function owned_tables(string $kind, string $item): array
    {
        global $wpdb;

        $norm = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(self::slug($kind, $item))), '_');
        $out  = [];
        if (strlen($norm) >= 3) {
            $base = $wpdb->prefix . $norm;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Enumerating a package's tables; no core API, and a cached list would miss a table the update created.
            $found = (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($base) . '%'));
            $core  = array_values((array) $wpdb->tables('all', true));
            foreach ($found as $table) {
                $table = (string) $table;
                $bare = (string) substr($table, strlen($wpdb->prefix));
                if (($table === $base || 0 === strpos($table, $base . '_')) && ! in_array($table, $core, true) && 0 !== strpos($bare, 'wpmcp_')) {
                    $out[] = $table;
                }
            }
        }

        $out = (array) apply_filters('wpmcp_safe_update_owned_tables', $out, $kind, $item);
        $out = array_values(array_unique(array_filter(array_map('strval', $out), static fn (string $t): bool => 0 === strpos($t, $wpdb->prefix))));
        sort($out);
        return $out;
    }

    /** @param string[] $tables */
    private static function tables_bytes(array $tables): int
    {
        global $wpdb;
        if ([] === $tables) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($tables), '%s'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The IN list is only %s placeholders, one per table, bound by prepare(); sizes must be live.
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name IN ($placeholders)", $tables));
    }

    /** Dump $tables into the operation's backup directory; returns the dump path. */
    private static function dump_tables(string $operation_id, array $tables): string
    {
        $path = File_Backup::operation_dir($operation_id) . '/' . self::TABLES_DUMP;
        if (false === file_put_contents($path, '')) {
            throw new \RuntimeException('the dump file could not be created');
        }
        $buffer = '';
        $flush  = static function (string $chunk) use ($path): void {
            if ('' !== $chunk && false === file_put_contents($path, $chunk, FILE_APPEND)) {
                throw new \RuntimeException('writing the dump failed (out of disk space?)');
            }
        };
        (new Db_Dumper())->dump(
            static function (string $sql) use (&$buffer, $flush): void {
                $buffer .= $sql;
                if (strlen($buffer) >= 1048576) {
                    $flush($buffer);
                    $buffer = '';
                }
            },
            $tables
        );
        $flush($buffer);
        return $path;
    }

    /**
     * Put the package's tables back from the dump, and drop tables named
     * after the package that the update created.
     *
     * @param string[] $tables The owned tables when the snapshot was taken.
     * @return string[] warnings
     */
    private static function restore_tables(string $kind, string $item, string $dump, array $tables): array
    {
        global $wpdb;
        $warnings = [];

        if ('' !== $dump && is_file($dump)) {
            try {
                $importer = new Sql_Importer($dump, new Sql_Import_Policy($wpdb->prefix, $tables));
                $scan     = $importer->scan();
                $result   = $importer->import($scan['placeholder']);
                if (null !== $result['failure']) {
                    $warnings[] = 'Some of the package\'s table rows could not be put back.';
                }
            } catch (\Throwable $e) {
                $warnings[] = 'The package\'s tables could not be put back: ' . $e->getMessage();
            }
        } elseif ([] !== $tables) {
            $warnings[] = 'The dump of the package\'s tables is gone, so they were not put back.';
        }

        foreach (array_diff(self::owned_tables($kind, $item), $tables) as $created) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Drops a table named after the package that the rolled-back update created; the name comes from SHOW TABLES on this site's prefix and is bound with %i.
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $created));
        }

        return $warnings;
    }

    /** The WHERE clause for the options a snapshot keeps: no transients, no cron, none of WP MCP's own. */
    private static function options_where(): string
    {
        global $wpdb;
        return (string) $wpdb->prepare(
            'option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name <> %s',
            $wpdb->esc_like('_transient_') . '%',
            $wpdb->esc_like('_site_transient_') . '%',
            $wpdb->esc_like('wpmcp_') . '%',
            'cron'
        );
    }

    private static function options_bytes(): int
    {
        global $wpdb;
        $where = self::options_where();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where was built by prepare() in options_where(); the size must be live.
        return (int) $wpdb->get_var("SELECT COALESCE(SUM(LENGTH(option_value)), 0) FROM {$wpdb->options} WHERE {$where}");
    }

    /**
     * Every kept option: name => [raw value, autoload, hash].
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private static function capture_options(): array
    {
        global $wpdb;
        $where = self::options_where();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where was built by prepare() in options_where(); a snapshot must read the live rows.
        $rows = (array) $wpdb->get_results("SELECT option_name, option_value, autoload, MD5(option_value) AS h FROM {$wpdb->options} WHERE {$where}", ARRAY_A);
        $out  = [];
        foreach ($rows as $row) {
            $out[ (string) $row['option_name'] ] = [ (string) $row['option_value'], (string) $row['autoload'], (string) $row['h'] ];
        }
        return $out;
    }

    /** @return array<string, string> name => hash of the kept options now */
    private static function option_hashes(): array
    {
        global $wpdb;
        $where = self::options_where();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where was built by prepare() in options_where(); the comparison must read the live rows.
        $rows = (array) $wpdb->get_results("SELECT option_name, MD5(option_value) AS h FROM {$wpdb->options} WHERE {$where}", ARRAY_A);
        $out  = [];
        foreach ($rows as $row) {
            $out[ (string) $row['option_name'] ] = (string) $row['h'];
        }
        return $out;
    }

    /**
     * The options the update added, changed or removed, with their values
     * before it, so restore can put exactly those back.
     *
     * @param array<string, array{0: string, 1: string, 2: string}> $before
     * @return array{names: string[], values?: array<string, array{existed: bool, raw?: string, autoload?: string}>}
     */
    private static function changed_options(array $before): array
    {
        $now     = self::option_hashes();
        $changed = [];
        foreach ($now as $name => $hash) {
            if (! isset($before[ $name ])) {
                $changed[ $name ] = [ 'existed' => false ];
            } elseif ($before[ $name ][2] !== $hash) {
                $changed[ $name ] = [ 'existed' => true, 'raw' => $before[ $name ][0], 'autoload' => $before[ $name ][1] ];
            }
        }
        foreach ($before as $name => $row) {
            if (! isset($now[ $name ])) {
                $changed[ $name ] = [ 'existed' => true, 'raw' => $row[0], 'autoload' => $row[1] ];
            }
        }

        $out   = [ 'names' => array_keys($changed) ];
        $bytes = array_sum(array_map(static fn (array $c): int => strlen($c['raw'] ?? ''), $changed));
        if ($bytes <= self::MAX_KEPT_OPTION_BYTES) {
            $out['values'] = $changed;
        }
        return $out;
    }

    /**
     * Put back what Safe_Mutation's folder restore does not: the tables,
     * every kept option as it was, and the package's active state.
     *
     * @param string[]                                              $tables
     * @param array<string, array{0: string, 1: string, 2: string}> $before
     * @return string[] warnings
     */
    private static function put_back(string $kind, string $item, string $dump, array $tables, array $before, array $active): array
    {
        $warnings = self::restore_tables($kind, $item, $dump, $tables);

        $now     = self::option_hashes();
        $changes = [];
        foreach ($now as $name => $hash) {
            if (! isset($before[ $name ])) {
                $changes[ $name ] = [ 'existed' => false ];
            }
        }
        foreach ($before as $name => $row) {
            if (! isset($now[ $name ]) || $now[ $name ] !== $row[2]) {
                $changes[ $name ] = [ 'existed' => true, 'raw' => $row[0], 'autoload' => $row[1] ];
            }
        }
        self::put_options($changes);
        self::reactivate($kind, $item, $active);

        return array_merge($warnings, Rollback_Service::take_warnings());
    }

    /** @param array<string, array{existed: bool, raw?: string, autoload?: string}> $changes */
    private static function put_options(array $changes): void
    {
        foreach ($changes as $name => $change) {
            $name = (string) $name;
            if (empty($change['existed'])) {
                delete_option($name);
                continue;
            }
            $autoload = (string) ($change['autoload'] ?? '');
            $flag     = in_array($autoload, [ 'yes', 'on', 'auto-on' ], true) ? true : (in_array($autoload, [ 'no', 'off', 'auto-off' ], true) ? false : null);
            update_option($name, maybe_unserialize((string) ($change['raw'] ?? '')), $flag);
        }
    }

    /** @return array{site: bool, network: bool} */
    private static function active_state(string $kind, string $item): array
    {
        if ('theme' === $kind) {
            return [ 'site' => false, 'network' => false ];
        }
        if (! function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return [
            'site'    => in_array($item, (array) get_option('active_plugins', []), true),
            'network' => is_multisite() && is_plugin_active_for_network($item),
        ];
    }

    /**
     * Put a plugin back on the active lists it was on. Core's upgrader
     * deactivates a plugin while it swaps it, and core's own update screen
     * reactivates it silently afterwards; this does the same without
     * loading the new code into this request, so a version that fatals on
     * load breaks the site check, not the update call.
     */
    private static function reactivate(string $kind, string $item, array $active): void
    {
        if ('plugin' !== $kind) {
            return;
        }
        if (! empty($active['site'])) {
            Plugin_State::locked(static function () use ($item): void {
                wp_cache_delete('alloptions', 'options');
                $list = array_values(array_filter((array) get_option('active_plugins', []), 'is_string'));
                if (! in_array($item, $list, true)) {
                    $list[] = $item;
                    sort($list);
                    update_option('active_plugins', $list);
                }
            });
        }
        if (! empty($active['network']) && is_multisite()) {
            $network = (array) get_site_option('active_sitewide_plugins', []);
            if (! isset($network[ $item ])) {
                $network[ $item ] = time();
                update_site_option('active_sitewide_plugins', $network);
            }
        }
    }

    /** @return array<string, array> */
    private static function runs(): array
    {
        $runs = get_option(self::RUNS_OPTION, []);
        return is_array($runs) ? $runs : [];
    }

    private static function save_run(array $run): void
    {
        $runs                   = self::runs();
        $runs[ $run['run_id'] ] = $run;
        while (count($runs) > self::MAX_RUNS_KEPT) {
            $oldest = (string) array_key_first($runs);
            foreach ((array) ($runs[ $oldest ]['packages'] ?? []) as $package) {
                if (! empty($package['restorable']) && ! empty($package['operation_id'])) {
                    File_Backup::delete_backup_dir((string) $package['operation_id']);
                }
            }
            unset($runs[ $oldest ]);
        }
        update_option(self::RUNS_OPTION, $runs, false);
    }

    /**
     * Drop the snapshots of kept updates whose retention window has passed:
     * the folder archive, the tables dump and the kept option values.
     */
    public static function prune(): void
    {
        $runs    = self::runs();
        $changed = false;
        $now     = self::now();
        foreach ($runs as $run_id => $run) {
            foreach ((array) ($run['packages'] ?? []) as $item => $package) {
                if (empty($package['restorable']) || (int) ($package['expires_at'] ?? 0) > $now) {
                    continue;
                }
                if (! empty($package['operation_id'])) {
                    File_Backup::delete_backup_dir((string) $package['operation_id']);
                }
                $package['restorable'] = false;
                $package['expired']    = true;
                unset($package['options']['values']);
                $runs[ $run_id ]['packages'][ $item ] = $package;
                $changed = true;
            }
        }
        if ($changed) {
            update_option(self::RUNS_OPTION, $runs, false);
        }
    }

    /** A run as the caller sees it: no option values, dates as ISO 8601. */
    private static function view(array $run): array
    {
        $packages = [];
        foreach ((array) ($run['packages'] ?? []) as $package) {
            $row = [
                'item'   => (string) ($package['item'] ?? ''),
                'status' => (string) ($package['status'] ?? 'pending'),
            ];
            foreach ([ 'from_version', 'to_version', 'operation_id', 'failed_step', 'message', 'checks', 'tables', 'warnings' ] as $key) {
                if (isset($package[ $key ]) && [] !== $package[ $key ]) {
                    $row[ $key ] = $package[ $key ];
                }
            }
            if (isset($package['options']['names'])) {
                $row['options_changed'] = $package['options']['names'];
            }
            if (in_array($row['status'], [ 'updated', 'restored' ], true)) {
                $row['restorable'] = ! empty($package['restorable']);
                if (! empty($package['restorable'])) {
                    $row['snapshot_expires_at'] = gmdate('c', (int) $package['expires_at']);
                }
            }
            $packages[] = $row;
        }

        $out = [
            'run_id'     => (string) $run['run_id'],
            'type'       => (string) $run['type'],
            'status'     => (string) $run['status'],
            'started_at' => gmdate('c', (int) $run['started_at']),
            'packages'   => $packages,
        ];
        if (isset($run['finished_at'])) {
            $out['finished_at'] = gmdate('c', (int) $run['finished_at']);
        }
        return $out;
    }

    /**
     * Default upgrader: core's Plugin_Upgrader or Theme_Upgrader, through
     * bulk_upgrade() so core turns maintenance mode on only while an active
     * package is swapped, and off again straight after.
     *
     * @return true|\WP_Error
     */
    public static function upgrade_package(string $kind, string $item)
    {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $skin     = new \Automatic_Upgrader_Skin();
        $upgrader = 'theme' === $kind ? new \Theme_Upgrader($skin) : new \Plugin_Upgrader($skin);
        $results  = $upgrader->bulk_upgrade([ $item ]);
        $result   = is_array($results) ? ($results[ $item ] ?? false) : $results;

        if (is_wp_error($result)) {
            return $result;
        }
        if (true === $result || (is_array($result) && [] !== $result)) {
            return true;
        }
        $messages = $skin->get_upgrade_messages();
        return new \WP_Error('wpmcp_update_failed', (string) (end($messages) ?: 'the upgrader reported a failure'));
    }
}
