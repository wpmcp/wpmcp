<?php

namespace WPMCP\Tools\ThemeBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Report which site part wins for a given context (issue #70 acceptance
 * criterion): pass a part type plus a context description (is_front_page,
 * is_404, is_search, is_archive, is_singular, post_type, post_id) and get the
 * winner plus every considered template with its match, specificity, and
 * priority. Read-only.
 */
class Resolve_Site_Part
{
    public function handle(array $args)
    {
        $part_type = Template_Store::validate_part_type(trim((string) ($args['part_type'] ?? '')));
        if (is_wp_error($part_type)) {
            return $part_type;
        }

        $context = is_array($args['context'] ?? null) ? $args['context'] : [];

        return Template_Resolver::resolve($part_type, $context);
    }
}
