<?php

namespace WPMCP\Pro\Chat;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Private-CPT conversation store for the in-admin chat (issue #73).
 *
 * Conversations are stored as a fully private custom post type: never
 * publicly queryable, no REST exposure of its own (the chat REST controller
 * is the only read/write path), and scoped per user via post_author so one
 * admin cannot read another admin's provider conversations.
 *
 * That per-user invariant is why the CPT declares delete_with_user: deleting
 * a user with "attribute all content to" would otherwise hand that user's
 * provider exchanges to whichever admin the content was reassigned to.
 */
class Conversation_Store
{
    public const POST_TYPE = 'wpmcp_chat_convo';
    private const MESSAGES_META = '_wpmcp_chat_messages';
    private const MAX_MESSAGES = 200;

    /**
     * Byte ceiling on the serialized history. MAX_MESSAGES bounds the count
     * but not the size, and the whole array is read, appended to and written
     * back on every turn, so without a byte cap one caller can grow a single
     * meta row without limit.
     */
    private const MAX_HISTORY_BYTES = 262144;

    /** Whether the most recent append_message() dropped older turns. */
    private bool $last_trimmed = false;

    /**
     * Registers the private CPT. Hooked on init; safe to call repeatedly.
     */
    public static function register_post_type(): void
    {
        if (! function_exists('register_post_type')) {
            return;
        }
        register_post_type(self::POST_TYPE, [
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => false,
            'show_in_rest'        => false,
            'show_in_menu'        => false,
            'show_in_nav_menus'   => false,
            'rewrite'             => false,
            'query_var'           => false,
            'supports'            => ['title', 'author'],
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            // Conversations die with their owner. They are never reassigned
            // to another user, whatever the user-deletion screen offers.
            'delete_with_user'    => true,
        ]);
    }

    /**
     * Force-deletes every conversation owned by a user being deleted.
     *
     * delete_with_user already covers the standard path, but it runs against
     * the registered post type, and this store must hold even when the CPT
     * was not registered on the request that deletes the user (the chat is a
     * gated feature whose registration can be off). Hooked on wp_delete_user.
     */
    public static function purge_for_user(int $user_id): void
    {
        $ids = get_posts([
            'post_type'        => self::POST_TYPE,
            'author'           => $user_id,
            'post_status'      => 'any',
            'numberposts'      => -1,
            'fields'           => 'ids',
            'suppress_filters' => true,
        ]);
        foreach ((array) $ids as $id) {
            wp_delete_post((int) $id, true);
        }
    }

    /**
     * Creates a new conversation owned by $user_id and returns its post ID,
     * or 0 on failure.
     */
    public function create(int $user_id, string $title = ''): int
    {
        $post_id = wp_insert_post([
            'post_type'   => self::POST_TYPE,
            'post_status' => 'private',
            'post_author' => $user_id,
            'post_title'  => $title !== '' ? $title : 'Chat ' . gmdate('Y-m-d H:i:s'),
        ], true);

        if (is_wp_error($post_id) || ! is_int($post_id)) {
            return 0;
        }
        update_post_meta($post_id, self::MESSAGES_META, []);
        return $post_id;
    }

    /**
     * Returns true only when $post_id is a chat conversation owned by
     * $user_id. Every read/append path MUST pass through this check; there
     * is deliberately no admin-wide override, because a conversation can
     * contain another admin's provider exchange.
     */
    public function is_owned_by(int $post_id, int $user_id): bool
    {
        $post = get_post($post_id);
        return $post instanceof \WP_Post
            && $post->post_type === self::POST_TYPE
            && (int) $post->post_author === $user_id;
    }

    /**
     * Appends one message ['role' => ..., 'content' => ...] to the
     * conversation. Fails closed on ownership mismatch. Oldest messages are
     * trimmed past MAX_MESSAGES and past MAX_HISTORY_BYTES so a single
     * conversation cannot grow into an unbounded meta row.
     *
     * An optional 'client_id' makes the append idempotent: a client retrying
     * a request whose response it never saw re-sends the same id and the
     * message is not stored twice.
     *
     * The value is wp_slash()ed on the way in because update_metadata() runs
     * wp_unslash() on whatever it is given. Without it every backslash in the
     * history (code snippets, Windows paths, regexes, tool-call arguments)
     * loses one level on every single append, since each append rewrites the
     * whole array.
     */
    public function append_message(int $post_id, int $user_id, array $message): bool
    {
        $this->last_trimmed = false;

        if (! $this->is_owned_by($post_id, $user_id)) {
            return false;
        }
        $role = isset($message['role']) ? (string) $message['role'] : '';
        if (! in_array($role, ['user', 'assistant', 'tool'], true)) {
            return false;
        }

        $messages  = $this->get_messages($post_id, $user_id);
        $client_id = isset($message['client_id']) ? (string) $message['client_id'] : '';
        if ($client_id !== '') {
            foreach ($messages as $existing) {
                if (isset($existing['client_id']) && (string) $existing['client_id'] === $client_id) {
                    return true; // Already stored: a retry, not a new turn.
                }
            }
        }

        $entry = [
            'role'    => $role,
            'content' => isset($message['content']) ? (string) $message['content'] : '',
            'time'    => time(),
        ];
        if ($client_id !== '') {
            $entry['client_id'] = $client_id;
        }
        $messages[] = $entry;

        if (count($messages) > self::MAX_MESSAGES) {
            $messages           = array_slice($messages, -self::MAX_MESSAGES);
            $this->last_trimmed = true;
        }
        while (count($messages) > 1 && strlen(serialize($messages)) > self::MAX_HISTORY_BYTES) {
            array_shift($messages);
            $this->last_trimmed = true;
        }

        return (bool) update_post_meta($post_id, self::MESSAGES_META, wp_slash($messages));
    }

    /**
     * Whether the last append_message() call dropped older turns from the
     * history, so the caller can say so instead of letting the user find out
     * from the model's memory.
     */
    public function last_append_trimmed(): bool
    {
        return $this->last_trimmed;
    }

    /**
     * Returns the message list, empty on ownership mismatch or no data.
     */
    public function get_messages(int $post_id, int $user_id): array
    {
        if (! $this->is_owned_by($post_id, $user_id)) {
            return [];
        }
        $messages = get_post_meta($post_id, self::MESSAGES_META, true);
        return is_array($messages) ? $messages : [];
    }
}
