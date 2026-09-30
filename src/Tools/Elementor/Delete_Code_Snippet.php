<?php

namespace WPMCP\Tools\Elementor;

use WPMCP\Safety\Save_Filters;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delete an Elementor Custom Code snippet by moving it to the trash (reversible
 * through WordPress trash / restore-post), mirroring the default delete-post
 * path.
 */
class Delete_Code_Snippet
{
    public function handle(array $args)
    {
        $snippet_id = (int) ($args['snippet_id'] ?? 0);
        if ($snippet_id <= 0) {
            return new \WP_Error('missing_snippet_id', 'A snippet_id is required.');
        }
        if ('elementor_snippet' !== get_post_type($snippet_id)) {
            return new \WP_Error('not_a_snippet', "Post {$snippet_id} is not an elementor_snippet.");
        }

        Save_Filters::trash_post($snippet_id);

        return ['snippet_id' => $snippet_id, 'deleted' => 'trashed'];
    }
}
