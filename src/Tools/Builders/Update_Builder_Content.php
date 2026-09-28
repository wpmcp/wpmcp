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
 * `_et_pb_use_builder` flag is 'on'. WPBakery: either a whole shortcode
 * string, or one element operation (update / add / remove / move) addressed
 * by the dotted paths get-builder-content returns, written to post_content
 * with the WPBakery meta kept in step (see WPBakery_Content::save).
 * Beaver Builder: either a whole node tree as a JSON string, or one
 * operation addressed by node id, written to its layout meta (see
 * Beaver_Builder_Content::save).
 * Elementor/gutenberg/classic posts are out of scope for this tool (use
 * update-element for Elementor) and return a WP_Error.
 *
 * All writes go through Safe_Mutation::run() with object_type='post':
 * Bricks' JSON lives in ordinary postmeta and Divi's shortcodes live in
 * ordinary post_content (as do WPBakery's shortcodes and meta, and Beaver
 * Builder's layout meta), all of which
 * are already part of the full post
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

        if ('wpbakery' === $builder) {
            return $this->update_wpbakery($post_id, $args);
        }

        if ('beaver-builder' === $builder) {
            return $this->update_beaver_builder($post_id, $args);
        }

        return new \WP_Error(
            'unsupported_builder',
            "update-builder-content only supports 'bricks', 'divi', 'wpbakery' and 'beaver-builder'; got '{$builder}'."
        );
    }

    private function update_beaver_builder(int $post_id, array $args)
    {
        $operation = (string) ($args['operation'] ?? '');
        $detected  = Builder_Detector::detect($post_id);
        $blank     = 'classic' === $detected && '' === trim((string) get_post($post_id)->post_content);

        if ('beaver-builder' !== $detected && ! $blank) {
            return new \WP_Error(
                'unsupported_builder',
                "This post was detected as '{$detected}', not a Beaver Builder page."
            );
        }

        if (Beaver_Builder_Content::draft_pending($post_id)) {
            return new \WP_Error(
                'beaver_builder_draft_pending',
                'This page has unpublished Beaver Builder edits; publish or discard them in the editor first.'
            );
        }

        $path = null;
        try {
            if ('' === $operation) {
                $content = $args['content'] ?? null;
                $tree    = is_string($content) ? json_decode($content) : null;
                if (! is_array($tree)) {
                    throw new \InvalidArgumentException(esc_html('Beaver Builder content must be a JSON array of nodes, or pass an operation.'));
                }
                $nodes = Beaver_Builder_Nodes::from_tree($tree);
            } else {
                [$nodes, $path] = $this->apply_beaver_operation(Beaver_Builder_Content::get_nodes($post_id), $operation, $args);
            }
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('invalid_beaver_builder_request', $e->getMessage());
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-builder-content',
                'args'        => $args,
            ],
            function () use ($post_id, $nodes) {
                Beaver_Builder_Content::save($post_id, $nodes);
                return true;
            }
        );

        $result = ['operation_id' => $out['operation_id'], 'post_id' => $post_id, 'builder' => 'beaver-builder'];
        if (null !== $path) {
            $result['path'] = $path;
        }

        return $result;
    }

    /**
     * Apply one node operation; `path` and `to` are node ids.
     *
     * @param array<string,object> $nodes
     * @return array{0:array<string,object>,1:?string} new nodes, and the
     *                                                  node id when the operation has one
     */
    private function apply_beaver_operation(array $nodes, string $operation, array $args): array
    {
        $path  = (string) ($args['path'] ?? '');
        $to    = (string) ($args['to'] ?? '');
        $index = isset($args['index']) ? (int) $args['index'] : null;

        switch ($operation) {
            case 'update':
                $attrs = $args['attrs'] ?? null;
                if (! is_array($attrs)) {
                    throw new \InvalidArgumentException(esc_html('update needs attrs: the settings to merge (null removes a key).'));
                }
                return [Beaver_Builder_Nodes::update($nodes, $path, $attrs), $path];

            case 'add':
                $element = $args['element'] ?? null;
                if (! is_array($element)) {
                    throw new \InvalidArgumentException(esc_html('add needs an element object: {type, settings?, children?}.'));
                }
                return Beaver_Builder_Nodes::add($nodes, $to, $index, $element);

            case 'remove':
                return [Beaver_Builder_Nodes::remove($nodes, $path), null];

            case 'move':
                return [Beaver_Builder_Nodes::move($nodes, $path, $to, $index), $path];
        }

        throw new \InvalidArgumentException(esc_html("Unknown operation {$operation}; use update, add, remove or move."));
    }

    private function update_wpbakery(int $post_id, array $args)
    {
        $operation = (string) ($args['operation'] ?? '');
        $path      = null;

        if ('' === $operation) {
            $content = $args['content'] ?? null;
            if (! is_string($content)) {
                return new \WP_Error('invalid_wpbakery_content', 'WPBakery content must be a shortcode string, or pass an operation.');
            }
        } else {
            $detected = Builder_Detector::detect($post_id);
            if (! in_array($detected, ['wpbakery', 'classic'], true)) {
                return new \WP_Error(
                    'unsupported_builder',
                    "This post was detected as '{$detected}', not a WPBakery page."
                );
            }

            try {
                [$content, $path] = $this->apply_operation(WPBakery_Content::get_content($post_id), $operation, $args);
            } catch (\InvalidArgumentException $e) {
                return new \WP_Error('invalid_wpbakery_operation', $e->getMessage());
            }
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
                WPBakery_Content::save($post_id, $content);
                return true;
            }
        );

        $result = ['operation_id' => $out['operation_id'], 'post_id' => $post_id, 'builder' => 'wpbakery'];
        if (null !== $path) {
            $result['path'] = $path;
        }

        return $result;
    }

    /**
     * Apply one element operation to the shortcode string.
     *
     * @return array{0:string,1:?string} new content, and the element's new
     *                                    path when the operation knows it
     */
    private function apply_operation(string $content, string $operation, array $args): array
    {
        $path  = (string) ($args['path'] ?? '');
        $to    = (string) ($args['to'] ?? '');
        $index = isset($args['index']) ? (int) $args['index'] : null;

        switch ($operation) {
            case 'update':
                $attrs = $args['attrs'] ?? null;
                $text  = $args['text'] ?? null;
                if (null !== $attrs && ! is_array($attrs)) {
                    throw new \InvalidArgumentException(esc_html('attrs must be an object.'));
                }
                if (null !== $text && ! is_string($text)) {
                    throw new \InvalidArgumentException(esc_html('text must be a string.'));
                }
                return [WPBakery_Shortcodes::update($content, $path, $attrs, $text), $path];

            case 'add':
                $element = $args['element'] ?? null;
                if (! is_array($element)) {
                    throw new \InvalidArgumentException(esc_html('add needs an element object: {tag, attrs?, text? | children?}.'));
                }
                return WPBakery_Shortcodes::add($content, $to, $index, $element);

            case 'remove':
                return [WPBakery_Shortcodes::remove($content, $path), null];

            case 'move':
                return [WPBakery_Shortcodes::move($content, $path, $to, $index), null];
        }

        throw new \InvalidArgumentException(esc_html("Unknown operation {$operation}; use update, add, remove or move."));
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
