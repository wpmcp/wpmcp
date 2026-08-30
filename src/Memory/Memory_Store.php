<?php

namespace WPMCP\Memory;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Per-site project-memory store (issue #131): ordinary wpmcp_memory posts
 * with the entry text in post_content and kind/severity/targets in postmeta.
 *
 * The trust gate IS the post status. Everything an agent proposes lands
 * 'pending' and is inert: not injected into the handshake, not enforced, not
 * returned as guidance. An administrator publishing the entry (the standard
 * wp-admin flow, with its own capability checks and nonces) is the single
 * act that makes an entry live. Nothing in the agent-facing path can publish.
 *
 * Storage choice: a CPT rather than a bespoke table because entries are
 * few, editable, revisionable, and trash-recoverable, and because the
 * pending/publish gate then reuses WordPress's own reviewed-content
 * semantics instead of a hand-rolled approval column.
 *
 * block_rules() is read on every non-read-only permission check, so it is
 * memoized per request and capped at MAX_RULES entries.
 */
class Memory_Store
{
    public const POST_TYPE = 'wpmcp_memory';

    public const META_KIND     = '_wpmcp_memory_kind';
    public const META_SEVERITY = '_wpmcp_memory_severity';
    public const META_TARGETS  = '_wpmcp_memory_targets';

    /** Hard ceiling on enforced rules read per request. */
    public const MAX_RULES = 200;

    /** Default number of entries a list() call returns. */
    public const DEFAULT_LIMIT = 20;

    /** @var array<int, array<string, mixed>>|null memoized published block rules. */
    private static ?array $rules_cache = null;

    /**
     * Re-entrancy latch for write_meta(). write_meta() normalizes the stored
     * title/content, and wp_update_post() fires save_post_wpmcp_memory, which
     * is exactly the hook Memory_Page uses to call back into the store. The
     * latch makes that loop terminate on the first pass instead of recursing.
     */
    private static bool $writing = false;

    /**
     * Registers the CPT. show_ui is on (with show_in_menu off, the wpmcp menu
     * adds the submenu itself) so approving a proposal is the ordinary
     * WordPress publish flow rather than a bespoke admin screen. Every
     * capability maps to manage_options: publishing an entry can silence a
     * guardrail or inject instructions into every agent session, which is the
     * same trust level as the rest of wpmcp's admin surface.
     */
    public static function ensure_post_type(): void
    {
        if (post_type_exists(self::POST_TYPE)) {
            return;
        }

        register_post_type(self::POST_TYPE, [
            'labels'          => [
                'name'          => 'Agent Memory',
                'singular_name' => 'Memory Entry',
                'add_new_item'  => 'Add Memory Entry',
                'edit_item'     => 'Edit Memory Entry',
                'search_items'  => 'Search Memory Entries',
            ],
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => false,
            'show_in_rest'    => false,
            'supports'        => ['title', 'editor'],
            'capability_type' => 'post',
            'map_meta_cap'    => true,
            'capabilities'    => [
                'edit_posts'           => 'manage_options',
                'edit_others_posts'    => 'manage_options',
                'edit_private_posts'   => 'manage_options',
                'edit_published_posts' => 'manage_options',
                'publish_posts'        => 'manage_options',
                'read_private_posts'   => 'manage_options',
                'create_posts'         => 'manage_options',
                'delete_posts'         => 'manage_options',
            ],
        ]);
    }

    /**
     * Store an agent proposal. Always 'pending', never publishable from here:
     * the parameter that would let a caller choose a status simply does not
     * exist, so no future call site can accidentally grant one.
     *
     * @param array<string, mixed> $raw
     * @return int|\WP_Error the new entry id.
     */
    public static function propose(array $raw)
    {
        $entry = Memory_Entry::validate($raw);
        if (is_wp_error($entry)) {
            return $entry;
        }

        self::ensure_post_type();

        $id = wp_insert_post([
            'post_type'    => self::POST_TYPE,
            'post_status'  => 'pending',
            'post_title'   => $entry['title'],
            'post_content' => $entry['text'],
        ], true);

        if (is_wp_error($id)) {
            return $id;
        }

        $id = (int) $id;
        self::write_meta($id, $entry);
        self::flush_rules_cache();

        return $id;
    }

    /**
     * Publish a pending entry (the admin approval action). Returns false for
     * anything that is not a memory entry.
     */
    public static function approve(int $id): bool
    {
        if (! self::is_entry($id)) {
            return false;
        }
        wp_update_post(['ID' => $id, 'post_status' => 'publish']);
        self::flush_rules_cache();
        return true;
    }

    /** Send an entry back to pending, immediately un-enforcing it. */
    public static function unpublish(int $id): bool
    {
        if (! self::is_entry($id)) {
            return false;
        }
        wp_update_post(['ID' => $id, 'post_status' => 'pending']);
        self::flush_rules_cache();
        return true;
    }

    /**
     * Overwrite an existing entry after validating the whole thing: the
     * classification (kind, severity, targets) plus the normalized title and
     * text. Admin edits therefore land in the store having passed exactly the
     * same validator as an agent proposal, which is the point: an
     * administrator cannot hand-write a malformed target, an untargeted block
     * rule, or HTML that would ride into the handshake, any more than an agent
     * can.
     *
     * @param array<string, mixed> $raw
     * @return true|\WP_Error
     */
    public static function update_fields(int $id, array $raw)
    {
        if (! self::is_entry($id)) {
            return new \WP_Error('wpmcp_memory_missing', sprintf('No memory entry with id %d.', $id));
        }

        $entry = Memory_Entry::validate($raw);
        if (is_wp_error($entry)) {
            return $entry;
        }

        self::write_meta($id, $entry);
        self::flush_rules_cache();
        return true;
    }

    /** @return array<string, mixed>|null the normalized entry, or null. */
    public static function get(int $id): ?array
    {
        if (! self::is_entry($id)) {
            return null;
        }
        $post = get_post($id);
        return null === $post ? null : self::to_entry($post);
    }

    public static function is_entry(int $id): bool
    {
        return $id > 0 && self::POST_TYPE === get_post_type($id);
    }

    /**
     * @param array{status?:string|string[],kind?:string|string[],topic?:string,limit?:int,exclude_kind?:string} $args
     * @return array<int, array<string, mixed>>
     */
    public static function query(array $args = []): array
    {
        self::ensure_post_type();

        $status = $args['status'] ?? 'publish';
        $limit  = max(1, min(self::MAX_RULES, (int) ($args['limit'] ?? self::DEFAULT_LIMIT)));

        $query = [
            'post_type'        => self::POST_TYPE,
            'post_status'      => $status,
            'posts_per_page'   => $limit,
            'orderby'          => 'ID',
            'order'            => 'ASC',
        ];

        if (isset($args['kind'])) {
            $query['meta_query'] = [
                [
                    'key'     => self::META_KIND,
                    'value'   => (array) $args['kind'],
                    'compare' => 'IN',
                ],
            ];
        }

        $entries = array_map([self::class, 'to_entry'], get_posts($query));

        if (isset($args['exclude_kind'])) {
            $excluded = (array) $args['exclude_kind'];
            $entries  = array_values(array_filter(
                $entries,
                static fn (array $e): bool => ! in_array($e['kind'], $excluded, true)
            ));
        }

        $topic = isset($args['topic']) ? trim((string) $args['topic']) : '';
        if ('' !== $topic) {
            $needle  = function_exists('mb_strtolower') ? mb_strtolower($topic) : strtolower($topic);
            $entries = array_values(array_filter($entries, static function (array $e) use ($needle): bool {
                $haystack = strtolower($e['title'] . ' ' . $e['text'] . ' ' . implode(' ', $e['targets']));
                return false !== strpos($haystack, $needle);
            }));
        }

        return $entries;
    }

    /** Published entries that are not session summaries: the guidance set. */
    public static function guidance(int $limit = self::DEFAULT_LIMIT, string $topic = ''): array
    {
        return self::query([
            'status'       => 'publish',
            'exclude_kind' => 'session-summary',
            'topic'        => $topic,
            'limit'        => $limit,
        ]);
    }

    /** Published session summaries, newest last (id order), for recall. */
    public static function sessions(int $limit = 10, string $topic = ''): array
    {
        return self::query([
            'status' => 'publish',
            'kind'   => 'session-summary',
            'topic'  => $topic,
            'limit'  => $limit,
        ]);
    }

    /**
     * The enforced rule set: PUBLISHED entries with severity=block. Memoized
     * per request because Registrar::is_permitted() consults it on every
     * non-read-only permission check.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function block_rules(): array
    {
        if (null !== self::$rules_cache) {
            return self::$rules_cache;
        }

        // Deliberately does NOT register the post type. This runs inside
        // every non-read-only permission check, so on a build or a flavor
        // where the memory group is off it must cost nothing and must not
        // create a post type as a side effect of asking a question.
        if (! post_type_exists(self::POST_TYPE)) {
            self::$rules_cache = [];
            return self::$rules_cache;
        }

        $posts = get_posts([
            'post_type'        => self::POST_TYPE,
            'post_status'      => 'publish',
            'posts_per_page'   => self::MAX_RULES,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'meta_query'       => [
                [
                    'key'   => self::META_SEVERITY,
                    'value' => 'block',
                ],
            ],
        ]);

        $rules = [];
        foreach ($posts as $post) {
            $entry = self::to_entry($post);
            // Defense in depth: an entry whose targets were emptied out
            // after publication (direct DB edit, a botched import) must
            // never widen into "block everything".
            if ([] === $entry['targets']) {
                continue;
            }
            $rules[] = $entry;
        }

        self::$rules_cache = $rules;
        return $rules;
    }

    public static function pending_count(): int
    {
        self::ensure_post_type();
        $counts = wp_count_posts(self::POST_TYPE);
        return (int) ($counts->pending ?? 0);
    }

    /** Drop the memoized rule set (called on every write, and by tests). */
    public static function flush_rules_cache(): void
    {
        self::$rules_cache = null;
    }

    /**
     * transition_post_status listener. A publish or unpublish performed in
     * wp-admin never calls approve()/unpublish(), so the memo has to be
     * dropped from the transition itself; anything but a memory entry is
     * ignored.
     *
     * @param string   $new_status
     * @param string   $old_status
     * @param \WP_Post $post
     */
    public static function flush_rules_cache_on_transition($new_status, $old_status, $post): void
    {
        if (is_object($post) && isset($post->post_type) && self::POST_TYPE === $post->post_type) {
            self::flush_rules_cache();
        }
    }

    /**
     * deleted_post listener: a deleted entry stops being enforced
     * immediately, not on the next request.
     *
     * @param int      $post_id
     * @param \WP_Post $post
     */
    public static function flush_rules_cache_on_delete($post_id, $post = null): void
    {
        if (is_object($post) && isset($post->post_type) && self::POST_TYPE === $post->post_type) {
            self::flush_rules_cache();
        }
    }

    /** @param array{title:string,text:string,kind:string,severity:string,targets:string[]} $entry */
    private static function write_meta(int $id, array $entry): void
    {
        if (self::$writing) {
            return;
        }

        self::$writing = true;
        try {
            update_post_meta($id, self::META_KIND, $entry['kind']);
            update_post_meta($id, self::META_SEVERITY, $entry['severity']);
            update_post_meta($id, self::META_TARGETS, $entry['targets']);

            $post = get_post($id);
            if (null !== $post && ($post->post_title !== $entry['title'] || $post->post_content !== $entry['text'])) {
                wp_update_post([
                    'ID'           => $id,
                    'post_title'   => $entry['title'],
                    'post_content' => $entry['text'],
                ]);
            }
        } finally {
            self::$writing = false;
        }
    }

    /**
     * @param \WP_Post $post
     * @return array<string, mixed>
     */
    private static function to_entry($post): array
    {
        $targets = get_post_meta($post->ID, self::META_TARGETS, true);
        $kind    = (string) get_post_meta($post->ID, self::META_KIND, true);
        $sev     = (string) get_post_meta($post->ID, self::META_SEVERITY, true);

        return [
            'id'       => (int) $post->ID,
            'title'    => (string) $post->post_title,
            'text'     => (string) $post->post_content,
            'kind'     => in_array($kind, Memory_Entry::KINDS, true) ? $kind : 'fact',
            'severity' => in_array($sev, Memory_Entry::SEVERITIES, true) ? $sev : 'note',
            'targets'  => is_array($targets) ? array_values(array_filter($targets, 'is_string')) : [],
            'status'   => (string) $post->post_status,
            'created'  => (string) $post->post_date_gmt,
        ];
    }
}
