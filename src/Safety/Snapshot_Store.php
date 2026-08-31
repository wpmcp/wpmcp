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
     * The single source of truth for the number. history_limit() below is
     * the only reader; no licence gate is consulted anywhere.
     */
    public const DEFAULT_HISTORY_LIMIT = 20;

    /**
     * Rows a single prune() call may delete.
     *
     * The cap is a bound on the work one write does, not on the history: a
     * site that arrives with a deeper table catches up over the next few
     * writes instead of loading every excess operation_id into memory,
     * deleting an unbounded number of LONGBLOB rows and walking one backup
     * directory per row inside the request that triggered it.
     */
    public const PRUNE_BATCH_LIMIT = 200;

    /**
     * Retention depth carried over from an install that predates the flat
     * cap. Zero on every install that has nothing to carry, which is every
     * fresh one. See ensure_retention_floor().
     */
    public const HISTORY_FLOOR_OPTION = 'wpmcp_snapshot_history_floor';

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

        // Stamp the retention floor while we know what the table looks like:
        // zero for a fresh install, the existing depth for a site that is
        // being reactivated after an upgrade with history already in it.
        self::ensure_retention_floor();
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
     * How many snapshots a site keeps. One number for every install: no
     * licence, no tier, nothing a payment changes. Filterable so a site
     * that wants deeper history can have it for free, which is the
     * difference guideline 5 draws between a product decision and a lock.
     */
    public static function history_limit(): int
    {
        $raw = apply_filters('wpmcp_snapshot_history_limit', self::DEFAULT_HISTORY_LIMIT);

        // Validated before the cast, not after. `(int) [20]` is 1, which
        // would prune the table to a single row and delete every other
        // operation's file backups; a float past PHP_INT_MAX emits a warning
        // mid-write and yields garbage. Anything that is not a plain number
        // in range is a filter bug, so the constant answers instead.
        if (! is_numeric($raw) || $raw < 1 || $raw > PHP_INT_MAX) {
            return self::DEFAULT_HISTORY_LIMIT;
        }

        return (int) $raw;
    }

    /**
     * The retention depth an upgrading install arrives with, or 0.
     *
     * Flattening the cap (issue #158) turned an unlimited 0.8.0 Pro history
     * into a 20-row one, and prune() deletes the File_Backup bytes behind
     * every row it drops. Doing that on the first write after an unattended
     * update is a decision the site owner never made, so the depth the table
     * already had is recorded once and held as a floor until the owner makes
     * it: either by setting the filter, or by acknowledging the admin notice
     * (Snapshot_Retention_Notice). The floor is a frozen number, so it holds
     * the existing history without letting the table grow further.
     */
    public static function ensure_retention_floor(): int
    {
        $stored = get_option(self::HISTORY_FLOOR_OPTION, false);
        if (false !== $stored) {
            return max(0, (int) $stored);
        }

        $existing = self::row_count();
        $floor    = $existing > self::DEFAULT_HISTORY_LIMIT ? $existing : 0;
        add_option(self::HISTORY_FLOOR_OPTION, $floor, '', true);

        return $floor;
    }

    /** Whether an upgraded install is still holding its pre-cap history. */
    public static function has_retention_floor(): bool
    {
        return self::ensure_retention_floor() > 0 && ! has_filter('wpmcp_snapshot_history_limit');
    }

    /** The owner has decided: pruning may proceed down to the cap. */
    public static function acknowledge_retention_floor(): void
    {
        update_option(self::HISTORY_FLOOR_OPTION, 0, true);
    }

    /** Rows currently in the table; 0 when the table is not installed yet. */
    private static function row_count(): int
    {
        global $wpdb;
        $table      = self::table_name();
        $suppressed = $wpdb->suppress_errors(true);
        $count      = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $wpdb->suppress_errors($suppressed);

        return null === $count ? 0 : (int) $count;
    }

    /**
     * Delete the oldest snapshot rows beyond the $keep most recent, at most
     * PRUNE_BATCH_LIMIT of them per call, and never below the retention
     * floor an upgrading install arrived with. $keep defaults to
     * history_limit(), so a call site cannot forget the flat cap.
     * Additionally
     * deletes each pruned row's attachment file backup dir (if any), via
     * File_Backup::delete_backup_dir(), so a force-deleted attachment's
     * backed-up bytes do not accumulate under wp-content/uploads/ forever
     * once its snapshot has aged out and can no longer be rolled back to.
     * Calling delete_backup_dir() for every pruned operation_id is a no-op
     * for the (overwhelming majority of) rows that never had one.
     */
    public static function prune(?int $keep = null): int
    {
        global $wpdb;
        $t = self::table_name();

        $keep = $keep ?? self::history_limit();

        // A filter is the owner deciding what the depth should be, which is
        // exactly what the floor was holding the question open for.
        if (self::ensure_retention_floor() > 0) {
            if (has_filter('wpmcp_snapshot_history_limit')) {
                self::acknowledge_retention_floor();
            } else {
                $keep = max($keep, self::ensure_retention_floor());
            }
        }

        $cutoff = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} ORDER BY id DESC LIMIT 1 OFFSET %d", $keep));
        if (null === $cutoff) {
            return 0;
        }

        // One batch per call. The ids come back oldest first so the batch is
        // a contiguous range ending at $batch_cutoff, which keeps the DELETE
        // a single range scan and keeps the rows deleted identical to the
        // operation_ids whose backup dirs are removed below.
        $batch = $wpdb->get_results(
            $wpdb->prepare("SELECT id, operation_id FROM {$t} WHERE id <= %d ORDER BY id ASC LIMIT %d", $cutoff, self::PRUNE_BATCH_LIMIT),
            ARRAY_A
        );
        if (! $batch) {
            return 0;
        }

        $last         = end($batch);
        $batch_cutoff = (int) $last['id'];

        $deleted = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE id <= %d", $batch_cutoff));

        foreach ($batch as $row) {
            File_Backup::delete_backup_dir((string) $row['operation_id']);
        }

        return $deleted;
    }
}
