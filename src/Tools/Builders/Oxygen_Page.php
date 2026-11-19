<?php

namespace WPMCP\Tools\Builders;

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
        return Breakdance_Page::plugin_version('oxygen');
    }

    /** A note when the loaded Oxygen is not the major version recorded here, else null. */
    public static function version_note(?string $version): ?string
    {
        return Breakdance_Page::version_note('oxygen', $version);
    }

    /** Why an Oxygen 6 page cannot be written now, or null when it can. */
    public static function unavailable(): ?\WP_Error
    {
        return Breakdance_Page::unavailable('oxygen');
    }

    /** Whether the post already has a stored Oxygen document row. */
    public static function has_layout(int $post_id): bool
    {
        return Breakdance_Page::has_layout($post_id, 'oxygen');
    }

    /** sha256 of the stored data row, the hash writes are pinned to. */
    public static function hash(int $post_id): string
    {
        return Breakdance_Page::hash($post_id, 'oxygen');
    }

    /** @return array<int,array{id:int,date_gmt:string,author:int,hash:string}> */
    public static function revisions(int $post_id): array
    {
        return Breakdance_Page::revisions($post_id, 'oxygen');
    }

    /**
     * The document stored on one of the page's revisions.
     *
     * @throws \InvalidArgumentException when $revision is not a revision of $post_id holding a layout
     */
    public static function revision_document(int $post_id, string $revision): object
    {
        return Breakdance_Page::revision_document($post_id, $revision, 'oxygen');
    }

    /**
     * Add every top-level node of an Oxygen template, Component or part
     * under `to` ('' is the top level) from `index` on, with new node ids.
     *
     * @return array{0:object,1:string} document, and the first new node's id
     */
    public static function insert_template(object $doc, string $to, ?int $index, int $template_id): array
    {
        return Breakdance_Page::insert_template($doc, $to, $index, $template_id, 'oxygen');
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
     * Component, as Oxygen's saveGlobalBlock() does (see
     * Breakdance_Page::save_block()).
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    public static function save_component(int $post_id, array $args)
    {
        return Breakdance_Page::save_block($post_id, $args, 'oxygen', self::blank());
    }
}
