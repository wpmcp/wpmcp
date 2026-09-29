<?php

namespace WPMCP\Tools\Packages;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Tools\Backup\Archive_Locator;
use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Run_Backup_Job;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WordPress core updates and plugin/theme auto-update settings (issue #389).
 *
 * type=core applies the core update the update_core transient offers, or,
 * when core files are already current but the database is behind, runs the
 * database upgrade. Replacing core files is outside what Snapshot_Store can
 * capture, so it is never snapshot-rolled-back; the undo path is the
 * full-site backup this tool insists on first: a completed type=full,
 * scope=all backup job whose archive still exists and is younger than the
 * wpmcp_core_update_backup_max_age window (one hour by default). It also
 * requires confirm:true, and an optional expected_version refuses the run
 * when the offer has changed since the caller looked.
 *
 * type=plugin|theme toggles one package's auto-update flag. Those flags
 * live in the auto_update_plugins / auto_update_themes options, so the
 * write goes through Safe_Mutation and rollback-operation restores the
 * prior list. On multisite they are network site options, which the
 * option snapshot does not cover, so the toggle refuses there.
 *
 * The core and database upgraders are constructor-injected callables so the
 * gates can be tested without ever replacing core files or calling out to
 * wordpress.org; production uses the defaults below.
 */
class Manage_Updates
{
    public const DEFAULT_BACKUP_MAX_AGE = HOUR_IN_SECONDS;

    /** @var callable(object): mixed */
    private $core_upgrader;

    /** @var callable(): void */
    private $db_upgrader;

    public function __construct(?callable $core_upgrader = null, ?callable $db_upgrader = null)
    {
        $this->core_upgrader = $core_upgrader ?? [self::class, 'upgrade_core'];
        $this->db_upgrader   = $db_upgrader ?? [self::class, 'upgrade_database'];
    }

    public function handle(array $args): array
    {
        $type = isset($args['type']) ? (string) $args['type'] : '';

        if ('core' === $type) {
            return $this->core($args);
        }
        if ('plugin' === $type || 'theme' === $type) {
            return $this->auto_update($type, $args);
        }

        throw new \InvalidArgumentException('type must be core, plugin or theme.');
    }

    private function core(array $args): array
    {
        if (true !== ($args['confirm'] ?? null)) {
            throw new \InvalidArgumentException('A core update replaces WordPress files and cannot be snapshot-rolled-back. Pass confirm:true to proceed.');
        }

        if (! function_exists('get_core_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        $offer      = self::current_offer();
        $db_pending = self::db_upgrade_needed();
        $expected   = isset($args['expected_version']) ? (string) $args['expected_version'] : '';

        if ('' !== $expected && (null === $offer || (string) $offer->current !== $expected)) {
            throw new \RuntimeException(sprintf(
                'Refusing: expected WordPress %s but the available update is %s. List updates again and retry.',
                esc_html($expected),
                esc_html(null === $offer ? 'none' : (string) $offer->current)
            ));
        }

        $from = (string) get_bloginfo('version');

        if (null === $offer && ! $db_pending) {
            return ['type' => 'core', 'up_to_date' => true, 'updated' => false, 'version' => $from];
        }

        $backup_job_id = self::fresh_backup_job_id();

        $out = [
            'type'          => 'core',
            'up_to_date'    => false,
            'updated'       => false,
            'db_upgraded'   => false,
            'from_version'  => $from,
            'backup_job_id' => $backup_job_id,
            'rollbackable'  => false,
        ];

        if (null !== $offer) {
            if (! Package_Guard::filesystem_ready()) {
                throw new \RuntimeException('Direct filesystem access is required to update WordPress core.');
            }

            $result = ($this->core_upgrader)($offer);
            if (is_wp_error($result) || ! $result) {
                $message = is_wp_error($result) ? $result->get_error_message() : 'unknown error';
                throw new \RuntimeException(sprintf(
                    'Core update failed: %s. Restore backup job %d with restore-site-backup if the site is broken.',
                    esc_html($message),
                    (int) $backup_job_id
                ));
            }

            $out['updated']     = true;
            $out['new_version'] = is_string($result) ? $result : (string) $offer->current;
        } else {
            ($this->db_upgrader)();
            $out['db_upgraded'] = true;
        }

        $out['warning'] = sprintf(
            'Core changes cannot be snapshot-rolled-back. To revert, restore backup job %d with restore-site-backup (include_files).',
            (int) $backup_job_id
        );

        return $out;
    }

    /** The offered core upgrade for this site's locale, or null when none is offered. */
    public static function current_offer(): ?object
    {
        if (! function_exists('get_core_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        $updates = get_core_updates();
        if (! is_array($updates)) {
            return null;
        }

        foreach ($updates as $update) {
            if (is_object($update) && 'upgrade' === ($update->response ?? '')) {
                return $update;
            }
        }

        return null;
    }

    /** Whether the stored database schema is older than the running core expects. */
    public static function db_upgrade_needed(): bool
    {
        return (int) get_option('db_version') < (int) ($GLOBALS['wp_db_version'] ?? 0);
    }

    /**
     * The newest completed full-site backup that is still on disk and inside
     * the freshness window, or a refusal that says how to get one.
     */
    private static function fresh_backup_job_id(): int
    {
        $max_age = (int) apply_filters('wpmcp_core_update_backup_max_age', self::DEFAULT_BACKUP_MAX_AGE);
        $cutoff  = time() - max(0, $max_age);

        foreach (Backup_Job_Store::list('completed') as $job) {
            if ('all' !== Run_Backup_Job::archive_scope($job) || ! empty($job['result']['deleted'])) {
                continue;
            }
            if ((int) ($job['updated_at'] ?? 0) < $cutoff) {
                continue;
            }
            try {
                Archive_Locator::resolve(['job_id' => (int) $job['id']]);
            } catch (\RuntimeException $e) {
                continue;
            }
            return (int) $job['id'];
        }

        throw new \RuntimeException(sprintf(
            'Refusing: a core update needs a completed full-site backup (trigger-backup type=full) from the last %d minutes, and none was found.',
            (int) ceil(max(0, $max_age) / 60)
        ));
    }

    private function auto_update(string $type, array $args): array
    {
        if (is_multisite()) {
            throw new \RuntimeException('Auto-update settings are network-wide on multisite; change them in Network Admin.');
        }

        $cap = 'plugin' === $type ? 'update_plugins' : 'update_themes';
        if (! current_user_can($cap)) {
            throw new \RuntimeException(sprintf('Changing %s auto-updates requires the %s capability.', esc_html($type), esc_html($cap)));
        }

        $item = isset($args['item']) ? (string) $args['item'] : '';
        if ('' === $item) {
            throw new \InvalidArgumentException('item (plugin file or theme stylesheet) is required.');
        }
        if (! is_bool($args['enabled'] ?? null)) {
            throw new \InvalidArgumentException('enabled (true or false) is required.');
        }
        $enabled = $args['enabled'];

        if ('plugin' === $type) {
            if (! function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            if (! isset(get_plugins()[ $item ])) {
                throw new \RuntimeException(sprintf('Plugin "%s" was not found.', esc_html($item)));
            }
        } elseif (! wp_get_theme($item)->exists()) {
            throw new \RuntimeException(sprintf('Theme "%s" was not found.', esc_html($item)));
        }

        $option = 'auto_update_' . $type . 's';

        $out = Safe_Mutation::run(
            [
                'object_type'         => 'option',
                'object_id'           => $option,
                'session_id'          => (string) ($args['session_id'] ?? 'default'),
                'tool_name'           => 'manage-updates',
                'args'                => $args,
                'extra_snapshot_data' => ['restore_capability' => $cap],
            ],
            static function () use ($option, $item, $enabled) {
                $list = array_values(array_filter((array) get_option($option, []), 'is_string'));
                $list = array_values(array_diff($list, [ $item ]));
                if ($enabled) {
                    $list[] = $item;
                }
                update_option($option, $list);
                return $list;
            }
        );

        return [
            'operation_id' => $out['operation_id'],
            'type'         => $type,
            'item'         => $item,
            'auto_update'  => $enabled,
            'effective'    => wp_is_auto_update_enabled_for_type($type),
        ];
    }

    /**
     * Default core upgrader: core's own Core_Upgrader with the offered
     * package, which also schedules core's database upgrade request.
     *
     * @return string|false|\WP_Error
     */
    public static function upgrade_core(object $offer)
    {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';

        $upgrader = new \Core_Upgrader(new \Automatic_Upgrader_Skin());
        return $upgrader->upgrade($offer);
    }

    /** Default database upgrader: core's wp_upgrade() for the running version. */
    public static function upgrade_database(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        wp_upgrade();
    }
}
