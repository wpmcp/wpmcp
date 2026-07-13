<?php

namespace WPMCP\Tools\Builders;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Replace the builder structure for a post. Bricks: validates the given
 * string is well-formed JSON decoding to an array, then writes it to the
 * `_bricks_page_content_2` postmeta. Divi: validates the given content is a
 * string, then writes it to post_content and ensures the
 * `_et_pb_use_builder` flag is 'on'. Elementor/gutenberg/classic posts are
 * out of scope for this tool (use update-element for Elementor) and return
 * a WP_Error.
 *
 * Both writes go through Safe_Mutation::run() with object_type='post':
 * Bricks' JSON lives in ordinary postmeta and Divi's shortcodes live in
 * ordinary post_content, both of which are already part of the full post
 * row + postmeta the existing post snapshot captures and restores, so no
 * safety-core change is needed for either edit to be undoable.
 */
class Update_Builder_Content
{
    public function handle(array $args)
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        $builder = (string) ($args['builder'] ?? '');
        $content = $args['content'] ?? null;

        if ($post_id <= 0) {
            return new \WP_Error('missing_post_id', 'A post_id is required.');
        }

        if (! get_post($post_id)) {
            return new \WP_Error('post_not_found', "No post found with id '{$post_id}'.");
        }

        if ('bricks' === $builder) {
            return $this->update_bricks($post_id, $content, $args);
        }

        if ('divi' === $builder) {
            return $this->update_divi($post_id, $content, $args);
        }

        return new \WP_Error(
            'unsupported_builder',
            "update-builder-content only supports 'bricks' and 'divi'; got '{$builder}'."
        );
    }

    /** @param mixed $content */
    private function update_bricks(int $post_id, $content, array $args)
    {
        if (! is_string($content)) {
            return new \WP_Error('invalid_bricks_json', 'Bricks content must be a JSON string.');
        }

        $decoded = json_decode($content, true);

        if (JSON_ERROR_NONE !== json_last_error() || ! is_array($decoded)) {
            return new \WP_Error('invalid_bricks_json', 'Bricks content must be well-formed JSON decoding to an array.');
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-builder-content',
                'args'        => $args,
            ],
            function () use ($post_id, $decoded) {
                Bricks_Content::save($post_id, $decoded);
                return true;
            }
        );

        return ['operation_id' => $out['operation_id'], 'post_id' => $post_id, 'builder' => 'bricks'];
    }

    /** @param mixed $content */
    private function update_divi(int $post_id, $content, array $args)
    {
        if (! is_string($content)) {
            return new \WP_Error('invalid_divi_content', 'Divi content must be a string.');
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-builder-content',
                'args'        => $args,
            ],
            function () use ($post_id, $content) {
                Divi_Content::save($post_id, $content);
                return true;
            }
        );

        return ['operation_id' => $out['operation_id'], 'post_id' => $post_id, 'builder' => 'divi'];
    }
}
