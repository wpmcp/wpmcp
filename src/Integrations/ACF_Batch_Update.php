<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The batch-update-fields operation of the acf dispatcher pair (issue #291):
 * ACF field values across many posts in one call.
 *
 * The whole batch is checked first, every value through acf_validate_value()
 * and every post against edit_post, and one bad value refuses the batch
 * before anything is written or snapshotted. Each post is then written
 * through its own Safe_Mutation, all under ONE session id (the caller's, or
 * a fresh one the response returns), so a single rollback-session puts every
 * post back exactly.
 */
class ACF_Batch_Update
{
    /** Largest batch one call accepts. */
    public const MAX_UPDATES = 100;

    /** The operation definition the ACF integration merges into its catalog. */
    public static function definition(): array
    {
        return [
            'mode'               => 'write',
            'objects'            => [ 'updates.*.post_id' => 'post' ],
            'tier'               => 'pro',
            'description'        => 'Set ACF field values on many posts at once. Every value is validated first and one invalid value refuses the whole batch. Each post is snapshotted under one session_id (returned), so rollback-session undoes the batch. Disabled by default (wpmcp_enable_acf_write filter)',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_acf_write', false),
            'self_snapshotting'  => true,
            'input_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'updates' => [
                        'type'     => 'array',
                        'minItems' => 1,
                        'maxItems' => self::MAX_UPDATES,
                        'items'    => [
                            'type'       => 'object',
                            'properties' => [
                                'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                                'fields'  => [ 'type' => 'object', 'minProperties' => 1 ],
                            ],
                            'required'   => [ 'post_id', 'fields' ],
                        ],
                    ],
                ],
                'required'   => [ 'updates' ],
            ],
            'validate'           => [ self::class, 'validate' ],
            'handler'            => [ self::class, 'handle' ],
        ];
    }

    /** Refuse the batch, before any write, when a post is missing or not editable or a value is invalid. */
    public static function validate(array $args): ?array
    {
        $errors = [];
        foreach ((array) $args['updates'] as $i => $update) {
            $post_id = (int) $update['post_id'];
            if (! get_post($post_id)) {
                $errors[] = [ 'index' => $i, 'post_id' => $post_id, 'field' => '', 'message' => 'Post not found.' ];
                continue;
            }
            if (! current_user_can('edit_post', $post_id)) {
                $errors[] = [ 'index' => $i, 'post_id' => $post_id, 'field' => '', 'message' => 'You cannot edit this post.' ];
                continue;
            }
            foreach (ACF_Schema::validate_values((array) $update['fields'], $post_id)['errors'] as $error) {
                $errors[] = [ 'index' => $i, 'post_id' => $post_id ] + $error;
            }
        }

        return [] === $errors ? null : [
            'code'    => 'invalid_field_values',
            'message' => sprintf('%d value(s) failed validation; nothing was written.', count($errors)),
            'data'    => [ 'errors' => $errors ],
        ];
    }

    /** Write each post under its own snapshot, all in the context's session. */
    public static function handle(array $args, array $context): array
    {
        $results = [];
        $ids     = [];
        foreach ((array) $args['updates'] as $update) {
            $post_id = (int) $update['post_id'];
            $out     = Safe_Mutation::run(
                [
                    'object_type' => 'post',
                    'object_id'   => $post_id,
                    'session_id'  => (string) $context['session_id'],
                    'tool_name'   => (string) $context['tool_name'],
                    'args'        => [ 'operation' => (string) $context['operation'], 'args' => $update ],
                ],
                static function () use ($post_id, $update): array {
                    foreach ((array) $update['fields'] as $selector => $value) {
                        update_field((string) $selector, $value, $post_id);
                    }
                    $fields = get_fields($post_id);
                    return is_array($fields) ? $fields : [];
                }
            );
            $ids[]     = $out['operation_id'];
            $results[] = [ 'post_id' => $post_id, 'operation_id' => $out['operation_id'], 'fields' => $out['result'] ];
        }

        return [ 'result' => [ 'updated' => $results ], 'operation_ids' => $ids ];
    }
}
