<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * What the wpForo and Asgaros Forum packs (issue #671) share: the plugin
 * refusal, paging, a writer that runs one write under one
 * 'plugin_table_rows' snapshot, and the next id of a table.
 */
final class Forum_Pack_Support
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads the forum plugins' own tables live.

    public const DEFAULT_PAGE_SIZE = 20;

    public const MAX_PAGE_SIZE = 100;

    /**
     * The refusal for a forum plugin that is not loaded: plugin_inactive when
     * its files are installed, plugin_missing when they are not.
     *
     * @return array{code: string, message: string, data: array}
     */
    public static function plugin_refusal(string $plugin): array
    {
        $out = Plugin_Refusal::make('plugin_missing', $plugin);
        if ('inactive' === ($out['data']['state'] ?? '')) {
            $out['code'] = 'plugin_inactive';
        }
        return $out;
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function tables_presence(string $plugin, array $tables)
    {
        foreach ($tables as $table) {
            if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
                return [
                    'code'    => 'forum_tables_missing',
                    'message' => sprintf('%s\'s table %s does not exist on this site. Deactivate and reactivate %s so it creates its tables, then try again.', $plugin, $table, $plugin),
                    'data'    => [ 'plugins_url' => admin_url('plugins.php') ],
                ];
            }
        }
        return true;
    }

    /** @return array<string, array<string, mixed>> */
    public static function paging_schema(): array
    {
        return [
            'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
            'offset'    => [ 'type' => 'integer', 'minimum' => 0 ],
        ];
    }

    /** @return array{0: int, 1: int} page size and offset */
    public static function paging(array $args): array
    {
        return [
            max(1, min(self::MAX_PAGE_SIZE, (int) ($args['page_size'] ?? self::DEFAULT_PAGE_SIZE))),
            max(0, (int) ($args['offset'] ?? 0)),
        ];
    }

    public static function next_offset(int $offset, int $count, int $total): ?int
    {
        return $offset + $count < $total ? $offset + $count : null;
    }

    /** @return array{code: string, message: string, data: array} */
    public static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /** The id the next insert takes: MAX(id) + 1 or the table's auto-increment, whichever is higher. */
    public static function next_id(string $table, string $column = 'id'): int
    {
        global $wpdb;
        $max    = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(%i) FROM %i', $column, $table));
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $table), ARRAY_A);
        $auto   = is_array($status) ? (int) ($status['Auto_increment'] ?? 0) : 0;
        return max($max + 1, $auto);
    }

    /**
     * Run $write under a 'plugin_table_rows' snapshot of one set of rows, for
     * a self-snapshotting op that touches several tables; returns the
     * operation id. A null $write only takes the snapshot.
     */
    public static function snapshotted(string $kind, string $suffix, array $ids, array $context, array $args, ?callable $write = null): string
    {
        $out = Safe_Mutation::run(
            [
                'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                'object_id'   => Plugin_Table_Rows_Snapshot::key($kind, $suffix, $ids),
                'session_id'  => (string) $context['session_id'],
                'tool_name'   => (string) $context['tool_name'],
                'args'        => [ 'operation' => (string) $context['operation'], 'args' => $args ],
            ],
            static function () use ($write): bool {
                if (null !== $write) {
                    $write();
                }
                return true;
            }
        );
        return (string) $out['operation_id'];
    }

    /** A user's display name, or '' for a guest or a removed user. */
    public static function user_name(int $user_id): string
    {
        if ($user_id <= 0) {
            return '';
        }
        $user = get_userdata($user_id);
        return $user instanceof \WP_User ? (string) $user->display_name : '';
    }
}
