<?php

namespace WPMCP\Tools\Context;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only orientation payload for an agent connecting to this site: name,
 * URL, tagline, WordPress/PHP versions, and (in later behaviors) theme,
 * plugins, content model, users, locale, and integration capabilities.
 *
 * Deliberately excludes the admin email and any other secret-shaped value:
 * this tool is meant to be safe at a low capability bar (edit_posts), so
 * nothing it returns should require a stronger gate to see.
 */
class Get_Site_Context
{
    public function handle(array $args): array
    {
        return [
            'site' => [
                'name'    => get_bloginfo('name'),
                'url'     => home_url(),
                'tagline' => get_bloginfo('description'),
            ],
            'wordpress_version' => get_bloginfo('version'),
            'php_version'       => PHP_VERSION,
        ];
    }
}
