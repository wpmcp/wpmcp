<?php

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Refuses post writes while another user is editing the post (issue #452).
 *
 * WordPress records an open editor with a post lock (_edit_lock, refreshed
 * by the editor's heartbeat and read by wp_check_post_lock()). A person who
 * opens a post someone else is editing is warned; a tool was not, so it
 * could overwrite their unsaved work or have its own change lost when they
 * saved. The check runs in Safe_Mutation::run(), the path every post write
 * takes, before the snapshot is taken: it sees the post a write actually
 * touches (including ones a tool finds at run time, such as the matches of
 * a find and replace or the original a stage is published over), and a
 * preview or dry run, which never reaches Safe_Mutation, is never blocked.
 *
 * Only another user's live lock refuses. The caller's own lock does not
 * (wp_check_post_lock() ignores the current user's), and neither does a
 * lock older than core's window (wp_check_post_lock_window, 150 seconds).
 *
 * Elementor 4.3 and later: for a document built with Elementor, the
 * elementor/mcp/pre_execute_guard filter is asked as well (Elementor refuses
 * while another user has the document open with unsaved changes), and after
 * a successful write Elementor's changed-by-MCP marker is set, which the open
 * editor's heartbeat reads to tell the person the document changed. On an
 * Elementor without them, nothing here changes.
 *
 * Undo: rollback-operation and rollback-session refuse the same way before
 * restoring anything (assert_restorable()), since a restore overwrites the
 * post just as a write does. The unwind of a write that failed inside the
 * same call is internal and never refused.
 *
 * The wpmcp_respect_edit_locks filter turns all of this off.
 */
class Edit_Lock
{
    private const ELEMENTOR_SYNC = '\\Elementor\\Modules\\Mcp\\Utils\\Editor_Sync_State';

    /** Whether writes respect edit locks for this post. */
    public static function enabled(int $post_id): bool
    {
        /**
         * Filters whether wpmcp refuses to write a post another user is
         * editing (issue #452). Return false to write regardless, as
         * versions before the check did.
         *
         * @param bool $respect Default true.
         * @param int  $post_id The post about to be written.
         */
        return (bool) apply_filters('wpmcp_respect_edit_locks', true, $post_id);
    }

    /**
     * Why the current user may not write this post right now, or null. A
     * revision is judged by the post it belongs to.
     */
    public static function refusal(int $post_id): ?\WP_Error
    {
        $post_id = self::subject($post_id);
        if ($post_id <= 0 || ! self::enabled($post_id)) {
            return null;
        }

        $lock      = self::lock_refusal($post_id);
        $elementor = self::is_elementor_document($post_id) ? self::elementor_refusal($post_id) : null;
        if (null === $lock && null === $elementor) {
            return null;
        }

        $message = null !== $lock ? $lock['message'] : sprintf('Post %d cannot be written right now.', $post_id);
        $data    = null !== $lock ? $lock['data'] : ['post_id' => $post_id];
        if (null !== $elementor) {
            $message        .= ' Elementor: ' . $elementor->get_error_message();
            $data['elementor'] = (string) $elementor->get_error_code();
        }
        $data['status'] = 409;

        return new \WP_Error('wpmcp_post_locked', $message . ' Nothing was written.', $data);
    }

    /**
     * Throws when refusal() refuses.
     *
     * @throws Post_Locked
     */
    public static function assert_writable(int $post_id): void
    {
        $error = self::refusal($post_id);
        if (null !== $error) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Post_Locked escapes the message it is given.
            throw new Post_Locked($error);
        }
    }

    /**
     * Refuses an undo that would restore a post another user is editing,
     * before anything is restored.
     *
     * @param array<int, array<string, mixed>> $rows Snapshot_Store rows.
     * @throws Post_Locked
     */
    public static function assert_restorable(array $rows): void
    {
        foreach ($rows as $row) {
            foreach (self::restored_post_ids($row) as $post_id) {
                $error = self::refusal($post_id);
                if (null !== $error) {
                    $refused = new \WP_Error(
                        'wpmcp_post_locked',
                        str_replace('Nothing was written.', 'Nothing was restored; undo again once they close it.', $error->get_error_message()),
                        ['operation_id' => (string) ($row['operation_id'] ?? '')] + (array) $error->get_error_data()
                    );
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Post_Locked escapes the message it is given.
                    throw new Post_Locked($refused);
                }
            }
        }
    }

    /**
     * After a successful write: tell an open Elementor editor the document
     * changed underneath it.
     */
    public static function note_written(int $post_id): void
    {
        $post_id = self::subject($post_id);
        if ($post_id <= 0 || ! self::is_elementor_document($post_id)) {
            return;
        }
        $sync = self::ELEMENTOR_SYNC;
        if (class_exists($sync) && method_exists($sync, 'set_mcp_mutation')) {
            $sync::set_mcp_mutation($post_id);
        }
    }

    /**
     * Another user's live lock, as a message and error data, or null.
     *
     * @return array{message: string, data: array<string, mixed>}|null
     */
    private static function lock_refusal(int $post_id): ?array
    {
        if (! function_exists('wp_check_post_lock')) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }
        $holder = (int) wp_check_post_lock($post_id);
        if ($holder <= 0) {
            return null;
        }

        $lock = explode(':', (string) get_post_meta($post_id, '_edit_lock', true));
        $time = (int) ($lock[0] ?? 0);
        $user = get_userdata($holder);
        $name = $user instanceof \WP_User ? (string) $user->display_name : '';

        return [
            'message' => sprintf(
                'Post %1$d is open in the editor by %2$s (user %3$d), whose edit lock was last refreshed %4$s UTC (%5$d seconds ago).',
                $post_id,
                '' !== $name ? $name : 'another user',
                $holder,
                gmdate('Y-m-d H:i:s', $time),
                max(0, time() - $time)
            ),
            'data'    => [
                'post_id'     => $post_id,
                'locked_by'   => $holder,
                'locked_name' => $name,
                'locked_at'   => gmdate('c', $time),
            ],
        ];
    }

    /** Elementor's own refusal for this document, when Elementor offers one. */
    private static function elementor_refusal(int $post_id): ?\WP_Error
    {
        if (! has_filter('elementor/mcp/pre_execute_guard')) {
            return null;
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Elementor's own hook, applied as Elementor applies it to its MCP writes.
        $guard = apply_filters('elementor/mcp/pre_execute_guard', null, ['post_id' => $post_id]);
        return $guard instanceof \WP_Error ? $guard : null;
    }

    private static function is_elementor_document(int $post_id): bool
    {
        return 'builder' === get_post_meta($post_id, '_elementor_edit_mode', true)
            || '' !== (string) get_post_meta($post_id, '_elementor_data', true);
    }

    /** The post a write to $post_id changes: a revision's parent, or the post itself. */
    private static function subject(int $post_id): int
    {
        if ($post_id <= 0) {
            return 0;
        }
        $post = get_post($post_id);
        if (! $post instanceof \WP_Post) {
            return 0;
        }
        if ('revision' === $post->post_type && $post->post_parent > 0) {
            return (int) $post->post_parent;
        }
        return $post_id;
    }

    /**
     * The existing posts restoring a snapshot row would write or remove.
     *
     * @param array<string, mixed> $row
     * @return int[]
     */
    private static function restored_post_ids(array $row): array
    {
        $type = (string) ($row['object_type'] ?? '');
        if (in_array($type, ['post', 'page_build', 'media_import'], true)) {
            return [(int) ($row['object_id'] ?? 0)];
        }
        if (Post_Creation_Snapshot::OBJECT_TYPE === $type) {
            try {
                $snapshot = isset($row['snapshot']) && is_array($row['snapshot'])
                    ? $row['snapshot']
                    : Snapshot::unserialize((string) ($row['before_blob'] ?? ''));
            } catch (\RuntimeException $e) {
                return [];
            }
            return array_map('intval', Post_Creation_Snapshot::post_ids($snapshot));
        }
        return [];
    }
}
