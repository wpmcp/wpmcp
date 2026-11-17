<?php

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A post write refused before anything was written, carrying the WP_Error
 * the caller gets (issues #452 and #893). A Mutation_Failed, so a tool that
 * reports a failed write per post (find-replace-content) reports it the
 * same way; the Registrar returns the carried WP_Error when a tool lets it
 * through.
 */
class Post_Write_Refused extends Mutation_Failed
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
