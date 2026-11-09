<?php

namespace WPMCP\Tools\Builders;

use WPMCP\Safety\Save_Filters;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read/write access to a page's Divi layout. Divi 4 (the classic builder)
 * stores it as shortcodes directly in `post_content`, with
 * `_et_pb_use_builder` = 'on' as the on-flag postmeta. Divi 5 stores it in
 * `post_content` too, as block markup (see Divi5_Blocks), and its builder
 * sets `_et_pb_use_divi_5` = 'on' beside the same flag on every save.
 * Because post_content and postmeta are both ordinary columns/rows on the
 * page post, the existing post snapshot in Safe_Mutation::run() already
 * captures and restores them, so no safety-core change is needed for these
 * edits to be undoable.
 */
class Divi_Content
{
    public const USE_BUILDER_META_KEY = '_et_pb_use_builder';

    public const USE_DIVI5_META_KEY = '_et_pb_use_divi_5';

    public static function get_content(int $post_id): string
    {
        $post = get_post($post_id);

        return $post ? (string) $post->post_content : '';
    }

    public static function uses_builder(int $post_id): bool
    {
        return 'on' === get_post_meta($post_id, self::USE_BUILDER_META_KEY, true);
    }

    /**
     * Write post_content and ensure the use-builder flag is set, and the
     * Divi 5 flag too when the content is a Divi 5 layout.
     */
    public static function save(int $post_id, string $content): void
    {
        if (Divi5_Blocks::is_divi5($content)) {
            self::save5($post_id, $content);
            return;
        }
        Save_Filters::update_post([
            'ID'           => $post_id,
            'post_content' => wp_slash($content),
        ]);
        update_post_meta($post_id, self::USE_BUILDER_META_KEY, 'on');
    }

    // --------------------------------------------------------------- Divi 5

    /** Whether the page holds a Divi 5 layout (or was last saved by Divi 5). */
    public static function is_divi5(int $post_id): bool
    {
        return 'on' === get_post_meta($post_id, self::USE_DIVI5_META_KEY, true)
            || Divi5_Blocks::is_divi5(self::get_content($post_id));
    }

    /** Optimistic-lock fingerprint of the stored layout, for expected_hash. */
    public static function hash(int $post_id): string
    {
        return hash('sha256', self::get_content($post_id));
    }

    /**
     * The loaded Divi builder's version (`ET_BUILDER_VERSION`, the Divi
     * theme's version), or null when Divi is not loaded. Filterable so the
     * suite can stand in for the theme.
     */
    public static function plugin_version(): ?string
    {
        $version = apply_filters('wpmcp_divi_version', defined('ET_BUILDER_VERSION') ? (string) ET_BUILDER_VERSION : null);

        return is_string($version) && '' !== $version ? $version : null;
    }

    /** Whether Divi 5 is loaded with its builder on. */
    public static function divi5_active(): bool
    {
        $version = self::plugin_version();
        if (null === $version || (int) $version < 5) {
            return false;
        }

        return ! function_exists('et_builder_d5_enabled') || (bool) et_builder_d5_enabled();
    }

    /**
     * Why a Divi 5 layout cannot be written now, or null when it can: Divi 5
     * owns the CSS it generates for a page and clears it when the page is
     * saved, so writes need it loaded.
     */
    public static function divi5_unavailable(): ?\WP_Error
    {
        $version = self::plugin_version();
        if (null === $version) {
            return new \WP_Error(
                'plugin_missing',
                'Divi 5 is not active on this site, so Divi 5 layouts cannot be edited. Install and activate Divi 5 (a theme: ' . admin_url('themes.php') . '), then try again.',
                ['status' => 403, 'plugin' => 'Divi', 'url' => admin_url('themes.php')]
            );
        }
        if (! self::divi5_active()) {
            return new \WP_Error(
                'plugin_version_unsupported',
                "Divi {$version} is loaded, but editing a Divi 5 layout needs Divi 5 with its builder enabled. Update Divi to version 5, then try again.",
                ['status' => 409, 'plugin' => 'Divi', 'plugin_version' => $version]
            );
        }

        return null;
    }

    /** @return array<string,mixed> */
    public static function read5(int $post_id): array
    {
        $content = self::get_content($post_id);
        $out     = [
            'post_id'        => $post_id,
            'builder'        => 'divi',
            'format'         => 'divi5',
            'plugin_active'  => self::divi5_active(),
            'plugin_version' => self::plugin_version(),
            'use_builder'    => self::uses_builder($post_id),
            'hash'           => hash('sha256', $content),
        ];

        try {
            $out['tree'] = Divi5_Blocks::tree($content);
        } catch (\InvalidArgumentException $e) {
            $out['tree']       = [];
            $out['tree_error'] = $e->getMessage();
        }
        $out['content'] = $content;

        return $out;
    }

    /** Write a Divi 5 layout and set both flags Divi 5's own save sets. */
    public static function save5(int $post_id, string $content): void
    {
        Save_Filters::update_post([
            'ID'           => $post_id,
            'post_content' => wp_slash($content),
        ]);
        update_post_meta($post_id, self::USE_BUILDER_META_KEY, 'on');
        update_post_meta($post_id, self::USE_DIVI5_META_KEY, 'on');
    }
}
