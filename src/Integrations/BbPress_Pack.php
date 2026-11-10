<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\BbPress_Posts_Snapshot;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * bbPress ops on the plugin-data pair: forums, topics and replies, posting,
 * and topic moderation (close, stick, spam).
 *
 * bbPress keeps forums, topics and replies as three post types (forum,
 * topic, reply) with their relationships and running counts in _bbp_ post
 * meta, two statuses of its own (closed, spam) and each forum's sticky list
 * in its _bbp_sticky_topics meta. Those keys are protected meta the generic
 * post tools hide, so the reads here surface them, straight from that
 * storage and kept to the rows the caller may read (Readable_Posts).
 *
 * Writes go through bbPress's own API (bbp_insert_topic, bbp_insert_reply,
 * bbp_close_topic, bbp_stick_topic, bbp_spam_topic), so its counts, sticky
 * lists, engagements and hooks update as they do from its own screens, and
 * each takes bbPress's own capability on top of the pair's: publish_topics,
 * publish_replies, or moderate for the topic. Each write is snapshotted
 * first as a 'bbpress_posts' image of everything it can move (the topic,
 * its replies, its forum and the forums above it, the authors' counts, the
 * topic's tags and the super sticky list), and a create's snapshot is
 * re-keyed on the post it made, so rollback-operation restores exactly:
 * moderation goes back, and a created topic or reply is deleted with the
 * counts and last-activity it moved put back.
 *
 * Presence is filterable through wpmcp_bbpress_active (default: bbPress is
 * loaded). While it is false every op stays documented in list-operations
 * (dependency_met:false) and answers bbpress_inactive without touching
 * anything; the writes also need bbPress's API itself to be loaded.
 */
final class BbPress_Pack
{
    private const MAX_PER_PAGE = 100;

    /** Topic statuses a listing may ask for (bbPress registers closed and spam). */
    private const TOPIC_STATUSES = [ 'publish', 'closed', 'spam', 'pending', 'private', 'hidden', 'trash' ];

    private const MODERATION = [ 'close', 'stick', 'spam' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_bbpress_active', function_exists('bbpress'));
    }

    /** @return true|array{code: string, message: string} */
    public static function requirement(bool $write = false)
    {
        if (! self::active() || ($write && ! function_exists('bbp_insert_topic'))) {
            return [ 'code' => 'bbpress_inactive', 'message' => 'bbPress is not active on this site.' ];
        }
        return true;
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $reads  = static fn () => self::requirement();
        $writes = static fn () => self::requirement(true);
        $paging = [
            'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
            'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
        ];
        // The undo takes the bbPress capability the write took.
        $scope  = static fn (string $kind, string $arg, string $cap): \Closure => static fn (array $args): array => [
            'object_type'         => BbPress_Posts_Snapshot::TYPE,
            'object_id'           => BbPress_Posts_Snapshot::key($kind, (int) $args[ $arg ]),
            'extra_snapshot_data' => [ 'restore_capability' => $cap ],
        ];

        return [
            'bbpress-list-forums'    => [
                'mode'         => 'read',
                'description'  => 'List bbPress forums (id, title, status, parent forum, forum or category, topic and reply counts, last activity); parent_id lists one forum\'s subforums',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'parent_id' => [ 'type' => 'integer', 'minimum' => 1 ] ] + $paging,
                ],
                'objects'      => [ 'parent_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'requires'     => $reads,
                'handler'      => static fn (array $args): array => self::list_forums($args),
            ],
            'bbpress-list-topics'    => [
                'mode'         => 'read',
                'description'  => 'List the topics of a bbPress forum, newest first (id, title, status publish/closed/spam/pending, sticky, author, reply and voice counts, last activity); filter by status',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'forum_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'status'   => [ 'type' => 'string', 'enum' => self::TOPIC_STATUSES ],
                    ] + $paging,
                    'required'   => [ 'forum_id' ],
                ],
                'objects'      => [ 'forum_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'requires'     => $reads,
                'validate'     => static fn (array $args): ?array => self::type_refusal((int) $args['forum_id'], 'forum'),
                'handler'      => static fn (array $args): array => self::list_topics($args),
            ],
            'bbpress-get-topic'      => [
                'mode'         => 'read',
                'description'  => 'Read one bbPress topic (title, content, status, forum, counts) with its replies in posting order, paged',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'topic_id' => [ 'type' => 'integer', 'minimum' => 1 ] ] + $paging,
                    'required'   => [ 'topic_id' ],
                ],
                'objects'      => [ 'topic_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'requires'     => $reads,
                'validate'     => static fn (array $args): ?array => self::type_refusal((int) $args['topic_id'], 'topic'),
                'handler'      => static fn (array $args): array => self::get_topic($args),
            ],
            'bbpress-create-topic'   => [
                'mode'         => 'write',
                'description'  => 'Post a topic in a bbPress forum (title, content, status publish or pending) through bbPress, as you. Snapshotted; rollback-operation deletes it and puts the forum counts back',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'forum_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'title'    => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
                        'content'  => [ 'type' => 'string', 'minLength' => 1 ],
                        'status'   => [ 'type' => 'string', 'enum' => [ 'publish', 'pending' ] ],
                    ],
                    'required'   => [ 'forum_id', 'title', 'content' ],
                ],
                'objects'      => [ 'forum_id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'read' ] ],
                'requires'     => $writes,
                'validate'     => static fn (array $args): ?array => self::create_topic_refusal($args),
                'snapshot'     => $scope('forum', 'forum_id', 'publish_topics'),
                'handler'      => static fn (array $args, array $context): array => self::create_topic($args, (string) ($context['operation_id'] ?? '')),
            ],
            'bbpress-create-reply'   => [
                'mode'         => 'write',
                'description'  => 'Post a reply to a bbPress topic (content, optional reply_to: a reply in the same topic) through bbPress, as you. Snapshotted; rollback-operation deletes it and puts the counts back',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'topic_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'content'  => [ 'type' => 'string', 'minLength' => 1 ],
                        'reply_to' => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                    'required'   => [ 'topic_id', 'content' ],
                ],
                'objects'      => [ 'topic_id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'read' ] ],
                'requires'     => $writes,
                'validate'     => static fn (array $args): ?array => self::create_reply_refusal($args),
                'snapshot'     => $scope('topic', 'topic_id', 'publish_replies'),
                'handler'      => static fn (array $args, array $context): array => self::create_reply($args, (string) ($context['operation_id'] ?? '')),
            ],
            'bbpress-moderate-topic' => [
                'mode'         => 'write',
                'capability'   => 'edit_others_posts',
                'description'  => 'Moderate a bbPress topic through bbPress: close (no new replies), stick (to the top of its forum) or spam (hides it and its replies). Needs bbPress\'s moderate capability. Snapshotted and reversible',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'topic_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'action'   => [ 'type' => 'string', 'enum' => self::MODERATION ],
                    ],
                    'required'   => [ 'topic_id', 'action' ],
                ],
                'objects'      => [ 'topic_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'requires'     => $writes,
                'validate'     => static fn (array $args): ?array => self::moderate_refusal($args),
                'snapshot'     => $scope('topic', 'topic_id', 'moderate'),
                'handler'      => static fn (array $args, array $context): array => self::moderate($args, (string) ($context['operation_id'] ?? '')),
            ],
        ];
    }

    // ------------------------------------------------------------ reads

    private static function list_forums(array $args): array
    {
        $query = [
            'post_type'      => BbPress_Posts_Snapshot::post_type('forum'),
            'posts_per_page' => (int) ($args['per_page'] ?? 20),
            'paged'          => (int) ($args['page'] ?? 1),
            'orderby'        => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
        ];
        if (isset($args['parent_id'])) {
            $query['post_parent'] = (int) $args['parent_id'];
        }
        $q      = Readable_Posts::query($query);
        $forums = [];
        foreach ($q->posts as $post) {
            $forums[] = [
                'id'                => (int) $post->ID,
                'title'             => (string) $post->post_title,
                'status'            => (string) $post->post_status,
                'parent_id'         => (int) $post->post_parent,
                'type'              => 'category' === get_post_meta($post->ID, '_bbp_forum_type', true) ? 'category' : 'forum',
                'topic_count'       => (int) get_post_meta($post->ID, '_bbp_topic_count', true),
                'reply_count'       => (int) get_post_meta($post->ID, '_bbp_reply_count', true),
                'total_topic_count' => (int) get_post_meta($post->ID, '_bbp_total_topic_count', true),
                'total_reply_count' => (int) get_post_meta($post->ID, '_bbp_total_reply_count', true),
                'last_active'       => (string) get_post_meta($post->ID, '_bbp_last_active_time', true),
            ];
        }
        return [ 'forums' => $forums, 'total' => (int) $q->found_posts ];
    }

    private static function list_topics(array $args): array
    {
        $forum_id = (int) $args['forum_id'];
        $status   = isset($args['status']) ? [ (string) $args['status'] ] : self::TOPIC_STATUSES;
        $status   = array_values(array_intersect($status, get_post_stati()));
        if ([] === $status) {
            return [ 'topics' => [], 'total' => 0 ];
        }
        $q      = Readable_Posts::query([
            'post_type'      => BbPress_Posts_Snapshot::post_type('topic'),
            'post_parent'    => $forum_id,
            'post_status'    => $status,
            'posts_per_page' => (int) ($args['per_page'] ?? 20),
            'paged'          => (int) ($args['page'] ?? 1),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        $sticky = self::sticky_ids($forum_id);
        $topics = [];
        foreach ($q->posts as $post) {
            $topics[] = self::topic_row($post, $sticky);
        }
        return [ 'topics' => $topics, 'total' => (int) $q->found_posts ];
    }

    private static function get_topic(array $args): array
    {
        $post             = get_post((int) $args['topic_id']);
        $topic            = self::topic_row($post, self::sticky_ids(BbPress_Posts_Snapshot::forum_of((int) $post->ID)));
        $topic['content'] = (string) $post->post_content;

        $statuses = array_values(array_intersect([ 'publish', 'closed', 'spam', 'pending', 'private', 'trash' ], get_post_stati()));
        $q        = Readable_Posts::query([
            'post_type'      => BbPress_Posts_Snapshot::post_type('reply'),
            'post_parent'    => (int) $post->ID,
            'post_status'    => $statuses,
            'posts_per_page' => (int) ($args['per_page'] ?? 50),
            'paged'          => (int) ($args['page'] ?? 1),
            'orderby'        => [ 'menu_order' => 'ASC', 'date' => 'ASC', 'ID' => 'ASC' ],
        ]);
        $replies  = [];
        foreach ($q->posts as $reply) {
            $replies[] = [
                'id'        => (int) $reply->ID,
                'status'    => (string) $reply->post_status,
                'author_id' => (int) $reply->post_author,
                'date'      => (string) $reply->post_date,
                'reply_to'  => (int) get_post_meta($reply->ID, '_bbp_reply_to', true),
                'content'   => (string) $reply->post_content,
            ];
        }
        return [ 'topic' => $topic, 'replies' => $replies, 'total_replies' => (int) $q->found_posts ];
    }

    private static function topic_row(\WP_Post $post, array $sticky): array
    {
        return [
            'id'          => (int) $post->ID,
            'title'       => (string) $post->post_title,
            'status'      => (string) $post->post_status,
            'forum_id'    => BbPress_Posts_Snapshot::forum_of((int) $post->ID),
            'author_id'   => (int) $post->post_author,
            'date'        => (string) $post->post_date,
            'sticky'      => in_array((int) $post->ID, $sticky, true),
            'reply_count' => (int) get_post_meta($post->ID, '_bbp_reply_count', true),
            'voice_count' => (int) get_post_meta($post->ID, '_bbp_voice_count', true),
            'last_active' => (string) get_post_meta($post->ID, '_bbp_last_active_time', true),
        ];
    }

    /** A forum's sticky topic ids, its own list and the site-wide super stickies. */
    private static function sticky_ids(int $forum_id): array
    {
        $own   = $forum_id > 0 ? (array) get_post_meta($forum_id, '_bbp_sticky_topics', true) : [];
        $super = (array) get_option(BbPress_Posts_Snapshot::SUPER_STICKY_OPTION, []);
        return array_values(array_unique(array_map('intval', array_filter(array_merge($own, $super)))));
    }

    // ------------------------------------------------------------ refusals

    /** @return array{code: string, message: string, data: array}|null */
    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    private static function type_refusal(int $id, string $which): ?array
    {
        if (BbPress_Posts_Snapshot::post_type($which) === get_post_type($id)) {
            return null;
        }
        return self::refusal("not_a_{$which}", sprintf('Post %d is not a bbPress %s.', $id, $which), [ 'post_id' => $id ]);
    }

    private static function cap_refusal(string $cap, int $id = 0): ?array
    {
        if ($id > 0 ? current_user_can($cap, $id) : current_user_can($cap)) {
            return null;
        }
        return self::refusal('operation_denied', sprintf('This needs bbPress\'s "%s" capability.', $cap), [ 'reason' => 'capability' ]);
    }

    private static function create_topic_refusal(array $args): ?array
    {
        $forum_id = (int) $args['forum_id'];
        $refused  = self::type_refusal($forum_id, 'forum') ?? self::cap_refusal('publish_topics');
        if (null !== $refused) {
            return $refused;
        }
        if (bbp_is_forum_category($forum_id)) {
            return self::refusal('forum_is_category', sprintf('Forum %d is a category; topics go in the forums under it.', $forum_id), [ 'forum_id' => $forum_id ]);
        }
        if (bbp_is_forum_closed($forum_id) && ! current_user_can('moderate', $forum_id)) {
            return self::refusal('forum_closed', sprintf('Forum %d is closed to new topics.', $forum_id), [ 'forum_id' => $forum_id ]);
        }
        return null;
    }

    private static function create_reply_refusal(array $args): ?array
    {
        $topic_id = (int) $args['topic_id'];
        $refused  = self::type_refusal($topic_id, 'topic') ?? self::cap_refusal('publish_replies');
        if (null !== $refused) {
            return $refused;
        }
        if (bbp_is_topic_closed($topic_id) && ! current_user_can('moderate', $topic_id)) {
            return self::refusal('topic_closed', sprintf('Topic %d is closed to new replies.', $topic_id), [ 'topic_id' => $topic_id ]);
        }
        $reply_to = (int) ($args['reply_to'] ?? 0);
        if ($reply_to > 0 && (BbPress_Posts_Snapshot::post_type('reply') !== get_post_type($reply_to) || (int) bbp_get_reply_topic_id($reply_to) !== $topic_id)) {
            return self::refusal('invalid_reply_to', sprintf('Post %d is not a reply in topic %d.', $reply_to, $topic_id), [ 'reply_to' => $reply_to ]);
        }
        return null;
    }

    private static function moderate_refusal(array $args): ?array
    {
        $topic_id = (int) $args['topic_id'];
        $refused  = self::type_refusal($topic_id, 'topic') ?? self::cap_refusal('moderate', $topic_id);
        if (null !== $refused) {
            return $refused;
        }
        $action = (string) $args['action'];
        $done   = [
            'close' => static fn (): bool => bbp_is_topic_closed($topic_id),
            'stick' => static fn (): bool => bbp_is_topic_sticky($topic_id, false),
            'spam'  => static fn (): bool => bbp_is_topic_spam($topic_id),
        ];
        if ($done[ $action ]()) {
            return self::refusal('already_done', sprintf('Topic %d is already %s.', $topic_id, [ 'close' => 'closed', 'stick' => 'sticky', 'spam' => 'spam' ][ $action ]), [ 'topic_id' => $topic_id ]);
        }
        if ('stick' === $action && BbPress_Posts_Snapshot::forum_of($topic_id) <= 0) {
            return self::refusal('no_forum', sprintf('Topic %d is in no forum to stick it to.', $topic_id), [ 'topic_id' => $topic_id ]);
        }
        return null;
    }

    // ------------------------------------------------------------ writes

    /**
     * Run one write, then complete its snapshot with what it created and
     * its after-image, even when the write fails half way.
     *
     * @param callable(): int $write returns the post it created, or 0
     * @return int
     */
    private static function tracked(string $operation_id, callable $write, string $kind): int
    {
        $id = 0;
        try {
            $id = (int) $write();
        } finally {
            BbPress_Posts_Snapshot::record_after($operation_id, $id > 0 ? [ $id ] : [], $id > 0 && '' !== $kind ? BbPress_Posts_Snapshot::key($kind, $id) : '');
        }
        return $id;
    }

    /** A write bbPress refused: void its undo point when it changed nothing, and say so. */
    private static function failed(string $operation_id, string $message): Operation_Refused
    {
        if ('' !== $operation_id && BbPress_Posts_Snapshot::unchanged($operation_id)) {
            Snapshot_Store::delete_operation($operation_id);
        }
        return new Operation_Refused('write_failed', $message);
    }

    private static function create_topic(array $args, string $operation_id): array
    {
        $forum_id = (int) $args['forum_id'];
        $title    = (string) apply_filters('bbp_new_topic_pre_title', sanitize_text_field((string) $args['title'])); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- bbPress's own filter, so a topic posted here is cleaned as bbPress's form cleans it.
        $content  = (string) apply_filters('bbp_new_topic_pre_content', (string) $args['content']); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- as above.
        $status   = 'pending' === ($args['status'] ?? 'publish') ? bbp_get_pending_status_id() : bbp_get_public_status_id();

        $id = self::tracked($operation_id, static fn (): int => (int) bbp_insert_topic(
            [
                'post_parent'  => $forum_id,
                'post_title'   => $title,
                'post_content' => $content,
                'post_status'  => $status,
                'post_author'  => get_current_user_id(),
            ],
            [ 'forum_id' => $forum_id ]
        ), 'topic_created');
        if ($id <= 0) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw self::failed($operation_id, 'bbPress could not create the topic.');
        }
        return [ 'topic_id' => $id, 'forum_id' => $forum_id, 'status' => (string) get_post_status($id) ];
    }

    private static function create_reply(array $args, string $operation_id): array
    {
        $topic_id = (int) $args['topic_id'];
        $forum_id = BbPress_Posts_Snapshot::forum_of($topic_id);
        $content  = (string) apply_filters('bbp_new_reply_pre_content', (string) $args['content']); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- bbPress's own filter, so a reply posted here is cleaned as bbPress's form cleans it.

        $id = self::tracked($operation_id, static fn (): int => (int) bbp_insert_reply(
            [
                'post_parent'  => $topic_id,
                'post_title'   => sprintf('Reply To: %s', get_the_title($topic_id)),
                'post_content' => $content,
                'post_status'  => bbp_get_public_status_id(),
                'post_author'  => get_current_user_id(),
            ],
            [ 'topic_id' => $topic_id, 'forum_id' => $forum_id, 'reply_to' => (int) ($args['reply_to'] ?? 0) ]
        ), 'reply_created');
        if ($id <= 0) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw self::failed($operation_id, 'bbPress could not create the reply.');
        }
        return [ 'reply_id' => $id, 'topic_id' => $topic_id, 'forum_id' => $forum_id, 'status' => (string) get_post_status($id) ];
    }

    private static function moderate(array $args, string $operation_id): array
    {
        $topic_id = (int) $args['topic_id'];
        $action   = (string) $args['action'];
        $ok       = false;
        self::tracked($operation_id, static function () use ($topic_id, $action, &$ok): int {
            switch ($action) {
                case 'close':
                    $ok = (int) bbp_close_topic($topic_id) > 0;
                    break;
                case 'spam':
                    $ok = (int) bbp_spam_topic($topic_id) > 0;
                    break;
                default:
                    $ok = (bool) bbp_stick_topic($topic_id, false);
            }
            return 0;
        }, '');
        if (! $ok) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw self::failed($operation_id, sprintf('bbPress could not %s topic %d.', $action, $topic_id));
        }
        clean_post_cache($topic_id);
        return [
            'topic_id' => $topic_id,
            'action'   => $action,
            'status'   => (string) get_post_status($topic_id),
            'sticky'   => in_array($topic_id, self::sticky_ids(BbPress_Posts_Snapshot::forum_of($topic_id)), true),
        ];
    }
}
