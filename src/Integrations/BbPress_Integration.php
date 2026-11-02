<?php

namespace WPMCP\Integrations;

use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * bbPress (forums) integration (wpmcp/bbpress-read and wpmcp/bbpress-write).
 *
 * bbPress stores forums, topics and replies as three post types (`forum`,
 * `topic`, `reply`) with their relationships and running counts in
 * underscore-prefixed `_bbp_*` postmeta (_bbp_forum_id, _bbp_topic_id,
 * _bbp_topic_count, _bbp_reply_count, _bbp_voice_count, _bbp_last_active_time;
 * the per-forum sticky list in _bbp_sticky_topics) plus two custom post
 * statuses, `closed` and `spam`. Those keys are protected meta the generic
 * get-post tool hides, so this integration surfaces them through curated,
 * forum-aware reads, and routes the writes through bbPress's own functions
 * (bbp_insert_topic, bbp_insert_reply, bbp_close_topic, bbp_stick_topic,
 * bbp_spam_topic) when they exist so its counts, caches and hooks fire.
 *
 * Every bbp_* call sits behind a function_exists guard with a plain-WordPress
 * fallback (WP_Query over the post types, wp_insert_post / wp_update_post),
 * so the operations are fully testable without the plugin loaded and still
 * answer on a site where the post types exist but bbPress is mid-upgrade.
 *
 * Writes are snapshot-wrapped. Moderation snapshots the topic (close, spam)
 * or the forum whose sticky list changes (stick), and is undoable. A create
 * has no pre-existing target of its own, so it snapshots the parent (forum
 * or topic) for the count before-image and is flagged undoable:false: the
 * new post is not removed by restoring its parent, and saying otherwise
 * would be a lie.
 */
class BbPress_Integration extends Integration_Dispatcher
{
    public const FORUM_POST_TYPE = 'forum';
    public const TOPIC_POST_TYPE = 'topic';
    public const REPLY_POST_TYPE = 'reply';

    /** Statuses a topic may carry; bbPress registers closed and spam itself. */
    private const TOPIC_STATUSES = [ 'publish', 'closed', 'spam', 'pending', 'private', 'hidden', 'trash' ];

    private const MODERATION_ACTIONS = [ 'close', 'stick', 'spam' ];

    public function integration(): string
    {
        return 'bbpress';
    }

    public function is_available(): bool
    {
        return function_exists('bbp_get_forum_post_type')
            || (post_type_exists(self::forum_type()) && post_type_exists(self::topic_type()));
    }

    protected function summary(): string
    {
        return 'bbPress (forums, topics, replies, and topic moderation)';
    }

    public static function forum_type(): string
    {
        return function_exists('bbp_get_forum_post_type') ? (string) bbp_get_forum_post_type() : self::FORUM_POST_TYPE;
    }

    public static function topic_type(): string
    {
        return function_exists('bbp_get_topic_post_type') ? (string) bbp_get_topic_post_type() : self::TOPIC_POST_TYPE;
    }

    public static function reply_type(): string
    {
        return function_exists('bbp_get_reply_post_type') ? (string) bbp_get_reply_post_type() : self::REPLY_POST_TYPE;
    }

    protected function operations(): array
    {
        $paging = [
            'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
            'page'      => [ 'type' => 'integer', 'minimum' => 1 ],
        ];

        return [
            'list-forums'    => [
                'mode'         => 'read',
                'description'  => 'List bbPress forums with id, title, status, parent forum, and running topic and reply counts',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $paging + [ 'status' => [ 'type' => 'string' ] ],
                ],
                'handler'      => fn (array $args): array => $this->list_forums($args),
            ],
            'list-topics'    => [
                'mode'         => 'read',
                'objects'      => [ 'forum_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'description'  => 'List the topics in one forum with id, title, status (publish, closed, spam, pending), sticky flag, author, reply and voice counts, and last activity',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $paging + [
                        'forum_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'status'   => [ 'type' => 'string', 'description' => 'One topic status, or omit for every status' ],
                    ],
                    'required'   => [ 'forum_id' ],
                ],
                'handler'      => fn (array $args): array => $this->list_topics($args),
            ],
            'get-topic'      => [
                'mode'         => 'read',
                'objects'      => [ 'topic_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'description'  => 'Read one topic (title, content, status, forum, author, counts) with its replies in posting order',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'topic_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'topic_id' ],
                ],
                'handler'      => fn (array $args): array => $this->get_topic($args),
            ],
            'create-topic'   => [
                'mode'         => 'write',
                'capability'   => 'publish_posts',
                'objects'      => [ 'forum_id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'read' ] ],
                'description'  => 'Create a topic in a forum (title, content, optional status publish|pending). Snapshots the forum\'s before-image for its counts; the new topic itself is not removed by rollback',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'forum_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'title'    => [ 'type' => 'string', 'minLength' => 1 ],
                        'content'  => [ 'type' => 'string' ],
                        'status'   => [ 'type' => 'string', 'enum' => [ 'publish', 'pending' ] ],
                    ],
                    'required'   => [ 'forum_id', 'title', 'content' ],
                ],
                'validate'     => fn (array $args): ?array => $this->require_type((int) $args['forum_id'], self::forum_type(), 'not_a_forum', 'forum'),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['forum_id'] ],
                'undoable'     => false,
                'handler'      => fn (array $args): array => $this->create_topic($args),
            ],
            'create-reply'   => [
                'mode'         => 'write',
                'capability'   => 'publish_posts',
                'objects'      => [ 'topic_id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'read' ] ],
                'description'  => 'Post a reply to a topic (content, optional reply_to for a threaded reply). Snapshots the topic\'s before-image for its counts; the new reply itself is not removed by rollback',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'topic_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'content'  => [ 'type' => 'string', 'minLength' => 1 ],
                        'reply_to' => [ 'type' => 'integer', 'minimum' => 0 ],
                    ],
                    'required'   => [ 'topic_id', 'content' ],
                ],
                'validate'     => fn (array $args): ?array => $this->require_type((int) $args['topic_id'], self::topic_type(), 'not_a_topic', 'topic'),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['topic_id'] ],
                'undoable'     => false,
                'handler'      => fn (array $args): array => $this->create_reply($args),
            ],
            'moderate-topic' => [
                'mode'         => 'write',
                'capability'   => 'edit_others_posts',
                'objects'      => [ 'topic_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'description'  => 'Moderate a topic: close (no new replies), stick (pin to the top of its forum), or spam (hide it and its replies). Snapshot-wrapped and undoable through rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'topic_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'action'   => [ 'type' => 'string', 'enum' => self::MODERATION_ACTIONS ],
                    ],
                    'required'   => [ 'topic_id', 'action' ],
                ],
                'validate'     => fn (array $args): ?array => $this->require_type((int) $args['topic_id'], self::topic_type(), 'not_a_topic', 'topic'),
                // Close and spam change the topic row; stick changes the
                // forum's _bbp_sticky_topics meta, so that is what is captured.
                'snapshot'     => static fn (array $args): array => [
                    'object_type' => 'post',
                    'object_id'   => 'stick' === $args['action'] ? self::forum_of((int) $args['topic_id']) : (int) $args['topic_id'],
                ],
                'handler'      => fn (array $args): array => $this->moderate_topic($args),
            ],
        ];
    }

    // ------------------------------------------------------------ reads

    private function list_forums(array $args): array
    {
        $q      = Readable_Posts::query([
            'post_type'      => self::forum_type(),
            'post_status'    => isset($args['status']) ? (string) $args['status'] : 'any',
            'posts_per_page' => (int) ($args['page_size'] ?? 20),
            'paged'          => (int) ($args['page'] ?? 1),
            'orderby'        => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
        ]);
        $forums = [];
        foreach ($q->posts as $post) {
            $forums[] = [
                'id'          => (int) $post->ID,
                'title'       => (string) $post->post_title,
                'status'      => (string) $post->post_status,
                'parent_id'   => (int) $post->post_parent,
                'topic_count' => (int) get_post_meta($post->ID, '_bbp_topic_count', true),
                'reply_count' => (int) get_post_meta($post->ID, '_bbp_reply_count', true),
            ];
        }
        return [ 'forums' => $forums, 'total' => (int) $q->found_posts ];
    }

    private function list_topics(array $args): array
    {
        $forum_id = (int) $args['forum_id'];
        $forum    = get_post($forum_id);
        if (! $forum || self::forum_type() !== $forum->post_type) {
            throw new Operation_Error('not_a_forum', sprintf('Post %d is not a bbPress forum.', $forum_id), [ 'forum_id' => $forum_id ]);
        }
        $status = isset($args['status']) ? [ (string) $args['status'] ] : self::TOPIC_STATUSES;
        $q      = Readable_Posts::query([
            'post_type'      => self::topic_type(),
            'post_parent'    => $forum_id,
            'post_status'    => array_values(array_intersect($status, get_post_stati())),
            'posts_per_page' => (int) ($args['page_size'] ?? 20),
            'paged'          => (int) ($args['page'] ?? 1),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        $sticky = self::sticky_ids($forum_id);
        $topics = [];
        foreach ($q->posts as $post) {
            $topics[] = $this->topic_row($post, $sticky);
        }
        return [ 'topics' => $topics, 'total' => (int) $q->found_posts ];
    }

    private function get_topic(array $args): array
    {
        $post = get_post((int) $args['topic_id']);
        if (! $post || self::topic_type() !== $post->post_type) {
            return [ 'topic' => null, 'replies' => [] ];
        }
        $topic            = $this->topic_row($post, self::sticky_ids((int) $post->post_parent));
        $topic['content'] = (string) $post->post_content;

        $q       = Readable_Posts::query([
            'post_type'      => self::reply_type(),
            'post_parent'    => (int) $post->ID,
            'post_status'    => array_values(array_intersect([ 'publish', 'spam', 'pending', 'private' ], get_post_stati())),
            'posts_per_page' => 200,
            'orderby'        => 'date',
            'order'          => 'ASC',
        ]);
        $replies = [];
        foreach ($q->posts as $reply) {
            $replies[] = [
                'id'        => (int) $reply->ID,
                'topic_id'  => (int) $reply->post_parent,
                'status'    => (string) $reply->post_status,
                'author_id' => (int) $reply->post_author,
                'date'      => (string) $reply->post_date,
                'reply_to'  => (int) get_post_meta($reply->ID, '_bbp_reply_to', true),
                'content'   => (string) $reply->post_content,
            ];
        }
        return [ 'topic' => $topic, 'replies' => $replies ];
    }

    private function topic_row(\WP_Post $post, array $sticky): array
    {
        return [
            'id'          => (int) $post->ID,
            'title'       => (string) $post->post_title,
            'status'      => (string) $post->post_status,
            'forum_id'    => (int) $post->post_parent,
            'author_id'   => (int) $post->post_author,
            'date'        => (string) $post->post_date,
            'sticky'      => in_array((int) $post->ID, $sticky, true),
            'reply_count' => (int) get_post_meta($post->ID, '_bbp_reply_count', true),
            'voice_count' => (int) get_post_meta($post->ID, '_bbp_voice_count', true),
            'last_active' => (string) get_post_meta($post->ID, '_bbp_last_active_time', true),
        ];
    }

    /** Sticky topic ids for a forum: its own list plus the site-wide super stickies. */
    private static function sticky_ids(int $forum_id): array
    {
        $own   = $forum_id > 0 ? (array) get_post_meta($forum_id, '_bbp_sticky_topics', true) : [];
        $super = (array) get_option('_bbp_super_sticky_topics', []);
        return array_values(array_unique(array_map('intval', array_merge($own, $super))));
    }

    private static function forum_of(int $topic_id): int
    {
        $meta = (int) get_post_meta($topic_id, '_bbp_forum_id', true);
        return $meta > 0 ? $meta : (int) get_post_field('post_parent', $topic_id);
    }

    /** validate() hook: refuse before any snapshot when the target is not the expected post type. */
    private function require_type(int $id, string $type, string $code, string $label): ?array
    {
        $post = get_post($id);
        if ($post && $type === $post->post_type) {
            return null;
        }
        return [
            'code'    => $code,
            'message' => sprintf('Post %d is not a bbPress %s.', $id, $label),
            'data'    => [ 'post_id' => $id ],
        ];
    }

    // ------------------------------------------------------------ writes

    private function create_topic(array $args): array
    {
        $forum_id = (int) $args['forum_id'];
        $data     = [
            'post_parent'  => $forum_id,
            'post_title'   => sanitize_text_field((string) $args['title']),
            'post_content' => wp_kses_post((string) $args['content']),
            'post_status'  => (string) ($args['status'] ?? 'publish'),
            'post_author'  => get_current_user_id(),
        ];

        if (function_exists('bbp_insert_topic')) {
            $id = bbp_insert_topic($data, [ 'forum_id' => $forum_id ]);
        } else {
            $id = wp_insert_post($data + [ 'post_type' => self::topic_type() ], true);
            if (! is_wp_error($id) && $id > 0) {
                update_post_meta($id, '_bbp_forum_id', $forum_id);
                update_post_meta($id, '_bbp_topic_id', (int) $id);
                update_post_meta($id, '_bbp_reply_count', 0);
                update_post_meta($id, '_bbp_voice_count', 1);
                update_post_meta($id, '_bbp_last_active_time', current_time('mysql'));
                update_post_meta($forum_id, '_bbp_topic_count', (int) get_post_meta($forum_id, '_bbp_topic_count', true) + 1);
            }
        }
        if (is_wp_error($id) || (int) $id <= 0) {
            throw new Operation_Refused('write_failed', is_wp_error($id) ? $id->get_error_message() : 'bbPress refused to create the topic.');
        }
        return [ 'topic_id' => (int) $id, 'forum_id' => $forum_id, 'status' => get_post_status((int) $id) ];
    }

    private function create_reply(array $args): array
    {
        $topic_id = (int) $args['topic_id'];
        $forum_id = self::forum_of($topic_id);
        $reply_to = (int) ($args['reply_to'] ?? 0);
        $data     = [
            'post_parent'  => $topic_id,
            'post_content' => wp_kses_post((string) $args['content']),
            'post_status'  => 'publish',
            'post_author'  => get_current_user_id(),
        ];

        if (function_exists('bbp_insert_reply')) {
            $id = bbp_insert_reply($data, [ 'topic_id' => $topic_id, 'forum_id' => $forum_id, 'reply_to' => $reply_to ]);
        } else {
            $id = wp_insert_post($data + [ 'post_type' => self::reply_type() ], true);
            if (! is_wp_error($id) && $id > 0) {
                update_post_meta($id, '_bbp_topic_id', $topic_id);
                update_post_meta($id, '_bbp_forum_id', $forum_id);
                update_post_meta($id, '_bbp_reply_to', $reply_to);
                update_post_meta($topic_id, '_bbp_reply_count', (int) get_post_meta($topic_id, '_bbp_reply_count', true) + 1);
                update_post_meta($topic_id, '_bbp_last_active_time', current_time('mysql'));
                if ($forum_id > 0) {
                    update_post_meta($forum_id, '_bbp_reply_count', (int) get_post_meta($forum_id, '_bbp_reply_count', true) + 1);
                }
            }
        }
        if (is_wp_error($id) || (int) $id <= 0) {
            throw new Operation_Refused('write_failed', is_wp_error($id) ? $id->get_error_message() : 'bbPress refused to create the reply.');
        }
        return [ 'reply_id' => (int) $id, 'topic_id' => $topic_id, 'forum_id' => $forum_id ];
    }

    private function moderate_topic(array $args): array
    {
        $topic_id = (int) $args['topic_id'];
        $action   = (string) $args['action'];

        switch ($action) {
            case 'close':
                if (function_exists('bbp_close_topic')) {
                    bbp_close_topic($topic_id);
                } else {
                    $this->set_status($topic_id, 'closed');
                }
                break;
            case 'spam':
                if (function_exists('bbp_spam_topic')) {
                    bbp_spam_topic($topic_id);
                } else {
                    $this->set_status($topic_id, 'spam');
                }
                break;
            case 'stick':
                $ok = function_exists('bbp_stick_topic') ? (bool) bbp_stick_topic($topic_id) : $this->stick($topic_id);
                if (! $ok) {
                    throw new Operation_Refused('write_failed', sprintf('Could not stick topic %d.', $topic_id));
                }
                break;
        }

        clean_post_cache($topic_id);
        $forum_id = self::forum_of($topic_id);
        return [
            'topic_id' => $topic_id,
            'action'   => $action,
            'status'   => (string) get_post_status($topic_id),
            'sticky'   => in_array($topic_id, self::sticky_ids($forum_id), true),
        ];
    }

    private function set_status(int $topic_id, string $status): void
    {
        $result = wp_update_post([ 'ID' => $topic_id, 'post_status' => $status ], true);
        if (is_wp_error($result)) {
            throw new Operation_Refused('write_failed', $result->get_error_message());
        }
    }

    /** Plain-WordPress stick: append the id to the forum's _bbp_sticky_topics list. */
    private function stick(int $topic_id): bool
    {
        $forum_id = self::forum_of($topic_id);
        if ($forum_id <= 0) {
            return false;
        }
        $list = array_map('intval', (array) get_post_meta($forum_id, '_bbp_sticky_topics', true));
        if (! in_array($topic_id, $list, true)) {
            $list[] = $topic_id;
        }
        update_post_meta($forum_id, '_bbp_sticky_topics', array_values($list));
        return true;
    }
}
