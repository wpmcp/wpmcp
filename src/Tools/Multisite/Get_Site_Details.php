<?php

namespace WPMCP\Tools\Multisite;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: a single network site's details (blog_id, url, name,
 * last_updated) via Multisite_Adapter::site_details(). Only registered when
 * is_multisite() is true (see Plugin.php).
 *
 * Throws for entirely missing or non-numeric blog_id (malformed input,
 * mirroring Delete_Identity's "entirely missing input" handling); an
 * unrecognized-but-well-formed blog_id, or a call outside a network, returns
 * a WP_Error from Multisite_Adapter rather than a thrown exception.
 */
class Get_Site_Details
{
    public function handle(array $args)
    {
        if (! isset($args['blog_id']) || ! is_numeric($args['blog_id'])) {
            throw new \InvalidArgumentException('A numeric blog_id is required.');
        }

        return Multisite_Adapter::site_details((int) $args['blog_id']);
    }
}
