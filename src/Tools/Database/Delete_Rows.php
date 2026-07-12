<?php

namespace WPMCP\Tools\Database;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delete rows matching an equality WHERE via $wpdb->delete() (parameterized;
 * never raw write SQL). Disabled by default: sites must opt in via the
 * wpmcp_enable_db_writes filter. Always requires confirm:true, and a
 * non-empty WHERE is mandatory, so this can never turn into an unbounded
 * delete of every row in a table. Refuses protected tables (users/usermeta
 * by default).
 *
 * Recoverability: same stance as Update_Rows (see its docblock) — this is
 * NOT routed through Safe_Mutation/Snapshot, whose apply_snapshot() only
 * knows how to restore a small fixed set of object types. The rows about to
 * be deleted are captured as a before-image and written to Database_Guard's
 * capped audit log BEFORE the delete runs; the response is honest that
 * 'recoverable' is false rather than claiming a rollback this tool cannot
 * perform.
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

        $before = Database_Guard::before_image($table, $where);

        global $wpdb;
        $affected = $wpdb->delete($table, $where);
        if (false === $affected) {
            throw new \RuntimeException($wpdb->last_error ?: 'Delete failed.');
        }

        Database_Guard::audit('delete', $table, (int) $affected, $before);

        return [
            'table'        => $table,
            'affected'     => (int) $affected,
            'before_image' => $before,
            'recoverable'  => false,
        ];
    }
}
