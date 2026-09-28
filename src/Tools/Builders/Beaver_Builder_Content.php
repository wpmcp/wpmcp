<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read/write access to a page's Beaver Builder layout, which lives in
 * postmeta: `_fl_builder_data` (the published node map), `_fl_builder_draft`
 * (the editor's working copy), their `_settings` twins (layout CSS/JS) and
 * the `_fl_builder_enabled` flag. See Beaver_Builder_Nodes for the node
 * format. All of it is ordinary postmeta, so the post snapshot in
 * Safe_Mutation::run() captures and restores every row exactly.
 *
 * Writes follow what Beaver Builder itself does when it publishes or
 * restores a revision: the new node map goes to `_fl_builder_data` and, when
 * the page has a draft, to `_fl_builder_draft` too, so the editor opens on
 * what is live; the builder is enabled; the layout asset cache is cleared.
 * A page with no draft keeps none: the editor copies the published layout
 * and its settings into a new draft when it opens. The layout settings rows
 * are never touched.
 *
 * A draft that differs from the published layout holds unpublished editor
 * changes. Writing over it would discard them and writing beside it would
 * be overwritten by the next publish, so such a page is refused until the
 * draft is published or discarded in the editor.
 *
 * `post_content` keeps the text copy Beaver Builder rendered on its last
 * publish; rendering a new one needs the plugin's front end, so it is left
 * alone.
 */
class Beaver_Builder_Content
{
    public const DATA_META_KEY    = '_fl_builder_data';
    public const DRAFT_META_KEY   = '_fl_builder_draft';
    public const ENABLED_META_KEY = '_fl_builder_enabled';

    /**
     * The stored node map for a status, or [] when absent or unreadable.
     *
     * @return array<string,object>
     */
    public static function get_nodes(int $post_id, string $key = self::DATA_META_KEY): array
    {
        $raw = get_post_meta($post_id, $key, true);
        if (! is_array($raw)) {
            return [];
        }

        return array_filter($raw, 'is_object');
    }

    /** Whether the draft holds editor changes the published layout lacks. */
    public static function draft_pending(int $post_id): bool
    {
        if (! metadata_exists('post', $post_id, self::DRAFT_META_KEY)) {
            return false;
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the comparison Beaver Builder itself makes.
        return serialize(self::get_nodes($post_id, self::DRAFT_META_KEY)) !== serialize(self::get_nodes($post_id));
    }

    /** @return array<string,mixed> */
    public static function read(int $post_id): array
    {
        return [
            'post_id'       => $post_id,
            'builder'       => 'beaver-builder',
            'plugin_active' => Beaver_Builder_Cache::plugin_active(),
            'draft_pending' => self::draft_pending($post_id),
            'tree'          => Beaver_Builder_Nodes::tree(self::get_nodes($post_id)),
        ];
    }

    /**
     * Store a node map as the published layout (and the draft, when one
     * exists), enable the builder, and clear the layout asset cache.
     *
     * @param array<string,object> $nodes
     */
    public static function save(int $post_id, array $nodes): void
    {
        update_post_meta($post_id, self::DATA_META_KEY, self::slash($nodes));

        if (metadata_exists('post', $post_id, self::DRAFT_META_KEY)) {
            update_post_meta($post_id, self::DRAFT_META_KEY, self::slash($nodes));
        }

        if ('1' !== (string) get_post_meta($post_id, self::ENABLED_META_KEY, true)) {
            update_post_meta($post_id, self::ENABLED_META_KEY, true);
        }

        Beaver_Builder_Cache::clear($post_id);
    }

    /**
     * Slash every string, inside objects too. update_post_meta() unslashes
     * objects' properties (map_deep) but wp_slash() skips them, so without
     * this every backslash in a setting would be lost. Beaver Builder slashes
     * its own writes the same way (FLBuilderModel::slash_settings).
     *
     * @param array<string,object> $nodes
     * @return array<string,object>
     */
    private static function slash(array $nodes): array
    {
        return map_deep(
            Beaver_Builder_Nodes::copy($nodes),
            static fn ($value) => is_string($value) ? addslashes($value) : $value
        );
    }
}
