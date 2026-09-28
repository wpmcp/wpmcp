<?php

namespace WPMCP\Tools\BlockBuilder;

use WPMCP\Safety\Post_Creation_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create a custom Gutenberg block from a spec (title, attributes, template).
 * Validated, stored as a wpmcp_block post, and registered via
 * register_block_type at runtime with a render_callback that interprets the
 * template (no code generation, no eval). Remove with delete-custom-block.
 * The creation is recorded as a 'post_create' ledger row under the caller's
 * session_id (issue #192): rolling it back deactivates the block (post
 * status draft) rather than deleting it.
 */
class Create_Custom_Block
{
    public function handle(array $args)
    {
        $spec = is_array($args['spec'] ?? null) ? $args['spec'] : [];

        $valid = Block_Spec::validate($spec);
        if (is_wp_error($valid)) {
            return $valid;
        }

        $id = Block_Spec_Store::create($spec);
        if (is_wp_error($id)) {
            return $id;
        }

        $operation_id = Post_Creation_Snapshot::record('create-custom-block', [$id], $args, (string) ($args['session_id'] ?? 'default'));

        $stored = Block_Spec_Store::get($id);
        return [
            'block_id'     => $id,
            'name'         => (string) ($stored['name'] ?? ''),
            'title'        => (string) ($stored['title'] ?? ''),
            'operation_id' => $operation_id,
        ];
    }
}
