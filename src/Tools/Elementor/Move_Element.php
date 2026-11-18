<?php

namespace WPMCP\Tools\Elementor;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Reparent an element by id: remove it from its current location and insert
 * it as a child of a new parent element (append only; ordering within the
 * new parent is not controllable here). Reads `_elementor_data`, mutates the
 * tree, and writes it back through Safe_Mutation::run() with
 * object_type='post': `_elementor_data` is ordinary postmeta on the page, so
 * the existing post snapshot captures and restores it, making this edit
 * undoable with no change to the safety core.
 */
class Move_Element
{
    public function handle(array $args)
    {
        $post_id    = (int) ($args['post_id'] ?? 0);
        $element_id = (string) ($args['element_id'] ?? '');
        $parent_id  = (string) ($args['parent_id'] ?? '');

        if ($post_id <= 0) {
            return new \WP_Error('missing_post_id', 'A post_id is required.');
        }

        if ('' === $element_id) {
            return new \WP_Error('missing_element_id', 'An element_id is required.');
        }

        if ('' === $parent_id) {
            return new \WP_Error('missing_parent_id', 'A parent_id is required.');
        }

        $elements = Elementor_Page_Data::get($post_id);
        $element  = Elementor_Page_Data::find($elements, $element_id);

        if (null === $element) {
            return new \WP_Error('element_not_found', "No element found with id '{$element_id}'.");
        }

        if (null === Elementor_Page_Data::find($elements, $parent_id)) {
            return new \WP_Error('parent_not_found', "No element found with id '{$parent_id}'.");
        }

        if ($element_id === $parent_id || self::contains($element, $parent_id)) {
            return new \WP_Error('invalid_move', 'An element cannot be moved into itself or one of its own children.');
        }

        $refusal = Elementor_Page_Data::builder_mode_refusal($post_id);
        if ($refusal) {
            return $refusal;
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'move-element',
                'args'        => $args,
            ],
            function () use ($post_id, $element_id, $parent_id, $element) {
                $elements = Elementor_Page_Data::get($post_id);
                Elementor_Page_Data::remove($elements, $element_id);
                Elementor_Page_Data::insert($elements, $parent_id, $element);
                Elementor_Page_Data::save($post_id, $elements);
                Elementor_Page_Data::mark_built_with_elementor($post_id);
                return true;
            }
        );

        return ['operation_id' => $out['operation_id'], 'post_id' => $post_id, 'element_id' => $element_id];
    }

    /** True if $id is $element itself or found anywhere within its descendant tree. */
    private static function contains(array $element, string $id): bool
    {
        if (empty($element['elements']) || ! is_array($element['elements'])) {
            return false;
        }

        return null !== Elementor_Page_Data::find($element['elements'], $id);
    }
}
