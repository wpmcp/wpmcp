<?php

namespace WPMCP\Tools\Builders;

use WPMCP\Safety\Post_Creation_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Page-level pieces of Oxygen 6 editing (issue #635) that sit beside the
 * node operations of Breakdance_Tree. Oxygen 6 is the Breakdance engine run
 * with BREAKDANCE_MODE 'oxygen' (see Breakdance_Cache), so a page's document
 * is the `_oxygen_data` row Breakdance_Content reads and writes. Recorded
 * from the Oxygen 6.1 source:
 *
 * - Revisions are ordinary WordPress revisions; Oxygen copies the page's
 *   `_oxygen_data` row onto each one it makes (Breakdance\Revisions,
 *   save_revisioned_meta_fields()), so a revision that carries the row holds
 *   the layout to restore.
 * - Templates are posts of the `oxygen_template`, `oxygen_header`,
 *   `oxygen_footer`, `oxygen_block` (Oxygen calls these Components) and
 *   `oxygen_part` (design library) types, each keeping its document in the
 *   same row a page does.
 * - A Component sits in a page as an `OxygenElements\Component` node whose
 *   `content.content.block.componentId` names the `oxygen_block` post.
 *   Oxygen's "save as Component" (saveGlobalBlock()) inserts a new
 *   oxygen_block post and stores the tree in its data row; Oxygen forces
 *   that post type to publish.
 */
final class Oxygen_Page
{
    /** How many revisions a page read lists, newest first. */
    public const REVISION_LIMIT = 20;

    /** The major version this adapter was recorded from. */
    public const SUPPORTED_MAJOR = 6;

    public const DATA_KEY = '_oxygen_data';

    public const COMPONENT_TYPE = 'OxygenElements\\Component';

    public const COMPONENT_POST_TYPE = 'oxygen_block';

    /** Every Oxygen post type whose tree can be added into a page. */
    public const TEMPLATE_POST_TYPES = ['oxygen_template', 'oxygen_header', 'oxygen_footer', 'oxygen_block', 'oxygen_part'];

    /** The loaded Oxygen's version, or null when Oxygen 6 is not loaded. */
    public static function plugin_version(): ?string
    {
        if (! Breakdance_Cache::plugin_active('oxygen') || ! defined('__BREAKDANCE_VERSION')) {
            return null;
        }
        $version = (string) constant('__BREAKDANCE_VERSION');

        return '' === $version ? null : $version;
    }

    /** A note when the loaded Oxygen is not the major version recorded here, else null. */
    public static function version_note(?string $version): ?string
    {
        if (null === $version || (int) $version === self::SUPPORTED_MAJOR) {
            return null;
        }

        return "Oxygen {$version} is loaded; this adapter was built against Oxygen " . self::SUPPORTED_MAJOR . '.x, so check edits in the Oxygen editor.';
    }

    /**
     * Why an Oxygen 6 page cannot be written now, or null when it can: the
     * engine owns the CSS cache and the post types a write touches, so
     * writes need Oxygen 6 loaded.
     */
    public static function unavailable(): ?\WP_Error
    {
        if (Breakdance_Cache::plugin_active('oxygen')) {
            return null;
        }
        $url = admin_url('plugins.php');

        return new \WP_Error(
            'plugin_missing',
            "Oxygen 6 is not active on this site, so Oxygen pages cannot be edited. Install and activate Oxygen 6 ({$url}), then try again.",
            ['status' => 403, 'plugin' => 'oxygen', 'url' => $url]
        );
    }

    /** Whether the post already has a stored Oxygen document row. */
    public static function has_layout(int $post_id): bool
    {
        return metadata_exists('post', $post_id, self::DATA_KEY);
    }

    /** sha256 of the stored data row, the hash writes are pinned to. */
    public static function hash(int $post_id): string
    {
        $raw = get_post_meta($post_id, self::DATA_KEY, true);

        return hash('sha256', is_string($raw) ? $raw : '');
    }

    /** @return array<int,array{id:int,date_gmt:string,author:int,hash:string}> */
    public static function revisions(int $post_id): array
    {
        $out = [];
        foreach (wp_get_post_revisions($post_id, ['posts_per_page' => self::REVISION_LIMIT, 'check_enabled' => false]) as $revision) {
            if (wp_is_post_autosave($revision) || null === Breakdance_Content::get_document((int) $revision->ID, 'oxygen')) {
                continue;
            }
            $out[] = [
                'id'       => (int) $revision->ID,
                'date_gmt' => (string) $revision->post_date_gmt,
                'author'   => (int) $revision->post_author,
                'hash'     => self::hash((int) $revision->ID),
            ];
        }

        return $out;
    }

    /**
     * The document stored on one of the page's revisions.
     *
     * @throws \InvalidArgumentException when $revision is not a revision of $post_id holding a layout
     */
    public static function revision_document(int $post_id, string $revision): object
    {
        $id   = ctype_digit($revision) ? (int) $revision : 0;
        $post = $id > 0 ? get_post($id) : null;
        if (! $post || 'revision' !== $post->post_type || (int) $post->post_parent !== $post_id) {
            throw new \InvalidArgumentException(esc_html("restore needs path: the id of a revision of this page (see revisions in get-builder-content); got '{$revision}'."));
        }
        $doc = Breakdance_Content::get_document($id, 'oxygen');
        if (null === $doc) {
            throw new \InvalidArgumentException(esc_html("Revision {$id} holds no Oxygen layout."));
        }

        return $doc;
    }

    /**
     * Add every top-level node of an Oxygen template, Component or part
     * under `to` ('' is the top level) from `index` on, with new node ids.
     *
     * @return array{0:object,1:string} document, and the first new node's id
     */
    public static function insert_template(object $doc, string $to, ?int $index, int $template_id): array
    {
        $post   = $template_id > 0 ? get_post($template_id) : null;
        $source = $post && in_array($post->post_type, self::TEMPLATE_POST_TYPES, true) ? Breakdance_Content::get_document($template_id, 'oxygen') : null;
        if (null === $source) {
            throw new \InvalidArgumentException(esc_html("No Oxygen template with id {$template_id}; read get-builder-content scope templates for the ids."));
        }
        $nodes = Breakdance_Tree::nodes($source);
        if ([] === $nodes) {
            throw new \InvalidArgumentException(esc_html("Template {$template_id} holds no elements."));
        }

        $first = null;
        foreach ($nodes as $offset => $node) {
            [$doc, $id] = Breakdance_Tree::add($doc, $to, null === $index ? null : $index + $offset, get_object_vars($node));
            $first      = $first ?? $id;
        }

        return [$doc, (string) $first];
    }

    /**
     * The node spec of a Component instance, the shape Oxygen's Component
     * element reads (its editor recomputes the targets on load).
     *
     * @return array<string,mixed>
     */
    public static function component_spec(int $component_id): array
    {
        $post = $component_id > 0 ? get_post($component_id) : null;
        if (! $post || self::COMPONENT_POST_TYPE !== $post->post_type) {
            throw new \InvalidArgumentException(esc_html("No Oxygen Component with id {$component_id}; read get-builder-content scope templates (type block) for the ids."));
        }

        return [
            'type'       => self::COMPONENT_TYPE,
            'properties' => (object) ['content' => (object) ['content' => (object) ['block' => (object) [
                'componentId' => $component_id,
                'targets'     => [],
                'properties'  => new \stdClass(),
            ]]]],
        ];
    }

    /** An empty document as the Oxygen 6.1 editor starts and exports one. */
    public static function blank(): object
    {
        return (object) [
            'root'        => (object) [
                'id'       => 1,
                'data'     => (object) ['type' => 'root', 'properties' => new \stdClass()],
                'children' => [],
            ],
            '_nextNodeId' => 100,
            'status'      => 'exported',
        ];
    }

    /**
     * Save one node of a page (with no path, the whole page) as a new
     * Component, as Oxygen's saveGlobalBlock() does. The page is left as it
     * is. Recorded as a creation, so rollback-operation trashes the new post.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    public static function save_component(int $post_id, array $args)
    {
        if (! post_type_exists(self::COMPONENT_POST_TYPE)) {
            return new \WP_Error('builder_not_loaded', 'Saving a Component needs Oxygen 6 loaded on this site, since the Component post type is its own.');
        }

        $spec  = $args['element'] ?? null;
        $title = is_array($spec) && is_string($spec['title'] ?? null) ? trim($spec['title']) : '';
        if ('' === $title) {
            return new \WP_Error('invalid_oxygen_request', 'save_template needs element {title}: the Component name to save under.');
        }

        $page = Breakdance_Content::get_document($post_id, 'oxygen');
        if (null === $page) {
            return new \WP_Error('invalid_oxygen_request', 'This page holds no Oxygen layout to save.');
        }

        $path = (string) ($args['path'] ?? '');
        try {
            if ('' === $path) {
                $doc = $page;
            } else {
                [$doc] = Breakdance_Tree::add(self::blank(), '', null, get_object_vars(Breakdance_Tree::node($page, $path)));
            }
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('invalid_oxygen_request', $e->getMessage());
        }

        $type_object = get_post_type_object(self::COMPONENT_POST_TYPE);
        if ($type_object && ! current_user_can($type_object->cap->edit_posts)) {
            return new \WP_Error('forbidden', 'Saving a Component needs the capability to create Oxygen Components.');
        }

        $id = wp_insert_post(wp_slash([
            'post_title'  => sanitize_text_field($title),
            'post_status' => 'publish',
            'post_type'   => self::COMPONENT_POST_TYPE,
        ]), true);
        if (is_wp_error($id)) {
            return $id;
        }
        $id = (int) $id;
        Breakdance_Content::save($id, $doc, 'oxygen');

        $operation_id = Post_Creation_Snapshot::record('update-builder-content', [$id], $args, (string) ($args['session_id'] ?? 'default'));

        return [
            'operation_id' => $operation_id,
            'post_id'      => $post_id,
            'builder'      => 'oxygen',
            'template_id'  => $id,
            'type'         => 'block',
            'hash'         => self::hash($id),
        ];
    }
}
