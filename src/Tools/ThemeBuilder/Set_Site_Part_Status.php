<?php

namespace WPMCP\Tools\ThemeBuilder;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Activate (publish) or deactivate (draft) a theme-builder site part by id.
 * Only active templates are considered by the resolver, so this is the switch
 * that takes a header or footer off the front end without deleting it.
 * Snapshot-first through Safe_Mutation.
 */
class Set_Site_Part_Status
{
    public function handle(array $args)
    {
        $id = (int) ($args['template_id'] ?? 0);
        if (! Template_Store::is_template($id)) {
            return new \WP_Error('wpmcp_template_not_found', "No site-part template found with id {$id}.");
        }

        $status = 'draft' === ($args['status'] ?? '') ? 'draft' : 'publish';

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'wpmcp/set-site-part-status',
                'args'        => $args,
            ],
            static function () use ($id, $status) {
                wp_update_post(['ID' => $id, 'post_status' => $status]);
                return true;
            },
            static function () use ($id, $status): bool {
                clean_post_cache($id);
                return $status === get_post_status($id);
            }
        );

        return [
            'operation_id' => $out['operation_id'],
            'template_id'  => $id,
            'status'       => $status,
        ];
    }
}
