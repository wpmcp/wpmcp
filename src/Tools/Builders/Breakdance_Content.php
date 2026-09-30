<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read/write access to a page's layout on the Breakdance engine, which
 * lives in the `_breakdance_data` postmeta for Breakdance (`breakdance_data`
 * on pages saved by Breakdance 1.x, see Breakdance_Cache::prefix()) and in
 * `_oxygen_data` for Oxygen 6 (the same engine under another meta prefix):
 * a JSON object whose `tree_json_string` key holds the document as a JSON
 * string (see Breakdance_Tree for its shape). The engine writes that row
 * with wp_slash(wp_json_encode(...)) and reads it back with json_decode();
 * wpmcp does exactly the same, so the row it stores is the row the builder
 * would store. The generated CSS cache rows (see Breakdance_Cache) sit
 * beside it. All of it is ordinary postmeta, so the post snapshot in
 * Safe_Mutation::run() captures and restores every row exactly.
 *
 * `post_content` is not the engine's storage (a page keeps whatever it held
 * before the builder took over), so it is left alone.
 *
 * Every method takes the builder slug, 'breakdance' or 'oxygen'.
 */
class Breakdance_Content
{
    public const DATA_META_KEY = '_breakdance_data';

    /** The data row a builder's page uses: the one it has, else the one the loaded builder writes. */
    public static function data_key(string $builder = 'breakdance', int $post_id = 0): string
    {
        return Breakdance_Cache::data_key($builder, $post_id);
    }

    /** The stored document, or null when absent or not a valid tree. */
    public static function get_document(int $post_id, string $builder = 'breakdance'): ?object
    {
        $raw   = get_post_meta($post_id, self::data_key($builder, $post_id), true);
        $outer = is_string($raw) ? json_decode($raw, true) : null;
        $tree  = is_array($outer) ? ($outer['tree_json_string'] ?? null) : null;

        return is_string($tree) ? Breakdance_Tree::decode($tree) : null;
    }

    /** @return array<string,mixed> */
    public static function read(int $post_id, string $builder = 'breakdance'): array
    {
        $doc = self::get_document($post_id, $builder) ?? Breakdance_Tree::blank();

        return [
            'post_id'       => $post_id,
            'builder'       => $builder,
            'plugin_active' => Breakdance_Cache::plugin_active($builder),
            'next_node_id'  => $doc->_nextNodeId ?? null,
            'tree'          => Breakdance_Tree::nodes($doc),
        ];
    }

    /**
     * Store a document and refresh the generated cache: the stale cache rows
     * are dropped (the builder rebuilds missing rows on the next page view)
     * and, when the plugin is loaded, rebuilt right away. A write that
     * changes nothing touches nothing.
     */
    public static function save(int $post_id, object $doc, string $builder = 'breakdance'): void
    {
        $key   = self::data_key($builder, $post_id);
        $value = (string) wp_json_encode(['tree_json_string' => Breakdance_Tree::encode($doc)]);

        if (get_post_meta($post_id, $key, true) === $value) {
            return;
        }

        update_post_meta($post_id, $key, wp_slash($value));
        foreach (Breakdance_Cache::cache_keys($builder, $post_id) as $cache_key) {
            delete_post_meta($post_id, $cache_key);
        }
        Breakdance_Cache::regenerate($post_id, $builder);
    }
}
