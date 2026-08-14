<?php

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

class Snapshot
{
    /**
     * Capture the pre-mutation state of an object so it can later be restored
     * by Rollback_Service::apply_snapshot(). The object identifier's type
     * depends on $object_type: posts (and attachments, which are posts) are
     * identified by their integer ID; options are identified by their string
     * name. Dispatching here on $object_type, rather than on the PHP type of
     * $object_id, keeps the door open for future object types (e.g. users)
     * that might also use an int identifier but need different capture logic.
     */
    public static function capture(string $object_type, $object_id): array
    {
        if ('option' === $object_type) {
            return self::capture_option((string) $object_id);
        }
        if ('user' === $object_type) {
            return self::capture_user((int) $object_id);
        }
        if ('comment' === $object_type) {
            return self::capture_comment((int) $object_id);
        }
        if ('wc_order' === $object_type) {
            return self::capture_wc_order((int) $object_id);
        }
        if ('db_rows' === $object_type) {
            return self::capture_db_rows((string) $object_id);
        }
        if ('redirect' === $object_type) {
            return self::capture_redirect((string) $object_id);
        }
        if ('term' === $object_type) {
            return self::capture_term((string) $object_id);
        }
        return self::capture_post($object_id);
    }

    /**
     * Capture a taxonomy term, keyed by "taxonomy:slug" rather than by
     * term_id, for exactly the reason capture_redirect() is keyed by source
     * path: the slug is the term's natural key within its taxonomy (WordPress
     * enforces it as unique there), and it is known BEFORE the write, while
     * the auto-increment term_id is not.
     *
     * That is what lets create-term run through Safe_Mutation like every
     * other write instead of recording an after-the-fact "I made this" row:
     * the capture is simply "no term owned this slug yet", and the undo is to
     * delete whatever now does.
     *
     * The full row is captured, term_id included, so restoring a deleted term
     * resurrects the same id rather than a copy. term_taxonomy_id, parent,
     * description and count come along because wp_insert_term() cannot
     * reconstruct them from the name alone, and a category that comes back
     * without its parent has silently moved in the site's hierarchy.
     */
    /**
     * Cap on how many object assignments a term snapshot carries. See
     * capture_term(); exceeding it is recorded, never silently dropped.
     */
    public const MAX_TERM_OBJECTS = 5000;

    private static function capture_term(string $key): array
    {
        [$taxonomy, $slug] = self::split_term_key($key);

        $term = ('' !== $taxonomy && '' !== $slug)
            ? get_term_by('slug', $slug, $taxonomy, ARRAY_A)
            : false;

        $meta      = [];
        $objects   = [];
        $truncated = false;

        if (is_array($term) && isset($term['term_id'])) {
            $term_id = (int) $term['term_id'];

            $stored = get_term_meta($term_id);
            $meta   = is_array($stored) ? $stored : [];

            // The object relationships have to come along. wp_delete_term()
            // deletes the wp_term_relationships rows as well as the term, so
            // restoring the term and term_taxonomy rows alone resurrects an
            // EMPTY term: it looks correct in wp-admin while every post that
            // was filed under it has silently lost the assignment.
            $objects = get_objects_in_term([$term_id], [$taxonomy]);
            $objects = is_wp_error($objects) ? [] : array_map('intval', (array) $objects);

            if (count($objects) > self::MAX_TERM_OBJECTS) {
                // A term on a large site can hold tens of thousands of posts,
                // and a snapshot blob that big is its own problem. The cap is
                // recorded rather than hidden so the restore can say plainly
                // that it reattached a subset.
                $objects   = array_slice($objects, 0, self::MAX_TERM_OBJECTS);
                $truncated = true;
            }
        }

        return [
            'object_type' => 'term',
            'object_id'   => $key,
            'data'        => [
                'taxonomy'         => $taxonomy,
                'slug'             => $slug,
                'existed'          => is_array($term),
                'term'             => is_array($term) ? $term : null,
                'meta'             => $meta,
                'objects'          => $objects,
                'objects_truncated' => $truncated,
            ],
        ];
    }

    /**
     * Split a "taxonomy:slug" snapshot key. Only the FIRST colon separates
     * the two: a taxonomy name cannot contain a colon (sanitize_key strips
     * it) but a slug can arrive with one before sanitisation, so splitting on
     * the last colon would mis-key those terms.
     *
     * @return array{0: string, 1: string}
     */
    public static function split_term_key(string $key): array
    {
        $at = strpos($key, ':');
        if (false === $at) {
            return ['', ''];
        }

        return [substr($key, 0, $at), substr($key, $at + 1)];
    }

    /** Build the snapshot key for a term. */
    public static function term_key(string $taxonomy, string $slug): string
    {
        return $taxonomy . ':' . $slug;
    }

    /**
     * Capture a managed redirect (issue #128), keyed by its SOURCE PATH
     * rather than its row id, exactly like an option is keyed by its name.
     *
     * The source path is the redirect's natural key (it is the UNIQUE column
     * the front-end handler looks requests up by) and, unlike the
     * auto-increment id, it is known BEFORE the write. That is what lets
     * create-redirect run through Safe_Mutation like every other write
     * instead of recording an after-the-fact "I created this" row: the
     * capture is simply "no redirect owned this path yet", and the undo is to
     * delete whatever now does.
     *
     * The whole row is captured, id included, so restoring a deleted
     * redirect resurrects the same row rather than a copy with a new id.
     */
    private static function capture_redirect(string $source_path): array
    {
        $source = \WPMCP\Tools\Redirects\Redirect_Store::normalize_path($source_path);
        $row    = \WPMCP\Tools\Redirects\Redirect_Store::find_by_source($source);

        return [
            'object_type' => 'redirect',
            'object_id'   => $source,
            'data'        => [
                'source_path' => $source,
                'existed'     => null !== $row,
                'row'         => $row,
            ],
        ];
    }

    /**
     * Skeleton snapshot for a generic single-table row write (update-rows /
     * delete-rows, issue #82). Unlike every other object type, the state to
     * capture cannot be derived from ($object_type, $object_id) alone: the
     * rows are selected by the tool's WHERE clause, and the tool has already
     * fetched them (before-image) to decide recoverability. The tool
     * therefore supplies the real payload via Safe_Mutation's additive
     * 'extra_snapshot_data' seam, which merges into 'data' here:
     *   table       string  validated real table name
     *   operation   string  'update' | 'delete'
     *   primary_key array   PK column names, in index order (never empty)
     *   where       array   the tool call's equality WHERE (context)
     *   set         array   update only: the applied column => value map,
     *                       used for conflict detection at rollback time
     *   rows        array   full before-image rows (SELECT *, capped)
     * $object_id is the table name (a string), so Snapshot_Store persists 0
     * in its BIGINT object_id column, exactly like 'option' snapshots.
     */
    private static function capture_db_rows(string $table): array
    {
        return [
            'object_type' => 'db_rows',
            'object_id'   => $table,
            'data'        => [],
        ];
    }

    /**
     * Capture a WooCommerce order's prior status so update-order-status can be
     * undone. Only the status is captured, deliberately: an order can live in
     * HPOS custom tables or the legacy CPT, and a full generic row-restore
     * across both stores would be unsafe to promise. update-order-status only
     * ever changes the status, so restoring exactly that (via WC_Order's CRUD
     * setter, which writes correctly to whichever store is active) is an
     * honest, complete undo of what the tool did. If the order no longer
     * exists at capture time the status is null, matching how the post/comment
     * paths record a missing object.
     */
    private static function capture_wc_order(int $order_id): array
    {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        return [
            'object_type' => 'wc_order',
            'object_id'   => $order_id,
            'data'        => [
                'status' => $order ? $order->get_status() : null,
            ],
        ];
    }

    /**
     * Capture a comment's full row (plus its commentmeta) so moderate/edit
     * writes can be undone and a force-deleted comment can be resurrected.
     *
     * The full get_comment(ARRAY_A) row is kept rather than a hand-picked
     * subset (mirroring the post path's stance): a partial capture would let
     * a resurrection rebuild the missing columns from wp_insert_comment()'s
     * defaults (comment_date, comment_author_IP, comment_agent, comment_type,
     * comment_parent all lost). If the comment no longer exists at capture
     * time the row is null, matching how capture_post() records a missing post.
     */
    private static function capture_comment(int $comment_id): array
    {
        $comment = get_comment($comment_id, ARRAY_A);
        return [
            'object_type' => 'comment',
            'object_id'   => $comment_id,
            'data'        => [
                'comment' => $comment ?: null,
                'meta'    => get_comment_meta($comment_id),
            ],
        ];
    }

    /**
     * Capture a user's editable profile so Update_User's write can be undone.
     *
     * Only the columns wp_update_user() can restore are kept, plus the full
     * usermeta map (mirroring the post path's "full row, not a hand-picked
     * subset" stance so a rollback is exact even for meta the mutation added).
     * The password hash (user_pass) is deliberately NEVER captured: there is
     * no update-password tool, so a restore never needs it, and keeping it out
     * of the snapshot blob means the stored secret can never leak. There is
     * also no delete-user tool, so unlike posts there is no force-delete /
     * resurrection case to plan for here: the user always still exists at
     * rollback time and is restored in place.
     */
    private static function capture_user(int $user_id): array
    {
        $user = get_userdata($user_id);
        return [
            'object_type' => 'user',
            'object_id'   => $user_id,
            'data'        => [
                'fields' => $user ? [
                    'display_name' => $user->display_name,
                    'user_email'   => $user->user_email,
                    'user_url'     => $user->user_url,
                    'nickname'     => $user->nickname,
                    'first_name'   => $user->first_name,
                    'last_name'    => $user->last_name,
                    'description'  => $user->description,
                ] : null,
                'meta'   => $user ? get_user_meta($user_id) : [],
            ],
        ];
    }

    private static function capture_post(int $object_id): array
    {
        $post = get_post($object_id, ARRAY_A);
        return [
            'object_type' => 'post',
            'object_id'   => $object_id,
            'data'        => [
                // Full row (all columns), not a hand-picked subset: a partial
                // capture means resurrection after a force-delete rebuilds
                // the missing columns from wp_insert_post()'s defaults
                // (post_type becomes 'post', post_author/post_parent/post_name/
                // dates/menu_order/etc are lost). See apply_snapshot() for how
                // the in-place vs resurrection restore paths use this.
                'post'     => $post ?: null,
                'meta'     => get_post_meta($object_id),
                'terms'    => $post ? self::capture_terms($object_id, $post['post_type']) : [],
                // Only needed for the force-delete -> resurrect path: wp_delete_post($id, true)
                // destroys comments + commentmeta, which have no equivalent in
                // the trash/in-place-update paths.
                'comments' => $post ? self::capture_comments($object_id) : [],
            ],
        ];
    }

    /**
     * Options have no equivalent of trash/force-delete: a write either
     * changes an existing option's value or, if it didn't exist yet, an
     * update introduces one. 'existed' records which case this was so
     * Rollback_Service can decide between update_option() (put the old
     * value back) and delete_option() (remove the option entirely, since it
     * wasn't there before the mutation).
     */
    private static function capture_option(string $name): array
    {
        return [
            'object_type' => 'option',
            'object_id'   => $name,
            'data'        => [
                'name'    => $name,
                'value'   => get_option($name),
                'existed' => self::option_exists($name),
            ],
        ];
    }

    /** True if $name has a row in the options table, distinguishing "unset" from a falsy stored value. */
    private static function option_exists(string $name): bool
    {
        $sentinel = '__wpmcp_missing__' . $name;
        return get_option($name, $sentinel) !== $sentinel;
    }

    /** Comments (with their commentmeta) attached to the post, for resurrection after a force-delete. */
    private static function capture_comments(int $post_id): array
    {
        $comments = get_comments(['post_id' => $post_id, 'status' => 'all', 'orderby' => 'comment_ID', 'order' => 'ASC']);
        $out = [];
        foreach ($comments as $comment) {
            $data = $comment->to_array();
            $data['meta'] = get_comment_meta((int) $comment->comment_ID);
            $out[] = $data;
        }
        return $out;
    }

    /** Map of taxonomy => term IDs currently assigned to the post, for terms rollback. */
    private static function capture_terms(int $post_id, string $post_type): array
    {
        $terms = [];
        foreach ((array) get_object_taxonomies($post_type) as $taxonomy) {
            $ids = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
            if (is_array($ids)) {
                $terms[ $taxonomy ] = $ids;
            }
        }
        return $terms;
    }

    public static function serialize(array $before): string
    {
        return gzencode(wp_json_encode($before));
    }

    public static function unserialize(string $blob): array
    {
        return json_decode(gzdecode($blob), true);
    }
}
