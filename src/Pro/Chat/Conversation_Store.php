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
 */
class Conversation_Store
{
    public const POST_TYPE = 'wpmcp_chat_convo';
    private const MESSAGES_META = '_wpmcp_chat_messages';
    private const MAX_MESSAGES = 200;

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
        ]);
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
     * trimmed past MAX_MESSAGES so a single conversation cannot grow into an
     * unbounded meta row.
     */
    public function append_message(int $post_id, int $user_id, array $message): bool
    {
        if (! $this->is_owned_by($post_id, $user_id)) {
            return false;
        }
        $role = isset($message['role']) ? (string) $message['role'] : '';
        if (! in_array($role, ['user', 'assistant', 'tool'], true)) {
            return false;
        }
        $messages   = $this->get_messages($post_id, $user_id);
        $messages[] = [
            'role'    => $role,
            'content' => isset($message['content']) ? (string) $message['content'] : '',
            'time'    => time(),
        ];
        if (count($messages) > self::MAX_MESSAGES) {
            $messages = array_slice($messages, -self::MAX_MESSAGES);
        }
        return (bool) update_post_meta($post_id, self::MESSAGES_META, $messages);
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
