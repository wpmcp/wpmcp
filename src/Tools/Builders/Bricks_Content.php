<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read/write access to a page's Bricks builder structure, stored in the
 * `_bricks_page_content_2` postmeta key. Bricks itself stores the element
 * tree as a NATIVE (serialized) array there (see Bricks includes/ajax.php,
 * which calls update_post_meta with the raw $content array), so this reader
 * returns arrays and the writer stores arrays, keeping what an agent writes
 * fully readable by Bricks. A legacy JSON string is still tolerated on read.
 *
 * Read and written directly as postmeta rather than through the Bricks
 * plugin's runtime, matching how Elementor_Page_Data treats `_elementor_data`:
 * because it is ordinary postmeta on the page post, the existing post snapshot
 * in Safe_Mutation::run() already captures and restores it, so no safety-core
 * change is needed for these edits to be undoable.
 */
class Bricks_Content
{
    public const META_KEY = '_bricks_page_content_2';

    /** Return the element tree as an array, or null if missing/invalid. */
    public static function get(int $post_id): ?array
    {
        $raw = get_post_meta($post_id, self::META_KEY, true);

        // How real Bricks stores it: a native array in postmeta.
        if (is_array($raw)) {
            return $raw;
        }

        // Tolerate a legacy JSON-encoded string (older data / external writer).
        if (is_string($raw) && '' !== $raw) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    public static function save(int $post_id, array $elements): void
    {
        // Store a native array exactly as Bricks does so Bricks can read it.
        // wp_slash keeps clean (unslashed) data byte-accurate through
        // update_post_meta's internal wp_unslash.
        update_post_meta($post_id, self::META_KEY, wp_slash($elements));

        // What Bricks' own save does after the layout row (issue #914): the
        // editor mode that makes Bricks render the page, then the CSS file.
        Bricks_Cache::sync_editor_mode($post_id);
        Bricks_Cache::regenerate($post_id);
    }
}
