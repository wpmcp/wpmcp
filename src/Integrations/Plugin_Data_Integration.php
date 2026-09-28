<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Custom field and translation plugins beyond ACF, Meta Box and Polylang
 * (issue #299) behind one free dispatcher pair, wpmcp/plugin-data-read and
 * wpmcp/plugin-data-write, so three plugins cost the tools/list surface one
 * pair rather than three:
 *  - JetEngine: field definitions per post type and post field values
 *    (JetEngine_Pack);
 *  - Pods: field definitions per post type pod and post field values, in
 *    post meta or in the pod's own table when it uses table storage
 *    (Pods_Pack);
 *  - TranslatePress: languages and string translations in its dictionary
 *    tables (TranslatePress_Pack).
 *
 * The pair registers only while at least one of the three is loaded, and
 * every op declares a 'requires' check, so a plugin that is not active is
 * skipped cleanly: its ops stay documented in list-operations
 * (dependency_met:false) and answer <plugin>_inactive without touching
 * anything. Presence is filterable per plugin (wpmcp_jetengine_active,
 * wpmcp_pods_active, wpmcp_translatepress_active).
 *
 * Every write is snapshotted: field values in post meta as a post snapshot,
 * Pods table rows and TranslatePress dictionary rows as a
 * 'plugin_table_rows' row image, so rollback-operation restores exactly.
 * Field value writes follow the ACF and Meta Box posture and are off until a
 * site opts in (wpmcp_enable_jetengine_write, wpmcp_enable_pods_write).
 */
final class Plugin_Data_Integration extends Integration_Dispatcher
{
    public function integration(): string
    {
        return 'plugin-data';
    }

    public function is_available(): bool
    {
        return JetEngine_Pack::active() || Pods_Pack::active() || TranslatePress_Pack::active();
    }

    public function registers_only_when_available(): bool
    {
        return true;
    }

    protected function summary(): string
    {
        return 'JetEngine and Pods field values and TranslatePress string translations';
    }

    protected function operations(): array
    {
        return array_merge(
            JetEngine_Pack::operations(),
            Pods_Pack::operations(),
            TranslatePress_Pack::operations()
        );
    }

    /** Input schema shared by the list-fields ops. */
    public static function post_type_schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [ 'post_type' => [ 'type' => 'string', 'minLength' => 1 ] ],
            'required'   => [ 'post_type' ],
        ];
    }

    /** Input schema shared by the get-fields ops. */
    public static function get_fields_schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                'keys'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
            ],
            'required'   => [ 'post_id' ],
        ];
    }

    /** Input schema shared by the update-fields ops. */
    public static function update_fields_schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                'fields'  => [ 'type' => 'object', 'minProperties' => 1 ],
            ],
            'required'   => [ 'post_id', 'fields' ],
        ];
    }

    /**
     * Refusal for a post the current user may not act on, or null. $write
     * asks for edit_post, otherwise read_post.
     *
     * @return array{code: string, message: string, data: array}|null
     */
    public static function post_refusal(int $post_id, bool $write): ?array
    {
        $post = get_post($post_id);
        if (! $post instanceof \WP_Post) {
            return [ 'code' => 'post_not_found', 'message' => sprintf('Post %d does not exist.', $post_id), 'data' => [] ];
        }
        if (! current_user_can($write ? 'edit_post' : 'read_post', $post_id)) {
            return [
                'code'    => 'operation_denied',
                'message' => sprintf('You are not allowed to %s post %d.', $write ? 'edit' : 'read', $post_id),
                'data'    => [ 'reason' => 'capability' ],
            ];
        }
        return null;
    }

    /**
     * Refusal naming the keys that are not fields of the post's type, or null.
     *
     * @param array<string, mixed> $defined name => definition
     * @param string[]             $keys
     * @return array{code: string, message: string, data: array}|null
     */
    public static function unknown_keys_refusal(array $defined, array $keys, string $post_type, string $plugin): ?array
    {
        $unknown = array_values(array_diff($keys, array_keys($defined)));
        if ([] === $unknown) {
            return null;
        }
        return [
            'code'    => 'unknown_field',
            'message' => sprintf('Not a %s field of post type "%s": %s.', $plugin, $post_type, implode(', ', $unknown)),
            'data'    => [ 'unknown' => $unknown, 'fields' => array_keys($defined) ],
        ];
    }

    /** The current value of one post meta key, or null when it is not set. */
    public static function meta_value(int $post_id, string $key)
    {
        return metadata_exists('post', $post_id, $key) ? get_post_meta($post_id, $key, true) : null;
    }
}
