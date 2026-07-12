<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches brief's public interface).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches brief's public interface).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

class Snapshot_Store
{
    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wpmcp_snapshots';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            operation_id CHAR(36) NOT NULL,
            session_id CHAR(36) NOT NULL,
            object_type VARCHAR(32) NOT NULL,
            object_id BIGINT UNSIGNED NOT NULL,
            tool_name VARCHAR(64) NOT NULL,
            args_hash CHAR(64) NOT NULL,
            before_blob LONGBLOB NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY operation_id (operation_id),
            KEY session_id (session_id)
        ) {$charset};");
    }

    public static function save(string $operation_id, string $session_id, array $snapshot, string $tool_name, string $args_hash): int
    {
        global $wpdb;
        $wpdb->insert(self::table_name(), [
            'operation_id' => $operation_id,
            'session_id'   => $session_id,
            'object_type'  => $snapshot['object_type'],
            'object_id'    => $snapshot['object_id'],
            'tool_name'    => $tool_name,
            'args_hash'    => $args_hash,
            'before_blob'  => Snapshot::serialize($snapshot),
            'user_id'      => get_current_user_id(),
            'created_at'   => current_time('mysql', true),
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function get_by_operation(string $operation_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table_name() . " WHERE operation_id = %s", $operation_id), ARRAY_A);
        if (! $row) {
            return null;
        }
        $row['snapshot'] = Snapshot::unserialize($row['before_blob']);
        return $row;
    }

    public static function list_by_session(string $session_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table_name() . " WHERE session_id = %s ORDER BY id DESC", $session_id), ARRAY_A);
    }

    public static function recent(int $limit): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table_name() . " ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
    }

    public static function prune(int $keep): int
    {
        global $wpdb;
        $t = self::table_name();
        $cutoff = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} ORDER BY id DESC LIMIT 1 OFFSET %d", $keep));
        if (null === $cutoff) {
            return 0;
        }
        return (int) $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE id <= %d", $cutoff));
    }
}
