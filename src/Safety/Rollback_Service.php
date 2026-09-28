<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches brief's public interface).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches brief's public interface).

namespace WPMCP\Safety;

use WPMCP\Tools\Database\Database_Guard;
use WPMCP\Tools\Builders\Beaver_Builder_Cache;
use WPMCP\Tools\Builders\Elementor_Cache;

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
        if (! self::may_restore($row['snapshot'])) {
            self::warn(sprintf(
                'operation %s refused: its snapshot holds personal data and restoring it requires the "%s" capability.',
                $operation_id,
                (string) self::restore_capability($row['snapshot'])
            ));
            return false;
        }
        self::apply_snapshot($row['snapshot']);
        return true;
    }

    /**
     * Post types whose snapshots hold personal data, mapped to the capability
     * a caller must hold to restore one.
     *
     * Deleting a form submission is snapshotted so it can be undone, which
     * means a verbatim plaintext copy of the submission (name, email, remote
     * IP, message body) sits in wpmcp_snapshots until it is pruned. The forms
     * adapters gate reading and deleting a submission behind an
     * administrator-only capability, but wpmcp/rollback-operation and
     * wpmcp/rollback-session are registered at edit_posts, so without this the
     * gate is one-way: anyone who can edit posts could resurrect a submission
     * they are not allowed to read. Keyed on the snapshotted post type rather
     * than on the adapter, because the snapshot outlives the adapter call and
     * every entry-bearing adapter needs the same protection.
     *
     * @return array<string, string> post type => required capability
     */
    private static function pii_snapshot_capabilities(): array
    {
        return (array) apply_filters('wpmcp_pii_snapshot_capabilities', [
            // Contact Form 7 via Flamingo; edit_users is the cap Flamingo maps
            // every inbound-message capability to.
            'flamingo_inbound' => 'edit_users',
            'metform-entry'    => 'manage_options',
            // Ninja Forms submissions (issue #66): the adapter's status change
            // snapshots the whole nf_sub post, submitted values included.
            'nf_sub'           => 'manage_options',
        ]);
    }

    /** The capability this snapshot demands, or null when it holds no PII. */
    private static function restore_capability(array $snapshot): ?string
    {
        if ('post' !== ($snapshot['object_type'] ?? '')) {
            return null;
        }
        $post_type = (string) ($snapshot['data']['post']['post_type'] ?? '');
        return self::pii_snapshot_capabilities()[ $post_type ] ?? null;
    }

    /**
     * Whether the CURRENT user may restore this snapshot. Only the two
     * agent-facing entry points below consult it: Safe_Mutation's own unwind
     * of a mutation that threw is an internal integrity operation, already
     * behind the op's capability, and must never be blocked half way through.
     */
    private static function may_restore(array $snapshot): bool
    {
        $capability = self::restore_capability($snapshot);
        return null === $capability || current_user_can($capability);
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
            try {
                $snapshot = Snapshot::unserialize($r['before_blob']);
            } catch (\RuntimeException $e) {
                // One corrupt row must not abort the unwind of every other
                // operation in the session - but it must be visible: the row
                // is reported, skipped, and NOT counted as restored.
                self::warn(sprintf('operation %s skipped: %s', $r['operation_id'], $e->getMessage()));
                continue;
            }
            // An acf_options snapshot is partial in the same way: it covers
            // the rows under the field names ONE write named, and two writes
            // to one options page can name overlapping sets.
            // And so is an option_set snapshot (classic widget writes): each
            // covers sidebars_widgets plus ONE widget type's option, so two
            // writes share sidebars_widgets but not the rest.
            if (in_array($snapshot['object_type'], [ 'db_rows', 'acf_options', 'option_set' ], true)) {
                self::apply_snapshot($snapshot);
                $count++;
                continue;
            }
            // Compiled-widget manifest changes (issue #72) are unwound the
            // same way, newest first, every one of them. A compile snapshot
            // holds ONE widget's entry and bytes, and a status or spec update
            // holds one widget's enabled flag, so several of them touch the
            // same widget in different ways; only a reverse-chronological
            // unwind lands on the pre-session manifest.
            if (self::is_compiled_widget_snapshot($snapshot)) {
                self::apply_snapshot($snapshot);
                $count++;
                continue;
            }
            if (is_array($snapshot['data']['compiled_widget_entry'] ?? null)) {
                self::restore_compiled_widget_entry($snapshot['data']['compiled_widget_entry']);
                // The post half still goes through the oldest-first pass.
                unset($snapshot['data']['compiled_widget_entry']);
            }
            $legacy[] = $snapshot;
        }

        // Pass 2 (unchanged behavior): whole-object snapshots, oldest first,
        // restoring the OLDEST snapshot per object (its pre-session state).
        // Runs after the db_rows pass so that when both kinds touched the
        // same underlying rows, the exact whole-object restore wins.
        $legacy   = array_reverse($legacy); // oldest first, so we can unwind to the earliest
        $seen     = [];
        $deferred = [];
        foreach ($legacy as $snapshot) {
            $key = self::object_identity($snapshot);
            if (isset($seen[ $key ])) {
                $count++;
                continue;
            }
            $seen[ $key ] = true;
            // A creation row that covers several posts (duplicate-post with
            // include_children) claims every one of them, so a later edit of
            // a created child cannot bring it back out of the trash.
            if (Post_Creation_Snapshot::OBJECT_TYPE === $snapshot['object_type']) {
                foreach (Post_Creation_Snapshot::post_ids($snapshot) as $created_id) {
                    $seen[ 'post:' . $created_id ] = true;
                }
            }
            if (! self::may_restore($snapshot)) {
                // One refused snapshot must not abort the rest of the unwind,
                // but it must be visible and must NOT be counted as restored.
                self::warn(sprintf(
                    'snapshot %s skipped: it holds personal data and restoring it requires the "%s" capability.',
                    $key,
                    (string) self::restore_capability($snapshot)
                ));
                continue;
            }
            // A child-theme scaffold is undone LAST: its restore refuses to
            // delete the active theme, so the stylesheet/template options a
            // later switch-theme in the same session changed must be put back
            // first, or the session rollback would leave the scaffold behind.
            if ('theme_scaffold' === $snapshot['object_type']) {
                $deferred[] = $snapshot;
                continue;
            }
            self::apply_snapshot($snapshot);
            $count++;
        }
        foreach ($deferred as $snapshot) {
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
        // Every compile snapshot is an 'option' snapshot of the ONE shared
        // manifest option, but it only ever describes one widget. Keyed by the
        // option name, all compiles in a session collapsed onto one identity.
        if (self::is_compiled_widget_snapshot($snapshot)) {
            return 'compiled_widget:' . (int) ($snapshot['data']['compiled_widget']['spec_id'] ?? 0);
        }
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
        // Same reasoning for a media import: the snapshot IS the oldest state
        // of that attachment ("did not exist yet"), keyed as post:<id> so a
        // session rollback deletes the import instead of restoring any later
        // 'post' snapshot of the same attachment (e.g. an update-media edit).
        if ('media_import' === $snapshot['object_type']) {
            return 'post:' . $snapshot['object_id'];
        }
        // And for a post create-post, duplicate-post or a spec builder
        // created (issue #192): its oldest state is "did not exist yet".
        if (Post_Creation_Snapshot::OBJECT_TYPE === $snapshot['object_type']) {
            return 'post:' . $snapshot['object_id'];
        }
        // And for a comment create-comment or reply-to-comment posted
        // (issue #284): keyed as comment:<id> so a later moderate-comment or
        // edit-comment in the same session cannot bring it back from the trash.
        if (Comment_Creation_Snapshot::OBJECT_TYPE === $snapshot['object_type']) {
            return 'comment:' . $snapshot['object_id'];
        }
        // And for a product or variation an import created: its oldest
        // state is "did not exist yet", whatever later edits followed.
        if ('wc_product_create' === $snapshot['object_type']) {
            return 'post:' . $snapshot['object_id'];
        }
        // Users, like posts, are identified by an int object_id, so the raw
        // object_type:object_id key is already stable and distinct.
        return $snapshot['object_type'] . ':' . $snapshot['object_id'];
    }

    /** A compile-custom-widget snapshot: one widget's manifest entry plus bytes. */
    private static function is_compiled_widget_snapshot(array $snapshot): bool
    {
        return 'option' === $snapshot['object_type']
            && is_array($snapshot['data']['compiled_widget'] ?? null);
    }

    private static function restore_compiled_widget_entry(array $state): void
    {
        $manifest = self::compiled_widget_manifest();
        if (null !== $manifest) {
            $manifest::restore_entry($state);
        }
    }

    /**
     * The compiled-widget manifest class, when this build ships it. The only
     * place Rollback_Service names it, so a build without the compiler has
     * exactly one thing to remove.
     */
    private static function compiled_widget_manifest(): ?string
    {
        $class = '\\WPMCP\\Tools\\WidgetBuilder\\Compiler\\Compiled_Widget_Manifest';
        return class_exists($class) ? $class : null;
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
        // A compiled-widget snapshot carries the single manifest entry it
        // changed plus the generated file's previous bytes. Restoring those
        // together is the only correct undo: putting the whole option back
        // would revert every other widget compiled since, and putting the old
        // hash back against the new bytes would leave the widget inert.
        $manifest = self::compiled_widget_manifest();
        if (null !== $manifest && self::is_compiled_widget_snapshot($snapshot)) {
            $manifest::restore($snapshot['data']['compiled_widget']);
            return;
        }

        $name = (string) $snapshot['data']['name'];
        if ($snapshot['data']['existed']) {
            update_option($name, $snapshot['data']['value']);
        } else {
            delete_option($name);
        }
        self::options_restored([ $name ]);
    }

    /**
     * Announce the options a rollback just put back (issue #316), so code
     * that derives state from them (a theme's generated CSS) can rebuild it
     * instead of serving what the undone write produced.
     *
     * @param string[] $names
     */
    private static function options_restored(array $names): void
    {
        /** Action: options restored by a rollback, as a list of option names. */
        do_action('wpmcp_rollback_options_restored', $names);
    }

    /**
     * Put back ONE term's row inside Yoast's `wpseo_taxonomy_meta` option
     * (see Snapshot::capture_yoast_term_seo()), leaving every other term's
     * row as it is now. Goes through write_yoast_term_seo_row(), the same
     * path the write took, which handles Yoast's re-validation of the option
     * and refreshes the term's indexable.
     */
    private static function apply_yoast_term_seo_snapshot(array $snapshot): void
    {
        $data     = (array) $snapshot['data'];
        $taxonomy = (string) ($data['taxonomy'] ?? '');
        $term_id  = (int) ($data['term_id'] ?? 0);
        if ('' === $taxonomy || $term_id <= 0) {
            return;
        }

        $row = ! empty($data['existed']) && is_array($data['row'] ?? null) ? (array) $data['row'] : null;

        self::write_yoast_term_seo_row($taxonomy, $term_id, $row);
    }

    /**
     * Replace one term's row inside Yoast's `wpseo_taxonomy_meta` option,
     * or remove it when $row is null. Used by the term SEO write (issue #67)
     * and by the rollback of a 'yoast_term_seo' snapshot, so the write and
     * its undo take one path. Lives here, in the safety layer, so the
     * restore has no dependency on the paid SEO classes.
     *
     * With Yoast loaded every save of the option is re-validated through its
     * sanitize_option filter, and that validation keeps the previously
     * stored value for any key missing from the new row. Yoast also drops a
     * key whose value is its default ('default' for noindex), so a cleared
     * flag would be missing and the old 'noindex' would silently survive.
     * The row is therefore removed in one save (leaving no old value to
     * keep) and written in a second. Both run inside the caller's single
     * Safe_Mutation, after its snapshot.
     *
     * Yoast renders from its indexables table and rebuilds a term's
     * indexable only on `edited_term`, so that core action is fired after
     * the save, or the page would keep showing the old values.
     */
    public static function write_yoast_term_seo_row(string $taxonomy, int $term_id, ?array $row): void
    {
        $yoast_loaded = class_exists('WPSEO_Taxonomy_Meta');

        $option = get_option(\WPMCP\Safety\Snapshot::YOAST_TAXONOMY_META_OPTION, []);
        $option = is_array($option) ? $option : [];

        if ($yoast_loaded || null === $row) {
            unset($option[$taxonomy][$term_id]);
            if (isset($option[$taxonomy]) && [] === $option[$taxonomy]) {
                unset($option[$taxonomy]);
            }
            update_option(\WPMCP\Safety\Snapshot::YOAST_TAXONOMY_META_OPTION, $option);
        }

        if (null !== $row) {
            $option = get_option(\WPMCP\Safety\Snapshot::YOAST_TAXONOMY_META_OPTION, []);
            $option = is_array($option) ? $option : [];
            if (! isset($option[$taxonomy]) || ! is_array($option[$taxonomy])) {
                $option[$taxonomy] = [];
            }
            $option[$taxonomy][$term_id] = $row;
            update_option(\WPMCP\Safety\Snapshot::YOAST_TAXONOMY_META_OPTION, $option);
        }

        $term = get_term($term_id, $taxonomy);
        if ($yoast_loaded && $term instanceof \WP_Term) {
            clean_term_cache($term_id, $taxonomy);
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, fired so Yoast rebuilds the term indexable.
            do_action('edited_term', $term_id, (int) $term->term_taxonomy_id, $taxonomy, []);
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
     * Put the captured guid back byte for byte. Both core write paths run a
     * guid through its db-context sanitizing filters, which entity-encode the
     * "&" in a custom post type's "?post_type=x&p=N" guid ("&#038;" on an
     * update, "&amp;" on an insert), so a post a write merely re-saved came
     * back from rollback with a different guid than it had. Nothing reads the
     * encoded form differently, but a restore that promises the captured row
     * should return the captured row.
     */
    private static function restore_guid(int $object_id, string $guid): void
    {
        $current = get_post($object_id);
        if ('' === $guid || ! $current || $current->guid === $guid) {
            return;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- core offers no unsanitized guid write; the post cache is cleaned below.
        $wpdb->update($wpdb->posts, [ 'guid' => $guid ], [ 'ID' => $object_id ]);
        clean_post_cache($object_id);
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
     * Every object_type apply_snapshot() below actually knows how to restore.
     * List_Operations reads this rather than keeping its own copy: the copy
     * had gone stale for six object types at once, so every one of those
     * operations was reported to agents (and to the audit-log screen's
     * Restore button) as un-rollbackable while the tools that wrote them
     * reported recoverable: true. Add a branch below, get the listing for
     * free.
     *
     * @return string[]
     */
    public static function restorable_object_types(): array
    {
        return [
            'post',
            'option',
            'user',
            'comment',
            'wc_order',
            'db_rows',
            'redirect',
            'term',
            'yoast_term_seo',
            'wc_tax_rate',
            'php_snippet',
            'page_build',
            'media_import',
            'wc_product_create',
            Post_Creation_Snapshot::OBJECT_TYPE,
            Comment_Creation_Snapshot::OBJECT_TYPE,
            'elementor_global_classes',
            'elementor_global_variables',
            'theme_scaffold',
            'package_install',
            'acf_structure',
            'acf_options',
            'option_set',
        ];
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
        // A post snapshot that also carries a compiled widget's enabled flag
        // (set-widget-status, update-custom-widget): restore the post first,
        // then the flag, so the undo brings back the spec AND the compiled
        // path it was rendering through.
        if (is_array($snapshot['data']['compiled_widget_entry'] ?? null)) {
            $entry = $snapshot['data']['compiled_widget_entry'];
            unset($snapshot['data']['compiled_widget_entry']);
            self::apply_snapshot($snapshot);
            self::restore_compiled_widget_entry($entry);
            return;
        }

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

        if ('redirect' === $snapshot['object_type']) {
            self::apply_redirect_snapshot($snapshot);
            return;
        }

        if ('term' === $snapshot['object_type']) {
            self::apply_term_snapshot($snapshot);
            return;
        }

        if ('yoast_term_seo' === $snapshot['object_type']) {
            self::apply_yoast_term_seo_snapshot($snapshot);
            return;
        }

        if ('wc_tax_rate' === $snapshot['object_type']) {
            self::apply_wc_tax_rate_snapshot($snapshot);
            return;
        }

        if ('php_snippet' === $snapshot['object_type']) {
            self::apply_php_snippet_snapshot($snapshot);
            return;
        }

        if ('page_build' === $snapshot['object_type']) {
            self::apply_page_build_snapshot($snapshot);
            return;
        }

        if ('media_import' === $snapshot['object_type']) {
            self::apply_media_import_snapshot($snapshot);
            return;
        }

        if ('wc_product_create' === $snapshot['object_type']) {
            self::apply_wc_product_create_snapshot($snapshot);
            return;
        }

        // Spelled as a literal (Post_Creation_Snapshot::OBJECT_TYPE) like
        // every other branch here, so the restorable-types parity test sees it.
        if ('post_create' === $snapshot['object_type']) {
            self::apply_post_create_snapshot($snapshot);
            return;
        }

        // Comment_Creation_Snapshot::OBJECT_TYPE, spelled as a literal for the same reason.
        if ('comment_create' === $snapshot['object_type']) {
            self::apply_comment_create_snapshot($snapshot);
            return;
        }

        if ('elementor_global_classes' === $snapshot['object_type']) {
            self::apply_elementor_global_classes_snapshot($snapshot);
            return;
        }

        if ('elementor_global_variables' === $snapshot['object_type']) {
            self::apply_elementor_global_variables_snapshot($snapshot);
            return;
        }

        if ('theme_scaffold' === $snapshot['object_type']) {
            self::apply_theme_scaffold_snapshot($snapshot);
            return;
        }

        if ('package_install' === $snapshot['object_type']) {
            self::apply_package_install_snapshot($snapshot);
            return;
        }

        if ('acf_structure' === $snapshot['object_type']) {
            self::apply_acf_structure_snapshot($snapshot);
            return;
        }

        if ('acf_options' === $snapshot['object_type']) {
            self::apply_acf_options_snapshot($snapshot);
            return;
        }

        if ('option_set' === $snapshot['object_type']) {
            self::apply_option_set_snapshot($snapshot);
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
                // wp_update_post() unslashes its input; the snapshot holds
                // the raw stored columns, so they are slashed first or every
                // backslash (block JSON escapes such as \u003c) is lost.
                wp_update_post(wp_slash($postarr));
            } else {
                self::resurrect($object_id, $snapshot['data']['post'], $snapshot['data']['comments'] ?? []);
            }
            self::restore_guid($object_id, (string) ($snapshot['data']['post']['guid'] ?? ''));
        }

        $snapshotted_meta = (array) $snapshot['data']['meta'];
        $current_meta     = get_post_meta($object_id);
        $is_elementor     = self::is_elementor_document($snapshotted_meta) || self::is_elementor_document($current_meta);

        // Purge any meta key that didn't exist at snapshot time (newly added by the mutation).
        foreach (array_keys(array_diff_key($current_meta, $snapshotted_meta)) as $key) {
            delete_post_meta($object_id, $key);
        }

        // Restore snapshotted keys/values exactly as captured.
        foreach ($snapshotted_meta as $key => $values) {
            delete_post_meta($object_id, $key);
            foreach ((array) $values as $v) {
                // add_post_meta() unslashes too; slash so the value lands byte-for-byte.
                add_post_meta($object_id, $key, self::slash_meta_value(maybe_unserialize($v)));
            }
        }

        // Restore taxonomy term assignments captured at snapshot time. Older
        // snapshots predating term capture simply have no 'terms' key, so
        // this is a no-op for them (backward compatible).
        foreach ((array) ($snapshot['data']['terms'] ?? []) as $taxonomy => $term_ids) {
            wp_set_object_terms($object_id, array_map('intval', (array) $term_ids), (string) $taxonomy, false);
        }

        self::restore_files($snapshot['data']['files'] ?? null);

        if ($is_elementor) {
            self::refresh_elementor_caches($object_id, $snapshotted_meta);
        }

        if (isset($snapshotted_meta['_fl_builder_data']) || isset($current_meta['_fl_builder_data'])) {
            Beaver_Builder_Cache::clear($object_id);
        }

        self::refresh_woocommerce_product($object_id);
        self::refresh_woocommerce_coupon($object_id);
    }

    /**
     * Drop WooCommerce's coupon lookups after a raw restore of a shop_coupon
     * post (issue #195). WooCommerce resolves a code to a coupon id through
     * an object-cache entry keyed by the code, and its own save path only
     * clears the entry for the code it is saving. A rollback of a code change
     * writes wp_posts directly, so without this the NEW code would keep
     * resolving to the coupon after it has been put back to the old one.
     * Invalidating the whole 'coupons' group is what WooCommerce itself does
     * when coupon data changes in bulk; it costs one cache prefix bump.
     *
     * No-op when WooCommerce is absent or the post is not a coupon.
     */
    private static function refresh_woocommerce_coupon(int $object_id): void
    {
        if (! class_exists('WC_Cache_Helper') || 'shop_coupon' !== get_post_type($object_id)) {
            return;
        }
        \WC_Cache_Helper::invalidate_cache_group('coupons');
    }

    /**
     * Restore a WooCommerce tax rate captured by Snapshot::capture_wc_tax_rate()
     * (issue #195): update it in place when it still exists, or re-insert it
     * at its original id when it was deleted, then put its postcode and city
     * rows back exactly.
     *
     * Every write goes through WC_Tax's own internal CRUD helpers, so the
     * 'taxes' cache group is invalidated and the woocommerce_tax_rate_added /
     * _updated actions fire exactly as they do for an edit in wp-admin. The
     * resurrection passes tax_rate_id through _insert_tax_rate(), which
     * forwards unknown keys to $wpdb->insert() unchanged; the returned id is
     * then checked, and a mismatch (the id was somehow taken) is a loud
     * Mutation_Failed rather than a "restored" rate at the wrong id.
     *
     * Restoring store tax configuration is itself a store-settings write, so,
     * like the redirect restore, it re-checks the capability the write tools
     * require instead of trusting whoever reached the rollback.
     */
    private static function apply_wc_tax_rate_snapshot(array $snapshot): void
    {
        $data = (array) ($snapshot['data'] ?? []);
        $row  = $data['rate'] ?? null;
        if (! is_array($row) || ! class_exists('WC_Tax')) {
            return;
        }

        if (! current_user_can('manage_woocommerce')) {
            throw new Mutation_Failed('Rollback refused: restoring a tax rate requires the manage_woocommerce capability.');
        }

        $tax_rate_id = (int) $snapshot['object_id'];
        if ($tax_rate_id <= 0) {
            return;
        }

        $fields = array_diff_key($row, ['tax_rate_id' => true]);
        $live   = \WC_Tax::_get_tax_rate($tax_rate_id, ARRAY_A);

        if (is_array($live) && ! empty($live)) {
            \WC_Tax::_update_tax_rate($tax_rate_id, $fields);
        } else {
            $inserted = (int) \WC_Tax::_insert_tax_rate(array_merge(['tax_rate_id' => $tax_rate_id], $fields));
            if ($inserted !== $tax_rate_id) {
                throw new Mutation_Failed(sprintf(
                    'Rollback failed to restore tax rate %d at its original id (got %d).',
                    (int) $tax_rate_id,
                    (int) $inserted
                ));
            }
        }

        \WC_Tax::_update_tax_rate_postcodes($tax_rate_id, array_map('strval', (array) ($data['postcodes'] ?? [])));
        \WC_Tax::_update_tax_rate_cities($tax_rate_id, array_map('strval', (array) ($data['cities'] ?? [])));
    }

    /**
     * Slash a meta value for add_post_meta(), strings inside objects too.
     * add_post_meta() unslashes with map_deep(), which walks object
     * properties, while wp_slash() skips objects, so a value holding objects
     * (a Beaver Builder node map is an array of stdClass) would otherwise
     * lose every backslash in those strings on restore. Identical to
     * wp_slash() for strings and arrays.
     *
     * @param mixed $value freshly unserialized, so mutating its objects is safe
     * @return mixed
     */
    private static function slash_meta_value($value)
    {
        return map_deep($value, static fn ($item) => is_string($item) ? addslashes($item) : $item);
    }

    /** Whether a post meta map (get_post_meta() shape) carries Elementor document data. */
    private static function is_elementor_document(array $meta): bool
    {
        return isset($meta['_elementor_data']) || isset($meta['_elementor_page_settings']);
    }

    /**
     * Drop Elementor's derived caches after a raw post restore.
     *
     * The meta restore above puts back whatever `_elementor_css` and render
     * cache the snapshot held, while the CSS file on disk was regenerated for
     * the content being rolled back, so the page would be served that CSS for
     * the restored data. A kit's settings feed every document's CSS, so
     * restoring the kit purges site-wide.
     */
    private static function refresh_elementor_caches(int $object_id, array $snapshotted_meta): void
    {
        Elementor_Cache::invalidate_document($object_id);

        $type = $snapshotted_meta['_elementor_template_type'][0] ?? get_post_meta($object_id, '_elementor_template_type', true);
        if ('kit' === maybe_unserialize($type)) {
            Elementor_Cache::clear_all();
        }
    }

    /**
     * Bring WooCommerce's derived data back in line after a raw post restore.
     *
     * The post path above writes wp_posts and wp_postmeta directly, which is
     * exactly right for the snapshot contract but bypasses WooCommerce's CRUD
     * layer. For a product or variation that layer maintains three things the
     * raw restore leaves stale: per-product transients (the variable parent's
     * cached variation price range among them), the wc_product_meta_lookup
     * row that sorting and filtering read, and, for a variation, the parent's
     * synced _price range and stock status. Without this, rolling back a
     * variation price change restores the variation's own meta while the
     * storefront keeps showing the un-rolled-back parent range.
     *
     * No-op when WooCommerce is absent or the post is not a product.
     */
    private static function refresh_woocommerce_product(int $object_id): void
    {
        if (! function_exists('wc_get_product') || ! class_exists('WC_Product_Data_Store_CPT')) {
            return;
        }

        $post_type = get_post_type($object_id);
        if (! in_array($post_type, ['product', 'product_variation'], true)) {
            return;
        }

        wc_delete_product_transients($object_id);
        self::refresh_woocommerce_lookup_row($object_id);

        if ('product_variation' !== $post_type) {
            return;
        }

        $parent_id = (int) wp_get_post_parent_id($object_id);
        if ($parent_id <= 0 || ! class_exists('WC_Product_Variable')) {
            return;
        }

        // sync() re-reads the children's _price rows, rewrites the parent's
        // _price range and stock status, refreshes the parent's lookup row
        // and saves the parent through the CRUD layer, which is the same path
        // a variation save() takes.
        wc_delete_product_transients($parent_id);
        \WC_Product_Variable::sync($parent_id);
    }

    /**
     * Rewrite one product's wc_product_meta_lookup row from its (restored)
     * postmeta. WooCommerce keeps update_lookup_table() protected on the data
     * store and only calls it from its own save path, which a raw meta restore
     * never goes through; the anonymous subclass is the narrowest way to reach
     * it without re-implementing the row's column mapping.
     */
    private static function refresh_woocommerce_lookup_row(int $object_id): void
    {
        $refresher = new class extends \WC_Product_Data_Store_CPT {
            public function refresh(int $id): void
            {
                $this->update_lookup_table($id, 'wc_product_meta_lookup');
            }
        };
        $refresher->refresh($object_id);
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
     *    the exact real name, which is then bound with %i);
     *  - the table must not be protected (users/usermeta by default) — a
     *    legitimate snapshot can never reference one, because the write
     *    tools refuse protected tables before capturing anything;
     *  - a non-empty primary key must be declared in the snapshot, and every
     *    captured row must carry a non-null value for each PK column;
     *  - every captured column name must exactly match a live column of the
     *    table (closes both column-name injection, since %i quotes an
     *    identifier but does not check that it exists, and silent schema
     *    drift: a dropped column means the promised exact restore is
     *    impossible, so fail loudly instead).
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
            throw new Mutation_Failed('Rollback refused: ' . esc_html($table->get_error_message()));
        }
        if (\WPMCP\Tools\Database\Database_Guard::is_protected($table)) {
            throw new Mutation_Failed('Rollback refused: table "' . esc_html($table) . '" is protected.');
        }

        $primary_key = array_values(array_map('strval', (array) ($data['primary_key'] ?? [])));
        if ([] === $primary_key) {
            throw new Mutation_Failed('Rollback refused: db_rows snapshot has no primary key.');
        }

        $live_columns = \WPMCP\Tools\Database\Database_Guard::columns($table);
        foreach ($primary_key as $column) {
            if (! in_array($column, $live_columns, true)) {
                throw new Mutation_Failed('Rollback refused: primary-key column "' . esc_html($column) . '" is not a column of "' . esc_html($table) . '".');
            }
        }

        $operation = (string) ($data['operation'] ?? '');
        $set       = (array) ($data['set'] ?? []);

        // The restore is as raw a write as the operation it undoes, so the
        // same caches are stale afterwards (issue #182): without the
        // invalidation, get_option() and friends keep serving the value the
        // rollback just overwrote. It runs in `finally` so that a
        // Mutation_Failed on a later row still invalidates the rows already
        // restored.
        $attempted = [];
        try {
            foreach ($rows as $row) {
                $row = (array) $row;

                foreach (array_keys($row) as $column) {
                    if (! in_array((string) $column, $live_columns, true)) {
                        throw new Mutation_Failed('Rollback refused: captured column "' . esc_html((string) $column) . '" is not a column of "' . esc_html($table) . '".');
                    }
                }

                $where = [];
                foreach ($primary_key as $column) {
                    if (! isset($row[ $column ])) {
                        throw new Mutation_Failed('Rollback refused: a captured row is missing primary-key value "' . esc_html($column) . '".');
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

                // Recorded before the write: a write that fails part-way may
                // still have changed the row, so it is invalidated too.
                $attempted[] = $row;

                if (null === $current) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- reinserts a captured before-image row into the Database_Guard-validated table it was deleted from; no WP API covers raw table rows.
                    if (false === $wpdb->insert($table, $row)) {
                        throw new Mutation_Failed('Rollback failed to reinsert row ' . esc_html($pk_desc) . ' into "' . esc_html($table) . '": ' . (esc_html($wpdb->last_error) ?: 'insert failed'));
                    }
                    continue;
                }

                $restore = array_diff_key($row, array_flip($primary_key));
                if ([] === $restore) {
                    continue; // PK-only table: existing row is already the before-image.
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- restores a captured before-image row in the Database_Guard-validated table; undo-critical write, no WP API covers raw table rows. Not cached: the object caches over this table are invalidated right after via Database_Guard::invalidate_caches().
                if (false === $wpdb->update($table, $restore, $where)) {
                    throw new Mutation_Failed('Rollback failed to restore row ' . esc_html($pk_desc) . ' in "' . esc_html($table) . '": ' . (esc_html($wpdb->last_error) ?: 'update failed'));
                }
            }
        } finally {
            if ([] !== $attempted) {
                Database_Guard::invalidate_caches($table, ['rows' => $attempted]);
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

    /**
     * Undo a media import (issue #64: import-stock-image / upload-svg).
     * Same creation-snapshot semantics as 'page_build': the snapshot records
     * the attachment the tool CREATED, so restoring it means deleting that
     * attachment again — wp_delete_attachment(force) removes both the post
     * row and the physical files, the true inverse of the import. The same
     * post_date_gmt identity check protects an unrelated post that has since
     * reclaimed the id, and a non-attachment at the id is likewise left
     * untouched with a warning rather than destroyed.
     */
    private static function apply_media_import_snapshot(array $snapshot): void
    {
        $data     = (array) ($snapshot['data'] ?? []);
        $media_id = (int) $snapshot['object_id'];
        $current  = get_post($media_id);
        if (! $current) {
            return; // Already gone; nothing left to undo.
        }

        if ('attachment' !== $current->post_type || ($data['post_date_gmt'] ?? null) !== $current->post_date_gmt) {
            self::warn("Post {$media_id} is not the attachment this import created (the id was reclaimed); it was left untouched.");
            return;
        }

        wp_delete_attachment($media_id, true);
    }

    /**
     * Undo the creation of a WooCommerce product or variation
     * (apply-product-import). Same creation-snapshot semantics as
     * 'media_import': the snapshot names what the operation CREATED, so the
     * restore deletes it permanently through WooCommerce's CRUD layer, which
     * also clears its lookup row and caches. A created variable product takes
     * every variation under it along; a variation created under a product
     * that already existed is removed and the parent's price range and stock
     * status are re-synced from the variations that remain.
     *
     * The same post_date_gmt and post type identity check as the other
     * creation restores protects an unrelated post that has since reclaimed
     * the id: it is left untouched with a warning.
     */
    private static function apply_wc_product_create_snapshot(array $snapshot): void
    {
        $data       = (array) ($snapshot['data'] ?? []);
        $product_id = (int) $snapshot['object_id'];
        $current    = get_post($product_id);
        if (! $current) {
            return; // Already gone; nothing left to undo.
        }

        if (($data['post_type'] ?? null) !== $current->post_type || ($data['post_date_gmt'] ?? null) !== $current->post_date_gmt) {
            self::warn("Post {$product_id} is not the product this import created (the id was reclaimed); it was left untouched.");
            return;
        }
        if (! function_exists('wc_get_product')) {
            self::warn("WooCommerce is not active, so product {$product_id} created by an import was left in place.");
            return;
        }

        $children = get_posts([
            'post_type'      => 'product_variation',
            'post_parent'    => $product_id,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => -1,
        ]);
        foreach ($children as $child_id) {
            $child = wc_get_product((int) $child_id);
            if ($child) {
                $child->delete(true);
            }
        }

        $product = wc_get_product($product_id);
        if ($product) {
            $product->delete(true);
        } else {
            wp_delete_post($product_id, true);
        }

        $parent_id = (int) ($data['parent_id'] ?? 0);
        if ($parent_id > 0 && class_exists('WC_Product_Variable')) {
            $parent = wc_get_product($parent_id);
            if ($parent && $parent->is_type('variable')) {
                wc_delete_product_transients($parent_id);
                \WC_Product_Variable::sync($parent_id);
            }
        }
    }

    /**
     * Post types whose creation is undone by deactivating rather than
     * trashing: the custom widget and block spec stores, where post status
     * draft is the store's own "inactive" (set-widget-status and
     * set-block-status use the same switch). A draft spec stops rendering in
     * both the dynamic and the compiled form and stays editable.
     */
    private const DEACTIVATE_ON_CREATION_ROLLBACK = ['wpmcp_widget', 'wpmcp_block'];

    /**
     * Undo a creation recorded by Post_Creation_Snapshot (create-post,
     * duplicate-post, create-custom-widget, create-custom-block; issue #192).
     *
     * Deliberately non-destructive, unlike 'page_build' and 'media_import':
     * every created post is moved to the trash, where restore-post can bring
     * it back, and a custom widget or block spec is deactivated instead.
     * Nothing is permanently deleted.
     *
     * Each post gets the same identity check as the other creation restores:
     * post_type and post_date_gmt are fixed at creation, so a mismatch means
     * a different post has since reclaimed the id; it is left untouched with
     * a warning. A post that is already gone, already in the trash, or (for a
     * spec) already inactive needs nothing.
     */
    private static function apply_post_create_snapshot(array $snapshot): void
    {
        $created = (array) ($snapshot['data']['created'] ?? []);

        // Children first, so no trashed parent is ever left with live children.
        foreach (array_reverse($created) as $entry) {
            $post_id = (int) ($entry['post_id'] ?? 0);
            $current = $post_id > 0 ? get_post($post_id) : null;
            if (! $current) {
                continue; // Already gone; nothing left to undo.
            }

            if (($entry['post_type'] ?? null) !== $current->post_type || ($entry['post_date_gmt'] ?? null) !== $current->post_date_gmt) {
                self::warn("Post {$post_id} is not the post this operation created (the id was reclaimed); it was left untouched.");
                continue;
            }

            if (in_array($current->post_type, self::DEACTIVATE_ON_CREATION_ROLLBACK, true)) {
                if ('draft' !== $current->post_status && 'trash' !== $current->post_status) {
                    $updated = wp_update_post(['ID' => $post_id, 'post_status' => 'draft'], true);
                    if (is_wp_error($updated) || 0 === $updated) {
                        self::warn("Spec {$post_id} created by this operation could not be deactivated.");
                    }
                }
                continue;
            }

            if ('trash' === $current->post_status) {
                continue;
            }
            if (! wp_trash_post($post_id)) {
                self::warn("Post {$post_id} created by this operation could not be moved to the trash; it was left in place.");
            }
        }
    }

    /**
     * Undo a comment creation recorded by Comment_Creation_Snapshot
     * (create-comment, reply-to-comment; issue #284). Non-destructive like
     * the post creation undo: the comment is moved to the trash, where it can
     * be restored. The identity check (post and comment_date_gmt, both fixed
     * at creation) keeps a comment that has since reclaimed the id untouched.
     */
    private static function apply_comment_create_snapshot(array $snapshot): void
    {
        $comment_id = (int) $snapshot['object_id'];
        $current    = $comment_id > 0 ? get_comment($comment_id) : null;
        if (! $current) {
            return; // Already gone; nothing left to undo.
        }

        $data = (array) ($snapshot['data'] ?? []);
        if ((int) ($data['comment_post_ID'] ?? 0) !== (int) $current->comment_post_ID || ($data['comment_date_gmt'] ?? null) !== $current->comment_date_gmt) {
            self::warn("Comment {$comment_id} is not the comment this operation created (the id was reclaimed); it was left untouched.");
            return;
        }

        if ('trash' === $current->comment_approved) {
            return;
        }
        if (! wp_trash_comment($comment_id)) {
            self::warn("Comment {$comment_id} created by this operation could not be moved to the trash; it was left in place.");
        }
    }

    /**
     * Undo a managed-redirect write (issue #128; snapshot shape documented at
     * Snapshot::capture_redirect()).
     *
     * Like the db_rows path, THE ROLLBACK ITSELF PERFORMS A RAW TABLE WRITE,
     * so it enforces the write tools' own gate here rather than inheriting
     * the rollback tools' deliberately lower edit_posts gate. Without this,
     * an edit_posts caller could add or remove site-wide redirects by
     * replaying an administrator's operation through rollback-operation.
     *
     * Two prior states are possible, and they are exactly the two an option
     * snapshot has:
     *  - the path already had a redirect: put the captured row back. If the
     *    row still exists it is overwritten in place (which is what restores
     *    a source_path that update-redirect renamed, since the id is
     *    unchanged); if it is gone, the full captured row is reinserted
     *    INCLUDING its original id, so a deleted redirect comes back as the
     *    same row rather than a copy.
     *  - the path had no redirect: the write introduced one, so whatever now
     *    owns that path is deleted.
     * A row that has since been reclaimed by a different source path is
     * reported as a warning, not silently overwritten, matching how every
     * other restore path treats a reclaimed identity.
     */
    /**
     * Restore a taxonomy term to its captured state.
     *
     * Three cases, mirroring apply_redirect_snapshot():
     *  - the term did not exist at capture time: delete whatever now holds
     *    that (taxonomy, slug), which undoes a create-term;
     *  - the term existed and still does: overwrite its fields and meta;
     *  - the term existed and is gone: resurrect it at its ORIGINAL term_id,
     *    because post-to-term relationships are stored by term_taxonomy_id
     *    and a term that comes back with a fresh id is silently detached from
     *    every post that was filed under it.
     *
     * Deleting a term is not routed through wp_delete_term() in the
     * "undo a create" case only when the term is the taxonomy default; that
     * would move posts to a replacement term, which is a second mutation the
     * user never asked for. It is reported instead.
     */
    private static function apply_term_snapshot(array $snapshot): void
    {
        if (! current_user_can('manage_categories')) {
            throw new Mutation_Failed('Rollback refused: restoring a term requires the manage_categories capability.');
        }

        $data     = (array) ($snapshot['data'] ?? []);
        $taxonomy = (string) ($data['taxonomy'] ?? '');
        $slug     = (string) ($data['slug'] ?? '');

        if ('' === $taxonomy || ! taxonomy_exists($taxonomy)) {
            self::warn(sprintf('Taxonomy "%s" no longer exists, so its term could not be restored.', $taxonomy));
            return;
        }

        $captured = is_array($data['term'] ?? null) ? (array) $data['term'] : null;

        if (empty($data['existed'])) {
            $current = get_term_by('slug', $slug, $taxonomy);
            if ($current instanceof \WP_Term) {
                wp_delete_term($current->term_id, $taxonomy);
            }
            return;
        }

        if (null === $captured) {
            return;
        }

        $term_id = (int) ($captured['term_id'] ?? 0);
        if ($term_id <= 0) {
            return;
        }

        // Something else may have taken the captured slug since the
        // operation. Restoring onto it would fail the taxonomy's unique-slug
        // rule, so the squatter is cleared first, exactly as the redirect
        // path does for its UNIQUE source_path.
        $holder = get_term_by('slug', $slug, $taxonomy);
        if ($holder instanceof \WP_Term && (int) $holder->term_id !== $term_id) {
            self::warn(sprintf(
                'Term slug "%s" in %s had been taken by term #%d since the operation; it was removed so the captured term could be restored.',
                $slug,
                $taxonomy,
                (int) $holder->term_id
            ));
            wp_delete_term((int) $holder->term_id, $taxonomy);
        }

        $was_missing = null === get_term($term_id, $taxonomy);

        if ($was_missing) {
            self::resurrect_term($term_id, $taxonomy, $captured);
        } else {
            wp_update_term($term_id, $taxonomy, [
                'name'        => (string) ($captured['name'] ?? ''),
                'slug'        => $slug,
                'description' => (string) ($captured['description'] ?? ''),
                'parent'      => (int) ($captured['parent'] ?? 0),
            ]);
        }

        self::restore_term_meta($term_id, (array) ($data['meta'] ?? []));

        // Only a resurrection needs the relationships rebuilt: an in-place
        // update never removed them, and re-adding them there would fight
        // with assignments made after the operation.
        if ($was_missing) {
            self::restore_term_objects($term_id, $taxonomy, $data);
        }
    }

    /**
     * Refile the objects that were assigned to a term before it was deleted.
     *
     * wp_delete_term() removes the wp_term_relationships rows along with the
     * term, so without this a restored term comes back empty: correct-looking
     * in wp-admin, and silently detached from all of its content.
     */
    private static function restore_term_objects(int $term_id, string $taxonomy, array $data): void
    {
        $objects = array_map('intval', (array) ($data['objects'] ?? []));

        foreach ($objects as $object_id) {
            if ($object_id > 0) {
                wp_set_object_terms($object_id, [$term_id], $taxonomy, true);
            }
        }

        if (! empty($data['objects_truncated'])) {
            self::warn(sprintf(
                'Term %d held more than %d objects when it was captured; the %d most recent were reattached and the rest were not.',
                $term_id,
                \WPMCP\Safety\Snapshot::MAX_TERM_OBJECTS,
                count($objects)
            ));
        }

        wp_update_term_count_now($objects ? [$term_id] : [], $taxonomy);
    }

    /**
     * Re-insert a deleted term at its original term_id.
     *
     * wp_insert_term() always allocates a new id, so the row goes in
     * directly. term_taxonomy_id is likewise preserved: it is the column
     * wp_term_relationships joins on, so reusing it is what actually
     * reattaches the posts rather than merely recreating a same-named term.
     */
    private static function resurrect_term(int $term_id, string $taxonomy, array $captured): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Restoring a deleted term at its original id; no core API preserves term_id or term_taxonomy_id. Not cached: clean_term_cache() runs once both rows are back.
        $wpdb->insert($wpdb->terms, [
            'term_id'    => $term_id,
            'name'       => (string) ($captured['name'] ?? ''),
            'slug'       => (string) ($captured['slug'] ?? ''),
            'term_group' => (int) ($captured['term_group'] ?? 0),
        ]);

        $term_taxonomy_id = (int) ($captured['term_taxonomy_id'] ?? 0);
        $row              = [
            'term_id'     => $term_id,
            'taxonomy'    => $taxonomy,
            'description' => (string) ($captured['description'] ?? ''),
            'parent'      => (int) ($captured['parent'] ?? 0),
            'count'       => (int) ($captured['count'] ?? 0),
        ];
        if ($term_taxonomy_id > 0) {
            $row['term_taxonomy_id'] = $term_taxonomy_id;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above; term_taxonomy_id is what wp_term_relationships joins on. clean_term_cache() below invalidates.
        $wpdb->insert($wpdb->term_taxonomy, $row);

        clean_term_cache([$term_id], $taxonomy);
    }

    /** Replace a term's meta with the captured map. */
    private static function restore_term_meta(int $term_id, array $meta): void
    {
        $existing = get_term_meta($term_id);
        if (is_array($existing)) {
            foreach (array_keys($existing) as $key) {
                delete_term_meta($term_id, (string) $key);
            }
        }

        foreach ($meta as $key => $values) {
            foreach ((array) $values as $value) {
                add_term_meta($term_id, (string) $key, maybe_unserialize($value));
            }
        }
    }

    /**
     * Undo one stored PHP snippet write (issue #85; snapshot shape
     * documented at Snapshot::capture_php_snippet()).
     *
     * manage_options is required for the same reason the redirect path
     * requires it: the store holds PHP source, and restoring a record is a
     * site-administration action, not a content edit.
     *
     * Two cases, and only the one captured record is touched either way, so
     * snippets created or edited after this operation survive the rollback:
     *  - the id existed: put the captured record back, with ONE deliberate
     *    departure from verbatim, below.
     *  - the id did not exist: the write created it, so remove it.
     *
     * THE STATUS FLAG IS NEVER RESTORED AS ACTIVE. rollback-operation and
     * rollback-session are not in Opt_In_Gates and do not consult the PHP
     * execution gate, the license check, the governance toggle or the
     * identity scope of activate-php-snippet, and write no activation entry
     * to the governance trail. Restoring a captured status='active'
     * would make an undo a second, ungoverned door to activation (undo a
     * deactivate-php-snippet, or an update that forced the snippet back to
     * inactive, and the snippet is armed again). An open exec gate is not
     * enough either, since it is only one part of that contract, so the
     * restore is unconditionally INACTIVE. Every other field is restored
     * exactly, and re-activation goes
     * back through activate-php-snippet. This does not depend on "nothing
     * executes from the status flag yet", which stops being true the moment
     * the documented executor lands.
     */
    private static function apply_php_snippet_snapshot(array $snapshot): void
    {
        if (! current_user_can('manage_options')) {
            throw new Mutation_Failed('Rollback refused: restoring a stored PHP snippet requires the manage_options capability.');
        }

        $data = (array) ($snapshot['data'] ?? []);
        $id   = (string) ($data['id'] ?? '');
        if ('' === $id) {
            return;
        }

        if (empty($data['existed'])) {
            \WPMCP\Tools\Code\Php_Snippet_Store::delete($id);
            return;
        }

        $record = $data['record'] ?? null;
        if (! is_array($record)) {
            throw new Mutation_Failed('Rollback refused: the captured PHP snippet record is missing or malformed.');
        }

        // Restore under the key the record was captured under, which is what
        // every snippet tool resolves it by. A record whose own id field had
        // drifted would otherwise be written to a second, ghost key while the
        // real one stayed as the undone write left it.
        $record['id']     = $id;
        $record['status'] = \WPMCP\Tools\Code\Php_Snippet_Store::STATUS_INACTIVE;

        \WPMCP\Tools\Code\Php_Snippet_Store::save($record);
    }

    private static function apply_redirect_snapshot(array $snapshot): void
    {
        if (! current_user_can('manage_options')) {
            throw new Mutation_Failed('Rollback refused: restoring a managed redirect requires the manage_options capability.');
        }

        $data   = (array) ($snapshot['data'] ?? []);
        $source = (string) ($data['source_path'] ?? '');
        if ('' === $source) {
            return;
        }

        if (empty($data['existed'])) {
            \WPMCP\Tools\Redirects\Redirect_Store::delete_by_source($source);
            return;
        }

        $row = (array) ($data['row'] ?? []);
        $id  = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            return;
        }

        $current = \WPMCP\Tools\Redirects\Redirect_Store::get($id);
        if (null === $current) {
            \WPMCP\Tools\Redirects\Redirect_Store::insert_raw($row);
            return;
        }

        // Restoring the captured source_path onto this id would collide with
        // the UNIQUE key if some other row has taken that path in the
        // meantime. Clear the squatter first: it is either the row this
        // operation created (create-redirect on a path that was later
        // re-pointed) or a third-party duplicate that cannot coexist with the
        // state we promised to restore.
        $holder = \WPMCP\Tools\Redirects\Redirect_Store::find_by_source($source);
        if (null !== $holder && $holder['id'] !== $id) {
            self::warn(sprintf(
                'Redirect source "%s" had been taken by redirect #%d since the operation; it was removed so the captured redirect could be restored.',
                $source,
                $holder['id']
            ));
            \WPMCP\Tools\Redirects\Redirect_Store::delete($holder['id']);
        }

        \WPMCP\Tools\Redirects\Redirect_Store::overwrite($id, $row);
    }

    /**
     * Undo an Elementor v4 global classes write (issue #132: create / update /
     * delete / reorder a Class Manager entry).
     *
     * Unlike every other Elementor edit, the class set is not a single post:
     * since Elementor 4.2 each class is its own post while order and labels
     * live on the kit, so the snapshot captures the WHOLE prior class set
     * (items + order) and the undo replays it through Elementor's repository,
     * which computes the create/update/delete diff. That restores an edited
     * class, resurrects a deleted one and puts the order back in a single
     * step.
     *
     * The repository call is made here rather than through the Elementor tool
     * layer on purpose: the safety layer must be able to restore without
     * depending on the tool that wrote. If Elementor is no longer present the
     * snapshot cannot be applied, which is warned about rather than silently
     * treated as a successful rollback.
     */
    private static function apply_elementor_global_classes_snapshot(array $snapshot): void
    {
        $data       = (array) ($snapshot['data'] ?? []);
        $repository = '\\Elementor\\Modules\\GlobalClasses\\Global_Classes_Repository';

        if (! class_exists($repository) || ! is_callable([$repository, 'make'])) {
            self::warn('Elementor global classes cannot be restored: Elementor 4.0+ is no longer available on this site.');
            return;
        }

        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $order = is_array($data['order'] ?? null) ? array_values($data['order']) : [];

        try {
            call_user_func([$repository, 'make'])->put($items, $order);
        } catch (\Throwable $e) {
            self::warn('Elementor refused the global classes restore: ' . $e->getMessage());
        }

        // Class styles are compiled into generated CSS across the site.
        Elementor_Cache::clear_all();
    }

    /**
     * Undo an Elementor 4 global variables write (create / update / delete a
     * design token).
     *
     * Elementor keeps every variable in one JSON record on the kit and bumps a
     * watermark on each save, so replaying the change through its service
     * could never reproduce the prior record. The snapshot holds the record's
     * raw bytes instead (or that there was none), and the undo puts exactly
     * those back, which also revives a soft-deleted variable. The generated
     * CSS is cleared so the restored tokens render on the next view.
     */
    private static function apply_elementor_global_variables_snapshot(array $snapshot): void
    {
        $data   = (array) ($snapshot['data'] ?? []);
        $kit_id = (int) ($data['kit_id'] ?? $snapshot['object_id'] ?? 0);

        if ($kit_id <= 0 || ! get_post($kit_id)) {
            self::warn('Elementor global variables cannot be restored: the kit they belonged to no longer exists.');
            return;
        }

        // Written here, not through the Elementor tool layer, so the safety
        // layer can restore without depending on the tool that wrote.
        if (! empty($data['exists'])) {
            update_post_meta($kit_id, '_elementor_global_variables', wp_slash((string) ($data['raw'] ?? '')));
        } else {
            delete_post_meta($kit_id, '_elementor_global_variables');
        }
        clean_post_cache($kit_id);

        if (class_exists('\\Elementor\\Plugin') && isset(\Elementor\Plugin::instance()->files_manager)) {
            \Elementor\Plugin::instance()->files_manager->clear_cache();
        }
    }

    /**
     * Restore one piece of ACF structure (issue #291) to its captured tree:
     * the field group, ACF post type or ACF taxonomy post plus every acf-field
     * post beneath it. Posts under the key now that were not captured (a new
     * group, or a field an update added) are deleted; every captured post is
     * restored through the ordinary post path, which updates in place or
     * resurrects a force-deleted field at its original ID. ACF's own caches
     * for every touched key are flushed afterwards, since the restore writes
     * rows underneath ACF rather than through it.
     *
     * Restoring structure needs the capability ACF itself demands for editing
     * it, so rollback cannot become a way around ACF's own admin gate.
     */
    private static function apply_acf_structure_snapshot(array $snapshot): void
    {
        $capability = function_exists('acf_get_setting') ? (string) acf_get_setting('capability') : 'manage_options';
        if (! current_user_can('' === $capability ? 'manage_options' : $capability)) {
            throw new Mutation_Failed(esc_html(sprintf('Rollback refused: restoring ACF structure requires the %s capability.', $capability)));
        }

        $data     = (array) ($snapshot['data'] ?? []);
        $key      = (string) ($data['key'] ?? $snapshot['object_id']);
        $captured = (array) ($data['posts'] ?? []);

        $root    = Snapshot::acf_structure_root_id($key);
        $current = null === $root ? [] : Snapshot::acf_structure_tree($root);
        $touched = [];
        foreach ($current as $id) {
            $touched[ $id ] = get_post($id, ARRAY_A);
        }

        // Children first, so a parent is never deleted with live children.
        foreach (array_reverse($current) as $id) {
            if (! isset($captured[ (string) $id ])) {
                wp_delete_post($id, true);
            }
        }

        foreach ($captured as $id => $post_data) {
            self::apply_snapshot([
                'object_type' => 'post',
                'object_id'   => (int) $id,
                'data'        => (array) $post_data,
            ]);
            $touched[ (int) $id ] = (array) ($post_data['post'] ?? []);
        }

        self::flush_acf_caches(array_filter($touched));
    }

    /** Drop ACF's stores and cached lookups for every restored or removed structure post. */
    private static function flush_acf_caches(array $rows): void
    {
        if (! function_exists('acf_flush_field_cache')) {
            return;
        }
        foreach ($rows as $row) {
            $type = (string) ($row['post_type'] ?? '');
            $key  = (string) ($row['post_name'] ?? '');
            if ('acf-field' === $type) {
                acf_flush_field_cache([
                    'key'    => $key,
                    'name'   => (string) ($row['post_excerpt'] ?? ''),
                    'parent' => (int) ($row['post_parent'] ?? 0),
                ]);
            } elseif (in_array($type, Snapshot::ACF_STRUCTURE_POST_TYPES, true) && function_exists('acf_flush_internal_post_type_cache')) {
                acf_flush_internal_post_type_cache([ 'key' => $key ], $type);
            }
        }
        foreach ([ 'fields', 'field-groups', 'post-types', 'taxonomies' ] as $store) {
            $instance = acf_get_store($store);
            if ($instance) {
                $instance->reset();
            }
        }
    }

    /**
     * Restore every option an option_set snapshot captured (issue #285), each
     * the way apply_option_snapshot() restores one: the captured value goes
     * back, or the option is deleted when the write created it.
     */
    private static function apply_option_set_snapshot(array $snapshot): void
    {
        foreach ((array) ($snapshot['data']['options'] ?? []) as $name => $state) {
            $state = (array) $state;
            if (! empty($state['existed'])) {
                update_option((string) $name, $state['value'] ?? null);
            } else {
                delete_option((string) $name);
            }
        }
        self::options_restored(array_map('strval', array_keys((array) ($snapshot['data']['options'] ?? []))));
    }

    /**
     * Restore the option rows an ACF options page write touched (issue #291):
     * rows under the captured field names that the write added are deleted,
     * and every captured row gets its captured value back. The in-request
     * value store is reset so a later get_field() in the same request reads
     * the restored rows instead of ACF's memo of the undone ones.
     */
    private static function apply_acf_options_snapshot(array $snapshot): void
    {
        $data    = (array) ($snapshot['data'] ?? []);
        $post_id = (string) ($data['post_id'] ?? '');
        $names   = array_map('strval', (array) ($data['names'] ?? []));
        $rows    = (array) ($data['rows'] ?? []);

        foreach (Snapshot::acf_option_names($post_id, $names) as $name) {
            if (! array_key_exists($name, $rows)) {
                delete_option($name);
            }
        }
        foreach ($rows as $name => $value) {
            update_option((string) $name, $value);
        }

        if (function_exists('acf_get_store')) {
            $values = acf_get_store('values');
            if ($values) {
                $values->reset();
            }
        }
    }

    /**
     * Undo a create-child-theme scaffold (see Snapshot::capture_theme_scaffold()).
     *
     * Only the scaffold's own files are touched: each is put back to its
     * captured bytes, or deleted when it did not exist before. The directory
     * is removed only when the scaffold created it AND nothing else has been
     * added to it since; anything a person added afterwards is left in place
     * with a warning, never deleted as collateral.
     *
     * A scaffold that is currently the active theme (or the parent of it) is
     * left alone with a warning: deleting the active theme's files would take
     * the front end down, which is the opposite of what an undo is for.
     * Switch themes first (itself an undoable operation), then roll back.
     */
    private static function apply_theme_scaffold_snapshot(array $snapshot): void
    {
        $data = (array) ($snapshot['data'] ?? []);
        $slug = (string) ($data['slug'] ?? '');

        if ('' === $slug || sanitize_key($slug) !== $slug) {
            self::warn('Child theme scaffold cannot be restored: the snapshot carries no valid theme slug.');
            return;
        }

        $dir = trailingslashit(get_theme_root()) . $slug;
        if (is_link($dir)) {
            self::warn(sprintf('Child theme "%s" was not rolled back: its directory is now a symlink.', $slug));
            return;
        }
        if (! is_dir($dir)) {
            if (! empty($data['dir_existed'])) {
                self::warn(sprintf('Child theme "%s" was not rolled back: its directory no longer exists.', $slug));
            }
            return;
        }
        if (get_stylesheet() === $slug || get_template() === $slug) {
            self::warn(sprintf('Child theme "%s" is the active theme, so its files were left in place. Switch to another theme, then roll back again.', $slug));
            return;
        }

        $fs        = self::direct_filesystem();
        $too_large = (array) ($data['too_large'] ?? []);
        foreach (Snapshot::THEME_SCAFFOLD_FILES as $file) {
            $path = $dir . '/' . $file;
            if (in_array($file, $too_large, true)) {
                self::warn(sprintf('Child theme "%s": %s was too large to capture and was left as it is.', $slug, $file));
                continue;
            }
            if (is_link($path)) {
                self::warn(sprintf('Child theme "%s": %s is now a symlink and was left as it is.', $slug, $file));
                continue;
            }
            $before = $data['files'][ $file ] ?? null;
            if (null === $before) {
                if (is_file($path)) {
                    $fs->delete($path);
                }
                continue;
            }
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes the file bytes Snapshot::capture_theme_scaffold() encoded, not obfuscation.
            $bytes = base64_decode((string) $before, true);
            if (false === $bytes || ! $fs->put_contents($path, $bytes, 0644)) {
                self::warn(sprintf('Child theme "%s": %s could not be restored.', $slug, $file));
            }
        }

        if (empty($data['dir_existed'])) {
            $left = array_diff((array) scandir($dir), ['.', '..']);
            if ([] === $left) {
                $fs->rmdir($dir);
            } else {
                self::warn(sprintf('Child theme "%s": the scaffold files were removed, but the directory was kept because it holds files the scaffold did not create.', $slug));
            }
        }

        // WP_Theme caches parsed headers per directory, and
        // wp_clean_themes_cache() only flushes themes it still finds on disk,
        // so the removed child's own entry is dropped explicitly first;
        // otherwise wp_get_theme() keeps reporting it as installed.
        wp_get_theme($slug)->cache_delete();
        wp_clean_themes_cache();
    }

    /**
     * Undo install-package-from-zip (see Snapshot::capture_package_install()).
     *
     * A fresh install is removed. A replaced package is put back from the
     * archive File_Backup::backup_directory() took of its prior directory,
     * and only when that archive still exists: without it the current
     * version is left alone with a warning, because deleting it would leave
     * less than either version.
     *
     * A fresh install that is now active (the plugin, or the theme or its
     * parent) is left in place with a warning, like a child-theme scaffold:
     * deleting live code is the opposite of what an undo is for. Deactivate
     * or switch first (both undoable), then roll back again.
     */
    private static function apply_package_install_snapshot(array $snapshot): void
    {
        $data = (array) ($snapshot['data'] ?? []);
        $type = (string) ($data['type'] ?? '');
        $slug = (string) ($data['slug'] ?? '');

        if (! in_array($type, ['plugin', 'theme'], true) || 1 !== preg_match(Snapshot::PACKAGE_SLUG_PATTERN, $slug)) {
            self::warn('Package install cannot be rolled back: the snapshot carries no valid package.');
            return;
        }

        $label = sprintf('%s "%s"', 'theme' === $type ? 'Theme' : 'Plugin', $slug);
        $dir   = trailingslashit('theme' === $type ? get_theme_root() : WP_PLUGIN_DIR) . $slug;
        if (is_link($dir)) {
            self::warn(sprintf('%s was not rolled back: its directory is now a symlink.', $label));
            return;
        }

        $fs = self::direct_filesystem();

        if (empty($data['existed'])) {
            if (! is_dir($dir)) {
                return;
            }
            if (self::package_is_active($type, $slug)) {
                self::warn(sprintf('%s is active, so it was left in place. Deactivate it (or switch themes), then roll back again.', $label));
                return;
            }
            if (! $fs->delete($dir, true)) {
                self::warn(sprintf('%s could not be removed.', $label));
            }
            self::clean_package_caches($type, $slug);
            return;
        }

        if (! empty($data['was_empty'])) {
            if (is_dir($dir)) {
                $fs->delete($dir, true);
            }
            wp_mkdir_p($dir);
            self::clean_package_caches($type, $slug);
            return;
        }

        $backup = (string) ($data['backup_operation_id'] ?? '');
        if ('' === $backup || ! File_Backup::has_directory_backup($backup)) {
            self::warn(sprintf('%s was not rolled back: the backup of its previous version is no longer available.', $label));
            return;
        }

        if (is_dir($dir) && ! $fs->delete($dir, true)) {
            self::warn(sprintf('%s could not be removed, so its previous version was not restored.', $label));
            return;
        }
        if (! File_Backup::restore_directory($backup, $dir)) {
            self::warn(sprintf('%s: its previous version could not be fully restored.', $label));
        }
        self::clean_package_caches($type, $slug);
    }

    /** Whether the plugin in $slug (any file of it) or the theme $slug is live. */
    private static function package_is_active(string $type, string $slug): bool
    {
        if ('theme' === $type) {
            return get_stylesheet() === $slug || get_template() === $slug;
        }

        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        wp_clean_plugins_cache(false);
        foreach (array_keys(get_plugins('/' . $slug)) as $file) {
            if (is_plugin_active($slug . '/' . $file)) {
                return true;
            }
        }
        return false;
    }

    private static function clean_package_caches(string $type, string $slug): void
    {
        if ('theme' === $type) {
            wp_get_theme($slug)->cache_delete();
            wp_clean_themes_cache();
            return;
        }
        if (! function_exists('wp_clean_plugins_cache')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        wp_clean_plugins_cache(false);
    }

    /**
     * A direct-method WP_Filesystem for the theme scaffold restore. Plugin
     * Check promotes WordPress.WP.AlternativeFunctions to an error, so file
     * writes and deletes go through WP_Filesystem; the direct transport is
     * used explicitly because a rollback cannot stop to prompt for FTP
     * credentials, and the scaffold being undone was written the same way.
     */
    private static function direct_filesystem(): \WP_Filesystem_Direct
    {
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        return new \WP_Filesystem_Direct(null);
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
        $result  = wp_insert_post(wp_slash($postarr), true);

        if (is_wp_error($result)) {
            throw new Mutation_Failed('Rollback failed to resurrect post ' . (int) $object_id . ': ' . esc_html($result->get_error_message()));
        }

        $new_id = (int) $result;
        if ($new_id !== $object_id) {
            throw new Mutation_Failed(
                'Rollback could not resurrect post ' . (int) $object_id . ' at its original ID '
                . '(import_id collision; WordPress inserted it as post ' . (int) $new_id . ' instead). '
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
