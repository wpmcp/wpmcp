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
        // The in-admin chat conversation store (issue #73). Named as a
        // literal rather than through the class constant so this guard
        // holds even on a request where that CPT was never registered,
        // and so this list depends on nothing outside the content tools.
        // Conversations are per-user private provider exchanges between
        // one admin and their own model, never generic content.
        'wpmcp_chat_convo',
    ];

    /**
     * Post types that hold plugin-internal per-user data and are therefore
     * never content an agent may read, whatever capability the caller holds.
     *
     * Separate from INTERNAL_TYPES because that list is about writes, and
     * some of it (attachment) is legitimately readable. This list is about
     * reads: a chat conversation is one admin's private exchange with their
     * own model, and list-posts (capability edit_posts, arbitrary post_type,
     * default status 'any') would otherwise enumerate every user's.
     * WP_Query does not help here: it skips its private-post permission
     * clause on the 'any' status branch, so post_status bounds nothing.
     *
     * Named as literals: the chat store lives outside the content tools and
     * the directory build does not ship it, so this list must not depend on
     * a class constant.
     */
    private const PRIVATE_TYPES = [
        'wpmcp_chat_convo',
    ];

    /** Whether the content tools may read a post of this type at all. */
    public static function is_agent_readable_post_type(string $post_type): bool
    {
        return ! in_array($post_type, self::PRIVATE_TYPES, true);
    }

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
