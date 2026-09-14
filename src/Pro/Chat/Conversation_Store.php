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
 * That per-user invariant needs three separate things, because each one
 * covers a hole the others do not:
 *
 * 1. Every capability is narrowed to manage_options. The default
 *    capability_type 'post' would hand every Editor read_private_posts /
 *    edit_others_posts / delete_others_posts over conversations, and
 *    can_export defaults to true, which puts the whole history into a
 *    Tools -> Export WXR. Ownership is enforced in this class, not by
 *    post_status: WP_Query skips its private-post permission clause on the
 *    'any' status branch, so post_status alone protects nothing.
 * 2. The type is listed as internal in Content_Guard and List_Post_Types, so
 *    the generic content tools cannot use it as a second read/write path.
 * 3. Conversations are destroyed when their owner is deleted. delete_with_user
 *    alone does NOT do this: core consults that flag only when the deletion
 *    reassigns nothing ($reassign === null). The "attribute all content to"
 *    path runs a raw UPDATE wp_posts SET post_author and would hand one
 *    admin's provider exchanges to another. register_user_deletion_hooks()
 *    is the part that actually holds, because delete_user fires before that
 *    UPDATE.
 */
class Conversation_Store
{
    public const POST_TYPE = 'wpmcp_chat_convo';
    private const MESSAGES_META = '_wpmcp_chat_messages';

    /**
     * One row per client_id seen in this conversation. Kept as its own meta
     * key rather than read out of the history because the idempotency lookup
     * has to work across a user's conversations, before we know which
     * conversation (if any) a retry belongs to.
     */
    private const CLIENT_ID_META = '_wpmcp_chat_client_id';

    private const MAX_MESSAGES = 200;

    /**
     * Byte ceiling on the serialized history, measured on the slashed array
     * that is actually written. MAX_MESSAGES bounds the count but not the
     * size, and the whole array is read, appended to and written back on
     * every turn, so without a byte cap one caller can grow a single meta row
     * without limit.
     */
    private const MAX_HISTORY_BYTES = 262144;

    /**
     * Byte ceiling on a single entry's content, enforced here rather than in
     * the REST controller: the controller's limit applies to the 'user' role
     * only, and the assistant/tool appends the executor slice will make are
     * the ones that can arrive large. Without this the trim loop cannot
     * bound the row at all, since it never drops the only remaining message.
     */
    private const MAX_ENTRY_BYTES = 65536;

    /** No error on the most recent append_message(). */
    public const APPEND_OK = '';
    /** The caller does not own the conversation, or it does not exist. */
    public const APPEND_FORBIDDEN = 'forbidden';
    /** The role is not one this store accepts. */
    public const APPEND_INVALID_ROLE = 'invalid_role';
    /** The meta write itself failed: a storage fault, not an authz result. */
    public const APPEND_WRITE_FAILED = 'write_failed';

    /** Whether the most recent append_message() dropped older turns. */
    private bool $last_trimmed = false;

    /** Why the most recent append_message() returned false; '' when it did not. */
    private string $last_error = self::APPEND_OK;

    /**
     * Registers the private CPT. Hooked on init; safe to call repeatedly.
     *
     * Deliberately NOT gated on Pro\Gate::is_pro(): a safety rule must not
     * stop applying because a license lapsed. If the type were unregistered
     * on a lapsed install, existing conversations would become ordinary
     * orphan rows that delete_with_user no longer covers and that the content
     * tools' internal-type list is the only thing still hiding.
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
            'can_export'          => false,
            'supports'            => ['title', 'author'],
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            // Every capability, not just the edit ones: an Editor holding the
            // stock 'post' caps would otherwise be able to read, list and
            // delete another admin's conversations through any generic query.
            //
            // Only PRIMITIVE caps are listed. Remapping the meta caps
            // (edit_post / read_post / delete_post) would register
            // manage_options itself as a meta capability site-wide via
            // _post_type_meta_capabilities(), and every current_user_can(
            // 'manage_options') on the site would then be mapped as an
            // unqualified delete_post check.
            'capabilities'        => [
                'edit_posts'             => 'manage_options',
                'edit_others_posts'      => 'manage_options',
                'edit_private_posts'     => 'manage_options',
                'edit_published_posts'   => 'manage_options',
                'publish_posts'          => 'manage_options',
                'read_private_posts'     => 'manage_options',
                'create_posts'           => 'manage_options',
                'delete_posts'           => 'manage_options',
                'delete_others_posts'    => 'manage_options',
                'delete_private_posts'   => 'manage_options',
                'delete_published_posts' => 'manage_options',
            ],
            // Belt to the purge hook's braces: this covers the deletion that
            // reassigns nothing. It does NOT cover the reassigning deletion,
            // which core never consults this flag for.
            'delete_with_user'    => true,
        ]);
    }

    /**
     * Wires the purge to every core path that deletes a user or detaches one
     * from a site.
     *
     * There is no 'wp_delete_user' action: wp_delete_user() is the function,
     * and it fires 'delete_user' (before it reassigns or deletes the user's
     * posts) and 'deleted_user' (after). Only the former runs early enough to
     * destroy conversations before the reassignment UPDATE claims them, so
     * that is the one this hooks.
     */
    public static function register_user_deletion_hooks(): void
    {
        // Single site and, on multisite, deletion from one site.
        add_action('delete_user', [self::class, 'purge_for_user']);
        // Multisite network-wide user deletion.
        add_action('wpmu_delete_user', [self::class, 'purge_for_user']);
        // Multisite: a user detached from this site also has their posts
        // reassigned, so the same leak applies.
        add_action('remove_user_from_blog', [self::class, 'purge_for_user']);
    }

    /**
     * Force-deletes every conversation owned by a user being deleted.
     *
     * Runs against the raw post type name rather than the registered object,
     * so it still holds on a request where the CPT was never registered.
     */
    public static function purge_for_user(int $user_id): void
    {
        if ($user_id <= 0) {
            return;
        }
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
     * Returns the id of this user's conversation that already carries
     * $client_id, or 0 when no conversation does.
     *
     * This is what makes a retry idempotent on the FIRST turn as well. A
     * client that never saw the response has no conversation_id to send back,
     * so a lookup scoped to one conversation cannot help it: without this,
     * the retry opens a second conversation and stores the message again.
     */
    public function find_by_client_id(int $user_id, string $client_id): int
    {
        if ($user_id <= 0 || $client_id === '') {
            return 0;
        }
        $ids = get_posts([
            'post_type'        => self::POST_TYPE,
            'author'           => $user_id,
            'post_status'      => 'any',
            'numberposts'      => 1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'meta_key'         => self::CLIENT_ID_META,
            'meta_value'       => $client_id,
        ]);
        return isset($ids[0]) ? (int) $ids[0] : 0;
    }

    /**
     * Lists this user's conversations, newest first. Ownership is applied as
     * a query argument AND re-checked per row, so a filter that widened the
     * query cannot widen the result.
     *
     * @return array<int, array{id:int,title:string,modified:string,messages:int}>
     */
    public function list_for_user(int $user_id, int $limit = 50): array
    {
        if ($user_id <= 0) {
            return [];
        }
        $ids = get_posts([
            'post_type'        => self::POST_TYPE,
            'author'           => $user_id,
            'post_status'      => 'any',
            'numberposts'      => max(1, min(100, $limit)),
            'orderby'          => 'modified',
            'order'            => 'DESC',
            'fields'           => 'ids',
            'suppress_filters' => true,
        ]);

        $rows = [];
        foreach ((array) $ids as $id) {
            $id = (int) $id;
            if (! $this->is_owned_by($id, $user_id)) {
                continue;
            }
            $post   = get_post($id);
            $rows[] = [
                'id'       => $id,
                'title'    => (string) $post->post_title,
                'modified' => (string) $post->post_modified_gmt,
                'messages' => count($this->get_messages($id, $user_id)),
            ];
        }
        return $rows;
    }

    /** Force-deletes one conversation, but only for its owner. */
    public function delete(int $post_id, int $user_id): bool
    {
        if (! $this->is_owned_by($post_id, $user_id)) {
            return false;
        }
        return (bool) wp_delete_post($post_id, true);
    }

    /**
     * Appends one message ['role' => ..., 'content' => ...] to the
     * conversation. Fails closed on ownership mismatch. Oldest messages are
     * trimmed past MAX_MESSAGES and past MAX_HISTORY_BYTES, and one entry's
     * content is capped at MAX_ENTRY_BYTES, so a single conversation cannot
     * grow into an unbounded meta row.
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
     *
     * On false, last_append_error() says which of the three unrelated
     * failures happened, so the caller can tell a storage fault from an
     * authorization result instead of reporting both as a missing
     * conversation.
     */
    public function append_message(int $post_id, int $user_id, array $message): bool
    {
        $was_trimmed        = $this->last_trimmed;
        $this->last_trimmed = false;
        $this->last_error   = self::APPEND_OK;

        if (! $this->is_owned_by($post_id, $user_id)) {
            $this->last_error = self::APPEND_FORBIDDEN;
            return false;
        }
        $role = isset($message['role']) ? (string) $message['role'] : '';
        if (! in_array($role, ['user', 'assistant', 'tool'], true)) {
            $this->last_error = self::APPEND_INVALID_ROLE;
            return false;
        }

        $messages  = $this->get_messages($post_id, $user_id);
        $client_id = isset($message['client_id']) ? (string) $message['client_id'] : '';
        if ($client_id !== '') {
            foreach ($messages as $existing) {
                if (isset($existing['client_id']) && (string) $existing['client_id'] === $client_id) {
                    // Already stored: a retry, not a new turn. The trim flag
                    // keeps the value the original append produced, so a
                    // retried request does not report a trim that happened as
                    // if it had not.
                    $this->last_trimmed = $was_trimmed;
                    return true;
                }
            }
        }

        $content = isset($message['content']) ? (string) $message['content'] : '';
        if (strlen($content) > self::MAX_ENTRY_BYTES) {
            // mb_strcut, not substr: a byte-offset cut lands mid-character on
            // any multibyte content and hands wpdb a string that is not valid
            // UTF-8, which fails the write outright rather than shortening it.
            $content = mb_strcut($content, 0, self::MAX_ENTRY_BYTES, 'UTF-8') . "\n[truncated]";
        }

        $entry = [
            'role'    => $role,
            'content' => $content,
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

        // Sized on the slashed entries, because slashed is what gets written:
        // a history full of backslashes and quotes serializes to roughly
        // twice the unslashed size, which is exactly the code-snippet case
        // the slashing exists for. Sizes are summed once and decremented as
        // entries are dropped rather than re-serializing the whole array on
        // every iteration, which was quadratic on the long histories this cap
        // exists to handle. Per-entry sums include each entry's own array
        // envelope, so the total is an over-estimate and the cap is never
        // exceeded.
        $sizes = [];
        foreach ($messages as $entry_to_size) {
            $sizes[] = strlen(serialize(wp_slash($entry_to_size)));
        }
        $total = array_sum($sizes);
        while (count($messages) > 1 && $total > self::MAX_HISTORY_BYTES) {
            array_shift($messages);
            $total             -= (int) array_shift($sizes);
            $this->last_trimmed = true;
        }

        if (! update_post_meta($post_id, self::MESSAGES_META, wp_slash($messages))) {
            $this->last_error = self::APPEND_WRITE_FAILED;
            return false;
        }
        if ($client_id !== '') {
            add_post_meta($post_id, self::CLIENT_ID_META, $client_id);
        }
        return true;
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
     * Why the last append_message() returned false: one of the APPEND_*
     * constants, or APPEND_OK when it succeeded.
     */
    public function last_append_error(): string
    {
        return $this->last_error;
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
