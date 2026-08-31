<?php

namespace WPMCP\Tools\Content;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shared guardrails for the content tools: which post types are writable,
 * and which meta keys are protected from direct writes.
 */
class Content_Guard
{
    /** Internal/non-writable post types, never valid targets for create/update/delete. */
    private const INTERNAL_TYPES = [
        'revision',
        'nav_menu_item',
        'custom_css',
        'customize_changeset',
        'oembed_cache',
        'user_request',
        'wp_template',
        'wp_template_part',
        'wp_global_styles',
        'wp_navigation',
        'attachment',
        // The theme-builder site parts (issue #70). Same reasoning as core's
        // wp_template / wp_template_part above: the payload is post_content
        // and it renders on every matching page, so the generic content tools
        // (edit_posts) must not reach markup whose own create tool requires
        // manage_options. The wpmcp_block / wpmcp_widget CPTs need no entry
        // here because their payload lives in protected `_`-prefixed meta,
        // which check_meta() already refuses.
        'wpmcp_template',
    ];

    public static function is_writable_post_type(string $post_type): bool
    {
        if ('' === $post_type || ! post_type_exists($post_type)) {
            return false;
        }
        return ! in_array($post_type, self::INTERNAL_TYPES, true);
    }

    /** Returns true if allowed, or a string error message if the meta map contains a protected key. */
    public static function check_meta(array $meta)
    {
        foreach (array_keys($meta) as $key) {
            $key = (string) $key;
            if ('_' === substr($key, 0, 1) || is_protected_meta($key, 'post')) {
                return "Refusing to write protected meta key \"{$key}\".";
            }
        }
        return true;
    }
}
