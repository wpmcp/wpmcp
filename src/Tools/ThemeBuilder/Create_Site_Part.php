<?php

namespace WPMCP\Tools\ThemeBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create a theme-builder site part (header, footer, or 404 template) with an
 * include/exclude condition set and a tie-break priority. Conditions are
 * validated, the per-part-type cap is enforced, the markup is run through
 * wp_kses_post on the way in, and the result is stored as a wpmcp_template
 * post.
 *
 * NOT snapshot-first, deliberately, and this is the same create-only
 * exemption every other create tool in the plugin takes: there is no prior
 * state for Snapshot::capture() to record, and the undo for a create is
 * wpmcp/delete-site-part, which IS snapshot-first. Every mutation of an
 * existing template (delete, status change) goes through Safe_Mutation.
 */
class Create_Site_Part
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
