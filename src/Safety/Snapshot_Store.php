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
     * the only reader; nothing else decides the cap.
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

    /** Open hold_pruning() scopes; prune() is a no-op while any is open. */
    private static int $prune_holds = 0;

    /**
     * Run $work with pruning suspended, so one multi-write operation keeps
     * every undo point it writes. Without the hold, an import that writes
     * more rows than history_limit() would prune its own oldest snapshots
     * mid-run and rollback-session would then undo only part of it. The
     * next write outside the hold prunes as usual, by whole sessions (see
     * prune()), so the finished run is kept whole.
     *
     * @return mixed Whatever $work returns.
     */
    public static function hold_pruning(callable $work)
    {
        self::$prune_holds++;
        try {
            return $work();
        } finally {
            self::$prune_holds--;
        }
    }

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
     * which is 0 when nothing was written - and Safe_Mutation read that as
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- wpmcp_snapshots is this plugin's own table; the undo point must be written directly.
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
                    . ($wpdb->last_error ? ' (' . esc_html($wpdb->last_error) . ')' : '') . '.'
            );
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Rewrite a persisted undo point, for a write that only learns after it
     * ran what its undo must also cover (the rows a host plugin's hooks
     * added, the id the host assigned). Loud on failure, like save().
     *
     * @throws Mutation_Failed When the snapshot row could not be rewritten.
     */
    public static function update_snapshot(string $operation_id, array $snapshot): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpmcp_snapshots is this plugin's own table; the undo point must be written directly.
        $written = $wpdb->update(self::table_name(), [
            'object_id'   => self::db_object_id($snapshot),
            'before_blob' => Snapshot::serialize($snapshot),
        ], [ 'operation_id' => $operation_id ]);

        if (false === $written) {
            throw new Mutation_Failed(
                'The change was made, but its undo point could not be completed'
                    . ($wpdb->last_error ? ' (' . esc_html($wpdb->last_error) . ')' : '') . '.'
            );
        }
    }

    public static function get_by_operation(string $operation_id): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpmcp_snapshots is this plugin's own table; reads back undo state that must never be stale.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE operation_id = %s', self::table_name(), $operation_id), ARRAY_A);
        if (! $row) {
            return null;
        }
        $row['snapshot'] = Snapshot::unserialize($row['before_blob']);
        return $row;
    }

    public static function list_by_session(string $session_id): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpmcp_snapshots is this plugin's own table; reads back undo state that must never be stale.
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE session_id = %s ORDER BY id DESC', self::table_name(), $session_id), ARRAY_A);
    }

    public static function recent(int $limit): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpmcp_snapshots is this plugin's own table; reads back undo state that must never be stale.
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id DESC LIMIT %d', self::table_name(), $limit), ARRAY_A);
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpmcp_snapshots is this plugin's own ledger; a session index must reflect the live rows.
        return (array) $wpdb->get_results($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- INDEX_COLUMNS is the literal column list declared on this class; the table is bound with %i and the values with %s/%d.
            'SELECT ' . self::INDEX_COLUMNS . ' FROM %i WHERE session_id = %s ORDER BY id DESC LIMIT %d',
            self::table_name(),
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpmcp_snapshots is this plugin's own ledger; a since-marker index must reflect the live rows.
        return (array) $wpdb->get_results($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- INDEX_COLUMNS is the literal column list declared on this class; the table is bound with %i and the values with %d.
            'SELECT ' . self::INDEX_COLUMNS . ' FROM %i WHERE id > %d ORDER BY id DESC LIMIT %d',
            self::table_name(),
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpmcp_snapshots is this plugin's own ledger; the retention floor moves with every prune, so it must be read live.
        $min = $wpdb->get_var($wpdb->prepare('SELECT MIN(id) FROM %i', self::table_name()));
        return null === $min ? null : (int) $min;
    }

    /** How many rows are currently in the ledger; 0 when the table is not installed yet. */
    public static function row_count(): int
    {
        global $wpdb;
        $suppressed = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpmcp_snapshots is this plugin's own ledger; the row count must be live.
        $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', self::table_name()));
        $wpdb->suppress_errors($suppressed);

        return null === $count ? 0 : (int) $count;
    }

    /**
     * Void the undo point of a mutation that did not happen. Safe_Mutation
     * persists the snapshot before it runs the closure, so a closure that
     * throws (a refused write, a concurrency check) leaves a row whose
     * captured state may predate a write that DID land in the meantime;
     * restoring it later would clobber that newer write. Callers that refuse
     * inside the closure remove the row with this rather than leave a
     * restorable operation that describes nothing.
     */
    public static function delete_operation(string $operation_id): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpmcp_snapshots is this plugin's own ledger; voiding one undo point is a direct row delete.
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE operation_id = %s', self::table_name(), $operation_id));
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpmcp_snapshots is this plugin's own ledger; the operation_id to row id resolution must see the live rows.
        $id = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE operation_id = %s',
            self::table_name(),
            $operation_id
        ));
        return null === $id ? null : (int) $id;
    }

    /**
     * Option holding session_id => number of that session's ledger rows that
     * prune() has discarded. The surviving ledger cannot answer "did this
     * session lose rows?" (prune deletes by id, sessions interleave, and a
     * session whose every row is gone leaves no trace at all), so the answer
     * is recorded at the one moment it is knowable: inside prune(), before
     * the DELETE. Read by the change-set builder (issue #192) to report a
     * session-derived change set as truncated only when it actually is.
     */
    public const PRUNED_SESSIONS_OPTION = 'wpmcp_pruned_sessions';

    /**
     * Cap on remembered sessions. Entries are kept in insertion order and the
     * oldest are dropped past this, so the option stays a few KB no matter
     * how long the site lives. A session older than the last hundred pruned
     * ones has no surviving rows to build a change set from anyway.
     */
    private const PRUNED_SESSIONS_MAX = 100;

    /** How many of a session's ledger rows prune() has discarded (0 if none, or if the record has aged out). */
    public static function pruned_rows_for_session(string $session_id): int
    {
        $map = get_option(self::PRUNED_SESSIONS_OPTION, []);
        if (! is_array($map) || ! isset($map[ $session_id ])) {
            return 0;
        }
        return (int) $map[ $session_id ];
    }

    /** @param array<string, int> $counts session_id => rows about to be deleted */
    private static function record_pruned_sessions(array $counts): void
    {
        if ([] === $counts) {
            return;
        }
        $map = get_option(self::PRUNED_SESSIONS_OPTION, []);
        if (! is_array($map)) {
            $map = [];
        }
        foreach ($counts as $session_id => $count) {
            $session_id = (string) $session_id;
            if ('' === $session_id) {
                continue;
            }
            // Re-touching a key keeps its original position, so a long-running
            // session can still age out behind newer ones.
            $map[ $session_id ] = (int) ($map[ $session_id ] ?? 0) + (int) $count;
        }
        if (count($map) > self::PRUNED_SESSIONS_MAX) {
            $map = array_slice($map, -self::PRUNED_SESSIONS_MAX, null, true);
        }
        update_option(self::PRUNED_SESSIONS_OPTION, $map, false);
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

    /**
     * Sessions that are not a run: the catch-all a tool writes to when the
     * caller names no session. Their rows are ordinary single writes and are
     * pruned one by one, exactly as the flat cap always did.
     */
    private const LOOSE_SESSIONS = [ '', 'default' ];

    /** Whether a session id is the catch-all rather than one named run. */
    public static function is_loose_session(string $session_id): bool
    {
        return in_array($session_id, self::LOOSE_SESSIONS, true);
    }

    /**
     * Prune the history back to $keep rows, by whole sessions (issue #439).
     *
     * A bulk run (an optimize-media background run, a product import, a
     * database cleanup continued over many calls, mirror restores under one
     * session_id) writes one undo point per item under one session, and
     * rollback-session promises to undo the whole run. Deleting the oldest
     * rows by id cut such a run down to the cap, and the rollback then
     * restored only part of it without saying so. So:
     *
     * - Rows of the catch-all session ('default', or none) are ordinary
     *   single writes: each is its own unit, pruned oldest first to the cap.
     * - A named session is one unit, kept or dropped whole, never cut.
     * - Units are ranked by their newest row. Walking from the newest, each
     *   unit is kept while the rows kept so far plus its own stay within
     *   $keep; the first that does not fit, and every older unit, is dropped.
     * - Two sessions are always kept, whatever their size: the newest named
     *   session (the run being written, or the one that just finished), and
     *   the newest session larger than $keep (the last bulk run). Both count
     *   toward $keep, so ordinary history newer than them still fits the cap.
     *
     * The upper bound: $keep rows of recent history, plus at most those two
     * whole sessions. A run stays undoable until a newer run larger than the
     * cap finishes and a later write prunes; then it is dropped whole. A
     * dropped session is recorded (PRUNED_SESSIONS_OPTION) before any of its
     * rows go, so rollback-session refuses it instead of undoing part of it,
     * and a session larger than one batch is finished by the next prunes.
     *
     * At most PRUNE_BATCH_LIMIT rows go per call, never below the retention
     * floor an upgrading install arrived with. $keep defaults to
     * history_limit(), so a call site cannot forget the cap. Each pruned
     * row's attachment file backup dir (if any) is deleted too, via
     * File_Backup::delete_backup_dir(), so a force-deleted attachment's
     * backed-up bytes do not accumulate once its snapshot can no longer be
     * rolled back to; that call is a no-op for rows that never had one.
     */
    public static function prune(?int $keep = null): int
    {
        if (self::$prune_holds > 0) {
            return 0;
        }
        /**
         * Whether a multi-request operation still running needs every undo
         * point it has written so far (issue #432: a background
         * optimize-media run spans many cron requests, and a write between
         * two of them would otherwise prune its first ones). The next write
         * once nothing holds prunes to the cap as usual.
         *
         * @param bool $held
         */
        if (true === apply_filters('wpmcp_snapshot_prune_held', false)) {
            return 0;
        }

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
        $keep = max(1, $keep);

        if (self::row_count() <= $keep) {
            return 0;
        }

        return self::delete_planned(self::plan_prune($keep));
    }

    /**
     * Decide what prune() drops.
     *
     * @return array{sessions: string[], loose_below: int} the named
     *         sessions to drop, oldest first and only as many as one batch
     *         can reach, and the row id below which catch-all rows go
     *         (PHP_INT_MAX when none is kept)
     */
    private static function plan_prune(int $keep): array
    {
        global $wpdb;
        $t     = self::table_name();
        $loose = self::LOOSE_SESSIONS;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpmcp_snapshots is this plugin's own table; pruning must see the live row set.
        $sessions = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT session_id, COUNT(*) AS n, MAX(id) AS last FROM %i WHERE session_id NOT IN (%s, %s) GROUP BY session_id',
            $t,
            $loose[0],
            $loose[1]
        ), ARRAY_A);

        // Only the newest $keep catch-all rows can fit; every older one is
        // dropped whatever else the walk decides.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpmcp_snapshots is this plugin's own table; pruning must see the live row set.
        $loose_ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM %i WHERE session_id IN (%s, %s) ORDER BY id DESC LIMIT %d',
            $t,
            $loose[0],
            $loose[1],
            $keep
        )));

        $units  = [];
        $newest = null;
        $bulk   = null;
        foreach ($sessions as $row) {
            $unit = [ 'last' => (int) $row['last'], 'n' => (int) $row['n'], 'session' => (string) $row['session_id'] ];
            $units[] = $unit;
            if (null === $newest || $unit['last'] > $newest['last']) {
                $newest = $unit;
            }
            if ($unit['n'] > $keep && (null === $bulk || $unit['last'] > $bulk['last'])) {
                $bulk = $unit;
            }
        }
        foreach ($loose_ids as $id) {
            $units[] = [ 'last' => $id, 'n' => 1, 'session' => null ];
        }
        usort($units, static fn (array $a, array $b): int => $b['last'] <=> $a['last']);

        $protected = array_filter([ $newest['session'] ?? null, $bulk['session'] ?? null ], 'is_string');

        $kept_rows   = 0;
        $full        = false;
        $oldest_kept = null;
        $dropped     = [];
        foreach ($units as $unit) {
            $session = $unit['session'];
            if (null !== $session && in_array($session, $protected, true)) {
                $kept_rows += $unit['n'];
                continue;
            }
            // Already partly deleted (a drop larger than one batch, or a
            // flat prune before this one): the rest goes too.
            $doomed = null !== $session && self::pruned_rows_for_session($session) > 0;
            if (! $doomed && ! $full && $kept_rows + $unit['n'] <= $keep) {
                $kept_rows += $unit['n'];
                if (null === $session) {
                    $oldest_kept = $unit['last'];
                }
                continue;
            }
            if (! $doomed) {
                $full = true;
            }
            if (null !== $session) {
                $dropped[] = $unit;
            }
        }

        // Oldest first, and only as many sessions as one batch can reach, so
        // the IN list stays bounded however many sessions a site has.
        $dropped = array_reverse($dropped);
        $names   = [];
        $reach   = 0;
        foreach ($dropped as $unit) {
            if ($reach >= self::PRUNE_BATCH_LIMIT) {
                break;
            }
            $names[] = $unit['session'];
            $reach  += $unit['n'];
        }

        // Kept catch-all rows are always the newest ones (the walk keeps
        // them only until the first unit that does not fit), so everything
        // below the oldest kept one goes; with none kept, all of them go.
        return [ 'sessions' => $names, 'loose_below' => $oldest_kept ?? PHP_INT_MAX ];
    }

    /**
     * Delete one batch of what plan_prune() dropped, oldest rows first, and
     * record per session what went before anything is deleted.
     *
     * @param array{sessions: string[], loose_below: int} $plan
     */
    private static function delete_planned(array $plan): int
    {
        global $wpdb;
        $t     = self::table_name();
        $loose = self::LOOSE_SESSIONS;

        $where  = [];
        $values = [ $t ];
        if ([] !== $plan['sessions']) {
            $where[] = 'session_id IN (' . implode(', ', array_fill(0, count($plan['sessions']), '%s')) . ')';
            array_push($values, ...$plan['sessions']);
        }
        $where[] = '(session_id IN (%s, %s) AND id < %d)';
        array_push($values, $loose[0], $loose[1], $plan['loose_below']);
        $values[] = self::PRUNE_BATCH_LIMIT;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- wpmcp_snapshots is this plugin's own table; the WHERE is built only from literal fragments and placeholders, and every value is bound through the spread.
        $batch = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT id, operation_id, session_id FROM %i WHERE ' . implode(' OR ', $where) . ' ORDER BY id ASC LIMIT %d',
            ...$values
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        if ([] === $batch) {
            return 0;
        }

        $ids         = [];
        $per_session = [];
        foreach ($batch as $row) {
            $ids[]      = (int) $row['id'];
            $session_id = (string) $row['session_id'];
            $per_session[ $session_id ] = ($per_session[ $session_id ] ?? 0) + 1;
        }

        // Recorded before the delete: a session is not undoable from the
        // moment its first row is about to go.
        self::record_pruned_sessions($per_session);

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- wpmcp_snapshots is this plugin's own table; the id list is one %d placeholder per id, bound through the spread.
        $deleted = (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE id IN (' . implode(', ', array_fill(0, count($ids), '%d')) . ')',
            $t,
            ...$ids
        ));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        foreach ($batch as $row) {
            File_Backup::delete_backup_dir((string) $row['operation_id']);
        }

        return $deleted;
    }
}
