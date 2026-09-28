<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Non-destructive settings merge on a layout element (container, section,
 * or column) by id (issue #58): given keys are overwritten or added, every
 * other settings key survives untouched. Widgets are refused - that is
 * update-element's job. Hash-guarded and snapshot-first via Element_Tree.
 */
class Update_Container
{
    public function handle(array $args)
    {
        $element_id = (string) ($args['element_id'] ?? '');
        if ('' === $element_id) {
            return new \WP_Error('missing_element_id', 'An element_id is required.');
        }

        $settings = is_array($args['settings'] ?? null) ? $args['settings'] : [];
        if ([] === $settings) {
            return new \WP_Error('missing_settings', 'A non-empty settings object is required.');
        }

        $read = Element_Tree::read_for_edit($args);
        if (is_wp_error($read)) {
            return $read;
        }
        [$post_id, $elements] = $read;

        $element = Elementor_Page_Data::find($elements, $element_id);
        if (null === $element) {
            return new \WP_Error('element_not_found', "No element found with id '{$element_id}'.");
        }
        if ('widget' === ($element['elType'] ?? '')) {
            return new \WP_Error(
                'not_a_container',
                "Element '{$element_id}' is a widget; use update-element for widget settings."
            );
        }

        Elementor_Page_Data::update_settings($elements, $element_id, $settings);

        $out = Element_Tree::write($post_id, $elements, 'update-container', $args);
        if (is_wp_error($out)) {
            return $out;
        }

        return $out + ['element_id' => $element_id];
    }
}
