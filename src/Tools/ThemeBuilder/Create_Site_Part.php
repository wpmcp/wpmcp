<?php

namespace WPMCP\Tools\ThemeBuilder;

use WPMCP\Safety\Post_Creation_Snapshot;

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
 * A part goes live the moment it is published, so the create is recorded in
 * the ledger like create-post (issue #725): a 'post_create' row written right
 * after the post exists, whose operation_id is returned. rollback-operation
 * on it, or rollback-session for its session, moves the part to the trash,
 * which takes it off the pages it matched. If the row cannot be written, the
 * part is removed again and the call fails: a live part is never left
 * without its undo point. Every change to an existing template (update,
 * delete, status change) goes through Safe_Mutation.
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

        $operation_id = Post_Creation_Snapshot::record(
            'create-site-part',
            [ $id ],
            $args,
            (string) ($args['session_id'] ?? 'default')
        );

        return (array) Template_Store::get($id) + [ 'operation_id' => $operation_id ];
    }
}
