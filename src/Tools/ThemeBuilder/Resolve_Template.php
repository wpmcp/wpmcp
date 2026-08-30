<?php

namespace WPMCP\Tools\ThemeBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Report which template wins for a given context (issue #70 acceptance
 * criterion): pass a part type plus a context description (is_front_page,
 * is_404, is_search, is_archive, is_singular, post_type, post_id) and get the
 * winner plus every considered template with its match, specificity, and
 * priority. Read-only.
 */
class Resolve_Template
{
    public function handle(array $args): array
    {
        $context = is_array($args['context'] ?? null) ? $args['context'] : [];
        return Template_Resolver::resolve((string) ($args['part_type'] ?? ''), $context);
    }
}
