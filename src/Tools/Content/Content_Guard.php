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
     * tools: can_read_post_type() for its type and core's read_post meta
     * capability for the row itself (issue #448: another user's draft or
     * private post is not readable below Editor). A revision or autosave is
     * judged by the post it belongs to. A missing post is not refused here:
     * the tool answers "not found" itself.
     */
    public static function can_read_post(int $post_id): bool
    {
        $post = self::subject_post($post_id);
        if (null === $post) {
            return true;
        }
        if (! self::can_read_post_type((string) $post->post_type)) {
            return false;
        }
        return self::user_can_post('read_post', $post);
    }

    /**
     * The post a capability check is about: the post itself, or for a
     * revision or autosave the post it belongs to. Null when there is none.
     */
    private static function subject_post(int $post_id): ?\WP_Post
    {
        if ($post_id <= 0) {
            return null;
        }
        $post = get_post($post_id);
        if (! $post instanceof \WP_Post) {
            return null;
        }
        if ('revision' === $post->post_type && $post->post_parent > 0) {
            $parent = get_post((int) $post->post_parent);
            if ($parent instanceof \WP_Post) {
                return $parent;
            }
        }
        return $post;
    }

    /**
     * Core's per-post meta capability (read_post, edit_post, delete_post)
     * for a post. A row of an unregistered type has no capability map, and
     * core reports asking for one as a misuse; can_read_post_type() already
     * limits those rows to site administrators, so they pass here.
     */
    private static function user_can_post(string $capability, \WP_Post $post): bool
    {
        $object = get_post_type_object((string) $post->post_type);
        if (! $object instanceof \WP_Post_Type) {
            return true;
        }
        // A published row of a public type is public content: core's
        // read_post grants it to everyone, and asking current_user_can()
        // would only differ for a caller without the bare read capability.
        if ('read_post' === $capability && ($object->public || 'attachment' === $post->post_type)) {
            $status = get_post_status_object((string) get_post_status($post));
            if ($status instanceof \stdClass && ! empty($status->public)) {
                return true;
            }
        }
        return current_user_can($capability, (int) $post->ID);
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

    /**
     * Keys that name a post the ability links to rather than changes: a
     * parent, a copy source, a redirect target, a menu item's object. The
     * caller needs to be able to read that post, not edit it.
     */
    private const REFERENCE_KEYS = ['parent', 'source_id', 'target_post_id', 'object_id'];

    /**
     * Keys that only link to a post (a parent, a redirect target, a menu
     * item's object) without returning or copying its content, so a
     * password-protected post may be named there (issue #450).
     */
    private const LINK_KEYS = ['parent', 'target_post_id', 'object_id'];

    /**
     * Read abilities that return a password-protected post with its content
     * withheld themselves, the way core's REST API does, rather than being
     * refused (issue #450).
     */
    private const REDACTS_PROTECTED_CONTENT = ['wpmcp/get-post'];

    /** Keys that name the post an ability works on, when present. */
    private const PRIMARY_KEYS = ['post_id', 'post_ids', 'id', 'ids', 'page_id'];

    /**
     * Keys that name the post an ability works on when the input names no
     * primary post, and otherwise something applied to that post (a
     * template, a social image), which is only read.
     */
    private const SECONDARY_KEYS = ['template_id', 'media_id', 'attachment_id'];

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
     * Why an ability invocation may not run for the current user, judged on
     * the posts and post types its input names; null when nothing refuses it.
     * Called from Registrar's permission decision, so every ability that
     * names a post by id gets the same rule without each one having to
     * remember it:
     *
     * - 'private-post': a post or post type the caller may not reach at all
     *   (can_read_post_type(), issue #446);
     * - 'post-capability': core's per-post meta capability for the named
     *   post fails (issue #448). The capability follows the ability's
     *   registered operation: read_post for a read, edit_post for a create or
     *   update (content, status, terms, meta, featured image, revisions,
     *   block and builder edits), delete_post for a delete. A post the call
     *   only links to or copies from (REFERENCE_KEYS, a SECONDARY_KEYS post
     *   next to a primary one, and the ability's own read_keys) needs
     *   read_post whatever the operation.
     * - 'protected-post': a post read for its content (not merely linked
     *   to) is password protected and the caller cannot edit it (issue
     *   #450). Core hands that content only to a user who can edit the post
     *   or who supplies the password, and the tools take no password.
     *   get-post is the exception: it answers with the content withheld.
     *
     * @param array<string, mixed> $input
     */
    public static function input_denial(\WPMCP\MCP\Ability $a, array $input): ?string
    {
        $write_cap = [
            'read'   => 'read_post',
            'delete' => 'delete_post',
        ][ $a->operation ] ?? 'edit_post';

        $has_primary = false;
        foreach (self::PRIMARY_KEYS as $key) {
            if (! empty($input[ $key ])) {
                $has_primary = true;
                break;
            }
        }

        foreach (self::post_id_keys($a->domain) as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $read_only = in_array($key, self::REFERENCE_KEYS, true)
                || ($has_primary && in_array($key, self::SECONDARY_KEYS, true))
                || in_array($key, $a->read_keys, true);
            $capability = $read_only ? 'read_post' : $write_cap;

            foreach ((array) $input[ $key ] as $value) {
                if (! is_numeric($value)) {
                    continue;
                }
                $post = self::subject_post((int) $value);
                if (null === $post) {
                    continue;
                }
                if (! self::can_read_post_type((string) $post->post_type)) {
                    return 'private-post';
                }
                if (! self::user_can_post('read_post', $post) || ! self::user_can_post($capability, $post)) {
                    return 'post-capability';
                }
                $reads_content = 'read_post' === $capability
                    && ! in_array($key, self::LINK_KEYS, true)
                    && ! in_array($a->name, self::REDACTS_PROTECTED_CONTENT, true);
                if ($reads_content && self::withholds_protected_content($post)) {
                    return 'protected-post';
                }
            }
        }

        return self::input_names_unreadable_type($input) ? 'private-post' : null;
    }

    /**
     * Whether a post's content must be withheld from the current user: it
     * is password protected and they cannot edit it (core's
     * can_access_password_content(), without a password to check). A
     * revision is judged by the post it belongs to.
     */
    public static function withholds_protected_content(\WP_Post $post): bool
    {
        $subject = self::subject_post((int) $post->ID) ?? $post;
        if ('' === (string) $subject->post_password) {
            return false;
        }
        if (! post_type_exists((string) $subject->post_type)) {
            return ! current_user_can('manage_options');
        }
        return ! current_user_can('edit_post', (int) $subject->ID);
    }

    /**
     * Why the current user may not act on a post an integration pack
     * operation names in its args, or null (issue #450). The rule is
     * input_denial()'s for one post:
     *
     * - $access 'read' asks read_post, and refuses password-protected
     *   content the caller cannot edit; 'write' asks read_post and
     *   edit_post; 'delete' asks read_post and delete_post;
     * - a plugin-private type is never reachable;
     * - $type_rule applies can_read_post_type() too. An op that only ever
     *   acts on its host plugin's own post type (and checks that itself)
     *   passes false: the type's own capabilities then decide, through the
     *   per-post meta capability.
     *
     * A published row passes the read check as core's read_post passes it,
     * without asking for a meta capability some plugin types do not map. A
     * missing post is not refused: the op answers "not found" itself.
     */
    public static function post_object_denial(int $post_id, string $access, bool $type_rule = true): ?string
    {
        $post = self::subject_post($post_id);
        if (null === $post) {
            return null;
        }
        $type = (string) $post->post_type;
        if (! self::is_agent_readable_post_type($type) || ($type_rule && ! self::can_read_post_type($type))) {
            return 'private-post';
        }
        $status   = get_post_status_object((string) get_post_status($post));
        $readable = ($status instanceof \stdClass && ! empty($status->public)) || self::user_can_post('read_post', $post);
        if (! $readable) {
            return 'post-capability';
        }
        if ('read' === $access) {
            return self::withholds_protected_content($post) ? 'protected-post' : null;
        }
        return self::user_can_post('delete' === $access ? 'delete_post' : 'edit_post', $post) ? null : 'post-capability';
    }

    /**
     * Whether the input names a post type the caller may not reach. The
     * literal 'any' is left to the tool: list-posts narrows it itself.
     *
     * @param array<string, mixed> $input
     */
    private static function input_names_unreadable_type(array $input): bool
    {
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

    /**
     * The SQL condition that keeps a post listing to the rows the current
     * user may read, following core's read_post: published rows of each
     * type, the caller's own rows, private rows with the type's
     * read_private_posts, and other users' drafts, pending and scheduled
     * rows with the type's edit_others_posts. Attachments inherit their
     * status and are listed as the media library lists them. Empty when the
     * caller may read every row of every type given.
     *
     * @param string[] $post_types
     */
    public static function readable_posts_where(array $post_types, string $table): string
    {
        global $wpdb;

        $user_id  = get_current_user_id();
        $public   = array_values(array_map('strval', get_post_stati(['public' => true])));
        $private  = array_values(array_map('strval', get_post_stati(['private' => true])));
        $status   = $wpdb->prepare('%i.post_status', $table);
        $in       = static fn (array $values): string => implode(', ', array_map(static fn (string $v): string => $wpdb->prepare('%s', $v), $values));
        $clauses  = [];
        $narrowed = false;

        foreach ($post_types as $type) {
            $object = get_post_type_object((string) $type);
            $typed  = $wpdb->prepare('%i.post_type = %s', $table, (string) $type);
            if (! $object instanceof \WP_Post_Type || 'attachment' === $type) {
                $clauses[] = $typed;
                continue;
            }
            $others  = current_user_can((string) $object->cap->edit_others_posts);
            $privacy = current_user_can((string) $object->cap->read_private_posts);
            if ($others && $privacy) {
                $clauses[] = $typed;
                continue;
            }
            $narrowed = true;
            $allowed  = [];
            if ([] !== $public) {
                $allowed[] = $status . ' IN (' . $in($public) . ')';
            }
            if ($user_id > 0) {
                $allowed[] = $wpdb->prepare('%i.post_author = %d', $table, $user_id);
            }
            if ($privacy && [] !== $private) {
                $allowed[] = $status . ' IN (' . $in($private) . ')';
            }
            if ($others) {
                $allowed[] = [] === $private ? '1=1' : $status . ' NOT IN (' . $in($private) . ')';
            }
            $clauses[] = '(' . $typed . ' AND (' . ([] === $allowed ? '1=0' : implode(' OR ', $allowed)) . '))';
        }

        if (! $narrowed || [] === $clauses) {
            return '';
        }
        return ' AND (' . implode(' OR ', $clauses) . ')';
    }

    /**
     * Whether an input names a post or post type the current user may not
     * read through the content tools: a plugin-private type, one whose own
     * capabilities the caller lacks, or a row core's read_post refuses
     * (can_read_post_type() and can_read_post()).
     *
     * This is the read half of input_denial(), which Registrar calls with
     * the ability's operation; it is kept for callers that only read.
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

        return self::input_names_unreadable_type($input);
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
