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
 * Avada: the same two forms over its shortcodes, written to post_content
 * with its builder flag set and its dynamic CSS invalidated (see
 * Avada_Content::save).
 * Beaver Builder: either a whole node tree as a JSON string, or one
 * operation addressed by node id, written to its layout meta (see
 * Beaver_Builder_Content::save).
 * Breakdance and Oxygen 6 (the same engine): either a whole node tree as a
 * JSON string, or one operation addressed by node id, written to
 * `_breakdance_data` or `_oxygen_data` (see Breakdance_Content::save).
 * Elementor/gutenberg/classic posts are out of scope for this tool (use
 * update-element for Elementor) and return a WP_Error.
 *
 * All writes go through Safe_Mutation::run() with object_type='post':
 * Bricks' JSON lives in ordinary postmeta and Divi's shortcodes live in
 * ordinary post_content (as do WPBakery's shortcodes and meta, and Beaver
 * Builder's, Breakdance's and Oxygen's layout meta), all of which
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

        if ('avada' === $builder) {
            return $this->update_avada($post_id, $args);
        }

        if ('beaver-builder' === $builder) {
            return $this->update_beaver_builder($post_id, $args);
        }

        if ('breakdance' === $builder || 'oxygen' === $builder) {
            return $this->update_breakdance($post_id, $args, $builder);
        }

        return new \WP_Error(
            'unsupported_builder',
            "update-builder-content only supports 'bricks', 'divi', 'wpbakery', 'avada', 'beaver-builder', 'breakdance' and 'oxygen'; got '{$builder}'."
        );
    }

    /**
     * Breakdance and Oxygen 6 pages: one engine, so one path; `$builder`
     * picks the meta prefix and the plugin whose cache is refreshed.
     */
    private function update_breakdance(int $post_id, array $args, string $builder)
    {
        $operation = (string) ($args['operation'] ?? '');
        $detected  = Builder_Detector::detect($post_id);
        $blank     = 'classic' === $detected && '' === trim((string) get_post($post_id)->post_content);

        if ($builder !== $detected && ! $blank) {
            $label = 'oxygen' === $builder ? 'an Oxygen' : 'a Breakdance';
            return new \WP_Error(
                'unsupported_builder',
                "This post was detected as '{$detected}', not {$label} page."
            );
        }

        $doc  = Breakdance_Content::get_document($post_id, $builder) ?? Breakdance_Tree::blank();
        $path = null;
        try {
            if ('' === $operation) {
                $content = $args['content'] ?? null;
                $list    = is_string($content) ? json_decode($content) : null;
                if (! is_array($list)) {
                    throw new \InvalidArgumentException(esc_html('Content must be a JSON array of nodes, or pass an operation.'));
                }
                $doc = Breakdance_Tree::replace($doc, $list);
            } else {
                [$doc, $path] = $this->apply_breakdance_operation($doc, $operation, $args);
            }
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error("invalid_{$builder}_request", $e->getMessage());
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-builder-content',
                'args'        => $args,
            ],
            function () use ($post_id, $doc, $builder) {
                Breakdance_Content::save($post_id, $doc, $builder);
                return true;
            }
        );

        $result = ['operation_id' => $out['operation_id'], 'post_id' => $post_id, 'builder' => $builder];
        if (null !== $path) {
            $result['path'] = $path;
        }

        return $result;
    }

    /**
     * Apply one Breakdance node operation; `path` and `to` are node ids.
     *
     * @return array{0:object,1:?string} new document, and the node id when
     *                                    the operation has one
     */
    private function apply_breakdance_operation(object $doc, string $operation, array $args): array
    {
        $path  = (string) ($args['path'] ?? '');
        $to    = (string) ($args['to'] ?? '');
        $index = isset($args['index']) ? (int) $args['index'] : null;

        switch ($operation) {
            case 'update':
                $attrs = $args['attrs'] ?? null;
                if (! is_array($attrs)) {
                    throw new \InvalidArgumentException(esc_html('update needs attrs: the properties to merge (null removes a key).'));
                }
                return [Breakdance_Tree::update($doc, $path, $attrs), $path];

            case 'add':
                $element = $args['element'] ?? null;
                if (! is_array($element)) {
                    throw new \InvalidArgumentException(esc_html('add needs an element object: {type, properties?, children?}.'));
                }
                return Breakdance_Tree::add($doc, $to, $index, $element);

            case 'remove':
                return [Breakdance_Tree::remove($doc, $path), null];

            case 'move':
                return [Breakdance_Tree::move($doc, $path, $to, $index), $path];
        }

        throw new \InvalidArgumentException(esc_html("Unknown operation {$operation}; use update, add, remove or move."));
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
                [$content, $path] = $this->apply_operation(WPBakery_Shortcodes::class, WPBakery_Content::get_content($post_id), $operation, $args);
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
     * Avada pages: whole shortcode content or one element operation, only on
     * a page detected as Avada or as plain classic content.
     */
    private function update_avada(int $post_id, array $args)
    {
        $operation = (string) ($args['operation'] ?? '');
        $content   = $args['content'] ?? null;
        $path      = null;

        if ('' === $operation && ! is_string($content)) {
            return new \WP_Error('invalid_avada_content', 'Avada content must be a shortcode string, or pass an operation.');
        }

        $detected = Builder_Detector::detect($post_id);
        if (! in_array($detected, ['avada', 'classic'], true)) {
            return new \WP_Error('unsupported_builder', "This post was detected as '{$detected}', not an Avada page.");
        }

        if ('' !== $operation) {
            try {
                [$content, $path] = $this->apply_operation(Avada_Shortcodes::class, Avada_Content::get_content($post_id), $operation, $args);
            } catch (\InvalidArgumentException $e) {
                return new \WP_Error('invalid_avada_operation', $e->getMessage());
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
                Avada_Content::save($post_id, $content);
                return true;
            }
        );

        $result = ['operation_id' => $out['operation_id'], 'post_id' => $post_id, 'builder' => 'avada'];
        if (null !== $path) {
            $result['path'] = $path;
        }

        return $result;
    }

    /**
     * Apply one element operation to a shortcode string, through the parser
     * of that builder's dialect.
     *
     * @param class-string<WPBakery_Shortcodes> $parser
     * @return array{0:string,1:?string} new content, and the element's new
     *                                    path when the operation knows it
     */
    private function apply_operation(string $parser, string $content, string $operation, array $args): array
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
                return [$parser::update($content, $path, $attrs, $text), $path];

            case 'add':
                $element = $args['element'] ?? null;
                if (! is_array($element)) {
                    throw new \InvalidArgumentException(esc_html('add needs an element object: {tag, attrs?, text? | children?}.'));
                }
                return $parser::add($content, $to, $index, $element);

            case 'remove':
                return [$parser::remove($content, $path), null];

            case 'move':
                return [$parser::move($content, $path, $to, $index), null];
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
