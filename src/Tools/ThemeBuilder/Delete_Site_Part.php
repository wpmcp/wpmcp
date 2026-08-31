<?php

namespace WPMCP\Tools\ThemeBuilder;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delete a theme-builder site part by moving its wpmcp_template post to the
 * trash (reversible), which is also how a free-tier site frees the one slot
 * its part type allows. Snapshot-first through Safe_Mutation, so the returned
 * operation_id restores the template through the standard rollback tools.
 */
class Delete_Site_Part
{
    public function handle(array $args)
    {
        $id = (int) ($args['template_id'] ?? 0);
        if (! Template_Store::is_template($id)) {
            return new \WP_Error('wpmcp_template_not_found', "No site-part template found with id {$id}.");
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'wpmcp/delete-site-part',
                'args'        => $args,
            ],
            static function () use ($id) {
                wp_trash_post($id);
                return true;
            }
        );

        return [
            'operation_id' => $out['operation_id'],
            'template_id'  => $id,
            'deleted'      => 'trashed',
        ];
    }
}
