<?php

namespace WPMCP\Tools\Elementor;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Remove an element (and its children) from a page's `_elementor_data` by
 * id. Reads `_elementor_data`, mutates the tree, and writes it back through
 * Safe_Mutation::run() with object_type='post': `_elementor_data` is
 * ordinary postmeta on the page, so the existing post snapshot captures and
 * restores it, making this edit undoable with no change to the safety core.
 */
class Remove_Element
{
    public function handle(array $args)
    {
        $post_id    = (int) ($args['post_id'] ?? 0);
        $element_id = (string) ($args['element_id'] ?? '');

        if ($post_id <= 0) {
            return new \WP_Error('missing_post_id', 'A post_id is required.');
        }

        if ('' === $element_id) {
            return new \WP_Error('missing_element_id', 'An element_id is required.');
        }

        $elements = Elementor_Page_Data::get($post_id);

        if (null === Elementor_Page_Data::find($elements, $element_id)) {
            return new \WP_Error('element_not_found', "No element found with id '{$element_id}'.");
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
                'tool_name'   => 'remove-element',
                'args'        => $args,
            ],
            function () use ($post_id, $element_id) {
                $elements = Elementor_Page_Data::get($post_id);
                Elementor_Page_Data::remove($elements, $element_id);
                Elementor_Page_Data::save($post_id, $elements);
                Elementor_Page_Data::mark_built_with_elementor($post_id);
                return true;
            }
        );

        return ['operation_id' => $out['operation_id'], 'post_id' => $post_id, 'element_id' => $element_id];
    }
}
