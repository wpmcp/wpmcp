<?php

namespace WPMCP\Tools\Database;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Insert a row into a table via $wpdb->insert() (parameterized; never raw
 * write SQL). Disabled by default: sites must opt in via the
 * wpmcp_enable_db_writes filter. Refuses protected tables (users/usermeta
 * by default; see Database_Guard::is_protected()).
 *
 * Insertion has no prior state to snapshot and is easily undone by a
 * delete-rows call against the new primary key, so this is not routed
 * through the safety core; see Update_Rows/Delete_Rows for the before-image
 * approach used where an existing row would otherwise be silently changed.
 */
class Insert_Row
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

        $table = Database_Guard::valid_table((string) ($args['table'] ?? ''));
        if (is_wp_error($table)) {
            throw new \RuntimeException($table->get_error_message());
        }

        if (Database_Guard::is_protected($table)) {
            throw new \RuntimeException("Refusing to write to protected table \"{$table}\".");
        }

        global $wpdb;
        $affected = $wpdb->insert($table, $data);
        if (false === $affected) {
            throw new \RuntimeException($wpdb->last_error ?: 'Insert failed.');
        }

        return [
            'table'     => $table,
            'insert_id' => (int) $wpdb->insert_id,
            'affected'  => (int) $affected,
        ];
    }
}
