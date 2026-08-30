<?php

namespace WPMCP\Tools\CustomCode;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Single option-backed store for agent-managed custom CSS/JS (issue #63).
 * Everything lives in ONE wp_option (self::OPTION) shaped as:
 *
 *   [
 *     'css' => [
 *       'site'  => '<css>',                 // site-wide block
 *       'posts' => [ <post_id> => '<css>' ] // page-scoped blocks
 *     ],
 *     'js'  => [
 *       'site' => '<js>'                    // site-wide, governance-gated
 *     ],
 *   ]
 *
 * Deliberately one option, not per-post meta: the existing snapshot/rollback
 * path already handles object_type 'option' end to end, so every write the
 * tools make is captured by Safe_Mutation with object_id self::OPTION and is
 * reversible via rollback-operation with NO safety-core change. This is the
 * "safe storage mechanism" the issue requires before site-wide snippet
 * management is allowed to exist at all.
 *
 * TODO(#63): builder-settings CSS (writing into Elementor page settings
 * custom_css) is a separate write path through Update_Builder_Content's
 * snapshot flow; not part of this slice.
 */
class Custom_Code_Store
{
    public const OPTION = 'wpmcp_custom_code';

    public static function read(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? $stored : [];
    }

    /** Merge one CSS block into the stored shape. $post_id null = site-wide. */
    public static function set_css(string $css, ?int $post_id = null): void
    {
        $data = self::read();

        if (null === $post_id) {
            $data['css']['site'] = $css;
        } else {
            $data['css']['posts'][ $post_id ] = $css;
        }

        update_option(self::OPTION, $data, false);
    }

    /** Replace the site-wide JS block. Callers must have passed Custom_Js_Guard. */
    public static function set_js(string $js): void
    {
        $data              = self::read();
        $data['js']['site'] = $js;

        update_option(self::OPTION, $data, false);
    }
}
