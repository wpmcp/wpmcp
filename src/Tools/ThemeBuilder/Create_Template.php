<?php

namespace WPMCP\Tools\ThemeBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create a theme-builder template (header, footer, or 404 site part) with an
 * include/exclude condition set and a tie-break priority. Conditions are
 * validated, the free-tier cap (one template per part type) is enforced, and
 * the template is stored as a wpmcp_template post. Creating destroys nothing;
 * templates are removable through the standard trash. Update and delete tools
 * (snapshot-first via Safe_Mutation) are a follow-up slice of issue #70.
 */
class Create_Template
{
    public function handle(array $args)
    {
        $conditions = is_array($args['conditions'] ?? null) ? $args['conditions'] : [];

        $id = Template_Store::create(
            (string) ($args['part_type'] ?? ''),
            (string) ($args['title'] ?? ''),
            (string) ($args['content'] ?? ''),
            $conditions,
            (int) ($args['priority'] ?? 0)
        );
        if (is_wp_error($id)) {
            return $id;
        }

        return Template_Store::get($id);
    }
}
