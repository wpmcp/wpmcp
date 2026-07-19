<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches brief's public interface).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches brief's public interface).

namespace WPMCP\Safety;

use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

class Rollback_Service
{
    /**
     * Non-fatal findings from the most recent restore, currently only ever
     * produced by the db_rows path's conflict detection (rows that changed,
     * vanished, or were reclaimed since the operation being undone). A
     * conflict is deliberately a WARNING, not a failure: rollback still
     * restores the captured before-image (that is the promise), but the
     * caller is told the ground shifted underneath it. Collected statically
     * because apply_snapshot() is a void pipeline shared by callers that
     * cannot thread a return value through (Safe_Mutation::restore).
     */
    private static array $warnings = [];

    /** Return and clear the warnings accumulated by the most recent restore. */
    public static function take_warnings(): array
    {
        $warnings       = self::$warnings;
        self::$warnings = [];
        return $warnings;
    }

    private static function warn(string $message): void
    {
        self::$warnings[] = $message;
    }

    public static function restore_operation(string $operation_id): bool
    {
        self::$warnings = [];
        $row = Snapshot_Store::get_by_operation($operation_id);
        if (! $row) {
            return false;
        }
        self::apply_snapshot($row['snapshot']);
        return true;
    }

    public static function restore_session(string $session_id): int
    {
        self::$warnings = [];
        $rows  = Snapshot_Store::list_by_session($session_id); // newest first
        $count = 0;

        // Pass 1: db_rows snapshots, applied NEWEST-first, every one of them.
        // Unlike the whole-object snapshots below, a db_rows snapshot covers
        // only the rows its WHERE matched, so two operations in one session
        // can capture PARTIALLY overlapping row sets. "Oldest snapshot per
        // object" dedup is only correct when each snapshot captures the whole
        // object; for partial captures the only correct unwind is to undo
        // each operation in reverse chronological order, letting older
        // before-images overwrite newer ones where they overlap.
        $legacy = [];
        foreach ($rows as $r) {
            $snapshot = Snapshot::unserialize($r['before_blob']);
            if ('db_rows' === $snapshot['object_type']) {
                self::apply_snapshot($snapshot);
                $count++;
                continue;
            }
            $legacy[] = $snapshot;
        }

        // Pass 2 (unchanged behavior): whole-object snapshots, oldest first,
        // restoring the OLDEST snapshot per object (its pre-session state).
        // Runs after the db_rows pass so that when both kinds touched the
        // same underlying rows, the exact whole-object restore wins.
        $legacy = array_reverse($legacy); // oldest first, so we can unwind to the earliest
        $seen   = [];
        foreach ($legacy as $snapshot) {
            $key = self::object_identity($snapshot);
            if (isset($seen[ $key ])) {
                $count++;
                continue;
            }
            $seen[ $key ] = true;
            self::apply_snapshot($snapshot);
            $count++;
        }
        return $count;
    }

    /**
     * A stable per-object dedup key for restore_session(), derived from the
     * unserialized snapshot rather than the DB row's object_id column. That
     * column is a BIGINT and is always 0 for 'option' snapshots (see
     * Snapshot_Store::db_object_id()); the option's real identity, its name,
     * only exists inside the serialized blob. Keying on the raw column would
     * collapse every distinct option in a session onto the same "option:0"
     * identity, restoring only the first one seen and silently skipping the
     * rest while still counting them as processed.
     */
    private static function object_identity(array $snapshot): string
    {
        if ('option' === $snapshot['object_type']) {
            return 'option:' . $snapshot['data']['name'];
        }
        // A page_build snapshot IS the oldest possible state of its page —
        // "did not exist yet". Keying it as post:<id> lets restore_session's
        // oldest-first dedup pick it over any later 'post' snapshot of the
        // same page, so a session rollback deletes the created page instead
        // of restoring an intermediate edit of it.
        if ('page_build' === $snapshot['object_type']) {
            return 'post:' . $snapshot['object_id'];
        }
        // Users, like posts, are identified by an int object_id, so the raw
        // object_type:object_id key is already stable and distinct.
        return $snapshot['object_type'] . ':' . $snapshot['object_id'];
    }

    /**
     * Restore a WordPress option to its pre-mutation state. Unlike a post,
     * an option has no trash/soft-delete; the only two prior states a
     * mutation could have started from are "existed with this value" (put
     * it back with update_option()) or "didn't exist yet" (the mutation
     * introduced it, so delete_option() removes it entirely rather than
     * leaving a value behind that was never there before).
     */
    private static function apply_option_snapshot(array $snapshot): void
    {
        $name = (string) $snapshot['data']['name'];
        if ($snapshot['data']['existed']) {
            update_option($name, $snapshot['data']['value']);
        } else {
            delete_option($name);
        }
    }

    /**
     * Restore a WooCommerce order's prior status.
     *
     * update-order-status only ever changes the status, so the undo is simply
     * to set the captured status back through WC_Order's CRUD setter, which
     * writes to whichever store (HPOS or legacy CPT) is active. There is no
     * delete-order tool, so the order always still exists here and is restored
     * in place; a null captured status means the order did not exist at
     * capture time, so there is nothing to restore.
     */
    private static function apply_wc_order_snapshot(array $snapshot): void
    {
        if (empty($snapshot['data']['status'])) {
            return;
        }

        if (! function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order((int) $snapshot['object_id']);
        if (! $order) {
            return;
        }

        $order->set_status((string) $snapshot['data']['status']);
        $order->save();
    }

    /**
     * Restore a user's editable profile to its pre-mutation state.
     *
     * Update_User only ever changes profile fields (never role, never
     * password), and there is no delete-user tool, so the user always still
     * exists here and is restored in place: the captured columns go back via
     * wp_update_user() and the usermeta is reconciled the same way the post
     * path reconciles post_meta (purge keys the mutation added, then re-write
     * every captured key/value exactly). user_pass is never in the snapshot,
     * so it is never touched.
     */
    private static function apply_user_snapshot(array $snapshot): void
    {
        $user_id = (int) $snapshot['object_id'];

        if (! empty($snapshot['data']['fields'])) {
            wp_update_user(array_merge(['ID' => $user_id], $snapshot['data']['fields']));
        }

        $snapshotted_meta = (array) $snapshot['data']['meta'];
        $current_meta     = get_user_meta($user_id);

        // Purge any usermeta key that didn't exist at snapshot time.
        foreach (array_keys(array_diff_key($current_meta, $snapshotted_meta)) as $key) {
            delete_user_meta($user_id, $key);
        }

        // Restore snapshotted keys/values exactly as captured.
        foreach ($snapshotted_meta as $key => $values) {
            delete_user_meta($user_id, $key);
            foreach ((array) $values as $v) {
                add_user_meta($user_id, $key, maybe_unserialize($v));
            }
        }
    }

    /**
     * Restore a comment to its pre-mutation state.
     *
     * moderate-comment and edit-comment only ever change an existing comment
     * (status, content, author fields), so in that case the comment still
     * exists here and is restored in place: the captured row goes back via
     * wp_update_comment() and the commentmeta is reconciled the same way the
     * post/user paths reconcile their meta (purge keys the mutation added,
     * then re-write every captured key/value exactly).
     *
     * delete-comment (force) destroys the row entirely, so wp_update_comment()
     * would silently no-op. When the comment no longer exists it is resurrected
     * via wp_insert_comment() instead. WordPress core has NO import_id
     * equivalent for comments, so the original comment_ID CANNOT be preserved:
     * the resurrected comment gets a fresh auto-increment ID. Rather than fail
     * or pretend otherwise, the content, author, status, dates, thread
     * association and commentmeta are restored honestly under the new ID.
     */
    private static function apply_comment_snapshot(array $snapshot): void
    {
        if (! $snapshot['data']['comment']) {
            return;
        }

        $comment_id = (int) $snapshot['object_id'];

        if (get_comment($comment_id)) {
            wp_update_comment($snapshot['data']['comment']);
            self::reconcile_comment_meta($comment_id, (array) $snapshot['data']['meta']);
            return;
        }

        self::resurrect_comment($snapshot['data']['comment'], (array) $snapshot['data']['meta']);
    }

    /**
     * Re-insert a force-deleted comment. The original comment_ID cannot be
     * reused (no import_id for comments in WordPress core), so it is dropped
     * and WordPress assigns a new one; every other captured field, plus the
     * commentmeta, is restored under that new ID.
     */
    private static function resurrect_comment(array $comment_row, array $meta): void
    {
        unset($comment_row['comment_ID']);
        $new_comment_id = wp_insert_comment($comment_row);
        if (! $new_comment_id) {
            throw new Mutation_Failed('Rollback failed to resurrect a force-deleted comment.');
        }
        self::reconcile_comment_meta((int) $new_comment_id, $meta);
    }

    /**
     * Reconcile a comment's commentmeta back to the captured map: delete any
     * key the mutation added, then re-write every captured key/value exactly.
     * Shared by the in-place restore and the resurrection path.
     */
    private static function reconcile_comment_meta(int $comment_id, array $snapshotted_meta): void
    {
        $current_meta = get_comment_meta($comment_id);

        foreach (array_keys(array_diff_key($current_meta, $snapshotted_meta)) as $key) {
            delete_comment_meta($comment_id, $key);
        }

        foreach ($snapshotted_meta as $key => $values) {
            delete_comment_meta($comment_id, $key);
            foreach ((array) $values as $v) {
                add_comment_meta($comment_id, $key, maybe_unserialize($v));
            }
        }
    }

    /**
     * Columns from a full get_post($id, ARRAY_A) row that are safe to feed
     * back into wp_update_post()/wp_insert_post(). Excluded:
     *  - 'ID' is merged in separately by the caller.
     *  - 'filter' is a WP_Post runtime property (value 'raw'), not a real
     *    column; wp_insert_post() would choke trying to sanitize it as post
     *    data via sanitize_post() semantics for an unknown filter context.
     *  - 'comment_count' is derived (recalculated from the comments table),
     *    never written directly.
     *  - 'guid' is dropped for the in-place wp_update_post() path per the
     *    fix brief: wp_update_post() ignores it anyway (it always re-reads
     *    the existing row's guid for updates), so passing it is a no-op
     *    there but excluding it avoids relying on that internal behavior.
     *    The resurrection path (wp_insert_post with import_id) keeps guid,
     *    since there the original value both matters (permalink identity)
     *    and is honored by WordPress core.
     */
    private static function restore_columns(array $post, bool $keep_guid): array
    {
        $excluded = ['ID', 'filter', 'comment_count'];
        if (! $keep_guid) {
            $excluded[] = 'guid';
        }
        return array_diff_key($post, array_flip($excluded));
    }

    /**
     * True if $current (a live get_post(ARRAY_A) row) is plausibly the same
     * post the snapshot was captured from, rather than a different post that
     * has since reclaimed the same ID. post_date_gmt is set once at
     * creation and never changes on update, making it a reliable identity
     * check that costs nothing extra to capture.
     */
    private static function is_same_post(array $current, array $snapshotted): bool
    {
        return ($current['post_date_gmt'] ?? null) === ($snapshotted['post_date_gmt'] ?? null);
    }

    /**
     * Restore an object to the exact state captured in $snapshot.
     *
     * For 'post' objects this must be a FULL restore, not an additive merge:
     * any meta key that exists on the object now but was NOT present in the
     * snapshot (i.e. it was added by the mutation being undone) must be
     * deleted. Otherwise a rollback can leave orphan meta behind, violating
     * the safety invariant that a restored object matches its pre-mutation
     * state exactly.
     *
     * A force-deleted post's row is gone entirely (unlike trash, which only
     * changes post_status), so wp_update_post() would silently no-op here.
     * When the post no longer exists, re-insert it at the same ID via
     * wp_insert_post()'s import_id instead of updating it.
     *
     * Both paths now pass the FULL captured row (post_type, post_author,
     * post_parent, post_name/slug, dates, menu_order, post_excerpt,
     * comment_status, ping_status, etc.), not just content/title/status:
     * a partial restore silently reconstructs missing columns from
     * wp_insert_post()'s defaults, e.g. a force-deleted 'page' comes back
     * as a plain 'post'.
     *
     * wp_insert_post()'s import_id is only honored if that ID is free; on a
     * collision it silently falls back to a new auto-increment ID. If that
     * happens here we'd otherwise end up with a "restored" post masquerading
     * at the wrong ID with no error, so the returned ID is verified against
     * the requested one and a Mutation_Failed is thrown on any mismatch or
     * WP_Error instead of leaving that wrong-ID post in place.
     *
     * Whether to update in place or resurrect is decided by identity, not
     * mere existence of a row at $object_id: post_date_gmt is immutable
     * after creation, so if a post exists at that ID but its post_date_gmt
     * doesn't match the snapshot, it is a DIFFERENT post that has since
     * reclaimed the original ID (e.g. a manual re-import after the original
     * was force-deleted), not the object being rolled back. Treating that
     * case as "exists, update in place" would silently overwrite an
     * unrelated post's content; routing it through resurrect() instead lets
     * the import_id collision check catch it and fail loudly.
     *
     * A 'files' entry in $snapshot['data'] (only ever present for an
     * attachment's force-delete snapshot, see Delete_Media/File_Backup) is
     * restored LAST, after the post row above is back: the physical bytes
     * are only meaningful once the attachment record they belong to exists
     * again. Every other object type, and posts without a 'files' key,
     * never reach that branch, so this is purely additive.
     */
    public static function apply_snapshot(array $snapshot): void
    {
        if ('option' === $snapshot['object_type']) {
            self::apply_option_snapshot($snapshot);
            return;
        }

        if ('user' === $snapshot['object_type']) {
            self::apply_user_snapshot($snapshot);
            return;
        }

        if ('comment' === $snapshot['object_type']) {
            self::apply_comment_snapshot($snapshot);
            return;
        }

        if ('wc_order' === $snapshot['object_type']) {
            self::apply_wc_order_snapshot($snapshot);
            return;
        }

        if ('db_rows' === $snapshot['object_type']) {
            self::apply_db_rows_snapshot($snapshot);
            return;
        }

        if ('page_build' === $snapshot['object_type']) {
            self::apply_page_build_snapshot($snapshot);
            return;
        }

        if ('post' !== $snapshot['object_type']) {
            return;
        }

        $object_id = (int) $snapshot['object_id'];

        if ($snapshot['data']['post']) {
            $current = get_post($object_id, ARRAY_A);
            if ($current && self::is_same_post($current, $snapshot['data']['post'])) {
                $postarr = array_merge(['ID' => $object_id], self::restore_columns($snapshot['data']['post'], false));
                wp_update_post($postarr);
            } else {
                self::resurrect($object_id, $snapshot['data']['post'], $snapshot['data']['comments'] ?? []);
            }
        }

        $snapshotted_meta = (array) $snapshot['data']['meta'];
        $current_meta     = get_post_meta($object_id);

        // Purge any meta key that didn't exist at snapshot time (newly added by the mutation).
        foreach (array_keys(array_diff_key($current_meta, $snapshotted_meta)) as $key) {
            delete_post_meta($object_id, $key);
        }

        // Restore snapshotted keys/values exactly as captured.
        foreach ($snapshotted_meta as $key => $values) {
            delete_post_meta($object_id, $key);
            foreach ((array) $values as $v) {
                add_post_meta($object_id, $key, maybe_unserialize($v));
            }
        }

        // Restore taxonomy term assignments captured at snapshot time. Older
        // snapshots predating term capture simply have no 'terms' key, so
        // this is a no-op for them (backward compatible).
        foreach ((array) ($snapshot['data']['terms'] ?? []) as $taxonomy => $term_ids) {
            wp_set_object_terms($object_id, array_map('intval', (array) $term_ids), (string) $taxonomy, false);
        }

        self::restore_files($snapshot['data']['files'] ?? null);
    }

    /**
     * Restore the exact before-image rows captured by update-rows /
     * delete-rows (issue #82; snapshot shape documented at
     * Snapshot::capture_db_rows()).
     *
     * THE ROLLBACK PATH ITSELF PERFORMS DB WRITES, so a forged or stale
     * snapshot must never become a write primitive the write tools would
     * refuse. Everything is re-validated against the LIVE database before a
     * single row is touched:
     *  - the table must still exist (Database_Guard::valid_table() resolves
     *    the exact real name; table names cannot be parameterized);
     *  - the table must not be protected (users/usermeta by default) — a
     *    legitimate snapshot can never reference one, because the write
     *    tools refuse protected tables before capturing anything;
     *  - a non-empty primary key must be declared in the snapshot, and every
     *    captured row must carry a non-null value for each PK column;
     *  - every captured column name must exactly match a live column of the
     *    table (closes both column-name injection — identifiers cannot be
     *    parameterized — and silent schema drift: a dropped column means the
     *    promised exact restore is impossible, so fail loudly instead).
     * Any violation throws Mutation_Failed before any write happens.
     *
     * Per row, the restore is an upsert keyed on the primary key: if a row
     * exists at the captured PK it is updated back to the captured values
     * ($wpdb->update(), parameterized); if not, the full captured row —
     * INCLUDING its original PK values — is reinserted ($wpdb->insert()),
     * which is what preserves auto-increment ids across a delete + rollback.
     * (Caveat: the table's auto-increment counter itself is not rewound, so
     * ids handed out between the delete and the rollback are simply skipped.)
     *
     * Conflict detection compares the CURRENT row against what the operation
     * left behind (before-image overlaid with the update's 'set' map, or
     * absence for a delete). Any drift — a third-party edit, a vanished row,
     * a reclaimed PK — is reported via warn() but does not stop the restore:
     * the captured before-image always wins, matching the safety invariant
     * that a restored object equals its pre-mutation state exactly.
     */
    private static function apply_db_rows_snapshot(array $snapshot): void
    {
        global $wpdb;

        // Every database tool is gated at manage_options (raw table access is
        // phpMyAdmin-level power), but the rollback tools are — and must stay —
        // edit_posts, so lower-privileged identities can undo their own content
        // writes. Without this check, an edit_posts caller could mutate raw
        // tables by replaying an administrator's operation through
        // rollback-operation. Enforce the database tools' own gate here, on
        // the one snapshot type whose restore IS a raw table write.
        if (! current_user_can('manage_options')) {
            throw new Mutation_Failed('Rollback refused: restoring raw table rows requires the manage_options capability.');
        }

        $data = (array) ($snapshot['data'] ?? []);
        $rows = (array) ($data['rows'] ?? []);
        if ([] === $rows) {
            return; // Nothing was captured, so there is nothing to restore.
        }

        $table = \WPMCP\Tools\Database\Database_Guard::valid_table((string) ($data['table'] ?? ''));
        if (is_wp_error($table)) {
            throw new Mutation_Failed('Rollback refused: ' . $table->get_error_message());
        }
        if (\WPMCP\Tools\Database\Database_Guard::is_protected($table)) {
            throw new Mutation_Failed("Rollback refused: table \"{$table}\" is protected.");
        }

        $primary_key = array_values(array_map('strval', (array) ($data['primary_key'] ?? [])));
        if ([] === $primary_key) {
            throw new Mutation_Failed('Rollback refused: db_rows snapshot has no primary key.');
        }

        $live_columns = \WPMCP\Tools\Database\Database_Guard::columns($table);
        foreach ($primary_key as $column) {
            if (! in_array($column, $live_columns, true)) {
                throw new Mutation_Failed("Rollback refused: primary-key column \"{$column}\" is not a column of \"{$table}\".");
            }
        }

        $operation = (string) ($data['operation'] ?? '');
        $set       = (array) ($data['set'] ?? []);

        foreach ($rows as $row) {
            $row = (array) $row;

            foreach (array_keys($row) as $column) {
                if (! in_array((string) $column, $live_columns, true)) {
                    throw new Mutation_Failed("Rollback refused: captured column \"{$column}\" is not a column of \"{$table}\".");
                }
            }

            $where = [];
            foreach ($primary_key as $column) {
                if (! isset($row[ $column ])) {
                    throw new Mutation_Failed("Rollback refused: a captured row is missing primary-key value \"{$column}\".");
                }
                $where[ $column ] = $row[ $column ];
            }

            $current = \WPMCP\Tools\Database\Database_Guard::before_image($table, $where, 1)[0] ?? null;
            $pk_desc = self::describe_pk($where);

            if ('delete' === $operation) {
                if (null !== $current) {
                    self::warn("Row {$pk_desc} in \"{$table}\" was recreated after the delete; it was overwritten with the captured before-image.");
                }
            } else {
                if (null === $current) {
                    self::warn("Row {$pk_desc} in \"{$table}\" was deleted after the operation; the captured before-image was reinserted.");
                } elseif (! self::row_matches($current, array_merge($row, $set))) {
                    self::warn("Row {$pk_desc} in \"{$table}\" changed after the operation; the captured before-image was restored over it.");
                }
            }

            if (null === $current) {
                if (false === $wpdb->insert($table, $row)) {
                    throw new Mutation_Failed("Rollback failed to reinsert row {$pk_desc} into \"{$table}\": " . ($wpdb->last_error ?: 'insert failed'));
                }
                continue;
            }

            $restore = array_diff_key($row, array_flip($primary_key));
            if ([] === $restore) {
                continue; // PK-only table: existing row is already the before-image.
            }
            if (false === $wpdb->update($table, $restore, $where)) {
                throw new Mutation_Failed("Rollback failed to restore row {$pk_desc} in \"{$table}\": " . ($wpdb->last_error ?: 'update failed'));
            }
        }
    }

    /**
     * Undo a build-page composition (issue #57). Unlike every other snapshot
     * type, a 'page_build' snapshot records what the operation CREATED
     * (recorded after the mutation, since the ids cannot exist before it),
     * so its restore is a deletion: the created page's pre-operation state
     * was nonexistence. The menu items placed by the build go first, then
     * the page itself — force-deleted, matching how resurrect() treats
     * force-deletion as the true inverse of creation.
     *
     * The page is only deleted if it is plausibly still the page the build
     * created: post_date_gmt is set once at creation and never changes on
     * update, so a mismatch means a DIFFERENT post has since reclaimed the
     * id and deleting it would destroy an unrelated object. That case warns
     * and leaves the post untouched (a non-fatal conflict, like the db_rows
     * drift warnings). Later edits to the created page keep post_date_gmt,
     * so an edited page is still honestly removed by the rollback.
     */
    private static function apply_page_build_snapshot(array $snapshot): void
    {
        $data = (array) ($snapshot['data'] ?? []);

        foreach ((array) ($data['menu_item_ids'] ?? []) as $item_id) {
            $item = get_post((int) $item_id);
            if ($item && 'nav_menu_item' === $item->post_type) {
                wp_delete_post((int) $item_id, true);
            }
        }

        $post_id = (int) $snapshot['object_id'];
        $current = get_post($post_id);
        if (! $current) {
            return; // Already gone; nothing left to undo.
        }

        if (($data['post_date_gmt'] ?? null) !== $current->post_date_gmt) {
            self::warn("Post {$post_id} is not the page this build created (the id was reclaimed by another post); it was left untouched.");
            return;
        }

        wp_delete_post($post_id, true);
    }

    /** Human-readable "pk=value" description of a row's primary-key values, for warnings and errors. */
    private static function describe_pk(array $where): string
    {
        $parts = [];
        foreach ($where as $column => $value) {
            $parts[] = $column . '=' . (is_scalar($value) ? (string) $value : wp_json_encode($value));
        }
        return '(' . implode(', ', $parts) . ')';
    }

    /**
     * Loose column-wise equality between a live DB row and an expected state.
     * MySQL hands every value back as a string (or null), while the expected
     * side mixes captured strings with raw tool-arg values (ints, bools), so
     * both sides are compared as strings, with null only ever equal to null.
     * Only columns present in $expected are compared. False mismatches (e.g.
     * float formatting) merely produce a spurious warning, never a failure.
     */
    private static function row_matches(array $current, array $expected): bool
    {
        foreach ($expected as $column => $value) {
            $live = $current[ $column ] ?? null;
            if (null === $value || null === $live) {
                if ($value !== $live) {
                    return false;
                }
                continue;
            }
            if ((string) $live !== (string) $value) {
                return false;
            }
        }
        return true;
    }

    /**
     * Restore an attachment's backed-up physical files, when present. Only
     * a force-deleted attachment's snapshot ever carries a 'files' entry
     * (['operation_id' => ..., 'manifest' => [original_abs_path => stored_filename]]);
     * every other snapshot has no such key, making this a no-op for them.
     */
    private static function restore_files(?array $files): void
    {
        if (empty($files['manifest']) || empty($files['operation_id'])) {
            return;
        }
        File_Backup::restore((string) $files['operation_id'], (array) $files['manifest']);
    }

    /**
     * Re-insert a force-deleted post at its original ID and restore its
     * comments. wp_insert_post() only honors 'import_id' when that ID is
     * still free; on a collision it silently returns a new auto-increment
     * ID instead of the one we asked for. Since a wrong-ID "restore" would
     * violate the safety guarantee (the caller thinks operation X was
     * undone, but a different post now exists at a different ID and the
     * original ID is still missing/occupied by someone else), that case is
     * treated as a hard failure rather than silently accepted.
     */
    private static function resurrect(int $object_id, array $post_columns, array $comments): void
    {
        $postarr = array_merge(['import_id' => $object_id], self::restore_columns($post_columns, true));
        $result  = wp_insert_post($postarr, true);

        if (is_wp_error($result)) {
            throw new Mutation_Failed('Rollback failed to resurrect post ' . $object_id . ': ' . $result->get_error_message());
        }

        $new_id = (int) $result;
        if ($new_id !== $object_id) {
            throw new Mutation_Failed(
                "Rollback could not resurrect post {$object_id} at its original ID "
                . "(import_id collision; WordPress inserted it as post {$new_id} instead). "
                . 'The site no longer has a free slot for the original ID, so the restore was aborted.'
            );
        }

        self::restore_comments($object_id, $comments);
    }

    /**
     * Recreate the comments (and their commentmeta) captured for a
     * force-deleted post. wp_insert_comment() always assigns a fresh
     * auto-increment comment_ID (WordPress core has no "import_id"
     * equivalent for comments), so original comment IDs are not preserved;
     * the content, author, dates, and thread association with the post are.
     */
    private static function restore_comments(int $post_id, array $comments): void
    {
        foreach ($comments as $comment) {
            $meta = $comment['meta'] ?? [];
            unset($comment['comment_ID'], $comment['meta']);
            $comment['comment_post_ID'] = $post_id;

            $new_comment_id = wp_insert_comment($comment);
            if (! $new_comment_id) {
                continue;
            }

            foreach ((array) $meta as $key => $values) {
                foreach ((array) $values as $v) {
                    add_comment_meta($new_comment_id, $key, maybe_unserialize($v));
                }
            }
        }
    }
}
