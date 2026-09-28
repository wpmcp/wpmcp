<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read/write access to a page's Avada (Fusion Builder) layout: shortcodes
 * in `post_content`, plus the `fusion_builder_status` postmeta flag ('active'
 * on a builder page). Post content and postmeta are both captured by the post
 * snapshot in Safe_Mutation::run(), so a rollback restores the layout and the
 * flag exactly; the rollback path then invalidates the dynamic CSS cache the
 * same way save() does.
 *
 * Works whether or not the Avada builder is loaded: nothing here calls into
 * it.
 */
class Avada_Content
{
    /** Whether the Avada builder plugin is loaded on this request. */
    public static function plugin_active(): bool
    {
        return (bool) apply_filters('wpmcp_avada_active', defined('FUSION_BUILDER_VERSION'));
    }

    public static function get_content(int $post_id): string
    {
        $post = get_post($post_id);

        return $post ? (string) $post->post_content : '';
    }

    /** @return array<string,mixed> */
    public static function read(int $post_id): array
    {
        $content = self::get_content($post_id);

        return [
            'post_id'        => $post_id,
            'builder'        => 'avada',
            'plugin_active'  => self::plugin_active(),
            'tree'           => Avada_Shortcodes::tree($content),
            'content'        => $content,
            'builder_status' => (string) get_post_meta($post_id, Avada_Cache::STATUS_META_KEY, true),
        ];
    }

    /**
     * Write post_content, mark the page as a builder page when it carries no
     * flag yet (an existing value is kept), and invalidate its dynamic CSS.
     */
    public static function save(int $post_id, string $content): void
    {
        wp_update_post([
            'ID'           => $post_id,
            'post_content' => wp_slash($content),
        ]);

        if ('' === (string) get_post_meta($post_id, Avada_Cache::STATUS_META_KEY, true)) {
            update_post_meta($post_id, Avada_Cache::STATUS_META_KEY, 'active');
        }

        Avada_Cache::invalidate($post_id);
    }
}
