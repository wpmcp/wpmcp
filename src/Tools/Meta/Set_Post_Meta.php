<?php

namespace WPMCP\Tools\Meta;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Tools\Content\Content_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Set a single meta key/value on a post. Refuses protected meta keys (a
 * leading underscore, or is_protected_meta()) via the same Content_Guard
 * check the Content tools already use for create/update-post meta writes,
 * so the refusal rule stays in one place.
 *
 * The write is ordinary postmeta, so it routes through Safe_Mutation with
 * the existing object_type 'post': the post snapshot already captures the
 * full postmeta map before the mutation, and a rollback-operation restores
 * it exactly, through the same engine that already covers posts. No
 * meta-specific snapshot logic is needed.
 */
class Set_Post_Meta
{
    public function handle(array $args): array
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        $post    = $post_id ? get_post($post_id) : null;
        if (! $post) {
            throw new \InvalidArgumentException('Post not found');
        }

        if (! isset($args['key']) || '' === (string) $args['key']) {
            throw new \InvalidArgumentException('A meta key is required.');
        }
        $key   = (string) $args['key'];
        $value = $args['value'] ?? '';

        $guard = Content_Guard::check_meta([$key => $value]);
        if (true !== $guard) {
            throw new \InvalidArgumentException($guard);
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'set-post-meta',
                'args'        => $args,
            ],
            function () use ($post_id, $key, $value): void {
                update_post_meta($post_id, $key, $value);
            }
        );

        return [
            'post_id'      => $post_id,
            'key'          => $key,
            'value'        => get_post_meta($post_id, $key, true),
            'operation_id' => $out['operation_id'],
            'recoverable'  => true,
        ];
    }
}
