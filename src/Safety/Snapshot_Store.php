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
    /**
     * Snapshots kept per site by default.
     *
     * The single source of truth for the number, so the directory build can
     * read it directly (see scripts/flavors/wporg/strip.php) instead of
     * asking a licence gate what the cap is.
     */
    public const DEFAULT_HISTORY_LIMIT = 20;

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
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            operation_id CHAR(36) NOT NULL,
            session_id CHAR(36) NOT NULL,
            object_type VARCHAR(32) NOT NULL,
            object_id BIGINT(20) UNSIGNED NOT NULL,
            tool_name VARCHAR(64) NOT NULL,
            args_hash CHAR(64) NOT NULL,
            before_blob LONGBLOB NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY operation_id (operation_id),
            KEY session_id (session_id)
        ) {$charset};");
    }

    /**
     * The object_id column is a BIGINT UNSIGNED, so it can only ever store a
     * numeric post/user/etc ID. Object types identified by a string (e.g.
     * 'option', keyed by option name) have no numeric ID to put there; the
     * real identifier already lives inside the serialized snapshot blob
     * (data.name), so the column is simply 0 for those rows. Existing
     * consumers (List_Operations, History_Page) already (int)-cast this
     * column for display, so this is backward compatible.
     */
    private static function db_object_id(array $snapshot): int
    {
        return is_int($snapshot['object_id']) ? $snapshot['object_id'] : 0;
    }

    /**
     * Persist the undo point for one mutation.
     *
     * Raises rather than returning 0 on failure. The previous version
     * ignored the insert's return value and handed back (int) $wpdb->insert_id,
     * which is 0 when nothing was written — and Safe_Mutation read that as
     * success and ran the write anyway. The result was a mutation that
     * reported a real-looking operation_id while its snapshot row did not
     * exist, so list-operations was empty and rollback quietly restored
     * nothing. A backup that fails loudly is recoverable; one that fails
     * silently is worse than none, because it is trusted.
     *
     * @throws Mutation_Failed When the snapshot row could not be written.
     */
    public static function save(string $operation_id, string $session_id, array $snapshot, string $tool_name, string $args_hash): int
    {
        global $wpdb;
        $written = $wpdb->insert(self::table_name(), [
            'operation_id' => $operation_id,
            'session_id'   => $session_id,
            'object_type'  => $snapshot['object_type'],
            'object_id'    => self::db_object_id($snapshot),
            'tool_name'    => $tool_name,
            'args_hash'    => $args_hash,
            'before_blob'  => Snapshot::serialize($snapshot),
            'user_id'      => get_current_user_id(),
            'created_at'   => current_time('mysql', true),
        ]);

        if (false === $written) {
            throw new Mutation_Failed(
                'The change was not made: its undo point could not be saved'
                    . ($wpdb->last_error ? ' (' . $wpdb->last_error . ')' : '') . '.'
            );
        }

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

    /**
     * Ledger columns that identify a row without dragging its before-image
     * along. before_blob is a LONGBLOB holding a whole serialized object, so
     * a consumer that only needs to know WHICH objects were touched (the
     * change-set builder, issue #192) must never SELECT *: on a Pro history
     * limit of PHP_INT_MAX that is an unbounded read straight into PHP
     * memory. Callers that need the before-image fetch it per row via
     * get_by_operation().
     */
    private const INDEX_COLUMNS = 'id, operation_id, session_id, object_type, object_id, tool_name, created_at';

    /**
     * Identify (do not load) the rows of one session, newest first.
     *
     * @return array[] At most $limit rows, before_blob excluded.
     */
    public static function index_by_session(string $session_id, int $limit): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT " . self::INDEX_COLUMNS . " FROM " . self::table_name() . " WHERE session_id = %s ORDER BY id DESC LIMIT %d",
            $session_id,
            $limit
        ), ARRAY_A);
    }

    /**
     * Identify (do not load) the rows written after a ledger row id, newest
     * first. Strictly greater than: the marker row is the caller's "I have
     * already seen this" cursor.
     *
     * @return array[] At most $limit rows, before_blob excluded.
     */
    public static function index_since(int $since_id, int $limit): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT " . self::INDEX_COLUMNS . " FROM " . self::table_name() . " WHERE id > %d ORDER BY id DESC LIMIT %d",
            $since_id,
            $limit
        ), ARRAY_A);
    }

    /**
     * The lowest row id still in the ledger: the retention floor left behind
     * by prune(). A consumer deriving a set of "everything touched since X"
     * is only telling the truth if X is above this floor, so the floor has
     * to be readable. Null when the ledger is empty.
     */
    public static function min_id(): ?int
    {
        global $wpdb;
        $min = $wpdb->get_var("SELECT MIN(id) FROM " . self::table_name());
        return null === $min ? null : (int) $min;
    }

    /** How many rows are currently in the ledger. */
    public static function row_count(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table_name());
    }

    /**
     * Resolve an operation_id to its ledger row id. operation_id is the
     * identifier every tool hands back to clients (list-operations, the
     * history screen); the numeric row id is not exposed anywhere, so a
     * marker API that only accepted the row id would be unreachable.
     */
    public static function id_for_operation(string $operation_id): ?int
    {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::table_name() . " WHERE operation_id = %s",
            $operation_id
        ));
        return null === $id ? null : (int) $id;
    }

    /**
     * Delete all but the $keep most recent snapshot rows. Additionally
     * deletes each pruned row's attachment file backup dir (if any), via
     * File_Backup::delete_backup_dir(), so a force-deleted attachment's
     * backed-up bytes do not accumulate under wp-content/uploads/ forever
     * once its snapshot has aged out and can no longer be rolled back to.
     * Calling delete_backup_dir() for every pruned operation_id is a no-op
     * for the (overwhelming majority of) rows that never had one.
     */
    public static function prune(int $keep): int
    {
        global $wpdb;
        $t = self::table_name();
        $cutoff = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} ORDER BY id DESC LIMIT 1 OFFSET %d", $keep));
        if (null === $cutoff) {
            return 0;
        }

        $pruned_op_ids = $wpdb->get_col($wpdb->prepare("SELECT operation_id FROM {$t} WHERE id <= %d", $cutoff));

        $deleted = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE id <= %d", $cutoff));

        foreach ((array) $pruned_op_ids as $operation_id) {
            File_Backup::delete_backup_dir((string) $operation_id);
        }

        return $deleted;
    }
}
