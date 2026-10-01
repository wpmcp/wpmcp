<?php

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A post write refused because another user is editing the post (issue
 * #452). A Mutation_Failed, so a tool that already reports a failed write
 * per post (find-replace-content) reports this one the same way; the
 * Registrar returns the carried WP_Error when a tool lets it through.
 */
class Post_Locked extends Mutation_Failed
{
    private \WP_Error $error;

    public function __construct(\WP_Error $error)
    {
        parent::__construct(esc_html($error->get_error_message()));
        $this->error = $error;
    }

    public function error(): \WP_Error
    {
        return $this->error;
    }
}
