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
 * Free tier: the engine ships free with an enforced cap of one template per
 * part type; unlimited templates are PRO via Pro\Gate (issue #70 tier split).
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

    /** @return int|\WP_Error the new template post id. */
    public static function create(string $part_type, string $title, string $content, array $conditions, int $priority)
    {
        self::ensure_post_type();

        if (! in_array($part_type, self::PART_TYPES, true)) {
            return new \WP_Error(
                'wpmcp_invalid_part_type',
                sprintf('Unknown part type "%s". Valid types: %s.', $part_type, implode(', ', self::PART_TYPES))
            );
        }

        if (! Gate::is_pro() && count(self::all(false, $part_type)) >= self::FREE_CAP_PER_TYPE) {
            return new \WP_Error(
                'wpmcp_template_cap',
                sprintf('The free tier allows %d template per part type; upgrade for unlimited templates.', self::FREE_CAP_PER_TYPE)
            );
        }

        $valid = Condition_Schema::validate($conditions);
        if (is_wp_error($valid)) {
            return $valid;
        }

        $id = wp_insert_post([
            'post_type'    => self::POST_TYPE,
            'post_status'  => 'publish',
            'post_title'   => sanitize_text_field($title),
            'post_content' => $content,
        ], true);

        if (is_wp_error($id)) {
            return $id;
        }
        $id = (int) $id;
        update_post_meta($id, '_wpmcp_template_type', $part_type);
        update_post_meta($id, '_wpmcp_template_conditions', $conditions);
        update_post_meta($id, '_wpmcp_template_priority', $priority);

        return $id;
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

    /** @return array<int,array> template summaries, optionally filtered by part type. */
    public static function all(bool $active_only = false, ?string $part_type = null): array
    {
        self::ensure_post_type();
        $query = [
            'post_type'        => self::POST_TYPE,
            'post_status'      => $active_only ? ['publish'] : ['publish', 'draft'],
            'posts_per_page'   => 200,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'suppress_filters' => true,
        ];
        if (null !== $part_type) {
            $query['meta_key']   = '_wpmcp_template_type';
            $query['meta_value'] = $part_type;
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
