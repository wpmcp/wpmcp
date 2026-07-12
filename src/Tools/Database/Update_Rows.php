<?php

namespace WPMCP\Tools\Database;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Update rows matching an equality WHERE via $wpdb->update() (parameterized;
 * never raw write SQL). Disabled by default: sites must opt in via the
 * wpmcp_enable_db_writes filter. A non-empty WHERE is mandatory, which
 * prevents an unqualified/unbounded update (a bare, WHERE-less write that
 * would touch every row in a table); it does not limit how many rows a
 * broad but valid WHERE can still match. Refuses protected tables
 * (users/usermeta by default).
 *
 * Recoverability: this is NOT routed through Safe_Mutation/Snapshot, because
 * that safety core's apply_snapshot() dispatches on a small, fixed set of
 * object types (post/option/user) with bespoke, hand-verified restore logic
 * for each; a generic "restore arbitrary rows in an arbitrary table" path
 * would be a much larger and riskier surface than this tool's scope
 * justifies. Instead, the rows about to be changed are captured as a
 * before-image and written to Database_Guard's capped audit log
 * (Database_Guard::AUDIT_OPTION) BEFORE the write runs, and the response is
 * honest about it: 'recoverable' is always false here, and 'before_image'
 * is returned so a human (or a future targeted rollback tool) can manually
 * reconstruct the prior values. This tool never claims a rollback capability
 * it cannot deliver.
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

        $before = Database_Guard::before_image($table, $where);

        global $wpdb;
        $affected = $wpdb->update($table, $data, $where);
        if (false === $affected) {
            throw new \RuntimeException($wpdb->last_error ?: 'Update failed.');
        }

        Database_Guard::audit('update', $table, (int) $affected, $before);

        return [
            'table'        => $table,
            'affected'     => (int) $affected,
            'before_image' => $before,
            'recoverable'  => false,
        ];
    }
}
