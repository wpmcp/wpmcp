<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * All-in-One WP Migration backups (issue #694): the .wpress archives the
 * plugin keeps in its backups folder (AI1WM_BACKUPS_PATH, by default
 * wp-content/ai1wm-backups), listed by name, date and size. The folder path
 * is never returned and neither is a download link: an archive is a full
 * site clone.
 *
 * Starting an export is not offered: the plugin's export runs as a
 * multi-request state machine driven by its own controller, so it stays out
 * of scope for this slice (plan 694).
 */
final class AIOWM_Status
{
    private const MAX_LIST = 50;

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        return [
            'list-aio-wp-migration-backups' => Ops_Status_Packs::read_op(
                'List All-in-One WP Migration backups (.wpress), newest first: name, date, size. No paths or links',
                static fn () => Ops_Status_Packs::presence(self::is_active(), 'aio_wp_migration_inactive', 'All-in-One WP Migration'),
                static fn (array $args): array => self::list_backups((int) ($args['limit'] ?? 20)),
                [ 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIST ] ]
            ),
        ];
    }

    /** Whether All-in-One WP Migration is loaded, filterable through wpmcp_aio_wp_migration_active. */
    public static function is_active(): bool
    {
        return (bool) apply_filters('wpmcp_aio_wp_migration_active', defined('AI1WM_PLUGIN_NAME'));
    }

    private static function dir(): string
    {
        $dir = defined('AI1WM_BACKUPS_PATH') ? (string) constant('AI1WM_BACKUPS_PATH') : WP_CONTENT_DIR . '/ai1wm-backups';
        return (string) apply_filters('wpmcp_aio_wp_migration_backups_dir', $dir);
    }

    /** @return array{backups:array<int,array<string,mixed>>,total:int} */
    private static function list_backups(int $limit): array
    {
        $files = glob(trailingslashit(self::dir()) . '*.wpress');
        $rows  = [];
        foreach (is_array($files) ? $files : [] as $file) {
            if (! is_file($file) || is_link($file)) {
                continue;
            }
            $mtime  = (int) filemtime($file);
            $rows[] = [
                'name' => sanitize_file_name(basename($file)),
                'date' => Ops_Status_Packs::iso_time($mtime),
                'size' => (int) filesize($file),
                '_t'   => $mtime,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['_t'] <=> $a['_t']);
        $total = count($rows);
        $rows  = array_map(static function (array $row): array {
            unset($row['_t']);
            return $row;
        }, array_slice($rows, 0, max(1, min(self::MAX_LIST, $limit))));

        return [ 'backups' => $rows, 'total' => $total ];
    }
}
