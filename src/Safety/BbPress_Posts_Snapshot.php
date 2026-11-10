<?php

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Snapshots of everything one bbPress write can change (the BbPress_Pack ops
 * on the plugin-data pair), as a 'bbpress_posts' image.
 *
 * bbPress keeps a forum's state spread over many rows: forums, topics and
 * replies are posts, their relationships and running counts are post meta
 * on every forum up the tree (_bbp_topic_count, _bbp_total_reply_count,
 * _bbp_last_active_time, the forum's _bbp_sticky_topics list), each user's
 * topic and reply counts are user options, super stickies are an option and
 * topic tags are terms. A moderation call moves several of those at once: a
 * spammed topic trashes its public replies, empties its tags, drops off the
 * sticky lists and changes the counts of every forum above it. So the image
 * covers a scope, not one row, keyed "<kind>:<id>":
 *  - topic:<id>  the topic, every reply in it, its forum and that forum's
 *                ancestors, the authors of the topic and its replies, the
 *                topic's tags and the super sticky option (moderate-topic,
 *                and create-reply, whose snapshot is re-keyed
 *                reply_created:<new id> once the reply exists);
 *  - forum:<id>  the forum and its ancestors, the current user and the
 *                super sticky option (create-topic, re-keyed
 *                topic_created:<new id>).
 * Each post is captured as its whole posts row and every meta row it has;
 * each user as every _bbp_ user meta row.
 *
 * After the write, record_after() adds what it created (the new topic or
 * reply, and any post a hook added under a covered post, such as a revision)
 * and an after-image of the same scope. A rollback then deletes what was
 * created, through wp_delete_post() so bbPress clears its own references,
 * and writes back every value the write changed, directly, so no status
 * transition runs (no notification goes out on undo and no count is bumped
 * twice). A value changed again since the write is left as it is, with a
 * warning, except a running count, which is moved back by what the write
 * added to it, so later posts stay counted. Without an after-image (a
 * write that died half way) the before-image is written back as it is.
 *
 * Undoing a create is refused while the new topic has replies, or the new
 * reply has threaded replies, that the create did not make: deleting it
 * would take them with it.
 */
final class BbPress_Posts_Snapshot
{
    public const TYPE = 'bbpress_posts';

    /** bbPress's post types and topic tag taxonomy, as bbPress 2.6 names them. */
    public const FORUM = 'forum';
    public const TOPIC = 'topic';
    public const REPLY = 'reply';
    public const TAG   = 'topic-tag';

    public const SUPER_STICKY_OPTION = '_bbp_super_sticky_topics';

    /** Meta keys holding a running count: moved back by the write's delta when changed since. */
    private const COUNTS = [
        '_bbp_topic_count',
        '_bbp_reply_count',
        '_bbp_total_topic_count',
        '_bbp_total_reply_count',
        '_bbp_topic_count_hidden',
        '_bbp_reply_count_hidden',
        '_bbp_total_topic_count_hidden',
        '_bbp_total_reply_count_hidden',
        '_bbp_forum_subforum_count',
        '_bbp_voice_count',
    ];

    public static function key(string $kind, int $id): string
    {
        return $kind . ':' . $id;
    }

    /** @return array{kind: string, id: int} */
    public static function parse(string $key): array
    {
        $parts = explode(':', $key, 2);
        return [ 'kind' => (string) $parts[0], 'id' => isset($parts[1]) ? (int) $parts[1] : 0 ];
    }

    public static function post_type(string $which): string
    {
        $fn = 'bbp_get_' . $which . '_post_type';
        return function_exists($fn) ? (string) $fn() : $which;
    }

    public static function tag_taxonomy(): string
    {
        return function_exists('bbp_get_topic_tag_tax_id') ? (string) bbp_get_topic_tag_tax_id() : self::TAG;
    }

    /** The forum a topic belongs to: its _bbp_forum_id, else its parent. */
    public static function forum_of(int $topic_id): int
    {
        $meta = (int) get_post_meta($topic_id, '_bbp_forum_id', true);
        return $meta > 0 ? $meta : (int) get_post_field('post_parent', $topic_id);
    }

    // ---------------------------------------------------------- capture

    public static function capture(string $key): array
    {
        global $wpdb;
        $scope = self::parse($key);
        $ids   = self::scope_ids($scope['kind'], $scope['id']);

        return [
            'object_type' => self::TYPE,
            'object_id'   => $key,
            'data'        => [
                'scope'     => $scope,
                'ids'       => $ids,
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the highest post id before the write, so record_after() can tell which posts it added.
                'watermark' => (int) $wpdb->get_var("SELECT COALESCE(MAX(ID), 0) FROM {$wpdb->posts}"),
                'before'    => self::image($ids),
                'after'     => null,
                'created'   => [],
            ],
        ];
    }

    /**
     * The posts, users and tagged topics a scope covers.
     *
     * @return array{posts: int[], users: int[], tagged: int[], options: string[]}
     */
    private static function scope_ids(string $kind, int $id): array
    {
        global $wpdb;
        $posts  = [];
        $tagged = [];
        $forum  = 0;
        if ('topic' === $kind && $id > 0) {
            $posts[] = $id;
            $tagged  = [ $id ];
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- every reply in the topic, whatever its status, read fresh for the before-image.
            $replies = (array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s ORDER BY ID ASC", $id, self::post_type('reply')));
            $posts   = array_merge($posts, array_map('intval', $replies));
            $forum   = self::forum_of($id);
        } elseif ('forum' === $kind) {
            $forum = $id;
        }
        $seen = [];
        while ($forum > 0 && ! isset($seen[ $forum ]) && self::post_type('forum') === get_post_type($forum)) {
            $seen[ $forum ] = true;
            $posts[]        = $forum;
            $forum          = (int) get_post_field('post_parent', $forum);
        }

        $users = [];
        foreach ($posts as $post_id) {
            if (self::post_type('forum') !== get_post_type($post_id)) {
                $users[] = (int) get_post_field('post_author', $post_id);
            }
        }
        $users[] = get_current_user_id();
        $users   = array_values(array_unique(array_filter(array_map('intval', $users))));
        sort($users);

        return [
            'posts'   => array_values(array_unique($posts)),
            'users'   => $users,
            'tagged'  => $tagged,
            'options' => [ self::SUPER_STICKY_OPTION ],
        ];
    }

    /**
     * Every value a scope covers, flat: path => value, null when absent.
     * Rows and options are strings, meta and tags lists.
     *
     * @param array<string, array> $ids
     * @return array<string, mixed>
     */
    private static function image(array $ids): array
    {
        global $wpdb;
        $out = [];
        foreach ((array) ($ids['posts'] ?? []) as $post_id) {
            $post_id = (int) $post_id;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the raw row, so the restore writes back exactly what was stored.
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d", $post_id), ARRAY_A);
            if (! is_array($row)) {
                $out[ "post:{$post_id}" ] = null;
                continue;
            }
            $out[ "post:{$post_id}" ] = 'exists';
            unset($row['ID']);
            foreach ($row as $column => $value) {
                $out[ "row:{$post_id}:{$column}" ] = null === $value ? null : (string) $value;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- raw meta rows in meta_id order, multi-valued keys (engagements, favorites) included.
            $meta = (array) $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC", $post_id), ARRAY_A);
            foreach ($meta as $m) {
                $out[ "meta:{$post_id}:{$m['meta_key']}" ][] = [ (int) $m['meta_id'], (string) $m['meta_value'] ];
            }
        }
        foreach ((array) ($ids['users'] ?? []) as $user_id) {
            $user_id = (int) $user_id;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bbPress's per-user counts are user options under this site's prefix.
            $meta = (array) $wpdb->get_results($wpdb->prepare("SELECT umeta_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND (meta_key LIKE %s OR meta_key LIKE %s) ORDER BY umeta_id ASC", $user_id, $wpdb->esc_like('_bbp_') . '%', $wpdb->esc_like($wpdb->get_blog_prefix() . '_bbp_') . '%'), ARRAY_A);
            $out[ "user:{$user_id}" ] = 'exists';
            foreach ($meta as $m) {
                $out[ "umeta:{$user_id}:{$m['meta_key']}" ][] = [ (int) $m['umeta_id'], (string) $m['meta_value'] ];
            }
        }
        foreach ((array) ($ids['tagged'] ?? []) as $topic_id) {
            $topic_id = (int) $topic_id;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read without the taxonomy registered too.
            $terms = (array) $wpdb->get_col($wpdb->prepare("SELECT tt.term_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s ORDER BY tt.term_id ASC", $topic_id, self::tag_taxonomy()));
            $out[ "tags:{$topic_id}" ] = array_map('strval', $terms);
        }
        foreach ((array) ($ids['options'] ?? []) as $name) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the raw stored value, compared byte for byte.
            $value                  = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", (string) $name));
            $out[ "option:{$name}" ] = null === $value ? null : (string) $value;
        }
        return $out;
    }

    // ---------------------------------------------------------- after the write

    /**
     * Add what the write created and the after-image to its snapshot, and
     * re-key it on the created post when $new_key is given, so the ledger
     * lists a create under its new id. If the snapshot cannot be completed
     * the write is undone right here and the error rethrown: a create is
     * never left behind without its undo point.
     *
     * @param int[] $created
     */
    public static function record_after(string $operation_id, array $created = [], string $new_key = ''): void
    {
        $row = '' === $operation_id ? null : Snapshot_Store::get_by_operation($operation_id);
        if (null === $row || self::TYPE !== ($row['snapshot']['object_type'] ?? '')) {
            return;
        }
        $snapshot = (array) $row['snapshot'];
        $data     = (array) $snapshot['data'];

        $data['created'] = self::created_since((int) ($data['watermark'] ?? 0), array_map('intval', $created), (array) ($data['ids']['posts'] ?? []));
        $data['after']   = self::image((array) $data['ids']);
        $snapshot['data'] = $data;
        if ('' !== $new_key) {
            $snapshot['object_id'] = $new_key;
        }

        try {
            Snapshot_Store::update_snapshot($operation_id, $snapshot);
        } catch (\Throwable $e) {
            self::restore($snapshot);
            Snapshot_Store::delete_operation($operation_id);
            throw $e;
        }
    }

    /** Whether the write changed nothing the snapshot covers and created nothing. */
    public static function unchanged(string $operation_id): bool
    {
        $row  = Snapshot_Store::get_by_operation($operation_id);
        $data = (array) ($row['snapshot']['data'] ?? []);
        return null !== $row && [] === (array) ($data['created'] ?? []) && ($data['before'] ?? null) === ($data['after'] ?? null);
    }

    /**
     * The posts the write created: the ones it reports, and any post above
     * the watermark parented under one of those or under a covered post (a
     * revision a hook saved), oldest first.
     *
     * @param int[] $reported
     * @param int[] $covered
     * @return array<int, array{id: int, post_type: string, post_date_gmt: string}>
     */
    private static function created_since(int $watermark, array $reported, array $covered): array
    {
        global $wpdb;
        $ids = array_values(array_filter($reported, static fn (int $id): bool => $id > $watermark));
        for ($round = 0; $round < 2; $round++) {
            $parents = array_values(array_unique(array_merge($ids, array_map('intval', $covered))));
            if ([] === $parents) {
                break;
            }
            $in = implode(',', array_fill(0, count($parents), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of %d placeholders, one per parent.
            $found = (array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_parent IN ({$in})", $watermark, ...$parents));
            $ids   = array_values(array_unique(array_merge($ids, array_map('intval', $found))));
        }
        sort($ids);

        $out = [];
        foreach ($ids as $id) {
            $post = get_post($id);
            if ($post instanceof \WP_Post) {
                $out[] = [ 'id' => $id, 'post_type' => (string) $post->post_type, 'post_date_gmt' => (string) $post->post_date_gmt ];
            }
        }
        return $out;
    }

    // ---------------------------------------------------------- rollback

    /**
     * Why undoing this snapshot is refused, or null: a created topic that
     * has replies the create did not make, or a created reply that has
     * threaded replies, would take them with it.
     */
    public static function refusal(array $snapshot): ?string
    {
        global $wpdb;
        if (self::TYPE !== ($snapshot['object_type'] ?? '')) {
            return null;
        }
        $created = (array) ($snapshot['data']['created'] ?? []);
        $own     = array_map('intval', array_column($created, 'id'));
        foreach ($created as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            if (! self::is_same_post($entry)) {
                continue;
            }
            if (self::post_type('topic') === $entry['post_type']) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the replies in the created topic now.
                $children = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s", $id, self::post_type('reply'))));
                if ([] !== array_diff($children, $own)) {
                    return sprintf('topic %d, which this operation created, has replies posted since; undoing it would delete them, so it was left in place.', $id);
                }
            }
            if (self::post_type('reply') === $entry['post_type']) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- replies threaded under the created reply now.
                $children = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bbp_reply_to' AND meta_value = %s", (string) $id)));
                if ([] !== array_diff($children, $own)) {
                    return sprintf('reply %d, which this operation created, has replies threaded under it since; undoing it would orphan them, so it was left in place.', $id);
                }
            }
        }
        return null;
    }

    /** Whether a created entry still names the post the write created (the id was not reclaimed). */
    private static function is_same_post(array $entry): bool
    {
        $post = get_post((int) ($entry['id'] ?? 0));
        return $post instanceof \WP_Post
            && (string) $post->post_type === (string) ($entry['post_type'] ?? '')
            && (string) $post->post_date_gmt === (string) ($entry['post_date_gmt'] ?? '');
    }

    /** Put a 'bbpress_posts' snapshot back. Returns a warning, or null. */
    public static function restore(array $snapshot): ?string
    {
        $data   = (array) ($snapshot['data'] ?? []);
        $before = (array) ($data['before'] ?? []);
        $after  = is_array($data['after'] ?? null) ? (array) $data['after'] : null;
        $ids    = (array) ($data['ids'] ?? []);
        $warn   = [];

        // Decide every value from the state the write left, before deleting
        // anything: bbPress's delete hooks move counts again.
        $current = self::image($ids);
        $plan    = [];
        $gone    = [];
        foreach ((array) ($ids['posts'] ?? []) as $post_id) {
            if (null !== ($before[ "post:{$post_id}" ] ?? null) && null === ($current[ "post:{$post_id}" ] ?? null)) {
                $gone[ (int) $post_id ] = true;
                $warn[]                 = sprintf('Post %d was deleted after this operation, so it could not be put back.', (int) $post_id);
            }
        }
        foreach (array_unique(array_merge(array_keys($before), array_keys($current), array_keys((array) $after))) as $path) {
            $path  = (string) $path;
            $parts = explode(':', $path, 3);
            if (in_array($parts[0], [ 'post', 'user' ], true) || (in_array($parts[0], [ 'row', 'meta', 'tags' ], true) && isset($gone[ (int) $parts[1] ]))) {
                continue;
            }
            $b = $before[ $path ] ?? null;
            $c = $current[ $path ] ?? null;
            $a = null === $after ? $b : ($after[ $path ] ?? null);
            if (null === $after || $c === $a || $c === $b) {
                $plan[ $path ] = $b;
                continue;
            }
            $delta = self::count_delta($path, $b, $a, $c);
            if (null !== $delta) {
                $plan[ $path ] = $delta;
                continue;
            }
            $plan[ $path ] = $c;
            $warn[]        = sprintf('%s changed again after this operation and was left as it is now.', self::describe($path));
        }

        foreach (array_reverse((array) ($data['created'] ?? [])) as $entry) {
            if (self::is_same_post((array) $entry)) {
                wp_delete_post((int) $entry['id'], true);
                if (null !== get_post((int) $entry['id'])) {
                    $warn[] = sprintf('Post %d created by this operation could not be deleted.', (int) $entry['id']);
                }
            }
        }

        // What the delete hooks added on their own goes too.
        $now = self::image($ids);
        foreach (array_keys($now) as $path) {
            $parts = explode(':', (string) $path, 3);
            if (! array_key_exists($path, $plan) && ! in_array($parts[0], [ 'post', 'user' ], true) && ! isset($gone[ (int) ($parts[1] ?? 0) ])) {
                $plan[ $path ] = $before[ $path ] ?? null;
            }
        }
        $touched = [ 'posts' => [], 'users' => [] ];
        foreach ($plan as $path => $value) {
            if (($now[ $path ] ?? null) === $value) {
                continue;
            }
            $problem = self::write_path($path, $value, $touched);
            if (null !== $problem) {
                $warn[] = $problem;
            }
        }
        foreach (array_keys($touched['posts']) as $post_id) {
            wp_cache_delete((int) $post_id, 'post_meta');
            clean_post_cache((int) $post_id);
        }
        foreach (array_keys($touched['users']) as $user_id) {
            wp_cache_delete((int) $user_id, 'user_meta');
            clean_user_cache((int) $user_id);
        }

        return [] === $warn ? null : implode(' ', array_unique($warn));
    }

    /**
     * A meta row to insert, at its own id while no other row holds it.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function row_at(string $column, int $id, string $table, array $row): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- whether the original id is free.
        if ($id > 0 && null === $wpdb->get_var($wpdb->prepare('SELECT %i FROM %i WHERE %i = %d', $column, $table, $column, $id))) {
            $row = [ $column => $id ] + $row;
        }
        return $row;
    }

    /** A count moved back by what the write added to it, or null when $path is not a count. */
    private static function count_delta(string $path, $before, $after, $current): ?array
    {
        $parts = explode(':', $path, 3);
        if (! in_array($parts[0], [ 'meta', 'umeta' ], true)) {
            return null;
        }
        $key   = (string) ($parts[2] ?? '');
        $count = in_array($key, self::COUNTS, true);
        foreach ([ '_bbp_topic_count', '_bbp_reply_count' ] as $user_count) {
            $count = $count || ('umeta' === $parts[0] && str_ends_with($key, $user_count));
        }
        $single = static fn ($v): ?int => is_array($v) && 1 === count($v) && is_numeric($v[0][1] ?? null) ? (int) $v[0][1] : null;
        $b      = null === $before ? 0 : $single($before);
        $a      = null === $after ? 0 : $single($after);
        $c      = null === $current ? 0 : $single($current);
        if (! $count || null === $b || null === $a || null === $c) {
            return null;
        }
        // Kept on the row it is in now (or was in), so no id moves.
        $id = (int) ($current[0][0] ?? $before[0][0] ?? 0);
        return [ [ $id, (string) max(0, $c + ($b - $a)) ] ];
    }

    private static function describe(string $path): string
    {
        $parts = explode(':', $path, 3);
        switch ($parts[0]) {
            case 'row':
                return sprintf('Post %d\'s %s', (int) $parts[1], $parts[2] ?? '');
            case 'meta':
                return sprintf('Post %d\'s %s meta', (int) $parts[1], $parts[2] ?? '');
            case 'umeta':
                return sprintf('User %d\'s %s', (int) $parts[1], $parts[2] ?? '');
            case 'tags':
                return sprintf('Topic %d\'s tags', (int) $parts[1]);
            default:
                return sprintf('The %s option', $parts[1] ?? '');
        }
    }

    /**
     * Write one value back. Returns a warning, or null.
     *
     * @param mixed $value
     * @param array{posts: array<int, true>, users: array<int, true>} $touched
     */
    private static function write_path(string $path, $value, array &$touched): ?string
    {
        global $wpdb;
        $parts = explode(':', $path, 3);
        $id    = (int) ($parts[1] ?? 0);
        $key   = (string) ($parts[2] ?? '');

        switch ($parts[0]) {
            case 'row':
                if (null === get_post($id)) {
                    return sprintf('Post %d no longer exists, so it could not be put back.', $id);
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- written without a status transition (no notification, no count bump on undo); the post cache is cleaned after.
                $wpdb->update($wpdb->posts, [ $key => $value ], [ 'ID' => $id ]);
                $touched['posts'][ $id ] = true;
                return null;
            case 'meta':
                if (null === get_post($id)) {
                    return sprintf('Post %d no longer exists, so its meta could not be put back.', $id);
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the raw meta rows go back as stored; the meta cache is cleaned after.
                $wpdb->delete($wpdb->postmeta, [ 'post_id' => $id, 'meta_key' => $key ]);
                foreach ((array) $value as [$meta_id, $v]) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above, at the row's own meta_id.
                    $wpdb->insert($wpdb->postmeta, self::row_at('meta_id', (int) $meta_id, $wpdb->postmeta, [ 'post_id' => $id, 'meta_key' => $key, 'meta_value' => (string) $v ]));
                }
                $touched['posts'][ $id ] = true;
                return null;
            case 'umeta':
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the raw user meta rows go back as stored; the user cache is cleaned after.
                $wpdb->delete($wpdb->usermeta, [ 'user_id' => $id, 'meta_key' => $key ]);
                foreach ((array) $value as [$meta_id, $v]) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above, at the row's own umeta_id.
                    $wpdb->insert($wpdb->usermeta, self::row_at('umeta_id', (int) $meta_id, $wpdb->usermeta, [ 'user_id' => $id, 'meta_key' => $key, 'meta_value' => (string) $v ]));
                }
                $touched['users'][ $id ] = true;
                return null;
            case 'tags':
                if (! taxonomy_exists(self::tag_taxonomy())) {
                    return sprintf('Topic %d\'s tags could not be put back: the topic tag taxonomy is not registered (is bbPress active?).', $id);
                }
                $set = wp_set_object_terms($id, array_map('intval', (array) $value), self::tag_taxonomy());
                return is_wp_error($set) ? sprintf('Topic %d\'s tags could not be put back: %s', $id, $set->get_error_message()) : null;
            default:
                $name = (string) ($parts[1] ?? '');
                if (null === $value) {
                    delete_option($name);
                } else {
                    update_option($name, maybe_unserialize((string) $value));
                }
                return null;
        }
    }
}
