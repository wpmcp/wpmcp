<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read/write access to a page's Breakdance layout, which lives in the
 * `_breakdance_data` postmeta: a JSON object whose `tree_json_string` key
 * holds the document as a JSON string (see Breakdance_Tree for its shape).
 * Breakdance writes that row with wp_slash(wp_json_encode(...)) and reads
 * it back with json_decode(); wpmcp does exactly the same, so the row it
 * stores is the row Breakdance would store. The generated CSS cache rows
 * (see Breakdance_Cache) sit beside it. All of it is ordinary postmeta, so
 * the post snapshot in Safe_Mutation::run() captures and restores every row
 * exactly.
 *
 * `post_content` is not Breakdance's storage (a page keeps whatever it held
 * before Breakdance took over), so it is left alone.
 */
class Breakdance_Content
{
    public const DATA_META_KEY = '_breakdance_data';

    /** The stored document, or null when absent or not a valid tree. */
    public static function get_document(int $post_id): ?object
    {
        $raw   = get_post_meta($post_id, self::DATA_META_KEY, true);
        $outer = is_string($raw) ? json_decode($raw, true) : null;
        $tree  = is_array($outer) ? ($outer['tree_json_string'] ?? null) : null;

        return is_string($tree) ? Breakdance_Tree::decode($tree) : null;
    }

    /** @return array<string,mixed> */
    public static function read(int $post_id): array
    {
        $doc = self::get_document($post_id) ?? Breakdance_Tree::blank();

        return [
            'post_id'       => $post_id,
            'builder'       => 'breakdance',
            'plugin_active' => Breakdance_Cache::plugin_active(),
            'next_node_id'  => $doc->_nextNodeId ?? null,
            'tree'          => Breakdance_Tree::nodes($doc),
        ];
    }

    /**
     * Store a document and refresh the generated cache: the stale cache rows
     * are dropped (Breakdance rebuilds missing rows on the next page view)
     * and, when the plugin is loaded, rebuilt right away. A write that
     * changes nothing touches nothing.
     */
    public static function save(int $post_id, object $doc): void
    {
        $value = (string) wp_json_encode(['tree_json_string' => Breakdance_Tree::encode($doc)]);

        if (get_post_meta($post_id, self::DATA_META_KEY, true) === $value) {
            return;
        }

        update_post_meta($post_id, self::DATA_META_KEY, wp_slash($value));
        delete_post_meta($post_id, Breakdance_Cache::CSS_META_KEY);
        delete_post_meta($post_id, Breakdance_Cache::DEPENDENCY_META_KEY);
        Breakdance_Cache::regenerate($post_id);
    }
}
