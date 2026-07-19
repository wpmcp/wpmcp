<?php

namespace WPMCP\Tools\Database;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delete rows matching an equality WHERE via $wpdb->delete() (parameterized;
 * never raw write SQL). Disabled by default: sites must opt in via the
 * wpmcp_enable_db_writes filter. Always requires confirm:true, and a
 * non-empty WHERE is mandatory, which prevents an unqualified/unbounded
 * delete (a bare, WHERE-less write that would touch every row in a table);
 * it does not limit how many rows a broad but valid WHERE can still match.
 * Refuses protected tables (users/usermeta by default).
 *
 * Recoverability (issue #82): same stance as Update_Rows (see its docblock).
 * When the table has a PRIMARY KEY, the WHERE matched no more rows than the
 * before-image cap, and the captured values are JSON-safe, the delete routes
 * through Safe_Mutation: the doomed rows' full before-images are snapshotted
 * to the operation history first, the response reports recoverable:true with
 * an operation_id, and rollback reinserts the rows WITH their original
 * primary-key ids (the insert carries the captured PK values explicitly).
 * Documented caveats: the table's auto-increment counter is not rewound, so
 * ids handed out between the delete and the rollback are skipped, and if a
 * deleted row's id has since been reclaimed by a new row, rollback restores
 * the captured before-image over it (with a warning).
 *
 * When an exact restore cannot be promised, the pre-#82 behavior is kept and
 * the response stays honestly recoverable:false with a recoverable_reason;
 * the before-image still goes to the capped audit log.
 */
class Delete_Rows
{
    public static function is_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_db_writes', false);
    }

    public function handle(array $args): array
    {
        if (! self::is_enabled()) {
            throw new \RuntimeException('Database write tools are disabled. Enable them with the wpmcp_enable_db_writes filter.');
        }

        if (true !== ($args['confirm'] ?? null)) {
            throw new \InvalidArgumentException('Deleting rows requires confirm:true.');
        }

        $where = (array) ($args['where'] ?? []);
        if ([] === $where) {
            throw new \InvalidArgumentException('A non-empty where object is required for delete.');
        }

        $table = Database_Guard::valid_table((string) ($args['table'] ?? ''));
        if (is_wp_error($table)) {
            throw new \RuntimeException($table->get_error_message());
        }

        if (Database_Guard::is_protected($table)) {
            throw new \RuntimeException("Refusing to write to protected table \"{$table}\".");
        }

        $probe  = Database_Guard::recoverability_probe($table, $where);
        $before = $probe['rows'];

        $mutation = static function () use ($table, $where): int {
            global $wpdb;
            $affected = $wpdb->delete($table, $where);
            if (false === $affected) {
                throw new \RuntimeException($wpdb->last_error ?: 'Delete failed.');
            }
            return (int) $affected;
        };

        if (! $probe['recoverable']) {
            $affected = $mutation();
            Database_Guard::audit('delete', $table, $affected, $before);

            return [
                'table'              => $table,
                'affected'           => $affected,
                'before_image'       => $before,
                'recoverable'        => false,
                'recoverable_reason' => 'Not snapshot-backed: ' . $probe['reason'] . '.',
            ];
        }

        $out = Safe_Mutation::run(
            [
                'object_type'         => 'db_rows',
                'object_id'           => $table,
                'session_id'          => (string) ($args['session_id'] ?? 'default'),
                'tool_name'           => 'delete-rows',
                'args'                => $args,
                'extra_snapshot_data' => [
                    'table'       => $table,
                    'operation'   => 'delete',
                    'primary_key' => $probe['primary_key'],
                    'where'       => $where,
                    'set'         => [],
                    'rows'        => $before,
                ],
            ],
            $mutation
        );

        Database_Guard::audit('delete', $table, (int) $out['result'], $before);

        return [
            'table'        => $table,
            'affected'     => (int) $out['result'],
            'before_image' => $before,
            'recoverable'  => true,
            'operation_id' => $out['operation_id'],
            'caveats'      => [
                'Rollback reinserts the captured rows with their original primary-key ids; the auto-increment counter is not rewound, and a reclaimed id is overwritten with the captured before-image (with a warning).',
            ],
        ];
    }
}
