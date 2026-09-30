<?php

namespace WPMCP\Tools\Elementor;

use WPMCP\Safety\Save_Filters;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delete an Elementor library template by moving it to the trash. Trashing is
 * reversible through WordPress's own trash (restore-post / wp-admin), so no
 * extra snapshot is needed, mirroring the default delete-post path.
 */
class Delete_Theme_Template
{
    public function handle(array $args)
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        if ($post_id <= 0) {
            return new \WP_Error('missing_post_id', 'A post_id is required.');
        }
        if (! Elementor_Template_Data::is_template($post_id)) {
            return new \WP_Error('not_a_template', "Post {$post_id} is not an elementor_library template.");
        }

        Save_Filters::trash_post($post_id);

        return [
            'template_id' => $post_id,
            'deleted'     => 'trashed',
        ];
    }
}
