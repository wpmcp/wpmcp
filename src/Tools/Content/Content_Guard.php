<?php

namespace WPMCP\Tools\Content;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shared guardrails for the content tools: which post types are writable,
 * and which meta keys are protected from direct writes.
 */
class Content_Guard
{
    /** Internal/non-writable post types, never valid targets for create/update/delete. */
    private const INTERNAL_TYPES = [
        'revision',
        'nav_menu_item',
        'custom_css',
        'customize_changeset',
        'oembed_cache',
        'user_request',
        'wp_template',
        'wp_template_part',
        'wp_global_styles',
        'wp_navigation',
        'attachment',
        // The theme-builder site parts (issue #70). Same reasoning as core's
        // wp_template / wp_template_part above: the payload is post_content
        // and it renders on every matching page, so the generic content tools
        // (edit_posts) must not reach markup whose own create tool requires
        // manage_options. The wpmcp_block / wpmcp_widget CPTs need no entry
        // here because their payload lives in protected `_`-prefixed meta,
        // which check_meta() already refuses.
        'wpmcp_template',
        // The in-admin chat conversation store (issue #73). Named as a
        // literal rather than through the class constant so this guard
        // holds even on a request where that CPT was never registered,
        // and so this list depends on nothing outside the content tools.
        // Conversations are per-user private provider exchanges between
        // one admin and their own model, never generic content.
        'wpmcp_chat_convo',
    ];

    /**
     * Post types that hold plugin-internal per-user data and are therefore
     * never content an agent may read, whatever capability the caller holds.
     *
     * Separate from INTERNAL_TYPES because that list is about writes, and
     * some of it (attachment) is legitimately readable. This list is about
     * reads: a chat conversation is one admin's private exchange with their
     * own model, and list-posts (capability edit_posts, arbitrary post_type,
     * default status 'any') would otherwise enumerate every user's.
     * WP_Query does not help here: it skips its private-post permission
     * clause on the 'any' status branch, so post_status bounds nothing.
     *
     * Named as literals: the chat store lives outside the content tools and
     * the directory build does not ship it, so this list must not depend on
     * a class constant.
     */
    private const PRIVATE_TYPES = [
        'wpmcp_chat_convo',
    ];

    /** Whether the content tools may read a post of this type at all. */
    public static function is_agent_readable_post_type(string $post_type): bool
    {
        return ! in_array($post_type, self::PRIVATE_TYPES, true);
    }

    /** Whether a post id refers to a post of a private type. */
    public static function is_private_post(int $post_id): bool
    {
        if ($post_id <= 0) {
            return false;
        }
        $type = get_post_type($post_id);
        return is_string($type) && ! self::is_agent_readable_post_type($type);
    }

    /**
     * Whether the current user may reach posts of this type through the
     * content tools (issue #446).
     *
     * The tools are gated on edit_posts, which every Contributor holds, so
     * without this any caller could list or read the records other plugins
     * keep as non-public post types (LMS enrollments, orders, form entries).
     * The rule, in order:
     *
     * - a plugin-private type (PRIVATE_TYPES) is never reachable;
     * - a public type, and attachments, are reachable exactly as before;
     * - a type that is not registered (rows left behind by a deactivated
     *   plugin) declares no capabilities at all, so only a site
     *   administrator (manage_options) may reach it;
     * - a non-public type without an admin screen (show_ui false) has no
     *   audience of its own, so it is refused below site administrator
     *   unless the `wpmcp_agent_readable_hidden_post_types` filter names it;
     * - otherwise the caller needs the type's own edit_posts capability,
     *   the same one wp-admin requires for its list screen.
     */
    public static function can_read_post_type(string $post_type): bool
    {
        if ('' === $post_type || ! self::is_agent_readable_post_type($post_type)) {
            return false;
        }

        $object = get_post_type_object($post_type);
        if (! $object instanceof \WP_Post_Type) {
            return current_user_can('manage_options');
        }
        if ($object->public || 'attachment' === $post_type) {
            return true;
        }
        if (! $object->show_ui && ! current_user_can('manage_options') && ! self::is_allowed_hidden_type($post_type)) {
            return false;
        }

        return current_user_can((string) $object->cap->edit_posts);
    }

    /**
     * Whether the current user may reach this post through the content
     * tools: can_read_post_type() for its type and, for a non-public type,
     * core's read_post meta capability for the row itself. A revision or
     * autosave is judged by the post it belongs to. A missing post is not
     * refused here: the tool answers "not found" itself.
     */
    public static function can_read_post(int $post_id): bool
    {
        if ($post_id <= 0) {
            return true;
        }
        $post = get_post($post_id);
        if (! $post instanceof \WP_Post) {
            return true;
        }
        if ('revision' === $post->post_type && $post->post_parent > 0) {
            $parent = get_post((int) $post->post_parent);
            if ($parent instanceof \WP_Post) {
                $post = $parent;
            }
        }

        $type = (string) $post->post_type;
        if (! self::can_read_post_type($type)) {
            return false;
        }
        $object = get_post_type_object($type);
        if (! $object instanceof \WP_Post_Type || $object->public || 'attachment' === $type) {
            return true;
        }
        return current_user_can('read_post', (int) $post->ID);
    }

    /**
     * Non-public types without an admin screen that the site owner has
     * opened to the content tools. Their own capabilities still apply.
     */
    private static function is_allowed_hidden_type(string $post_type): bool
    {
        /**
         * Filters the non-public post types without an admin screen that
         * the content tools may reach for users below site administrator.
         *
         * Such types are refused by default, because a type with no admin
         * screen usually stores a plugin's private records rather than
         * editorial content. Adding a type here lets callers who hold that
         * type's own capabilities (its edit_posts, and read_post for each
         * row) reach it. Plugin-private wpmcp types cannot be added.
         *
         * @param string[] $post_types Post type names. Default empty.
         */
        $allowed = apply_filters('wpmcp_agent_readable_hidden_post_types', []);

        return in_array($post_type, array_map('strval', (array) $allowed), true);
    }

    /**
     * Input keys that name a post on every ability that has them.
     */
    private const POST_ID_KEYS = [
        'post_id',
        'post_ids',
        'source_id',
        'object_id',
        'page_id',
        'template_id',
        'target_post_id',
        'media_id',
        'attachment_id',
        'revision_id',
        'from_revision_id',
        'to_revision_id',
    ];

    /** Keys that name a post type. */
    private const POST_TYPE_KEYS = ['post_type', 'post_types'];

    /**
     * Domains whose abilities use a bare 'id', 'ids' or 'parent' for a post.
     * Elsewhere those keys name users, comments, menus, terms or orders, and
     * checking them against posts would refuse legitimate calls whenever the
     * number happened to match a private post's id.
     */
    private const POST_ID_DOMAINS = ['content', 'core', 'blocks'];

    /**
     * The input keys the permission-time guard reads as post ids for an
     * ability in this domain.
     *
     * @return string[]
     */
    public static function post_id_keys(string $domain): array
    {
        $keys = self::POST_ID_KEYS;
        if (in_array($domain, self::POST_ID_DOMAINS, true)) {
            $keys = array_merge($keys, ['id', 'ids', 'parent']);
        }
        return $keys;
    }

    /**
     * The input keys the permission-time guard reads as post types.
     *
     * @return string[]
     */
    public static function post_type_keys(): array
    {
        return self::POST_TYPE_KEYS;
    }

    /**
     * Whether an ability invocation targets a post or post type the current
     * user may not reach through the content tools: a plugin-private type,
     * or one whose own capabilities the caller lacks (can_read_post_type()
     * and can_read_post()).
     *
     * Called from Registrar's permission decision, so it covers every
     * ability, including the dozens of generic tools that take an arbitrary
     * post id (duplicate-post, get-post-meta, extract-content, the block and
     * builder editors, revisions, ...) without each one having to remember
     * the rule. Without it, an edit_posts caller could, for example,
     * duplicate another administrator's chat conversation (meta and all)
     * into a post they own, or read another plugin's enrollment or order
     * records.
     *
     * The literal post_type 'any' is left to the tool: list-posts narrows it
     * to the types the caller may read.
     *
     * @param array<string, mixed> $input
     */
    public static function input_targets_private_post(string $domain, array $input): bool
    {
        foreach (self::post_id_keys($domain) as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            foreach ((array) $input[ $key ] as $value) {
                if (is_numeric($value) && ! self::can_read_post((int) $value)) {
                    return true;
                }
            }
        }

        foreach (self::POST_TYPE_KEYS as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            foreach ((array) $input[ $key ] as $value) {
                if (! is_string($value)) {
                    continue;
                }
                $value = sanitize_key($value);
                if ('' !== $value && 'any' !== $value && ! self::can_read_post_type($value)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function is_writable_post_type(string $post_type): bool
    {
        if ('' === $post_type || ! post_type_exists($post_type)) {
            return false;
        }
        return ! in_array($post_type, self::INTERNAL_TYPES, true);
    }

    /** Returns true if allowed, or a string error message if the meta map contains a protected key. */
    public static function check_meta(array $meta)
    {
        foreach (array_keys($meta) as $key) {
            $key = (string) $key;
            if ('_' === substr($key, 0, 1) || is_protected_meta($key, 'post')) {
                return 'Refusing to write protected meta key "' . esc_html($key) . '".';
            }
        }
        return true;
    }
}
