<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Save a page's Elementor content as a reusable library template
 * (`elementor_library` post). Creating a template destroys nothing, so this is
 * not routed through Safe_Mutation (mirroring create-post); the new template
 * can be removed with delete-post.
 */
class Save_As_Template
{
    public function handle(array $args)
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        if ($post_id <= 0) {
            return new \WP_Error('missing_post_id', 'A post_id is required.');
        }
        $source = get_post($post_id);
        if (! $source instanceof \WP_Post) {
            return new \WP_Error('post_not_found', "No post found with id {$post_id}.");
        }

        $title = (string) ($args['title'] ?? '');
        if ('' === trim($title)) {
            return new \WP_Error('missing_title', 'A title is required for the template.');
        }

        $type     = Elementor_Template_Data::normalize_type((string) ($args['template_type'] ?? 'page'));
        $elements = Elementor_Page_Data::get($post_id);

        // The template keeps the source's protection (issue #504): its
        // password, and a private status when the source is not public.
        $visibility  = Elementor_Template_Data::visibility_of($source);
        $template_id = Elementor_Template_Data::create($title, $type, $elements, $visibility);
        if (is_wp_error($template_id)) {
            return $template_id;
        }

        return [
            'template_id'   => $template_id,
            'title'         => sanitize_text_field($title),
            'template_type' => $type,
            'status'        => (string) get_post_status($template_id),
            'protected'     => '' !== $visibility['post_password'],
        ];
    }
}
