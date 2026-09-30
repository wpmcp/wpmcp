<?php

namespace WPMCP\Tools\Builders;

use WPMCP\Safety\Save_Filters;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read/write access to a page's WPBakery layout: shortcodes in
 * `post_content`, plus two postmeta rows WPBakery keeps beside them.
 * `_wpb_vc_js_status` is its backend-editor flag and
 * `_wpb_shortcodes_custom_css` is the compiled `.vc_custom_<n>{...}` rules
 * from each element's `css` attribute. Post content and postmeta are both
 * captured by the post snapshot in Safe_Mutation::run(), so a rollback
 * restores the layout and both meta rows exactly with no safety-core change.
 *
 * Works whether or not the WPBakery plugin is loaded: nothing here calls
 * into it.
 */
class WPBakery_Content
{
    public const JS_STATUS_META_KEY  = '_wpb_vc_js_status';
    public const CUSTOM_CSS_META_KEY = '_wpb_shortcodes_custom_css';

    /** Whether the WPBakery plugin is loaded on this request. */
    public static function plugin_active(): bool
    {
        return (bool) apply_filters('wpmcp_wpbakery_active', defined('WPB_VC_VERSION'));
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
            'post_id'       => $post_id,
            'builder'       => 'wpbakery',
            'plugin_active' => self::plugin_active(),
            'tree'          => WPBakery_Shortcodes::tree($content),
            'content'       => $content,
            'js_status'     => (string) get_post_meta($post_id, self::JS_STATUS_META_KEY, true),
            'custom_css'    => (string) get_post_meta($post_id, self::CUSTOM_CSS_META_KEY, true),
        ];
    }

    /**
     * Write post_content, mark the page as a WPBakery page when it carries no
     * editor flag yet, and recompile the custom CSS meta when the set of
     * `css` attribute rules changed (left untouched otherwise).
     */
    public static function save(int $post_id, string $content): void
    {
        $before = WPBakery_Shortcodes::custom_css_rules(self::get_content($post_id));
        $after  = WPBakery_Shortcodes::custom_css_rules($content);

        Save_Filters::update_post([
            'ID'           => $post_id,
            'post_content' => wp_slash($content),
        ]);

        if ('' === (string) get_post_meta($post_id, self::JS_STATUS_META_KEY, true)) {
            update_post_meta($post_id, self::JS_STATUS_META_KEY, 'true');
        }

        if ($before !== $after) {
            if ([] === $after) {
                delete_post_meta($post_id, self::CUSTOM_CSS_META_KEY);
            } else {
                update_post_meta($post_id, self::CUSTOM_CSS_META_KEY, wp_slash(implode('', $after)));
            }
        }
    }
}
