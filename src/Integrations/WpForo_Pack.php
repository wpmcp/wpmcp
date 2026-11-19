<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;
use WPMCP\Safety\Plugin_Table_Rows_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * wpForo (issue #671) on the plugin-data pair, free tier, so it adds no
 * top-level tools. Ops are prefixed wpforo- and named like the bbPress ops
 * (list-forums, list-topics, get-topic, moderate-topic), so agents see one
 * forum vocabulary.
 *
 * wpForo (WPFORO_VERSION) keeps each board in tables of its own; these ops
 * work on the main board, the one every install has (includes/install-sql.php
 * in 3.2.5):
 *  - {prefix}wpforo_forums: forums and categories (is_cat), with running
 *    topic and post counts and the last post;
 *  - {prefix}wpforo_topics: one row per topic (closed, type 1 = sticky,
 *    status 1 = waiting for approval, private, guest name and email);
 *  - {prefix}wpforo_posts: the opening post (is_first_post) and replies;
 *  - {prefix}wpforo_postmeta: post fields, carrying forumid and topicid.
 *
 * Reads: wpforo-list-forums, wpforo-list-topics, wpforo-get-topic and
 * wpforo-list-replies. Guest emails are masked in the lists
 * (Privacy\Masking) and shown in full on a single topic read.
 *
 * Writes (off until a site opts in with wpmcp_enable_wpforo_write):
 * wpforo-moderate-topic (close or open) and wpforo-move-topic, through
 * wpForo's own Topics::close(), open() and move() when it is loaded (its
 * hooks rebuild both forums' counters), each snapshotted as
 * 'plugin_table_rows' images of every row it can change, so rollback puts
 * them back exactly. Posting topics and replies and deleting replies are not
 * here: wpForo spreads those over member profiles, tags, activity and
 * notifications that a row image cannot put back.
 *
 * Every op takes manage_options. Presence is filterable
 * (wpmcp_wpforo_active).
 */
final class WpForo_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads and writes wpForo's own tables live.

    public const CAPABILITY = 'manage_options';

    public const OPT_IN_FILTER = 'wpmcp_enable_wpforo_write';

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_wpforo_active', defined('WPFORO_VERSION'));
    }

    /** A main-board table: forums, topics, posts or postmeta. */
    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wpforo_' . $name;
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        if (! self::active()) {
            return Forum_Pack_Support::plugin_refusal('wpForo');
        }
        return Forum_Pack_Support::tables_presence('wpForo', [ self::table('forums'), self::table('topics'), self::table('posts') ]);
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id     = [ 'type' => 'integer', 'minimum' => 1 ];
        $topic  = [ 'type' => 'object', 'properties' => [ 'topic_id' => $id ], 'required' => [ 'topic_id' ] ];
        $paging = Forum_Pack_Support::paging_schema();
        $base   = [
            'capability' => self::CAPABILITY,
            'requires'   => static fn () => self::presence(),
        ];
        $write  = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_wpforo_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $exists = static fn (array $args): ?array => null === self::topic_row((int) $args['topic_id']) ? self::no_topic((int) $args['topic_id']) : null;

        return [
            'wpforo-list-forums'    => $base + [
                'mode'         => 'read',
                'description'  => 'List wpForo forums and categories of the main board in board order: id, title, slug, parent, is_category, topic and post counts, last post. parent_id lists one forum\'s children',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'parent_id' => [ 'type' => 'integer', 'minimum' => 0 ] ] ],
                'handler'      => static fn (array $args): array => self::list_forums($args),
            ],
            'wpforo-list-topics'    => $base + [
                'mode'         => 'read',
                'description'  => 'List wpForo topics, latest activity first: id, forum, title, author (guest emails MASKED), counts, closed, sticky, solved, private, approved. forum_id, status (approved, unapproved, any), search in titles, page_size (max 100) and offset',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'forum_id' => $id,
                        'status'   => [ 'type' => 'string', 'enum' => [ 'approved', 'unapproved', 'any' ] ],
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                    ] + $paging,
                ],
                'handler'      => static fn (array $args): array => self::list_topics($args),
            ],
            'wpforo-get-topic'      => $base + [
                'mode'         => 'read',
                'description'  => 'Read one wpForo topic with its opening post body and the author\'s email in full; list-replies pages the replies',
                'input_schema' => $topic,
                'validate'     => $exists,
                'handler'      => static fn (array $args): array => [ 'topic' => self::topic((int) $args['topic_id'], true) ],
            ],
            'wpforo-list-replies'   => $base + [
                'mode'         => 'read',
                'description'  => 'Replies of one wpForo topic in posting order (the opening post is in get-topic): id, reply_to, author (guest emails MASKED), body, dates, likes, votes, is_answer, approved',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'topic_id' => $id ] + $paging, 'required' => [ 'topic_id' ] ],
                'validate'     => $exists,
                'handler'      => static fn (array $args): array => self::list_replies($args),
            ],
            'wpforo-moderate-topic' => $write + [
                'description'  => 'Close a wpForo topic (no new replies) or open it again, through wpForo. Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'topic_id' => $id, 'action' => [ 'type' => 'string', 'enum' => [ 'close', 'open' ] ] ],
                    'required'   => [ 'topic_id', 'action' ],
                ],
                'validate'     => static fn (array $args): ?array => self::moderate_refusal($args),
                'snapshot'     => static fn (array $args): array => [
                    'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                    'object_id'   => Plugin_Table_Rows_Snapshot::key('wpforo_topic', 'topics', [ (int) $args['topic_id'] ]),
                ],
                'handler'      => static fn (array $args): array => self::moderate($args),
            ],
            'wpforo-move-topic'     => $write + [
                'description'      => 'Move a wpForo topic and its posts to another forum (not a category), through wpForo, which recounts both forums. Undo: rollback-session with the returned session_id',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [ 'topic_id' => $id, 'forum_id' => $id ],
                    'required'   => [ 'topic_id', 'forum_id' ],
                ],
                'validate'         => static fn (array $args): ?array => self::move_refusal($args),
                'self_snapshotting' => true,
                'handler'          => static fn (array $args, array $context): array => self::move($args, $context),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Rows and views
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private static function topic_row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE topicid = %d', self::table('topics'), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private static function forum_row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE forumid = %d', self::table('forums'), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function no_topic(int $id): array
    {
        return Forum_Pack_Support::refusal('topic_not_found', sprintf('wpForo has no topic %d on its main board.', $id), [ 'topic_id' => $id ]);
    }

    private static function author(array $row, bool $full): array
    {
        $user  = (int) $row['userid'];
        $email = (string) ($row['email'] ?? '');
        return [
            'author_id'    => $user,
            'author_name'  => $user > 0 ? Forum_Pack_Support::user_name($user) : (string) ($row['name'] ?? ''),
            'author_email' => '' === $email ? null : ($full ? $email : Masking::email($email)),
        ];
    }

    private static function forum_view(array $row): array
    {
        return [
            'id'             => (int) $row['forumid'],
            'title'          => (string) $row['title'],
            'slug'           => (string) $row['slug'],
            'description'    => (string) ($row['description'] ?? ''),
            'parent_id'      => (int) $row['parentid'],
            'is_category'    => 1 === (int) $row['is_cat'],
            'status'         => (int) $row['status'],
            'order'          => (int) $row['order'],
            'topics'         => (int) $row['topics'],
            'posts'          => (int) $row['posts'],
            'last_topic_id'  => (int) $row['last_topicid'],
            'last_post_id'   => (int) $row['last_postid'],
            'last_post_date' => (string) $row['last_post_date'],
        ];
    }

    private static function topic_view(array $row, bool $full): array
    {
        return [
            'id'            => (int) $row['topicid'],
            'forum_id'      => (int) $row['forumid'],
            'title'         => (string) $row['title'],
            'slug'          => (string) $row['slug'],
        ] + self::author($row, $full) + [
            'created'       => (string) $row['created'],
            'modified'      => (string) $row['modified'],
            'posts'         => (int) $row['posts'],
            'views'         => (int) $row['views'],
            'first_post_id' => (int) $row['first_postid'],
            'last_post_id'  => (int) $row['last_post'],
            'closed'        => 1 === (int) $row['closed'],
            'sticky'        => 1 === (int) $row['type'],
            'solved'        => 1 === (int) $row['solved'],
            'private'       => 1 === (int) $row['private'],
            'approved'      => 0 === (int) $row['status'],
            'tags'          => array_values(array_filter(array_map('trim', explode(',', (string) ($row['tags'] ?? ''))))),
        ];
    }

    private static function topic(int $id, bool $full): array
    {
        global $wpdb;
        $row  = (array) self::topic_row($id);
        $view = self::topic_view($row, $full);
        if ($full) {
            $view['body'] = (string) $wpdb->get_var($wpdb->prepare('SELECT body FROM %i WHERE topicid = %d AND is_first_post = 1 ORDER BY postid ASC LIMIT 1', self::table('posts'), $id));
        }
        return $view;
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    private static function list_forums(array $args): array
    {
        global $wpdb;
        if (isset($args['parent_id'])) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE parentid = %d ORDER BY `order` ASC, forumid ASC', self::table('forums'), (int) $args['parent_id']), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY `order` ASC, forumid ASC', self::table('forums')), ARRAY_A);
        }
        $forums = array_map(static fn (array $row): array => self::forum_view($row), (array) $rows);
        return [ 'forums' => $forums, 'total' => count($forums) ];
    }

    private static function list_topics(array $args): array
    {
        global $wpdb;
        $where  = [];
        $params = [];
        if (isset($args['forum_id'])) {
            $where[]  = 'forumid = %d';
            $params[] = (int) $args['forum_id'];
        }
        $status = (string) ($args['status'] ?? 'any');
        if ('any' !== $status) {
            $where[] = 'approved' === $status ? 'status = 0' : 'status = 1';
        }
        $search = trim((string) ($args['search'] ?? ''));
        if ('' !== $search) {
            $where[]  = 'title LIKE %s';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }
        $sql            = [] === $where ? '' : ' WHERE ' . implode(' AND ', $where);
        [$size, $skip] = Forum_Pack_Support::paging($args);
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql holds only fixed column conditions and placeholders.
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i' . $sql, self::table('topics'), ...$params));
        $rows  = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i' . $sql . ' ORDER BY modified DESC, topicid DESC LIMIT %d OFFSET %d', self::table('topics'), ...array_merge($params, [ $size, $skip ])), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        return [
            'topics'      => array_map(static fn (array $row): array => self::topic_view($row, false), $rows),
            'total'       => $total,
            'masked'      => true,
            'next_offset' => Forum_Pack_Support::next_offset($skip, count($rows), $total),
        ];
    }

    private static function list_replies(array $args): array
    {
        global $wpdb;
        $id             = (int) $args['topic_id'];
        [$size, $skip] = Forum_Pack_Support::paging($args);
        $total          = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE topicid = %d AND is_first_post = 0', self::table('posts'), $id));
        $rows           = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE topicid = %d AND is_first_post = 0 ORDER BY created ASC, postid ASC LIMIT %d OFFSET %d', self::table('posts'), $id, $size, $skip), ARRAY_A);
        $replies        = [];
        foreach ($rows as $row) {
            $replies[] = [
                'id'       => (int) $row['postid'],
                'reply_to' => (int) $row['parentid'],
            ] + self::author($row, false) + [
                'body'      => (string) $row['body'],
                'created'   => (string) $row['created'],
                'modified'  => (string) $row['modified'],
                'likes'     => (int) $row['likes'],
                'votes'     => (int) $row['votes'],
                'is_answer' => 1 === (int) $row['is_answer'],
                'approved'  => 0 === (int) $row['status'],
                'private'   => 1 === (int) $row['private'],
            ];
        }
        return [
            'topic_id'    => $id,
            'replies'     => $replies,
            'total'       => $total,
            'masked'      => true,
            'next_offset' => Forum_Pack_Support::next_offset($skip, count($rows), $total),
        ];
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    private static function moderate_refusal(array $args): ?array
    {
        $id  = (int) $args['topic_id'];
        $row = self::topic_row($id);
        if (null === $row) {
            return self::no_topic($id);
        }
        $closed = 1 === (int) $row['closed'];
        if ('close' === $args['action'] && $closed) {
            return Forum_Pack_Support::refusal('already_closed', sprintf('Topic %d is already closed.', $id), [ 'topic_id' => $id ]);
        }
        if ('open' === $args['action'] && ! $closed) {
            return Forum_Pack_Support::refusal('already_open', sprintf('Topic %d is already open.', $id), [ 'topic_id' => $id ]);
        }
        return null;
    }

    private static function moderate(array $args): array
    {
        global $wpdb;
        $id     = (int) $args['topic_id'];
        $close  = 'close' === $args['action'];
        $topics = self::wpforo_topics();
        if (null !== $topics) {
            $close ? $topics->close($id) : $topics->open($id);
        } elseif (false === $wpdb->update(self::table('topics'), [ 'closed' => $close ? 1 : 0 ], [ 'topicid' => $id ])) {
            throw new Operation_Refused('update_failed', sprintf('Could not change topic %d: %s', $id, $wpdb->last_error));
        }
        return [ 'topic' => self::topic($id, false) ];
    }

    private static function move_refusal(array $args): ?array
    {
        $id    = (int) $args['topic_id'];
        $to    = (int) $args['forum_id'];
        $row   = self::topic_row($id);
        $forum = self::forum_row($to);
        if (null === $row) {
            return self::no_topic($id);
        }
        if (null === $forum) {
            return Forum_Pack_Support::refusal('forum_not_found', sprintf('wpForo has no forum %d on its main board.', $to), [ 'forum_id' => $to ]);
        }
        if (1 === (int) $forum['is_cat']) {
            return Forum_Pack_Support::refusal('forum_is_category', sprintf('Forum %d is a category; topics live in its forums.', $to), [ 'forum_id' => $to ]);
        }
        if ((int) $row['forumid'] === $to) {
            return Forum_Pack_Support::refusal('same_forum', sprintf('Topic %d is already in forum %d.', $id, $to), [ 'topic_id' => $id ]);
        }
        return null;
    }

    private static function move(array $args, array $context): array
    {
        $id   = (int) $args['topic_id'];
        $to   = (int) $args['forum_id'];
        $from = (int) (self::topic_row($id)['forumid'] ?? 0);
        $meta = Plugin_Table_Rows_Snapshot::table_exists(self::table('postmeta'));
        $sets = [
            [ 'wpforo_topic', 'topics', [ $id ] ],
            [ 'wpforo_forum', 'forums', [ $from, $to ] ],
            [ 'wpforo_topic_posts', 'posts', [ $id ] ],
        ];
        if ($meta) {
            $sets[] = [ 'wpforo_topic_postmeta', 'postmeta', [ $id ] ];
        }
        $write = static function () use ($id, $to, $from, $meta): void {
            $topics = self::wpforo_topics();
            if (null !== $topics) {
                if (false === $topics->move($id, $to)) {
                    throw new Operation_Refused('move_failed', sprintf('wpForo could not move topic %d.', $id));
                }
                return;
            }
            self::move_rows($id, $to, $meta);
            self::recount($from);
            self::recount($to);
        };
        // Every image is taken before anything changes; the last one carries the write.
        $ops  = [];
        $last = count($sets) - 1;
        foreach ($sets as $i => [ $kind, $suffix, $ids ]) {
            $ops[] = Forum_Pack_Support::snapshotted($kind, $suffix, $ids, $context, $args, $i === $last ? $write : null);
        }

        return [ 'result' => [ 'topic' => self::topic($id, false) ], 'operation_ids' => $ops ];
    }

    /** wpForo's own Topics model, when wpForo is loaded. */
    private static function wpforo_topics(): ?object
    {
        if (! function_exists('WPF')) {
            return null;
        }
        $wpf = \WPF();
        return is_object($wpf) && isset($wpf->topic) && is_object($wpf->topic) && method_exists($wpf->topic, 'move') ? $wpf->topic : null;
    }

    /** The rows Topics::move() and its PostMeta hook change, for a site where wpForo's model is not loaded. */
    private static function move_rows(int $id, int $to, bool $meta): void
    {
        global $wpdb;
        $tables = [ [ 'topics', 'topicid' ], [ 'posts', 'topicid' ] ];
        if ($meta) {
            $tables[] = [ 'postmeta', 'topicid' ];
        }
        foreach ($tables as [ $name, $column ]) {
            if (false === $wpdb->update(self::table($name), [ 'forumid' => $to ], [ $column => $id ])) {
                throw new Operation_Refused('move_failed', sprintf('Could not move topic %d: %s', $id, $wpdb->last_error));
            }
        }
    }

    /** A forum's counters and last post, as Forums::rebuild_stats() and rebuild_last_infos() keep them. */
    private static function recount(int $forum): void
    {
        global $wpdb;
        $topics = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE forumid = %d AND status = 0', self::table('topics'), $forum));
        $posts  = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE forumid = %d AND status = 0', self::table('posts'), $forum));
        $last   = $wpdb->get_row($wpdb->prepare('SELECT postid, topicid, userid, created FROM %i WHERE forumid = %d AND status = 0 ORDER BY created DESC, postid DESC LIMIT 1', self::table('posts'), $forum), ARRAY_A);
        $wpdb->update(self::table('forums'), [
            'topics'         => $topics,
            'posts'          => $posts,
            'last_postid'    => (int) ($last['postid'] ?? 0),
            'last_topicid'   => (int) ($last['topicid'] ?? 0),
            'last_userid'    => (string) ($last['userid'] ?? '0'),
            'last_post_date' => (string) ($last['created'] ?? '1000-01-01 00:00:00'),
        ], [ 'forumid' => $forum ]);
    }
}
