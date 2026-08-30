<?php

namespace WPMCP\Tools\ThemeBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * List the theme-builder templates on this site (id, part type, title,
 * conditions, priority, status), optionally filtered by part type. Read-only.
 */
class List_Templates
{
    public function handle(array $args): array
    {
        $part_type = isset($args['part_type']) ? (string) $args['part_type'] : null;
        return ['templates' => Template_Store::all(false, $part_type)];
    }
}
