<?php

namespace WPMCP\Tools\Builders;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Save_Filters;

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
 * `_breakdance_data` (`breakdance_data` on Breakdance 1.x pages) or
 * `_oxygen_data` (see Breakdance_Content::save).
 * Thrive Architect: either a whole layout as an HTML string, or one element
 * operation addressed by the dotted paths get-builder-content returns,
 * written to its layout meta (see Thrive_Content::save).
 * Classic Oxygen (4.x and earlier): either a whole JSON tree, or one element
 * operation addressed by the dotted paths get-builder-content returns,
 * written to its tree with its shortcode copy regenerated through Oxygen
 * (see Oxygen_Classic_Content::save).
 * Elementor/gutenberg/classic posts are out of scope for this tool (use
 * update-element for Elementor) and return a WP_Error.
 *
 * Avada pages also need (issue #637) an expected_hash on every write to a
 * page that has a layout (the read's hash; a page changed since is refused), an add whose element is {template: <id>}
 * (a library item spliced in, see Avada_Library::insert_template), and the
 * save_template operation, which saves an element or the whole page to the
 * Avada library as a new post recorded for undo.
 *
 * WPBakery pages need (issue #639) expected_hash, the read's hash, on any
 * write to a page that has a layout (missing or stale is refused), and take
 * publish, restore (path: a revision id from the read) and add with element {template: id} (a saved template, or
 * default:<n> while the plugin is loaded), and scope 'templates' writes its
 * saved templates (see WPBakery_Templates).
 *
 * Thrive Architect pages need (issue #640) expected_hash, the read's
 * hash, on any write to a page that has a layout, and take publish,
 * restore (path: a revision id from the read), add with element
 * {template: id} (a user template) and save_template (see Thrive_Page).
 *
 * Cornerstone (Themeco) pages (issue #646): a whole element list as a
 * JSON string or one element operation addressed by the dotted paths
 * get-builder-content returns, plus publish, restore (path: a revision id)
 * and add with element {template: id} (a section or page template), saved
 * through Cornerstone itself so it renders the page (see
 * Cornerstone_Engine). They need Cornerstone loaded and, once the page
 * holds a layout, the read's expected_hash.
 *
 * Oxygen 6 pages need (issue #635) Oxygen loaded (plugin_missing
 * otherwise) and expected_hash, the read's hash of the stored data row, on
 * any write to a page that has a layout; they take publish, restore (path: a
 * revision id from the read), add with element {template: id} (an Oxygen
 * template, Component or part, copied in with new node ids) or {component:
 * id} (one Component instance), and save_template, which saves a node or
 * the whole page as a new Component recorded for undo (see Oxygen_Page).
 *
 * BeTheme pages (issue #643) need BeTheme active (plugin_missing
 * otherwise) and expected_hash, the read's hash of the stored layout row, on
 * any write to a page that has a layout. Content is the whole layout as a
 * JSON array of sections; operations address elements by uid (update,
 * add, remove, move; add also takes element {template: id}) and publish
 * sets the page live (see BeTheme_Content and BeTheme_Tree).
 *
 * With `scope` 'design_system' the call edits one entry of the builder's
 * global design data instead of a post (issue #391): Bricks classes,
 * variables and palettes, Breakdance classes and palette colors, Avada
 * palette colors, typography sets and global options. See
 * Builder_Design_Write and Avada_Design, which snapshot the one option they
 * change.
 *
 * All writes go through Safe_Mutation::run() with object_type='post':
 * Bricks' JSON lives in ordinary postmeta and Divi's shortcodes live in
 * ordinary post_content (as do WPBakery's shortcodes and meta, and Beaver
 * Builder's, Breakdance's, Oxygen's, classic Oxygen's and Thrive
 * Architect's layout meta), all of which
 * are already part of the full post
 * row + postmeta the existing post snapshot captures and restores, so no
 * safety-core change is needed for either edit to be undoable.
 */
class Update_Builder_Content
{
    public function handle(array $args)
    {
        $scope = (string) ($args['scope'] ?? '');
        if ('' !== $scope) {
            if ('templates' === $scope && 'wpbakery' === ($args['builder'] ?? '')) {
                return WPBakery_Templates::write($args);
            }
            if ('design_system' !== $scope) {
                return new \WP_Error('invalid_scope', "update-builder-content's scopes are 'design_system' and, for wpbakery, 'templates'; got '{$scope}'.");
            }

            if ('avada' === (string) ($args['builder'] ?? '')) {
                return Avada_Design::write($args);
            }

            return Builder_Design_Write::write($args);
        }

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

        if ('thrive' === $builder) {
            return $this->update_thrive($post_id, $args);
        }

        if ('oxygen-classic' === $builder) {
            return $this->update_oxygen_classic($post_id, $args);
        }

        if ('cornerstone' === $builder) {
            return $this->update_cornerstone($post_id, $args);
        }

        if ('betheme' === $builder) {
            return $this->update_betheme($post_id, $args);
        }

        if ('breakdance' === $builder || 'oxygen' === $builder) {
            return $this->update_breakdance($post_id, $args, $builder);
        }

        return new \WP_Error(
            'unsupported_builder',
            "update-builder-content only supports 'bricks', 'divi', 'wpbakery', 'avada', 'beaver-builder', 'breakdance', 'oxygen', 'oxygen-classic', 'thrive', 'cornerstone' and 'betheme'; got '{$builder}'."
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

        $status = null;
        if ('oxygen' === $builder) {
            $refusal = $this->oxygen_refusal($post_id, $operation, $args);
            if (null !== $refusal) {
                return $refusal;
            }
            if ('save_template' === $operation) {
                return Oxygen_Page::save_component($post_id, $args);
            }
            if ('publish' === $operation) {
                $refusal = $this->publish_refusal($post_id, 'invalid_oxygen_request');
                if (null !== $refusal) {
                    return $refusal;
                }
                $status = 'publish';
            }
        }

        $doc  = Breakdance_Content::get_document($post_id, $builder) ?? Breakdance_Tree::blank();
        $path = null;
        try {
            if ('oxygen' === $builder && in_array($operation, ['publish', 'restore'], true)) {
                $doc = 'restore' === $operation ? Oxygen_Page::revision_document($post_id, (string) ($args['path'] ?? '')) : $doc;
            } elseif ('oxygen' === $builder && 'add' === $operation && $this->is_oxygen_insert($args['element'] ?? null)) {
                [$doc, $path] = $this->apply_oxygen_insert($doc, $args);
            } elseif ('' === $operation) {
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
            function () use ($post_id, $doc, $builder, $status) {
                if (null !== $status) {
                    Save_Filters::set_post_status($post_id, $status);
                    return true;
                }
                Breakdance_Content::save($post_id, $doc, $builder);
                return true;
            }
        );

        $result = ['operation_id' => $out['operation_id'], 'post_id' => $post_id, 'builder' => $builder];
        if ('oxygen' === $builder) {
            $result['hash'] = Oxygen_Page::hash($post_id);
        }
        if (null !== $path) {
            $result['path'] = $path;
        }

        return $result;
    }

    /**
     * Why an Oxygen 6 write must not run (issue #635), or null: Oxygen must
     * be loaded, and a page that already holds a layout is changed only
     * against the hash it was read with, since node ids of a page changed
     * since could point elsewhere. Saving a Component writes no page, so it
     * needs no hash.
     */
    private function oxygen_refusal(int $post_id, string $operation, array $args): ?\WP_Error
    {
        $unavailable = Oxygen_Page::unavailable();
        if (null !== $unavailable) {
            return $unavailable;
        }
        if ('save_template' === $operation || ! Oxygen_Page::has_layout($post_id)) {
            return null;
        }

        $expected = (string) ($args['expected_hash'] ?? '');
        if ('' === $expected) {
            return new \WP_Error('missing_expected_hash', 'expected_hash is required to change an Oxygen page: pass the hash get-builder-content returned for it.');
        }
        if (! hash_equals(Oxygen_Page::hash($post_id), $expected)) {
            return new \WP_Error('stale_expected_hash', 'Stale expected_hash: the page changed since it was read. Nothing was written; read it again and retry.');
        }

        return null;
    }

    /** @param mixed $element */
    private function is_oxygen_insert($element): bool
    {
        return is_array($element) && (array_key_exists('template', $element) || array_key_exists('component', $element));
    }

    /**
     * Oxygen's add from the library: element {template: id} copies that
     * template's, Component's or part's elements in with new ids, and
     * element {component: id} places one instance of a Component.
     *
     * @return array{0:object,1:string}
     */
    private function apply_oxygen_insert(object $doc, array $args): array
    {
        $element = $args['element'];
        if (1 !== count($element)) {
            throw new \InvalidArgumentException(esc_html('element takes either {template: <id>} or {component: <id>} alone.'));
        }
        $to    = (string) ($args['to'] ?? '');
        $index = isset($args['index']) ? (int) $args['index'] : null;
        $id    = $element['template'] ?? $element['component'];
        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            throw new \InvalidArgumentException(esc_html('The template or component id must be a post id.'));
        }

        if (array_key_exists('template', $element)) {
            return Oxygen_Page::insert_template($doc, $to, $index, (int) $id);
        }

        return Breakdance_Tree::add($doc, $to, $index, Oxygen_Page::component_spec((int) $id));
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

        $more = 'oxygen' === ($args['builder'] ?? '') ? ', move, publish, restore or save_template' : ' or move';
        throw new \InvalidArgumentException(esc_html("Unknown operation {$operation}; use update, add, remove{$more}."));
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
        $status    = null;
        $current   = WPBakery_Content::get_content($post_id);

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

            if ('publish' === $operation) {
                $refusal = $this->publish_refusal($post_id);
                if (null !== $refusal) {
                    return $refusal;
                }
                $content = null;
                $status  = 'publish';
            } else {
                try {
                    [$content, $path] = $this->apply_wpbakery_operation($post_id, $current, $operation, $args);
                } catch (\InvalidArgumentException $e) {
                    return new \WP_Error('invalid_wpbakery_operation', $e->getMessage());
                }
            }
        }

        // A page with a layout is changed only against the hash it was read
        // with (issue #639), the refusal Divi 5 pages use; a blank page has
        // nothing to lose.
        if ('' !== trim($current)) {
            $expected = (string) ($args['expected_hash'] ?? '');
            if ('' === $expected) {
                return new \WP_Error('missing_expected_hash', 'expected_hash is required to change a WPBakery page: pass the hash get-builder-content returned for it.');
            }
            if (! hash_equals(WPBakery_Page::hash($current), $expected)) {
                return new \WP_Error('stale_expected_hash', 'Stale expected_hash: the page changed since it was read. Nothing was written; read it again and retry.');
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
            function () use ($post_id, $content, $status) {
                if (null !== $status) {
                    Save_Filters::set_post_status($post_id, $status);
                    return true;
                }
                WPBakery_Content::save($post_id, $content);
                return true;
            }
        );

        $result = [
            'operation_id' => $out['operation_id'],
            'post_id'      => $post_id,
            'builder'      => 'wpbakery',
            'hash'         => WPBakery_Page::hash(WPBakery_Content::get_content($post_id)),
        ];
        if (null !== $path) {
            $result['path'] = $path;
        }

        return $result;
    }

    /**
     * WPBakery's operations: the four shared element operations, add from a
     * template (element {template}), and restore of a revision's layout.
     *
     * @return array{0:string,1:?string}
     */
    private function apply_wpbakery_operation(int $post_id, string $content, string $operation, array $args): array
    {
        $element = $args['element'] ?? null;
        if ('add' === $operation && is_array($element) && array_key_exists('template', $element)) {
            if (! is_string($element['template']) || 1 !== count($element)) {
                throw new \InvalidArgumentException(esc_html('element {template} takes only a template id (a string).'));
            }
            $index = isset($args['index']) ? (int) $args['index'] : null;

            return WPBakery_Page::insert_template($content, (string) ($args['to'] ?? ''), $index, $element['template']);
        }

        if ('restore' === $operation) {
            return [WPBakery_Page::revision_content($post_id, (string) ($args['path'] ?? '')), null];
        }

        if (! in_array($operation, ['update', 'add', 'remove', 'move'], true)) {
            throw new \InvalidArgumentException(esc_html("Unknown operation {$operation}; use update, add, remove, move, publish or restore."));
        }

        return $this->apply_operation(WPBakery_Shortcodes::class, $content, $operation, $args);
    }

    /** Why this page cannot be published by this user now, or null. */
    private function publish_refusal(int $post_id, string $code = 'invalid_wpbakery_operation'): ?\WP_Error
    {
        $status = get_post_status($post_id);
        if ('publish' === $status) {
            return new \WP_Error('already_published', 'This page is already published.');
        }
        if (! in_array($status, ['draft', 'pending', 'private', 'future'], true)) {
            return new \WP_Error($code, "A page with status '{$status}' cannot be published here.");
        }
        if (! current_user_can('publish_post', $post_id)) {
            return new \WP_Error('forbidden', 'You are not allowed to publish this page.');
        }

        return null;
    }

    /**
     * Avada pages: whole shortcode content or one element operation (add
     * also takes a library item), only on a page detected as Avada or as
     * plain classic content, and only while the page still has the hash the
     * caller read (required unless the page is blank). save_template writes
     * no page.
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

        if ('save_template' === $operation) {
            return Avada_Library::save_template($post_id, $args);
        }

        // Paths are child indexes, so a write against a page that changed
        // since the read could land on another element: every write to a
        // page with a layout needs the hash that read returned (as Divi 5).
        if ('' !== trim(Avada_Content::get_content($post_id))) {
            $expected = (string) ($args['expected_hash'] ?? '');
            if ('' === $expected) {
                return new \WP_Error('missing_expected_hash', 'expected_hash is required to change an Avada page: pass the hash get-builder-content returned for it.');
            }
            if (! hash_equals(Avada_Content::hash($post_id), $expected)) {
                return new \WP_Error('stale_expected_hash', 'Stale expected_hash: the page changed since it was read. Nothing was written; read it again and retry.');
            }
        }

        if ('' !== $operation) {
            $old = Avada_Content::get_content($post_id);
            try {
                $template = 'add' === $operation && is_array($args['element'] ?? null) ? ($args['element']['template'] ?? null) : null;
                if (null !== $template) {
                    [$content, $path] = Avada_Library::insert_template($old, (string) ($args['to'] ?? ''), isset($args['index']) ? (int) $args['index'] : null, (int) $template);
                } else {
                    [$content, $path] = $this->apply_operation(Avada_Shortcodes::class, $old, $operation, $args);
                }
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

        $result = ['operation_id' => $out['operation_id'], 'post_id' => $post_id, 'builder' => 'avada', 'hash' => Avada_Content::hash($post_id)];
        if (null !== $path) {
            $result['path'] = $path;
        }

        return $result;
    }

    /**
     * Thrive Architect pages: a whole layout HTML string or one element
     * operation, only on a page already detected as Thrive (a first layout
     * is made in the Thrive editor, which sets up the page's other rows).
     * Issue #640: a page with a layout is changed only against the hash it
     * was read with; publish, restore (path: a revision id) and add with
     * element {template: id} (a user template, its rules added to the page
     * CSS) are page writes too, and save_template writes no page.
     */
    private function update_thrive(int $post_id, array $args)
    {
        $operation = (string) ($args['operation'] ?? '');
        $path      = null;
        $css       = null;
        $rows      = null;
        $status    = null;

        $detected = Builder_Detector::detect($post_id);
        if ('thrive' !== $detected) {
            return new \WP_Error('unsupported_builder', "This post was detected as '{$detected}', not a Thrive Architect page.");
        }

        if ('save_template' === $operation) {
            return Thrive_Page::save_template($post_id, $args);
        }

        $old = Thrive_Content::get_content($post_id);
        if ('' !== trim($old)) {
            $expected = (string) ($args['expected_hash'] ?? '');
            if ('' === $expected) {
                return new \WP_Error('missing_expected_hash', 'expected_hash is required to change a Thrive Architect page: pass the hash get-builder-content returned for it.');
            }
            if (! hash_equals(Thrive_Page::hash($old), $expected)) {
                return new \WP_Error('stale_expected_hash', 'Stale expected_hash: the page changed since it was read. Nothing was written; read it again and retry.');
            }
        }

        $content     = $old;
        $before_more = null;
        try {
            $element = $args['element'] ?? null;
            if ('publish' === $operation) {
                $refusal = $this->publish_refusal($post_id, 'invalid_thrive_request');
                if (null !== $refusal) {
                    return $refusal;
                }
                $status = 'publish';
            } elseif ('restore' === $operation) {
                $rows = Thrive_Page::revision_rows($post_id, (string) ($args['path'] ?? ''));
            } elseif ('add' === $operation && is_array($element) && array_key_exists('template', $element)) {
                if (1 !== count($element) || ! is_scalar($element['template'])) {
                    throw new \InvalidArgumentException(esc_html('element {template} takes only a template id.'));
                }
                $index = isset($args['index']) ? (int) $args['index'] : null;
                [$content, $css, $path] = Thrive_Page::insert_template($old, Thrive_Content::get_css($post_id), (string) ($args['to'] ?? ''), $index, (string) $element['template']);
            } elseif ('' === $operation) {
                $content = $args['content'] ?? null;
                if (! is_string($content)) {
                    throw new \InvalidArgumentException(esc_html('Thrive content must be the layout HTML as a string, or pass an operation.'));
                }
                Thrive_Html::assert_balanced($content);
                Thrive_Html::tree($content);
            } elseif (in_array($operation, ['update', 'add', 'remove', 'move'], true)) {
                [$content, $path] = $this->apply_operation(Thrive_Html::class, $old, $operation, $args);
            } else {
                throw new \InvalidArgumentException(esc_html("Unknown operation {$operation}; use update, add, remove, move, publish, restore or save_template."));
            }
            if (null === $status && null === $rows) {
                $before_more = Thrive_Content::before_more($post_id, $old, $content);
            }
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('invalid_thrive_request', $e->getMessage());
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-builder-content',
                'args'        => $args,
            ],
            function () use ($post_id, $content, $before_more, $css, $rows, $status) {
                if (null !== $status) {
                    Save_Filters::set_post_status($post_id, $status);
                } elseif (null !== $rows) {
                    Thrive_Content::restore_rows($post_id, $rows);
                } else {
                    Thrive_Content::save($post_id, $content, $before_more, $css);
                }
                return true;
            }
        );

        $result = [
            'operation_id' => $out['operation_id'],
            'post_id'      => $post_id,
            'builder'      => 'thrive',
            'hash'         => Thrive_Page::hash(Thrive_Content::get_content($post_id)),
        ];
        if (null !== $path) {
            $result['path'] = $path;
        }

        return $result;
    }

    /**
     * Cornerstone pages (issue #646): only on a page detected as Cornerstone
     * or a blank one (a first layout replaces nothing), only while
     * Cornerstone is loaded (it renders the page on save), only against the
     * hash the page was read with once it holds a layout, and only where
     * Cornerstone's own permissions let this user edit the page.
     */
    private function update_cornerstone(int $post_id, array $args)
    {
        $operation = (string) ($args['operation'] ?? '');
        $detected  = Builder_Detector::detect($post_id);
        $blank     = 'classic' === $detected && '' === trim((string) get_post($post_id)->post_content);
        if ('cornerstone' !== $detected && ! $blank) {
            return new \WP_Error('unsupported_builder', "This post was detected as '{$detected}', not a Cornerstone page.");
        }

        $engine = Cornerstone_Content::plugin_active() ? Cornerstone_Engine::get() : null;
        if (null === $engine) {
            return Cornerstone_Content::unavailable();
        }

        if (Cornerstone_Content::has_layout($post_id)) {
            $expected = (string) ($args['expected_hash'] ?? '');
            if ('' === $expected) {
                return new \WP_Error('missing_expected_hash', 'expected_hash is required to change a Cornerstone page: pass the hash get-builder-content returned for it.');
            }
            if (! hash_equals(Cornerstone_Content::hash($post_id), $expected)) {
                return new \WP_Error('stale_expected_hash', 'Stale expected_hash: the page changed since it was read. Nothing was written; read it again and retry.');
            }
        }

        if (! $engine->allowed($post_id)) {
            return new \WP_Error('forbidden', 'Cornerstone\'s permissions do not let you edit this page.');
        }

        $status = null;
        $path   = null;
        try {
            [$elements, $path, $status] = $this->apply_cornerstone_operation($post_id, $operation, $args, Cornerstone_Content::placement($engine));
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('invalid_cornerstone_request', $e->getMessage());
        }
        if ('publish' === $status) {
            $refusal = $this->publish_refusal($post_id, 'invalid_cornerstone_request');
            if (null !== $refusal) {
                return $refusal;
            }
        }

        try {
            $out = Safe_Mutation::run(
                [
                    'object_type' => 'post',
                    'object_id'   => $post_id,
                    'session_id'  => (string) ($args['session_id'] ?? 'default'),
                    'tool_name'   => 'update-builder-content',
                    'args'        => $args,
                    'state_probe' => static function () use ($post_id) {
                        clean_post_cache($post_id);
                        return [Cornerstone_Content::raw($post_id), (string) get_post_field('post_content', $post_id), get_post_status($post_id)];
                    },
                ],
                function () use ($post_id, $elements, $status, $engine) {
                    if (null !== $status) {
                        Save_Filters::set_post_status($post_id, $status);
                        return true;
                    }
                    $engine->save($post_id, $elements);
                    return true;
                }
            );
        } catch (\Exception $e) {
            return new \WP_Error('cornerstone_save_failed', 'Cornerstone could not save the page: ' . $e->getMessage());
        }

        $result = ['operation_id' => $out['operation_id'], 'post_id' => $post_id, 'builder' => 'cornerstone', 'hash' => Cornerstone_Content::hash($post_id)];
        if (null !== $path) {
            $result['path'] = $path;
        }

        return $result;
    }

    /**
     * The element list a Cornerstone write saves.
     *
     * @return array{0:array<int,array<string,mixed>>,1:?string,2:?string} elements, the element's path when the operation knows it, and 'publish' for a publish
     */
    private function apply_cornerstone_operation(int $post_id, string $operation, array $args, callable $placement): array
    {
        $elements = Cornerstone_Content::elements($post_id);
        $path     = (string) ($args['path'] ?? '');
        $to       = (string) ($args['to'] ?? '');
        $index    = isset($args['index']) ? (int) $args['index'] : null;
        $element  = $args['element'] ?? null;

        switch ($operation) {
            case '':
                $content = $args['content'] ?? null;
                $list    = is_string($content) ? Cornerstone_Tree::decode($content) : null;
                if (null === $list) {
                    throw new \InvalidArgumentException(esc_html('Cornerstone content must be the whole element list as a JSON array, or pass an operation.'));
                }
                $list = array_map([Cornerstone_Tree::class, 'from_spec'], $list);
                Cornerstone_Tree::validate($list, $placement);
                return [$list, null, null];

            case 'publish':
                return [$elements, null, 'publish'];

            case 'restore':
                return [Cornerstone_Content::revision_elements($post_id, $path), null, null];

            case 'update':
                if (array_key_exists('text', $args)) {
                    throw new \InvalidArgumentException(esc_html('Cornerstone elements keep their text in values: pass attrs, such as {text_content} for a headline or text element (scope catalog with element lists the keys).'));
                }
                $attrs = $args['attrs'] ?? null;
                if (! is_array($attrs)) {
                    throw new \InvalidArgumentException(esc_html('update needs attrs: an object of the values to set (null removes a key).'));
                }
                return [Cornerstone_Tree::update($elements, $path, $attrs), $path, null];

            case 'add':
                if (is_array($element) && array_key_exists('template', $element)) {
                    if (1 !== count($element) || ! (is_int($element['template']) || (is_string($element['template']) && ctype_digit($element['template'])))) {
                        throw new \InvalidArgumentException(esc_html('element {template} takes only a template id (see scope templates).'));
                    }
                    $new = Cornerstone_Design::template_elements((int) $element['template']);
                } else {
                    $new = [Cornerstone_Tree::from_spec($element)];
                }
                [$elements, $path] = Cornerstone_Tree::insert($elements, $to, $index, $new, $placement);
                return [$elements, $path, null];

            case 'remove':
                return [Cornerstone_Tree::remove($elements, $path), null, null];

            case 'move':
                [$elements, $path] = Cornerstone_Tree::move($elements, $path, $to, $index, $placement);
                return [$elements, $path, null];
        }

        throw new \InvalidArgumentException(esc_html("Unknown operation {$operation}; use update, add, remove, move, publish or restore."));
    }

    /**

    /**
     * BeTheme pages (issue #643): a whole layout as a JSON array of
     * sections, or one operation by uid, on a page detected as BeTheme or a
     * blank classic page (its first layout), while BeTheme is active and the
     * page still has the hash the caller read.
     */
    private function update_betheme(int $post_id, array $args)
    {
        $operation = (string) ($args['operation'] ?? '');
        $detected  = Builder_Detector::detect($post_id);
        $blank     = 'classic' === $detected && '' === trim((string) get_post($post_id)->post_content);
        if ('betheme' !== $detected && ! $blank) {
            return new \WP_Error('unsupported_builder', "This post was detected as '{$detected}', not a BeTheme page.");
        }
        if (! BeTheme_Cache::plugin_active()) {
            return BeTheme_Content::unavailable();
        }

        if (BeTheme_Content::has_layout($post_id)) {
            $expected = (string) ($args['expected_hash'] ?? '');
            if ('' === $expected) {
                return new \WP_Error('missing_expected_hash', 'expected_hash is required to change a BeTheme page: pass the hash get-builder-content returned for it.');
            }
            if (! hash_equals(BeTheme_Content::hash($post_id), $expected)) {
                return new \WP_Error('stale_expected_hash', 'Stale expected_hash: the page changed since it was read. Nothing was written; read it again and retry.');
            }
        }

        $status   = null;
        $path     = null;
        $sections = null;
        try {
            if ('publish' === $operation) {
                $refusal = $this->publish_refusal($post_id, 'invalid_betheme_request');
                if (null !== $refusal) {
                    return $refusal;
                }
                $status = 'publish';
            } else {
                [$sections, $path] = $this->apply_betheme_operation($post_id, $operation, $args);
            }
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('invalid_betheme_request', $e->getMessage());
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-builder-content',
                'args'        => $args,
            ],
            function () use ($post_id, $sections, $status) {
                if (null !== $status) {
                    Save_Filters::set_post_status($post_id, $status);
                } else {
                    BeTheme_Content::save($post_id, (array) $sections);
                }
                return true;
            }
        );

        $result = [
            'operation_id' => $out['operation_id'],
            'post_id'      => $post_id,
            'builder'      => 'betheme',
            'hash'         => BeTheme_Content::hash($post_id),
        ];
        if (null !== $path) {
            $result['path'] = $path;
        }

        return $result;
    }

    /**
     * The new layout for one BeTheme operation, and the uid it names when
     * it has one.
     *
     * @return array{0:array<int|string,mixed>,1:?string}
     * @throws \InvalidArgumentException
     */
    private function apply_betheme_operation(int $post_id, string $operation, array $args): array
    {
        if ('' === $operation) {
            $content = $args['content'] ?? null;
            $decoded = is_string($content) ? json_decode($content, true) : null;
            if (! is_array($decoded)) {
                throw new \InvalidArgumentException(esc_html('BeTheme content must be a JSON array of sections, or pass an operation.'));
            }
            return [BeTheme_Tree::validate($decoded), null];
        }

        $sections = BeTheme_Content::sections($post_id);
        $sections = BeTheme_Tree::validate($sections);
        $uid   = (string) ($args['path'] ?? '');
        $to    = (string) ($args['to'] ?? '');
        $index = isset($args['index']) ? (int) $args['index'] : null;

        switch ($operation) {
            case 'update':
                $attrs   = $args['attrs'] ?? [];
                $element = $args['element'] ?? [];
                if (! is_array($attrs) || ! is_array($element) || ([] === $attrs && [] === $element)) {
                    throw new \InvalidArgumentException(esc_html('update needs attrs (fields to merge into attr; null removes one) or element (size fields).'));
                }
                return [BeTheme_Tree::update($sections, $uid, $attrs, $element, BeTheme_Content::css_def()), $uid];

            case 'add':
                $element = $args['element'] ?? null;
                if (! is_array($element)) {
                    throw new \InvalidArgumentException(esc_html('add needs an element: {attr, wraps} for a section, {size, attr, items} for a wrap, {type, size, attr} for an item, or {template: id}.'));
                }
                if (array_key_exists('template', $element)) {
                    if (1 !== count($element) || ! is_scalar($element['template'])) {
                        throw new \InvalidArgumentException(esc_html('element {template} takes only a template id.'));
                    }
                    $nodes = BeTheme_Content::template_nodes($sections, $to, (string) $element['template']);
                    return [BeTheme_Tree::insert_many($sections, $to, $index, $nodes), (string) $nodes[0]['uid']];
                }
                return BeTheme_Tree::add($sections, $to, $index, $element, BeTheme_Content::item_defaults(), BeTheme_Content::css_def());

            case 'remove':
                return [BeTheme_Tree::remove($sections, $uid), null];

            case 'move':
                return [BeTheme_Tree::move($sections, $uid, $to, $index), $uid];
        }

        throw new \InvalidArgumentException(esc_html("Unknown operation {$operation}; use update, add, remove, move or publish."));
    }

    /**
     * Classic Oxygen pages: a whole JSON tree or one element operation, only
     * on a page already detected as classic Oxygen and stored in the JSON
     * format, and only when both of its stores can be kept consistent.
     */
    private function update_oxygen_classic(int $post_id, array $args)
    {
        $operation = (string) ($args['operation'] ?? '');
        $path      = null;

        $detected = Builder_Detector::detect($post_id);
        if ('oxygen-classic' !== $detected) {
            return new \WP_Error('unsupported_builder', "This post was detected as '{$detected}', not a classic Oxygen page.");
        }
        if ('json' !== Oxygen_Classic_Content::format($post_id)) {
            return new \WP_Error(
                'oxygen_classic_legacy_format',
                'This page is still in Oxygen\'s pre-4.0 format, signed shortcodes that only Oxygen can rewrite. Open and save it in Oxygen 4 (or sign its shortcodes in Oxygen\'s settings) to store it as JSON, then edit it here.'
            );
        }
        if (! Oxygen_Classic_Content::writable($post_id)) {
            return new \WP_Error(
                'oxygen_classic_inactive',
                'Oxygen keeps a signed shortcode copy of this page beside its JSON, and only Oxygen can sign it; activate Oxygen to edit this page.'
            );
        }

        $old  = Oxygen_Classic_Content::get_json($post_id);
        $sign = Oxygen_Classic_Content::signer();
        try {
            if ('' === $operation) {
                $content = $args['content'] ?? null;
                if (! is_string($content)) {
                    throw new \InvalidArgumentException(esc_html('Classic Oxygen content must be the whole JSON tree as a string, or pass an operation.'));
                }
                Oxygen_Classic_Json::validate($content);
                $json = $sign($content);
            } else {
                [$json, $path] = $this->apply_oxygen_classic_operation($post_id, $old, $operation, $args, $sign);
            }
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('invalid_oxygen_classic_request', $e->getMessage());
        }

        $shortcodes = null;
        if ($json !== $old) {
            try {
                $shortcodes = Oxygen_Classic_Content::shortcodes_for($json);
            } catch (\RuntimeException $e) {
                return new \WP_Error('oxygen_classic_shortcodes_failed', $e->getMessage());
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
            function () use ($post_id, $json, $shortcodes) {
                Oxygen_Classic_Content::save($post_id, $json, $shortcodes);
                return true;
            }
        );

        $result = ['operation_id' => $out['operation_id'], 'post_id' => $post_id, 'builder' => 'oxygen-classic'];
        if (null !== $path) {
            $result['path'] = $path;
        }

        return $result;
    }

    /**
     * Apply one classic Oxygen element operation; `path` and `to` are dotted
     * paths, and every fragment written goes through the signer.
     *
     * @return array{0:string,1:?string} new tree, and the element's path when
     *                                    the operation knows it
     */
    private function apply_oxygen_classic_operation(int $post_id, string $json, string $operation, array $args, callable $sign): array
    {
        $path  = (string) ($args['path'] ?? '');
        $to    = (string) ($args['to'] ?? '');
        $index = isset($args['index']) ? (int) $args['index'] : null;

        switch ($operation) {
            case 'update':
                $attrs = $args['attrs'] ?? null;
                $text  = $args['text'] ?? null;
                if (null !== $attrs && ! is_array($attrs)) {
                    throw new \InvalidArgumentException(esc_html('attrs must be an object: the options to merge (null removes a key).'));
                }
                if (null !== $text && ! is_string($text)) {
                    throw new \InvalidArgumentException(esc_html('text must be a string.'));
                }
                return [Oxygen_Classic_Json::update($json, $path, $attrs, $text, $sign), $path];

            case 'add':
                $element = $args['element'] ?? null;
                if (! is_array($element)) {
                    throw new \InvalidArgumentException(esc_html('add needs an element object: {name, options?, text? | children?}.'));
                }
                return Oxygen_Classic_Json::add($json, $to, $index, $element, $post_id, $sign);

            case 'remove':
                return [Oxygen_Classic_Json::remove($json, $path), null];

            case 'move':
                return [Oxygen_Classic_Json::move($json, $path, $to, $index), null];
        }

        throw new \InvalidArgumentException(esc_html("Unknown operation {$operation}; use update, add, remove or move."));
    }

    /**
     * Apply one element operation to a layout string, through the parser
     * of that builder's dialect (the shortcode parsers, or Thrive's HTML
     * parser, which has the same four static edits).
     *
     * @param class-string<WPBakery_Shortcodes>|class-string<Thrive_Html> $parser
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

    /**
     * Divi pages. A Divi 4 page takes a whole shortcode string, as before.
     * A Divi 5 page (block markup, see Divi5_Blocks), or a Divi 5 layout
     * written over a page, takes a whole layout or one element operation
     * addressed by the dotted paths get-builder-content returns, and needs
     * Divi 5 loaded and, once the page holds a Divi 5 layout, the hash that
     * read returned, so a page changed since it was read is never
     * overwritten.
     *
     * @param mixed $content
     */
    private function update_divi(int $post_id, $content, array $args)
    {
        $operation = (string) ($args['operation'] ?? '');
        $current   = Divi_Content::get_content($post_id);
        $page5     = Divi_Content::is_divi5($post_id);

        if ('' === $operation) {
            if (! is_string($content)) {
                return new \WP_Error('invalid_divi_content', 'Divi content must be a string.');
            }
            if (! $page5 && ! Divi5_Blocks::is_divi5($content)) {
                return $this->update_divi4($post_id, $content, $args);
            }
        } elseif (! $page5) {
            $detected = Builder_Detector::detect($post_id);
            if ('divi' === $detected) {
                return new \WP_Error(
                    'divi4_operations_unsupported',
                    'This page holds a Divi 4 (shortcode) layout; element operations need a Divi 5 layout. Open the page in the Divi 5 builder to convert it, or pass the whole content instead.'
                );
            }
            if ('classic' !== $detected || '' !== trim($current)) {
                return new \WP_Error('unsupported_builder', "This post was detected as '{$detected}', not a Divi 5 page.");
            }
        }

        $unavailable = Divi_Content::divi5_unavailable();
        if (null !== $unavailable) {
            return $unavailable;
        }

        if ($page5 && '' !== trim($current)) {
            $expected = (string) ($args['expected_hash'] ?? '');
            if ('' === $expected) {
                return new \WP_Error('missing_expected_hash', 'expected_hash is required to change a Divi 5 page: pass the hash get-builder-content returned for it.');
            }
            if (! hash_equals(hash('sha256', $current), $expected)) {
                return new \WP_Error('stale_expected_hash', 'Stale expected_hash: the page changed since it was read. Nothing was written; read it again and retry.');
            }
        }

        // A user without unfiltered_html gets what kses lets through, as for
        // any post content: the attribute JSON is beyond kses's reach.
        $map  = current_user_can('unfiltered_html') ? null : 'wp_kses_post';
        $path = null;
        try {
            if ('' === $operation) {
                Divi5_Blocks::validate($content);
                $layout = null === $map ? $content : Divi5_Blocks::map_strings($content, $map);
            } else {
                [$layout, $path] = $this->apply_divi5_operation($current, $operation, $args, $map);
            }
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('invalid_divi_request', $e->getMessage());
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-builder-content',
                'args'        => $args,
            ],
            function () use ($post_id, $layout) {
                Divi_Content::save5($post_id, $layout);
                return true;
            }
        );

        $result = ['operation_id' => $out['operation_id'], 'post_id' => $post_id, 'builder' => 'divi', 'hash' => Divi_Content::hash($post_id)];
        if (null !== $path) {
            $result['path'] = $path;
        }

        return $result;
    }

    /**
     * Apply one Divi 5 element operation; `path` and `to` are dotted paths.
     *
     * @return array{0:string,1:?string} new layout, and the element's path
     *                                    when the operation knows it
     */
    private function apply_divi5_operation(string $content, string $operation, array $args, ?callable $map): array
    {
        $path  = (string) ($args['path'] ?? '');
        $to    = (string) ($args['to'] ?? '');
        $index = isset($args['index']) ? (int) $args['index'] : null;

        switch ($operation) {
            case 'update':
                $attrs = $args['attrs'] ?? null;
                $text  = $args['text'] ?? null;
                if (null !== $attrs && ! is_array($attrs)) {
                    throw new \InvalidArgumentException(esc_html('attrs must be an object: the attributes to merge (null removes a key).'));
                }
                if (null !== $text && ! is_string($text)) {
                    throw new \InvalidArgumentException(esc_html('text must be a string.'));
                }
                return [Divi5_Blocks::update($content, $path, $attrs, $text, $map), $path];

            case 'add':
                $element = $args['element'] ?? null;
                if (! is_array($element)) {
                    throw new \InvalidArgumentException(esc_html('add needs an element object: {name, attrs?, text?, children?}.'));
                }
                return Divi5_Blocks::add($content, $to, $index, $element, Divi_Content::plugin_version(), $map);

            case 'remove':
                return [Divi5_Blocks::remove($content, $path), null];

            case 'move':
                return [Divi5_Blocks::move($content, $path, $to, $index), null];
        }

        throw new \InvalidArgumentException(esc_html("Unknown operation {$operation}; use update, add, remove or move."));
    }

    private function update_divi4(int $post_id, string $content, array $args)
    {
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
