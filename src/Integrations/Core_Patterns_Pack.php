<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Save_Filters;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WordPress core synced patterns, the reusable blocks (issue #711), as free
 * ops on the theme dispatcher pair, so they add no top-level tool.
 *
 * A pattern is a wp_block post. It is synced unless its
 * wp_pattern_sync_status meta is "unsynced"; a synced pattern is placed in a
 * post as a core/block block whose "ref" attribute is the pattern's id, so
 * editing the pattern changes every post that places it.
 *
 * Reads: list-synced-patterns, get-synced-pattern (with its content hash and
 * a usage count) and find-pattern-usage (the posts, templates and patterns
 * that place one, kept to the rows the caller may read).
 *
 * Writes, all off until the site opts in through the
 * wpmcp_enable_core_patterns_write filter, all undoable:
 *  - create-synced-pattern records a creation row (rollback trashes it);
 *  - update-synced-pattern snapshots the pattern post;
 *  - detach-pattern-instance replaces the core/block placements of one
 *    pattern with the pattern's own blocks, in up to MAX_POSTS posts. Every
 *    post it rewrites is snapshotted under one session id, so
 *    rollback-session puts them all back. A post whose content does not
 *    round-trip through the block parser is refused before anything is
 *    written, so no untouched block is rewritten.
 */
final class Core_Patterns_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const ENABLE_FILTER = 'wpmcp_enable_core_patterns_write';

    private const POST_TYPE = 'wp_block';
    private const SYNC_META = 'wp_pattern_sync_status';
    private const MAX_POSTS = 50;

    /** Most candidate posts one usage lookup scans; beyond it the result says truncated. */
    private const USAGE_LIMIT = 500;

    /**
     * The wp_block post type's own primitive capability for $cap
     * ('edit_posts', 'create_posts'), so the pattern ops take the gate core
     * gives patterns, not the theme pair's edit_theme_options (issue #952).
     * Falls back to core's mapping while the post type is not registered.
     */
    public static function capability(string $cap = 'edit_posts'): string
    {
        $type = get_post_type_object(self::POST_TYPE);
        if ($type instanceof \WP_Post_Type && isset($type->cap->{$cap})) {
            return (string) $type->cap->{$cap};
        }
        return 'create_posts' === $cap ? 'publish_posts' : 'edit_posts';
    }

    /**
     * Op name => definition for the theme pair. Every op takes the wp_block
     * capability in place of the pair's edit_theme_options
     * ('own_capability', see Integration_Dispatcher::pair_capability()).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function operations(): array
    {
        // The literal name, not ENABLE_FILTER, so the hook stays greppable.
        $enabled = (bool) apply_filters('wpmcp_enable_core_patterns_write', false);
        $id      = [ 'type' => 'integer', 'minimum' => 1 ];
        $fields  = [
            'title'         => [ 'type' => 'string', 'maxLength' => 200 ],
            'content'       => [ 'type' => 'string' ],
            'synced'        => [ 'type' => 'boolean' ],
            'expected_hash' => [ 'type' => 'string' ],
        ];
        $edit    = self::capability('edit_posts');
        $write   = [
            'mode'               => 'write',
            'capability'         => $edit,
            'own_capability'     => true,
            'enabled_by_default' => $enabled,
            'opt_in_filter'      => self::ENABLE_FILTER,
        ];

        return [
            'list-synced-patterns'   => [
                'mode'           => 'read',
                'capability'     => $edit,
                'own_capability' => true,
                'description'  => 'List synced patterns (reusable blocks): id, title, status, sync state, modified. sync: synced (default), unsynced or all',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'sync'     => [ 'type' => 'string', 'enum' => [ 'synced', 'unsynced', 'all' ] ],
                        'search'   => [ 'type' => 'string' ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                ],
                'handler'      => static fn (array $args): array => self::list_patterns($args),
            ],
            'get-synced-pattern'     => [
                'mode'           => 'read',
                'capability'     => $edit,
                'own_capability' => true,
                'objects'      => [ 'id' => [ 'type' => 'post', 'access' => 'read' ] ],
                'description'  => 'Read one pattern: title, sync state, block content, content_hash (for expected_hash) and how many posts place it',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ],
                'validate'     => static fn (array $args): ?array => self::readable_refusal((int) $args['id']),
                'handler'      => static fn (array $args): array => self::get_pattern((int) $args['id']),
            ],
            'find-pattern-usage'     => [
                'mode'           => 'read',
                'capability'     => $edit,
                'own_capability' => true,
                'objects'      => [ 'id' => [ 'type' => 'post', 'access' => 'read' ] ],
                'description'  => 'Find the posts, templates and patterns that place a synced pattern (those you can read), with the placements in each. total is every placing post, readable the ones listed, widget_placements the block widgets that place it; truncated:true means the scan stopped at 500 candidates and total is a lower bound',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ],
                'validate'     => static fn (array $args): ?array => self::readable_refusal((int) $args['id']),
                'handler'      => static fn (array $args): array => [ 'pattern_id' => (int) $args['id'] ] + self::usage_report((int) $args['id']),
            ],
            'create-synced-pattern'  => [ 'capability' => self::capability('create_posts') ] + $write + [
                'self_snapshotting' => true,
                'description'       => 'Create a pattern (synced unless synced:false) from block markup. Status publish or draft (default publish). Undo: rollback-session trashes it. Disabled by default (wpmcp_enable_core_patterns_write filter)',
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => [ 'status' => [ 'type' => 'string', 'enum' => [ 'publish', 'draft' ] ] ] + array_diff_key($fields, [ 'expected_hash' => 1 ]),
                    'required'   => [ 'title' ],
                ],
                'validate'          => static fn (array $args): ?array => self::create_refusal($args),
                'handler'           => static fn (array $args, array $context): array => self::create($args, $context),
            ],
            'update-synced-pattern'  => $write + [
                'description'  => 'Change a pattern\'s title, content or sync state; only args given change. Every post that places a synced pattern shows the new content, so check find-pattern-usage first. Pass expected_hash from get-synced-pattern to refuse a stale write. Undo: rollback-operation. Disabled by default (wpmcp_enable_core_patterns_write filter)',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ] + $fields, 'required' => [ 'id' ] ],
                'objects'      => [ 'id' => 'post' ],
                'validate'     => static fn (array $args): ?array => self::update_refusal($args),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['id'] ],
                'handler'      => static fn (array $args): array => self::update($args),
            ],
            'detach-pattern-instance' => $write + [
                'self_snapshotting' => true,
                'objects'           => [ 'pattern_id' => [ 'type' => 'post', 'access' => 'read' ], 'post_ids.*' => 'post', 'post_ids' => 'post' ],
                'description'       => 'Replace every placement of a synced pattern in the posts named in post_ids (at most 50; only those posts are snapshotted and changed) with the pattern\'s own blocks, so later pattern edits no longer reach them. all:true takes every placing post instead and refuses over 50 or when the scan is truncated. Block attributes beyond ref (overrides, bindings) are dropped. Each post is snapshotted under one session_id; rollback-session undoes the call, and a failure part way undoes the earlier posts. Posts without a placement are skipped. Disabled by default (wpmcp_enable_core_patterns_write filter)',
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'pattern_id' => $id,
                        'post_ids'   => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_POSTS, 'items' => $id ],
                        'all'        => [ 'type' => 'boolean' ],
                    ],
                    'required'   => [ 'pattern_id' ],
                ],
                'validate'          => static fn (array $args): ?array => self::detach_refusal($args),
                'handler'           => static fn (array $args, array $context): array => self::detach($args, $context),
            ],
        ];
    }

    // ------------------------------------------------------------ helpers

    /**
     * The wpmcp_rollback_post_refusal guard: a restore that would write a
     * pattern other people's posts place takes what the write took.
     *
     * @param mixed $reason Another guard's reason, kept when set.
     * @return mixed
     */
    public static function restore_refusal($reason, $post)
    {
        if (null !== $reason || ! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type) {
            return $reason;
        }
        return self::placed_elsewhere_refusal((int) $post->ID);
    }

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    private static function is_synced(int $id): bool
    {
        return 'unsynced' !== get_post_meta($id, self::SYNC_META, true);
    }

    private static function pattern(int $id): ?\WP_Post
    {
        $post = get_post($id);
        return $post instanceof \WP_Post && self::POST_TYPE === $post->post_type ? $post : null;
    }

    private static function readable_refusal(int $id): ?array
    {
        $post = self::pattern($id);
        if (null === $post) {
            return self::refusal('pattern_not_found', sprintf('Pattern %d does not exist (a wp_block post is required).', $id));
        }
        if (! current_user_can('read_post', $id)) {
            return self::refusal('forbidden', 'You cannot read this pattern.');
        }
        return null;
    }

    private static function summary(\WP_Post $post): array
    {
        return [
            'id'       => (int) $post->ID,
            'title'    => (string) $post->post_title,
            'status'   => (string) $post->post_status,
            'synced'   => self::is_synced((int) $post->ID),
            'modified' => (string) $post->post_modified_gmt,
        ];
    }

    // ------------------------------------------------------------ reads

    private static function list_patterns(array $args): array
    {
        $sync  = (string) ($args['sync'] ?? 'synced');
        $query = [
            'post_type'      => self::POST_TYPE,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'posts_per_page' => (int) ($args['per_page'] ?? 20),
            'paged'          => (int) ($args['page'] ?? 1),
        ];
        if (! empty($args['search'])) {
            $query['s'] = (string) $args['search'];
        }
        if ('all' !== $sync) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one meta key on the small wp_block set, the only way to tell synced from unsynced.
            $query['meta_query'] = 'unsynced' === $sync
                ? [ [ 'key' => self::SYNC_META, 'value' => 'unsynced' ] ]
                : [ 'relation' => 'OR', [ 'key' => self::SYNC_META, 'compare' => 'NOT EXISTS' ], [ 'key' => self::SYNC_META, 'value' => 'unsynced', 'compare' => '!=' ] ];
        }
        $q = Readable_Posts::query($query);

        return [
            'patterns'    => array_map([ self::class, 'summary' ], $q->posts),
            'total'       => (int) $q->found_posts,
            'total_pages' => (int) $q->max_num_pages,
        ];
    }

    private static function get_pattern(int $id): array
    {
        $post = self::pattern($id);
        return self::summary($post) + [
            'content'      => (string) $post->post_content,
            'content_hash' => hash('sha256', (string) $post->post_content),
            'used_in'      => count(self::usage($id)),
            'placed_in_total' => self::placing($id)['total'],
            'usage_truncated' => self::placing($id)['truncated'],
        ];
    }

    /**
     * Every post that places a pattern, whatever its status, the readable or
     * not: the candidates are matched on the "ref" attribute in SQL, then
     * checked exactly against the parsed blocks.
     *
     * @return array{ids: int[], counts: array<int, int>, total: int, truncated: bool, widgets: int}
     */
    private static function placing(int $pattern_id): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a content scan of every post type, including ones the caller cannot read, so a write can be refused on the full set.
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status NOT IN ('auto-draft', 'inherit') AND post_type <> 'revision' AND post_content LIKE %s ORDER BY ID ASC LIMIT %d",
            '%' . $wpdb->esc_like('"ref":' . $pattern_id) . '%',
            self::USAGE_LIMIT + 1
        )));
        $truncated = count($ids) > self::USAGE_LIMIT;
        $ids       = array_slice($ids, 0, self::USAGE_LIMIT);

        $counts = [];
        foreach ($ids as $post_id) {
            $post = get_post($post_id);
            $n    = $post ? self::count_placements(parse_blocks((string) $post->post_content), $pattern_id) : 0;
            if ($n > 0) {
                $counts[ $post_id ] = $n;
            }
        }

        return [ 'ids' => array_keys($counts), 'counts' => $counts, 'total' => count($counts), 'truncated' => $truncated, 'widgets' => self::widget_placements($pattern_id) ];
    }

    /** How many block widgets (the widget_block option, not posts) place the pattern. */
    private static function widget_placements(int $pattern_id): int
    {
        $n = 0;
        foreach ((array) get_option('widget_block', []) as $widget) {
            $content = is_array($widget) ? (string) ($widget['content'] ?? '') : '';
            if (false !== strpos($content, '"ref":' . $pattern_id)) {
                $n += self::count_placements(parse_blocks($content), $pattern_id);
            }
        }
        return $n;
    }

    /** @return array{usage: array, readable: int, total: int, truncated: bool, widget_placements: int} */
    private static function usage_report(int $pattern_id): array
    {
        $placing = self::placing($pattern_id);
        $usage   = [];
        foreach ($placing['counts'] as $post_id => $n) {
            $post = get_post((int) $post_id);
            if (! $post || ! current_user_can('read_post', (int) $post_id)) {
                continue;
            }
            $usage[] = [ 'id' => (int) $post->ID, 'title' => (string) $post->post_title, 'type' => (string) $post->post_type, 'status' => (string) $post->post_status, 'placements' => $n ];
        }

        return [ 'usage' => $usage, 'readable' => count($usage), 'total' => $placing['total'], 'truncated' => $placing['truncated'], 'widget_placements' => $placing['widgets'] ];
    }

    /** @return array<int, array{id: int, title: string, type: string, status: string, placements: int}> */
    private static function usage(int $pattern_id): array
    {
        return self::usage_report($pattern_id)['usage'];
    }

    /**
     * Why the current user may not change or remove a pattern that other
     * people's posts place, or null. A synced pattern edit changes every
     * placing post, so it takes edit rights on each of them or the type's
     * edit_others_posts; a scan that stopped at the limit cannot prove the
     * first, so it takes the second. Also asked when a restore would put an
     * edit back or trash a creation (Rollback_Service::refused_post).
     */
    public static function placed_elsewhere_refusal(int $pattern_id): ?string
    {
        $post = self::pattern($pattern_id);
        if (null === $post || ! self::is_synced($pattern_id)) {
            return null;
        }
        $placing = self::placing($pattern_id);
        if ($placing['widgets'] > 0 && ! current_user_can('edit_theme_options')) {
            return sprintf('pattern %d is placed in %d block widget(s), which take edit_theme_options to change.', $pattern_id, $placing['widgets']);
        }
        $object = get_post_type_object(self::POST_TYPE);
        if ($object instanceof \WP_Post_Type && current_user_can((string) $object->cap->edit_others_posts)) {
            return null;
        }
        if ($placing['truncated']) {
            return sprintf('pattern %d is placed in more than %d posts, so only a user who can edit others\' posts may change it.', $pattern_id, self::USAGE_LIMIT);
        }
        $blocked = [];
        foreach ($placing['ids'] as $post_id) {
            if (! current_user_can('edit_post', $post_id)) {
                $blocked[] = $post_id;
            }
        }
        if ([] === $blocked) {
            return null;
        }
        return sprintf('pattern %d is placed in %d post(s) you cannot edit (first: %d); changing it would change them. Ask someone who can edit others\' posts.', $pattern_id, count($blocked), $blocked[0]);
    }

    private static function is_placement(array $block, int $pattern_id): bool
    {
        return 'core/block' === ($block['blockName'] ?? null) && (int) ($block['attrs']['ref'] ?? 0) === $pattern_id;
    }

    private static function count_placements(array $blocks, int $pattern_id): int
    {
        $n = 0;
        foreach ($blocks as $block) {
            $n += self::is_placement($block, $pattern_id) ? 1 : self::count_placements((array) ($block['innerBlocks'] ?? []), $pattern_id);
        }
        return $n;
    }

    // ------------------------------------------------------------ create

    private static function create_refusal(array $args): ?array
    {
        if ('' === trim((string) $args['title'])) {
            return self::refusal('invalid_title', 'A pattern needs a title.');
        }
        $object = get_post_type_object(self::POST_TYPE);
        if (! $object instanceof \WP_Post_Type || ! current_user_can((string) $object->cap->create_posts)) {
            return self::refusal('forbidden', 'You cannot create patterns.');
        }
        if ('publish' === ($args['status'] ?? 'publish') && ! current_user_can((string) $object->cap->publish_posts)) {
            return self::refusal('forbidden', 'You cannot publish patterns; pass status:draft.');
        }
        return null;
    }

    private static function create(array $args, array $context): array
    {
        $post_id = wp_insert_post([
            'post_type'    => self::POST_TYPE,
            'post_title'   => wp_slash((string) $args['title']),
            'post_content' => wp_slash((string) ($args['content'] ?? '')),
            'post_status'  => (string) ($args['status'] ?? 'publish'),
            'post_author'  => get_current_user_id(),
        ], true);
        if (is_wp_error($post_id) || 0 === (int) $post_id) {
            throw new Operation_Refused('insert_failed', is_wp_error($post_id) ? $post_id->get_error_message() : 'WordPress could not create the pattern.');
        }
        $post_id = (int) $post_id;
        $op      = Post_Creation_Snapshot::record((string) $context['tool_name'], [ $post_id ], [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']);
        if (isset($args['synced']) && false === $args['synced']) {
            update_post_meta($post_id, self::SYNC_META, 'unsynced');
        }
        clean_post_cache($post_id);

        return [ 'result' => [ 'pattern' => self::get_pattern($post_id) ], 'operation_ids' => [ $op ] ];
    }

    // ------------------------------------------------------------ update

    private static function update_refusal(array $args): ?array
    {
        $id = (int) $args['id'];
        $post = self::pattern($id);
        if (null === $post) {
            return self::refusal('pattern_not_found', sprintf('Pattern %d does not exist (a wp_block post is required).', $id));
        }
        if (! current_user_can('edit_post', $id)) {
            return self::refusal('forbidden', 'You cannot edit this pattern.');
        }
        if (! array_intersect_key($args, [ 'title' => 1, 'content' => 1, 'synced' => 1 ])) {
            return self::refusal('nothing_to_change', 'Pass title, content or synced.');
        }
        if (isset($args['title']) && '' === trim((string) $args['title'])) {
            return self::refusal('invalid_title', 'A pattern needs a title.');
        }
        if (isset($args['expected_hash']) && ! hash_equals(hash('sha256', (string) $post->post_content), (string) $args['expected_hash'])) {
            return self::refusal('stale_hash', 'The pattern changed since it was read. Re-read it with get-synced-pattern and retry.');
        }
        $elsewhere = self::placed_elsewhere_refusal($id);
        if (null !== $elsewhere) {
            return self::refusal('pattern_in_use_elsewhere', ucfirst($elsewhere));
        }
        return null;
    }

    private static function update(array $args): array
    {
        $id     = (int) $args['id'];
        $fields = [ 'ID' => $id ];
        if (isset($args['title'])) {
            $fields['post_title'] = wp_slash((string) $args['title']);
        }
        if (isset($args['content'])) {
            $fields['post_content'] = wp_slash((string) $args['content']);
        }
        $result = Save_Filters::update_post($fields);
        if (is_wp_error($result) || 0 === (int) $result) {
            throw new Operation_Refused('update_failed', is_wp_error($result) ? $result->get_error_message() : 'WordPress could not update the pattern.');
        }
        if (isset($args['synced'])) {
            if ($args['synced']) {
                delete_post_meta($id, self::SYNC_META);
            } else {
                update_post_meta($id, self::SYNC_META, 'unsynced');
            }
        }
        clean_post_cache($id);

        return self::get_pattern($id);
    }

    // ------------------------------------------------------------ detach

    private static function detach_refusal(array $args): ?array
    {
        $pattern_id = (int) $args['pattern_id'];
        $refused    = self::readable_refusal($pattern_id);
        if (null !== $refused) {
            return $refused;
        }
        $targets = self::detach_targets($args);
        if (isset($targets['error'])) {
            return $targets['error'];
        }
        $with = 0;
        foreach ($targets['ids'] as $post_id) {
            $post = get_post($post_id);
            if (! $post instanceof \WP_Post) {
                return self::refusal('post_not_found', sprintf('Post %d does not exist; nothing was written.', $post_id));
            }
            if (! current_user_can('edit_post', $post_id)) {
                return self::refusal('forbidden', sprintf('You cannot edit post %d; nothing was written.', $post_id));
            }
            $content = (string) $post->post_content;
            $blocks  = parse_blocks($content);
            if (self::count_placements($blocks, $pattern_id) < 1) {
                continue;
            }
            if (serialize_blocks($blocks) !== $content) {
                return self::refusal('content_not_round_trippable', sprintf('Post %d does not round-trip through the block parser, so rewriting it would change blocks the detach never touched; nothing was written.', $post_id));
            }
            ++$with;
        }
        if (0 === $with) {
            return self::refusal('no_placements', 'None of the posts places this pattern; nothing to detach.');
        }
        return null;
    }

    /** @return array{ids: int[]}|array{error: array} the posts a detach call covers. */
    private static function detach_targets(array $args): array
    {
        $pattern_id = (int) $args['pattern_id'];
        if (! empty($args['all'])) {
            $placing = self::placing($pattern_id);
            if ($placing['truncated'] || $placing['total'] > self::MAX_POSTS) {
                return [ 'error' => self::refusal('too_many_posts', sprintf(
                    'The pattern is placed in %s%d posts, over the limit of %d for one call; nothing was written. Pass post_ids in batches of at most %d (find-pattern-usage lists them).',
                    $placing['truncated'] ? 'at least ' : '',
                    $placing['total'],
                    self::MAX_POSTS,
                    self::MAX_POSTS
                ), [ 'total' => $placing['total'], 'truncated' => $placing['truncated'] ]) ];
            }
            return [ 'ids' => $placing['ids'] ];
        }
        if (empty($args['post_ids'])) {
            return [ 'error' => self::refusal('no_posts', 'Pass post_ids or all:true.') ];
        }
        return [ 'ids' => array_values(array_unique(array_map('intval', (array) $args['post_ids']))) ];
    }

    /** Replace each placement in a block list with the pattern's blocks, innerContent kept in step. */
    private static function replace_placements(array $blocks, int $pattern_id, array $pattern_blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if (self::is_placement($block, $pattern_id)) {
                array_push($out, ...$pattern_blocks);
                continue;
            }
            if (! empty($block['innerBlocks'])) {
                $inner   = (array) $block['innerBlocks'];
                $new     = [];
                $content = [];
                $cursor  = 0;
                foreach ((array) $block['innerContent'] as $chunk) {
                    if (null !== $chunk) {
                        $content[] = $chunk;
                        continue;
                    }
                    $child    = $inner[ $cursor++ ];
                    $replaced = self::replace_placements([ $child ], $pattern_id, $pattern_blocks);
                    foreach ($replaced as $r) {
                        $new[]     = $r;
                        $content[] = null;
                    }
                }
                $block['innerBlocks']  = $new;
                $block['innerContent'] = $content;
            }
            $out[] = $block;
        }
        return $out;
    }

    private static function detach(array $args, array $context): array
    {
        $pattern_id     = (int) $args['pattern_id'];
        $pattern_blocks = parse_blocks((string) get_post($pattern_id)->post_content);
        $detached       = [];
        $skipped        = [];
        $ids            = [];

        $targets = self::detach_targets($args);

        foreach ($targets['ids'] as $post_id) {
            $content = (string) get_post($post_id)->post_content;
            $blocks  = parse_blocks($content);
            $n       = self::count_placements($blocks, $pattern_id);
            if ($n < 1) {
                $skipped[] = $post_id;
                continue;
            }
            $new = serialize_blocks(self::replace_placements($blocks, $pattern_id, $pattern_blocks));
            try {
                $out = Safe_Mutation::run(
                    [
                        'object_type' => 'post',
                        'object_id'   => $post_id,
                        'session_id'  => (string) $context['session_id'],
                        'tool_name'   => (string) $context['tool_name'],
                        'args'        => [ 'operation' => (string) $context['operation'], 'args' => [ 'pattern_id' => $pattern_id, 'post_id' => $post_id ] ],
                    ],
                    static function () use ($post_id, $new): bool {
                        Save_Filters::update_post([ 'ID' => $post_id, 'post_content' => wp_slash($new) ]);
                        return true;
                    },
                    static function () use ($post_id, $new): bool {
                        clean_post_cache($post_id);
                        return (string) get_post($post_id)->post_content === $new;
                    }
                );
            } catch (\Throwable $e) {
                // Put the posts already rewritten back, newest first, so the call is all or nothing.
                $left = [];
                foreach (array_reverse($ids) as $done) {
                    try {
                        $restored = Rollback_Service::restore_operation($done);
                    } catch (\Throwable $undo_error) {
                        $restored = false;
                    }
                    if (! $restored) {
                        $left[] = $done;
                    }
                }
                throw new Operation_Refused('detach_failed', sprintf(
                    'Detaching failed at post %d (%s); %s',
                    $post_id,
                    $e->getMessage(),
                    [] === $left ? 'the posts already rewritten were put back.' : 'could not put back every earlier post, undo them with rollback-session.'
                ), [ 'failed_post_id' => $post_id, 'session_id' => (string) $context['session_id'], 'operation_ids' => $left ]);
            }
            $ids[]      = $out['operation_id'];
            $detached[] = [ 'post_id' => $post_id, 'placements' => $n, 'operation_id' => $out['operation_id'], 'content_hash' => hash('sha256', $new) ];
        }

        return [ 'result' => [ 'detached' => $detached, 'skipped' => $skipped ], 'operation_ids' => $ids ];
    }
}
