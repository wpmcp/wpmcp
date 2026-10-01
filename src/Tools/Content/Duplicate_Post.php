<?php

namespace WPMCP\Tools\Content;

use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Tools\Builders\Elementor_Cache;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Duplicate a post, page or custom post type entry with its content, meta,
 * terms and (optionally) its children.
 *
 * NOT routed through Safe_Mutation, following the same reasoning as
 * Create_Post: this only ever creates new posts and never writes back onto
 * the source, so there is no prior state to capture. Like create-post it
 * records ONE 'post_create' ledger row covering the copy and every copied
 * child (issue #192), so a session-derived change set lists them and
 * rollback-operation / rollback-session move them all to the trash.
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
 *
 * Staged edits (issue #417) ride on the same tool, since the tools/list
 * budget has no room for three more: stage:true makes a draft copy linked to
 * the original, publish_stage writes it back over the original and
 * discard_stage throws it away. See Post_Stage.
 */
class Duplicate_Post
{
    /** Meta keys that describe the source post itself, not its content. */
    public const SKIPPED_META = [
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_wp_old_date',
        // A copy of a stage is an ordinary post, not a second stage.
        Post_Stage::STAGE_OF_META,
        Post_Stage::STAGE_BASE_META,
    ];

    /** The stage link, for callers and tests that look a stage up by meta. */
    public const STAGE_OF_META = Post_Stage::STAGE_OF_META;

    public function handle(array $args): array
    {
        if (! empty($args['publish_stage'])) {
            return Post_Stage::publish((int) $args['publish_stage'], $args);
        }
        if (! empty($args['discard_stage'])) {
            return Post_Stage::discard((int) $args['discard_stage']);
        }

        $post_id = (int) ($args['post_id'] ?? 0);
        $source  = $post_id ? get_post($post_id) : null;

        // A private type (the chat conversation store) is refused with the
        // same answer as a missing post, so the tool cannot copy another
        // user's private record, meta and all, into a post the caller owns.
        // Registrar refuses the call before it gets here; this is the tool's
        // own guard for callers that reach the handler directly.
        $allowed = $source instanceof \WP_Post
            && Content_Guard::is_agent_readable_post_type((string) $source->post_type);
        if (! $allowed) {
            throw new \InvalidArgumentException('Post not found.');
        }

        if (! empty($args['stage'])) {
            return $this->stage($source, $args);
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
            // Only the children the caller may read (issue #465): another
            // user's draft or private child is not copied into a post the
            // caller owns.
            $readable = Readable_Posts::get_posts([
                'post_parent'    => $post_id,
                'post_type'      => 'any',
                'post_status'    => 'any',
                'posts_per_page' => -1,
            ]);
            foreach ($readable as $child) {
                if (! Content_Guard::can_read_post_type((string) $child->post_type)) {
                    continue;
                }
                $children[] = $this->copy($child, (string) $child->post_title, $status, $new_id);
            }
        }

        $operation_id = Post_Creation_Snapshot::record(
            'duplicate-post',
            array_merge([$new_id], $children),
            $args,
            (string) ($args['session_id'] ?? 'default')
        );

        $new = get_post($new_id);

        return [
            'source_id'    => $post_id,
            'post_id'      => $new_id,
            'children'     => $children,
            'status'       => $new instanceof \WP_Post ? (string) $new->post_status : $status,
            'edit_link'    => get_edit_post_link($new_id, 'raw'),
            'operation_id' => $operation_id,
        ];
    }

    /**
     * Stage a draft copy of $source (issue #417), or return its live stage.
     *
     * @return array<string, mixed>
     */
    private function stage(\WP_Post $source, array $args): array
    {
        if (Post_Stage::original_of((int) $source->ID) > 0) {
            throw new \InvalidArgumentException('Post ' . (int) $source->ID . ' is a stage; publish or discard it instead.');
        }
        if ('trash' === $source->post_status || ! Content_Guard::is_writable_post_type((string) $source->post_type)) {
            throw new \InvalidArgumentException('Post ' . (int) $source->ID . ' cannot be staged.');
        }
        if (! current_user_can('edit_post', $source->ID)) {
            throw new \RuntimeException('You cannot edit post ' . (int) $source->ID . '.');
        }

        $stage_id     = Post_Stage::find($source);
        $operation_id = null;
        if (0 === $stage_id) {
            $title    = (string) ($args['title'] ?? '');
            $stage_id = $this->copy($source, '' === $title ? (string) $source->post_title : $title, 'draft', (int) $source->post_parent);
            Post_Stage::link($stage_id, (int) $source->ID);
            $operation_id = Post_Creation_Snapshot::record(
                'duplicate-post',
                [$stage_id],
                $args,
                (string) ($args['session_id'] ?? 'default')
            );
        }

        $stage = get_post($stage_id);

        return [
            'source_id'    => (int) $source->ID,
            'post_id'      => $stage_id,
            'status'       => $stage instanceof \WP_Post ? (string) $stage->post_status : 'draft',
            'preview_url'  => $stage instanceof \WP_Post ? (string) get_preview_post_link($stage) : '',
            'edit_link'    => get_edit_post_link($stage_id, 'raw'),
            'existing'     => null === $operation_id,
            'operation_id' => $operation_id,
        ];
    }

    /** Copy one post row, its meta and its terms. Returns the new post id. */
    private function copy(\WP_Post $source, string $title, string $status, int $parent): int
    {
        // wp_insert_post() and add_post_meta() unslash their input: slash
        // first, or block JSON escapes (\u003c) and Elementor's \/ are lost.
        $new_id = wp_insert_post(wp_slash([
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
        ]), true);

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
                    add_post_meta($new_id, (string) $key, Post_Stage::slash(maybe_unserialize($value)));
                }
            }
        }

        // The copied Elementor caches were built for the source document
        // (its post-{id}.css, its render); the copy must build its own.
        if (metadata_exists('post', $new_id, '_elementor_data')) {
            Elementor_Cache::invalidate_document($new_id);
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
