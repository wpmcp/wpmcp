<?php

namespace WPMCP\Tools\Builders;

use WPMCP\Safety\Save_Filters;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read/write access to a page's Divi (classic builder) layout, stored as
 * shortcodes directly in `post_content`, with `_et_pb_use_builder` = 'on' as
 * the on-flag postmeta. Because post_content and postmeta are both ordinary
 * columns/rows on the page post, the existing post snapshot in
 * Safe_Mutation::run() already captures and restores them, so no
 * safety-core change is needed for these edits to be undoable.
 */
class Divi_Content
{
    public const USE_BUILDER_META_KEY = '_et_pb_use_builder';

    public static function get_content(int $post_id): string
    {
        $post = get_post($post_id);

        return $post ? (string) $post->post_content : '';
    }

    public static function uses_builder(int $post_id): bool
    {
        return 'on' === get_post_meta($post_id, self::USE_BUILDER_META_KEY, true);
    }

    /** Write post_content and ensure the use-builder flag is set. */
    public static function save(int $post_id, string $content): void
    {
        Save_Filters::update_post([
            'ID'           => $post_id,
            'post_content' => wp_slash($content),
        ]);
        update_post_meta($post_id, self::USE_BUILDER_META_KEY, 'on');
    }
}
