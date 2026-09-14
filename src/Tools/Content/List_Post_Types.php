<?php

namespace WPMCP\Tools\Content;

if (! defined('ABSPATH')) {
    exit;
}

class List_Post_Types
{
    /** Internal/non-writable post types, never useful targets for content tools. */
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

    public function handle(array $args): array
    {
        $public_only = ! isset($args['public_only']) || (bool) $args['public_only'];
        $query_args  = $public_only ? ['public' => true] : [];
        $objects     = get_post_types($query_args, 'objects');

        $rows = [];
        foreach ($objects as $name => $object) {
            if (in_array($name, self::INTERNAL_TYPES, true)) {
                continue;
            }
            $rows[] = [
                'name'         => (string) $name,
                'label'        => (string) ($object->label ?? $name),
                'hierarchical' => (bool) ($object->hierarchical ?? false),
                'public'       => (bool) ($object->public ?? false),
                'taxonomies'   => array_values(get_object_taxonomies($name)),
            ];
        }

        return ['post_types' => $rows];
    }
}
