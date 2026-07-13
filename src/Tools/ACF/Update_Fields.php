<?php

namespace WPMCP\Tools\ACF;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Set one or more ACF field values on a post via update_field().
 *
 * Disabled by default: sites must opt in with
 * add_filter('wpmcp_enable_acf_write', '__return_true') before this tool will
 * run at all, matching the posture of the other conditional write tools
 * (delete-product, the db/fs writers).
 *
 * ACF stores its values as ordinary postmeta, so the write routes through
 * Safe_Mutation with the existing object_type 'post' and the post id: the
 * existing post snapshot already captures the full postmeta map (including
 * ACF's field value keys and their '_'-prefixed reference keys) before the
 * mutation, and a rollback-operation restores it exactly, through the same
 * engine that already covers posts. No ACF-specific snapshot logic is
 * needed.
 *
 * update_field($selector, ...) accepts either a field name or a field key and
 * resolves it against the field registered for the given post through ACF's
 * own field-resolution logic, so field names given here reach the correct
 * stored key without this tool re-implementing that lookup.
 */
class Update_Fields
{
    public static function is_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_acf_write', false);
    }

    public function handle(array $args): array
    {
        if (! self::is_enabled()) {
            throw new \RuntimeException('The update-fields tool is disabled. Enable it with the wpmcp_enable_acf_write filter.');
        }

        $post_id = (int) ($args['post_id'] ?? 0);
        if ($post_id <= 0) {
            throw new \InvalidArgumentException('A post id is required.');
        }

        $fields = $args['fields'] ?? null;
        if (! is_array($fields) || [] === $fields) {
            throw new \InvalidArgumentException('One or more fields are required.');
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-fields',
                'args'        => $args,
            ],
            function () use ($post_id, $fields): void {
                foreach ($fields as $selector => $value) {
                    update_field((string) $selector, $value, $post_id);
                }
            }
        );

        return [
            'post_id'      => $post_id,
            'fields'       => is_array(get_fields($post_id)) ? get_fields($post_id) : [],
            'operation_id' => $out['operation_id'],
            'recoverable'  => true,
        ];
    }
}
