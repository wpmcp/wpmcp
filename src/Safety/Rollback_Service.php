<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches brief's public interface).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches brief's public interface).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

class Rollback_Service
{
    public static function restore_operation(string $operation_id): bool
    {
        $row = Snapshot_Store::get_by_operation($operation_id);
        if (! $row) {
            return false;
        }
        self::apply_snapshot($row['snapshot']);
        return true;
    }

    public static function restore_session(string $session_id): int
    {
        $rows = Snapshot_Store::list_by_session($session_id); // newest first
        $rows = array_reverse($rows); // oldest first, so we can unwind to the earliest

        // Restore the OLDEST snapshot per object (its pre-session state).
        $seen  = [];
        $count = 0;
        foreach ($rows as $r) {
            $key = $r['object_type'] . ':' . $r['object_id'];
            if (isset($seen[ $key ])) {
                $count++;
                continue;
            }
            $seen[ $key ] = true;
            self::apply_snapshot(Snapshot::unserialize($r['before_blob']));
            $count++;
        }
        return $count;
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
     */
    public static function apply_snapshot(array $snapshot): void
    {
        if ('option' === $snapshot['object_type']) {
            self::apply_option_snapshot($snapshot);
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
