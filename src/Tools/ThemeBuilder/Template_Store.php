<?php

namespace WPMCP\Tools\ThemeBuilder;

use WPMCP\Pro\Gate;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Storage for theme-builder templates as `wpmcp_template` posts (issue #70).
 * Scoped v1 site parts: header, footer, 404. The part type lives in the
 * _wpmcp_template_type meta, the include/exclude condition set in
 * _wpmcp_template_conditions, the tie-break priority in
 * _wpmcp_template_priority, and the template content (block markup) in
 * post_content. Active/inactive maps to publish/draft, so creation and status
 * changes are reversible through the standard trash.
 *
 * The post type is deliberately internal: it is on Content_Guard's
 * INTERNAL_TYPES list, so the generic content tools (which run at edit_posts)
 * cannot rewrite markup that renders on every page. The only writers are the
 * abilities in this directory, all of which require manage_options.
 *
 * Free tier: the engine ships free with a cap of one template per part type;
 * unlimited templates lift the cap on a licensed site (issue #70 tier split).
 * The cap is one number read from cap_per_type(), which is the single place
 * the wp.org directory build rewrites.
 */
class Template_Store
{
    public const POST_TYPE = 'wpmcp_template';

    public const PART_TYPES = ['header', 'footer', '404'];

    public const FREE_CAP_PER_TYPE = 1;

    /** Register the CPT (idempotent). Called on init and defensively before use. */
    public static function ensure_post_type(): void
    {
        if (! post_type_exists(self::POST_TYPE)) {
            register_post_type(self::POST_TYPE, [
                'public'       => false,
                'show_ui'      => false,
                'show_in_rest' => false,
                'supports'     => ['title', 'editor'],
            ]);
        }
    }

    /**
     * How many templates a site may keep per part type. 0 means unlimited.
     * One method so the cap has exactly one definition to read, to test, and
     * for the directory build to rewrite.
     */
    public static function cap_per_type(): int
    {
        return Gate::is_pro() ? 0 : self::FREE_CAP_PER_TYPE;
    }

    /**
     * Template markup is rendered with do_blocks() into the header, footer or
     * 404 body of every matching page, so it is filtered on the way IN rather
     * than trusted at the render boundary, matching Block_Renderer's
     * escape-before-output stance for the same class of payload.
     *
     * wp_kses_post() is applied unconditionally and explicitly: leaning on
     * wp_insert_post's own KSES pass would be leaning on nothing, because
     * kses_init_filters() is skipped for users holding `unfiltered_html`,
     * which is exactly the single-site administrator this tool requires.
     * Block delimiter comments survive wp_kses_post(), so block markup is
     * preserved while scripts and event-handler attributes are not.
     */
    public static function sanitize_content(string $content): string
    {
        return wp_kses_post($content);
    }

    /** @return int|\WP_Error the new template post id. */
    public static function create(string $part_type, string $title, string $content, array $conditions, int $priority)
    {
        self::ensure_post_type();

        $part_type = self::validate_part_type($part_type);
        if (is_wp_error($part_type)) {
            return $part_type;
        }

        $cap = self::cap_per_type();
        if ($cap > 0 && count(self::all(false, $part_type)) >= $cap) {
            return new \WP_Error(
                'wpmcp_template_cap',
                sprintf(
                    'This site keeps %d template per part type; trash the existing "%s" template first (wpmcp/delete-site-part).',
                    $cap,
                    $part_type
                )
            );
        }

        $valid = Condition_Schema::validate($conditions);
        if (is_wp_error($valid)) {
            return $valid;
        }

        $id = wp_insert_post(wp_slash([
            'post_type'    => self::POST_TYPE,
            'post_status'  => 'publish',
            'post_title'   => sanitize_text_field($title),
            'post_content' => self::sanitize_content($content),
        ]), true);

        if (is_wp_error($id)) {
            return $id;
        }
        $id = (int) $id;
        update_post_meta($id, '_wpmcp_template_type', $part_type);
        update_post_meta($id, '_wpmcp_template_conditions', $conditions);
        update_post_meta($id, '_wpmcp_template_priority', $priority);

        return $id;
    }

    /**
     * Part type or a WP_Error naming the valid set. Shared by every tool so
     * a typo ("head") is an error rather than an empty result that reads to
     * an agent as "nothing matched".
     *
     * @return string|\WP_Error
     */
    public static function validate_part_type(string $part_type)
    {
        if (! in_array($part_type, self::PART_TYPES, true)) {
            return new \WP_Error(
                'wpmcp_invalid_part_type',
                sprintf('Unknown part type "%s". Valid types: %s.', $part_type, implode(', ', self::PART_TYPES))
            );
        }
        return $part_type;
    }

    public static function get(int $id): ?array
    {
        if (! self::is_template($id)) {
            return null;
        }
        $post       = get_post($id);
        $conditions = get_post_meta($id, '_wpmcp_template_conditions', true);

        return [
            'template_id' => $id,
            'part_type'   => (string) get_post_meta($id, '_wpmcp_template_type', true),
            'title'       => get_the_title($post),
            'content'     => (string) $post->post_content,
            'conditions'  => is_array($conditions) ? $conditions : [],
            'priority'    => (int) get_post_meta($id, '_wpmcp_template_priority', true),
            'status'      => $post->post_status,
        ];
    }

    /**
     * @param int $limit -1 (the default) for every row. The resolver drives
     *                   correctness rather than a display listing, so it must
     *                   never silently ignore a template; only the
     *                   agent-facing listing passes a bounded page size.
     *
     * @return array<int,array> template summaries, optionally filtered by part type.
     */
    public static function all(bool $active_only = false, ?string $part_type = null, int $limit = -1): array
    {
        self::ensure_post_type();
        $query = [
            'post_type'        => self::POST_TYPE,
            'post_status'      => $active_only ? ['publish'] : ['publish', 'draft'],
            // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Bounded by the per-part-type cap; the resolver must see every candidate or the deterministic winner is a lie.
            'posts_per_page'   => $limit,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'suppress_filters' => true,
        ];
        if (null !== $part_type && '' !== $part_type) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The plugin's own wpmcp_template CPT: a handful of rows, capped per part type.
            $query['meta_query'] = [
                [
                    'key'     => '_wpmcp_template_type',
                    'value'   => $part_type,
                    'compare' => '=',
                ],
            ];
        }
        $rows = get_posts($query);

        $out = [];
        foreach ($rows as $row) {
            $conditions = get_post_meta($row->ID, '_wpmcp_template_conditions', true);
            $out[]      = [
                'template_id' => $row->ID,
                'part_type'   => (string) get_post_meta($row->ID, '_wpmcp_template_type', true),
                'title'       => get_the_title($row),
                'conditions'  => is_array($conditions) ? $conditions : [],
                'priority'    => (int) get_post_meta($row->ID, '_wpmcp_template_priority', true),
                'status'      => $row->post_status,
            ];
        }
        return $out;
    }

    public static function is_template(int $id): bool
    {
        return $id > 0 && self::POST_TYPE === get_post_type($id);
    }
}
