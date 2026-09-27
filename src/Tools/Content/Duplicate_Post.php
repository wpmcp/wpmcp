<?php

namespace WPMCP\Tools\Content;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Duplicate a post, page or custom post type entry with its content, meta,
 * terms and (optionally) its children.
 *
 * NOT routed through Safe_Mutation, following the same reasoning as
 * Create_Post: this only ever creates new posts and never reads back onto
 * the source, so there is no prior state to capture and nothing a rollback
 * could restore. The copies are plain posts and delete-post removes them.
 *
 * The copy is created as a DRAFT regardless of the source's status, unless a
 * status is explicitly requested. Silently publishing a duplicate is how a
 * half-finished clone ends up live on the front page, and "make me a copy of
 * this to work on" is overwhelmingly the intent behind this tool.
 *
 * The whole meta map is copied except the keys that identify the ORIGINAL
 * rather than describe the content: _edit_lock and _edit_last (stale editor
 * state) and _wp_old_slug (redirect history that belongs to the source URL).
 * Builder data (Elementor's _elementor_data and friends) IS copied, since a
 * duplicate of an Elementor page that loses its layout is not a duplicate.
 */
class Duplicate_Post
{
    /** Meta keys that describe the source post itself, not its content. */
    public const SKIPPED_META = ['_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date'];

    public function handle(array $args): array
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        $source  = $post_id ? get_post($post_id) : null;

        if (! $source instanceof \WP_Post) {
            throw new \InvalidArgumentException('Post not found.');
        }

        $status = (string) ($args['status'] ?? 'draft');
        if (! in_array($status, ['draft', 'pending', 'private', 'publish'], true)) {
            throw new \InvalidArgumentException('status must be one of draft, pending, private, publish.');
        }

        $title = (string) ($args['title'] ?? '');
        if ('' === $title) {
            /* translators: %s: the title of the post being duplicated. */
            $title = sprintf(__('%s (copy)', 'wpmcp'), $source->post_title);
        }

        $with_children = (bool) ($args['include_children'] ?? false);

        $new_id = $this->copy($source, $title, $status, (int) $source->post_parent);

        $children = [];
        if ($with_children) {
            foreach (get_children(['post_parent' => $post_id, 'post_type' => 'any']) as $child) {
                $children[] = $this->copy($child, (string) $child->post_title, $status, $new_id);
            }
        }

        $new = get_post($new_id);

        return [
            'source_id' => $post_id,
            'post_id'   => $new_id,
            'children'  => $children,
            'status'    => $new instanceof \WP_Post ? (string) $new->post_status : $status,
            'edit_link' => get_edit_post_link($new_id, 'raw'),
        ];
    }

    /** Copy one post row, its meta and its terms. Returns the new post id. */
    private function copy(\WP_Post $source, string $title, string $status, int $parent): int
    {
        $new_id = wp_insert_post([
            'post_title'     => $title,
            'post_content'   => $source->post_content,
            'post_excerpt'   => $source->post_excerpt,
            'post_type'      => $source->post_type,
            'post_status'    => $status,
            'post_parent'    => $parent,
            'menu_order'     => $source->menu_order,
            'comment_status' => $source->comment_status,
            'ping_status'    => $source->ping_status,
            'post_author'    => get_current_user_id() ?: (int) $source->post_author,
        ], true);

        if (is_wp_error($new_id)) {
            throw new \RuntimeException(esc_html($new_id->get_error_message()));
        }

        $new_id = (int) $new_id;

        $meta = get_post_meta((int) $source->ID);
        if (is_array($meta)) {
            foreach ($meta as $key => $values) {
                if (in_array($key, self::SKIPPED_META, true)) {
                    continue;
                }
                foreach ((array) $values as $value) {
                    add_post_meta($new_id, (string) $key, maybe_unserialize($value));
                }
            }
        }

        foreach (get_object_taxonomies($source->post_type) as $taxonomy) {
            $terms = wp_get_object_terms((int) $source->ID, $taxonomy, ['fields' => 'ids']);
            if (is_array($terms) && [] !== $terms) {
                wp_set_object_terms($new_id, $terms, $taxonomy);
            }
        }

        return $new_id;
    }
}
