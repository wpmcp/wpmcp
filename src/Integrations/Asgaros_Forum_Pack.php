<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Asgaros Forum (issue #671) on the plugin-data pair, free tier, so it adds
 * no top-level tools. Ops are prefixed asgaros- and named like the bbPress
 * ops (list-forums, list-topics, get-topic, create-topic, create-reply,
 * moderate-topic), so agents see one forum vocabulary.
 *
 * Asgaros Forum (class AsgarosForum) keeps its content in tables of its own
 * (includes/forum-database.php), and counts nothing ahead of time:
 *  - {prefix}forum_forums: forums (parent_id is the category term,
 *    parent_forum the forum above a subforum, forum_status normal, closed or
 *    approval);
 *  - {prefix}forum_topics: one row per topic (sticky 0, 1 in its forum, 2
 *    everywhere; closed; approved);
 *  - {prefix}forum_posts: the opening post (the topic's lowest id) and the
 *    replies, each carrying its topic (parent_id) and forum (forum_id).
 *
 * Reads: asgaros-list-forums, asgaros-list-topics, asgaros-get-topic and
 * asgaros-list-replies. Authors are WordPress users, named by display name.
 *
 * Writes (off until a site opts in with wpmcp_enable_asgaros_write), each
 * written the way Asgaros Forum's own content and moderation code writes it
 * and snapshotted as 'plugin_table_rows' images, so rollback restores
 * exactly: create-topic (the topic and its opening post, as you), create-reply,
 * moderate-topic (close, open, stick, unstick), move-topic (the topic and its
 * posts) and delete-reply (confirm:true; never the opening post). No
 * subscriber emails are sent, and a deleted reply's uploads, reactions and
 * reports are left in place, so its undo brings it back whole.
 *
 * Every op takes manage_options. Presence is filterable
 * (wpmcp_asgaros_active).
 */
final class Asgaros_Forum_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads and writes Asgaros Forum's own tables live; it has no cached read API for them.

    public const CAPABILITY = 'manage_options';

    public const OPT_IN_FILTER = 'wpmcp_enable_asgaros_write';

    private const STICKY = [ 0 => 'none', 1 => 'forum', 2 => 'global' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_asgaros_active', class_exists('AsgarosForum', false));
    }

    /** One of its tables: forums, topics or posts. */
    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'forum_' . $name;
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        if (! self::active()) {
            return Forum_Pack_Support::plugin_refusal('Asgaros Forum');
        }
        return Forum_Pack_Support::tables_presence('Asgaros Forum', [ self::table('forums'), self::table('topics'), self::table('posts') ]);
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id     = [ 'type' => 'integer', 'minimum' => 1 ];
        $text   = [ 'type' => 'string', 'minLength' => 1 ];
        $topic  = [ 'type' => 'object', 'properties' => [ 'topic_id' => $id ], 'required' => [ 'topic_id' ] ];
        $paging = Forum_Pack_Support::paging_schema();
        $base   = [
            'capability' => self::CAPABILITY,
            'requires'   => static fn () => self::presence(),
        ];
        $write  = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_asgaros_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $exists = static fn (array $args): ?array => null === self::topic_row((int) $args['topic_id']) ? self::no_topic((int) $args['topic_id']) : null;
        $row    = static fn (string $kind, string $suffix, string $arg): \Closure => static fn (array $args): array => [
            'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
            'object_id'   => Plugin_Table_Rows_Snapshot::key($kind, $suffix, [ (int) $args[ $arg ] ]),
        ];

        return [
            'asgaros-list-forums'    => $base + [
                'mode'         => 'read',
                'description'  => 'List Asgaros Forum forums in board order: id, name, slug, description, category (term id), parent forum, status (normal, closed, approval), topic and post counts',
                'input_schema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
                'handler'      => static fn (): array => self::list_forums(),
            ],
            'asgaros-list-topics'    => $base + [
                'mode'         => 'read',
                'description'  => 'List Asgaros Forum topics, latest post first: id, forum, title, author, views, sticky (none, forum, global), closed, approved, reply count, dates. forum_id, page_size (max 100) and offset',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'forum_id' => $id ] + $paging ],
                'handler'      => static fn (array $args): array => self::list_topics($args),
            ],
            'asgaros-get-topic'      => $base + [
                'mode'         => 'read',
                'description'  => 'Read one Asgaros Forum topic with its opening post body; list-replies pages the replies',
                'input_schema' => $topic,
                'validate'     => $exists,
                'handler'      => static fn (array $args): array => [ 'topic' => self::topic((int) $args['topic_id']) ],
            ],
            'asgaros-list-replies'   => $base + [
                'mode'         => 'read',
                'description'  => 'Replies of one Asgaros Forum topic in posting order (the opening post is in get-topic): id, author, body, date, last edit',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'topic_id' => $id ] + $paging, 'required' => [ 'topic_id' ] ],
                'validate'     => $exists,
                'handler'      => static fn (array $args): array => self::list_replies($args),
            ],
            'asgaros-create-topic'   => $write + [
                'description'       => 'Post a topic in an Asgaros Forum forum (title, content) as you, with its opening post; no subscriber emails are sent. Undo: rollback-session with the returned session_id',
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => [ 'forum_id' => $id, 'title' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 255 ], 'content' => $text ],
                    'required'   => [ 'forum_id', 'title', 'content' ],
                ],
                'validate'          => static fn (array $args): ?array => null === self::forum_row((int) $args['forum_id']) ? self::no_forum((int) $args['forum_id']) : null,
                'self_snapshotting' => true,
                'handler'           => static fn (array $args, array $context): array => self::create_topic($args, $context),
            ],
            'asgaros-create-reply'   => $write + [
                'description'  => 'Post a reply to an open Asgaros Forum topic (content) as you; no subscriber emails are sent. Undo: rollback-operation removes it',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'topic_id' => $id, 'content' => $text ], 'required' => [ 'topic_id', 'content' ] ],
                'validate'     => static fn (array $args): ?array => self::reply_refusal((int) $args['topic_id']),
                'snapshot'     => static fn (array $args): array => [
                    'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                    'object_id'   => Plugin_Table_Rows_Snapshot::key('asgaros_post', 'posts', [ Forum_Pack_Support::next_id(self::table('posts')) ]),
                ],
                'handler'      => static fn (array $args, array $context): array => self::create_reply($args, $context),
            ],
            'asgaros-moderate-topic' => $write + [
                'description'  => 'Moderate an Asgaros Forum topic: close (no new replies), open, stick (to the top of its forum) or unstick. Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'topic_id' => $id, 'action' => [ 'type' => 'string', 'enum' => [ 'close', 'open', 'stick', 'unstick' ] ] ],
                    'required'   => [ 'topic_id', 'action' ],
                ],
                'validate'     => static fn (array $args): ?array => self::moderate_refusal($args),
                'snapshot'     => $row('asgaros_topic', 'topics', 'topic_id'),
                'handler'      => static fn (array $args): array => self::moderate($args),
            ],
            'asgaros-move-topic'     => $write + [
                'description'       => 'Move an Asgaros Forum topic and its posts to another forum. Undo: rollback-session with the returned session_id',
                'input_schema'      => [ 'type' => 'object', 'properties' => [ 'topic_id' => $id, 'forum_id' => $id ], 'required' => [ 'topic_id', 'forum_id' ] ],
                'validate'          => static fn (array $args): ?array => self::move_refusal($args),
                'self_snapshotting' => true,
                'handler'           => static fn (array $args, array $context): array => self::move($args, $context),
            ],
            'asgaros-delete-reply'   => [ 'mode' => 'destructive' ] + $write + [
                'description'  => 'Delete one reply of an Asgaros Forum topic (never the opening post); needs confirm:true. Its uploads, reactions and reports stay. Undo: rollback-operation brings it back',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'reply_id' => $id ], 'required' => [ 'reply_id' ] ],
                'validate'     => static fn (array $args): ?array => self::delete_refusal((int) $args['reply_id']),
                'snapshot'     => $row('asgaros_post', 'posts', 'reply_id'),
                'handler'      => static fn (array $args): array => self::delete_reply((int) $args['reply_id']),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Rows and views
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private static function row(string $name, int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table($name), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function topic_row(int $id): ?array
    {
        return self::row('topics', $id);
    }

    private static function forum_row(int $id): ?array
    {
        return self::row('forums', $id);
    }

    private static function no_topic(int $id): array
    {
        return Forum_Pack_Support::refusal('topic_not_found', sprintf('Asgaros Forum has no topic %d.', $id), [ 'topic_id' => $id ]);
    }

    private static function no_forum(int $id): array
    {
        return Forum_Pack_Support::refusal('forum_not_found', sprintf('Asgaros Forum has no forum %d.', $id), [ 'forum_id' => $id ]);
    }

    /** The id of a topic's opening post, or 0. */
    private static function first_post_id(int $topic): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT MIN(id) FROM %i WHERE parent_id = %d', self::table('posts'), $topic));
    }

    private static function topic_view(array $row): array
    {
        global $wpdb;
        $id    = (int) $row['id'];
        $stats = (array) $wpdb->get_row($wpdb->prepare('SELECT COUNT(*) AS n, MIN(date) AS created, MAX(date) AS last FROM %i WHERE parent_id = %d', self::table('posts'), $id), ARRAY_A);
        $count = (int) ($stats['n'] ?? 0);
        return [
            'id'             => $id,
            'forum_id'       => (int) $row['parent_id'],
            'title'          => (string) $row['name'],
            'slug'           => (string) $row['slug'],
            'author_id'      => (int) $row['author_id'],
            'author_name'    => Forum_Pack_Support::user_name((int) $row['author_id']),
            'views'          => (int) $row['views'],
            'sticky'         => self::STICKY[ (int) $row['sticky'] ] ?? 'none',
            'closed'         => 1 === (int) $row['closed'],
            'approved'       => 1 === (int) $row['approved'],
            'replies'        => max(0, $count - 1),
            'created'        => (string) ($stats['created'] ?? ''),
            'last_post_date' => (string) ($stats['last'] ?? ''),
        ];
    }

    private static function topic(int $id): array
    {
        $view                  = self::topic_view((array) self::topic_row($id));
        $first                 = self::first_post_id($id);
        $view['first_post_id'] = $first;
        $view['body']          = (string) (self::row('posts', $first)['text'] ?? '');
        return $view;
    }

    private static function post_view(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'topic_id'    => (int) $row['parent_id'],
            'forum_id'    => (int) $row['forum_id'],
            'author_id'   => (int) $row['author_id'],
            'author_name' => Forum_Pack_Support::user_name((int) $row['author_id']),
            'body'        => (string) $row['text'],
            'date'        => (string) $row['date'],
            'date_edit'   => (string) $row['date_edit'],
        ];
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    private static function list_forums(): array
    {
        global $wpdb;
        $rows   = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY sort ASC, id ASC', self::table('forums')), ARRAY_A);
        $forums = [];
        foreach ($rows as $row) {
            $id       = (int) $row['id'];
            $forums[] = [
                'id'              => $id,
                'name'            => (string) $row['name'],
                'slug'            => (string) $row['slug'],
                'description'     => (string) $row['description'],
                'category_id'     => (int) $row['parent_id'],
                'parent_forum_id' => (int) $row['parent_forum'],
                'status'          => (string) $row['forum_status'],
                'sort'            => (int) $row['sort'],
                'topics'          => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE parent_id = %d', self::table('topics'), $id)),
                'posts'           => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE forum_id = %d', self::table('posts'), $id)),
            ];
        }
        return [ 'forums' => $forums, 'total' => count($forums) ];
    }

    private static function list_topics(array $args): array
    {
        global $wpdb;
        [$size, $skip] = Forum_Pack_Support::paging($args);
        $where          = isset($args['forum_id']) ? $wpdb->prepare(' WHERE t.parent_id = %d', (int) $args['forum_id']) : '';
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $where is prepared above.
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i t' . $where, self::table('topics')));
        $rows  = (array) $wpdb->get_results($wpdb->prepare('SELECT t.* FROM %i t LEFT JOIN (SELECT parent_id, MAX(date) AS last FROM %i GROUP BY parent_id) p ON p.parent_id = t.id' . $where . ' ORDER BY p.last DESC, t.id DESC LIMIT %d OFFSET %d', self::table('topics'), self::table('posts'), $size, $skip), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        return [
            'topics'      => array_map(static fn (array $row): array => self::topic_view($row), $rows),
            'total'       => $total,
            'next_offset' => Forum_Pack_Support::next_offset($skip, count($rows), $total),
        ];
    }

    private static function list_replies(array $args): array
    {
        global $wpdb;
        $id             = (int) $args['topic_id'];
        $first          = self::first_post_id($id);
        [$size, $skip] = Forum_Pack_Support::paging($args);
        $total          = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE parent_id = %d AND id <> %d', self::table('posts'), $id, $first));
        $rows           = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE parent_id = %d AND id <> %d ORDER BY id ASC LIMIT %d OFFSET %d', self::table('posts'), $id, $first, $size, $skip), ARRAY_A);
        return [
            'topic_id'    => $id,
            'replies'     => array_map(static fn (array $row): array => self::post_view($row), $rows),
            'total'       => $total,
            'next_offset' => Forum_Pack_Support::next_offset($skip, count($rows), $total),
        ];
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /** A slug no other topic holds, the way Asgaros Forum's rewrite code makes one. */
    private static function unique_slug(string $title): string
    {
        global $wpdb;
        $base = sanitize_title($title);
        if ('' === $base || is_numeric($base)) {
            $base = 'topic' . ('' === $base ? '' : '-' . $base);
        }
        $slug = $base;
        for ($n = 2; null !== $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s LIMIT 1', self::table('topics'), $slug)); $n++) {
            $slug = $base . '-' . $n;
        }
        return $slug;
    }

    /** Insert one post row under its reserved id; throws on failure. */
    private static function insert_post(int $id, int $topic, int $forum, string $text): void
    {
        global $wpdb;
        $ok = $wpdb->insert(self::table('posts'), [
            'id'        => $id,
            'text'      => $text,
            'parent_id' => $topic,
            'forum_id'  => $forum,
            'date'      => current_time('mysql'),
            'author_id' => get_current_user_id(),
            'uploads'   => maybe_serialize([]),
        ]);
        if (false === $ok) {
            throw new Operation_Refused('insert_failed', 'Could not store the post: ' . $wpdb->last_error);
        }
    }

    private static function create_topic(array $args, array $context): array
    {
        global $wpdb;
        $forum = (int) $args['forum_id'];
        $topic = Forum_Pack_Support::next_id(self::table('topics'));
        $post  = Forum_Pack_Support::next_id(self::table('posts'));
        $title = sanitize_text_field((string) $args['title']);
        $text  = wp_kses_post((string) $args['content']);
        $ops   = [ Forum_Pack_Support::snapshotted('asgaros_topic', 'topics', [ $topic ], $context, $args) ];
        $ops[] = Forum_Pack_Support::snapshotted('asgaros_post', 'posts', [ $post ], $context, $args, static function () use ($wpdb, $forum, $topic, $post, $title, $text): void {
            $ok = $wpdb->insert(self::table('topics'), [
                'id'        => $topic,
                'name'      => $title,
                'parent_id' => $forum,
                'slug'      => self::unique_slug($title),
                'approved'  => 1,
                'author_id' => get_current_user_id(),
            ]);
            if (false === $ok) {
                throw new Operation_Refused('insert_failed', 'Could not store the topic: ' . $wpdb->last_error);
            }
            self::insert_post($post, $topic, $forum, $text);
        });
        return [ 'result' => [ 'topic' => self::topic($topic) ], 'operation_ids' => $ops ];
    }

    private static function reply_refusal(int $topic): ?array
    {
        $row = self::topic_row($topic);
        if (null === $row) {
            return self::no_topic($topic);
        }
        if (1 === (int) $row['closed']) {
            return Forum_Pack_Support::refusal('topic_closed', sprintf('Topic %d is closed. Open it with asgaros-moderate-topic first.', $topic), [ 'topic_id' => $topic ]);
        }
        return null;
    }

    private static function create_reply(array $args, array $context): array
    {
        $parsed = Plugin_Table_Rows_Snapshot::parse((string) $context['target']['object_id']);
        $id     = (int) ($parsed['ids'][0] ?? 0);
        $topic  = (int) $args['topic_id'];
        try {
            self::insert_post($id, $topic, (int) (self::topic_row($topic)['parent_id'] ?? 0), wp_kses_post((string) $args['content']));
        } catch (Operation_Refused $e) {
            // Nothing was written, but the snapshot is persisted; left in place, its undo would remove whatever row later takes that id.
            if ('' !== (string) ($context['operation_id'] ?? '')) {
                Snapshot_Store::delete_operation((string) $context['operation_id']);
            }
            throw $e;
        }
        return [ 'reply' => self::post_view((array) self::row('posts', $id)) ];
    }

    private static function moderate_refusal(array $args): ?array
    {
        $id  = (int) $args['topic_id'];
        $row = self::topic_row($id);
        if (null === $row) {
            return self::no_topic($id);
        }
        $state = [
            'close'   => [ 1 === (int) $row['closed'], 'already_closed', 'already closed' ],
            'open'    => [ 0 === (int) $row['closed'], 'already_open', 'already open' ],
            'stick'   => [ 0 !== (int) $row['sticky'], 'already_sticky', 'already sticky' ],
            'unstick' => [ 0 === (int) $row['sticky'], 'not_sticky', 'not sticky' ],
        ][ (string) $args['action'] ];
        return $state[0] ? Forum_Pack_Support::refusal($state[1], sprintf('Topic %d is %s.', $id, $state[2]), [ 'topic_id' => $id ]) : null;
    }

    private static function moderate(array $args): array
    {
        global $wpdb;
        $id  = (int) $args['topic_id'];
        $set = [
            'close'   => [ 'closed' => 1 ],
            'open'    => [ 'closed' => 0 ],
            'stick'   => [ 'sticky' => 1 ],
            'unstick' => [ 'sticky' => 0 ],
        ][ (string) $args['action'] ];
        if (false === $wpdb->update(self::table('topics'), $set, [ 'id' => $id ])) {
            throw new Operation_Refused('update_failed', sprintf('Could not change topic %d: %s', $id, $wpdb->last_error));
        }
        return [ 'topic' => self::topic_view((array) self::topic_row($id)) ];
    }

    private static function move_refusal(array $args): ?array
    {
        $id  = (int) $args['topic_id'];
        $to  = (int) $args['forum_id'];
        $row = self::topic_row($id);
        if (null === $row) {
            return self::no_topic($id);
        }
        if (null === self::forum_row($to)) {
            return self::no_forum($to);
        }
        if ((int) $row['parent_id'] === $to) {
            return Forum_Pack_Support::refusal('same_forum', sprintf('Topic %d is already in forum %d.', $id, $to), [ 'topic_id' => $id ]);
        }
        return null;
    }

    private static function move(array $args, array $context): array
    {
        global $wpdb;
        $id    = (int) $args['topic_id'];
        $to    = (int) $args['forum_id'];
        $ops   = [ Forum_Pack_Support::snapshotted('asgaros_topic', 'topics', [ $id ], $context, $args) ];
        $ops[] = Forum_Pack_Support::snapshotted('asgaros_topic_posts', 'posts', [ $id ], $context, $args, static function () use ($wpdb, $id, $to): void {
            // As AsgarosForum::moveTopic() does: the topic, then every post in it.
            $moved = false !== $wpdb->update(self::table('topics'), [ 'parent_id' => $to ], [ 'id' => $id ]);
            if (! $moved || false === $wpdb->update(self::table('posts'), [ 'forum_id' => $to ], [ 'parent_id' => $id ])) {
                throw new Operation_Refused('move_failed', sprintf('Could not move topic %d: %s', $id, $wpdb->last_error));
            }
        });
        return [ 'result' => [ 'topic' => self::topic_view((array) self::topic_row($id)) ], 'operation_ids' => $ops ];
    }

    private static function delete_refusal(int $id): ?array
    {
        $row = self::row('posts', $id);
        if (null === $row) {
            return Forum_Pack_Support::refusal('reply_not_found', sprintf('Asgaros Forum has no post %d.', $id), [ 'reply_id' => $id ]);
        }
        if (self::first_post_id((int) $row['parent_id']) === $id) {
            return Forum_Pack_Support::refusal('first_post', sprintf('Post %d opens topic %d; it is not a reply and is not deleted on its own.', $id, (int) $row['parent_id']), [ 'reply_id' => $id ]);
        }
        return null;
    }

    private static function delete_reply(int $id): array
    {
        global $wpdb;
        $row = (array) self::row('posts', $id);
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Asgaros Forum's own hook, run so its extensions see the delete.
        do_action('asgarosforum_before_delete_post', $id);
        if (false === $wpdb->delete(self::table('posts'), [ 'id' => $id ])) {
            throw new Operation_Refused('delete_failed', sprintf('Could not delete post %d: %s', $id, $wpdb->last_error));
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- same.
        do_action('asgarosforum_after_delete_post', $id);
        return [ 'deleted' => true, 'reply_id' => $id, 'topic_id' => (int) ($row['parent_id'] ?? 0) ];
    }
}
