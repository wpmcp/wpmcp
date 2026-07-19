<?php

namespace WPMCP\Tools\Database;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Update rows matching an equality WHERE via $wpdb->update() (parameterized;
 * never raw write SQL). Disabled by default: sites must opt in via the
 * wpmcp_enable_db_writes filter. Always requires confirm:true. A non-empty
 * WHERE is mandatory, which prevents an unqualified/unbounded update (a
 * bare, WHERE-less write that would touch every row in a table); it does not
 * limit how many rows a broad but valid WHERE can still match. Refuses
 * protected tables (users/usermeta by default).
 *
 * Recoverability (issue #82): when an exact restore can genuinely be
 * promised — the table has a PRIMARY KEY, the WHERE matched no more rows
 * than the before-image cap, and the captured values survive JSON encoding
 * losslessly (no raw binary) — the write routes through Safe_Mutation like
 * every other recoverable tool: the matched rows' full before-images are
 * snapshotted to the operation history FIRST, the response reports
 * recoverable:true with an operation_id, and rollback-operation /
 * rollback-session restore the exact prior column values (see
 * Rollback_Service::apply_db_rows_snapshot(), which re-validates the
 * snapshot against the live schema and warns when rows drifted since the
 * operation). Documented caveats: the restore is per-captured-row, so rows
 * created by third parties after the operation are untouched, and
 * concurrent edits made after the operation are overwritten by the
 * before-image (with a warning).
 *
 * When an exact restore CANNOT be promised (no primary key, cap exceeded,
 * binary values), the behavior is the pre-#82 one and the response stays
 * HONESTLY recoverable:false with a recoverable_reason: the before-image
 * still goes to Database_Guard's capped audit log so a human can manually
 * reconstruct the prior values, and no rollback capability is claimed.
 */
class Update_Rows
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
            throw new \InvalidArgumentException('Updating raw table rows requires confirm:true.');
        }

        $data = (array) ($args['data'] ?? []);
        if ([] === $data) {
            throw new \InvalidArgumentException('A non-empty data object is required.');
        }

        $where = (array) ($args['where'] ?? []);
        if ([] === $where) {
            throw new \InvalidArgumentException('A non-empty where object is required for update.');
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

        $mutation = static function () use ($table, $data, $where): int {
            global $wpdb;
            $affected = $wpdb->update($table, $data, $where);
            if (false === $affected) {
                throw new \RuntimeException($wpdb->last_error ?: 'Update failed.');
            }
            return (int) $affected;
        };

        if (! $probe['recoverable']) {
            $affected = $mutation();
            Database_Guard::audit('update', $table, $affected, $before);

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
                'tool_name'           => 'update-rows',
                'args'                => $args,
                'extra_snapshot_data' => [
                    'table'       => $table,
                    'operation'   => 'update',
                    'primary_key' => $probe['primary_key'],
                    'where'       => $where,
                    'set'         => $data,
                    'rows'        => $before,
                ],
            ],
            $mutation
        );

        Database_Guard::audit('update', $table, (int) $out['result'], $before);

        return [
            'table'        => $table,
            'affected'     => (int) $out['result'],
            'before_image' => $before,
            'recoverable'  => true,
            'operation_id' => $out['operation_id'],
            'caveats'      => [
                'Rollback restores the exact captured before-images of the matched rows; edits made to those rows after this operation will be overwritten (with a warning).',
            ],
        ];
    }
}
