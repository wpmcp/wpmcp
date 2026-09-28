<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Patch a cataloged widget's settings from curated, typed params
 * (issue #59): the same Widget_Catalog schema add-widget inserts with,
 * validated (unknown params, enum and type violations refused) and merged
 * into the element's existing settings. Required params are NOT enforced -
 * a patch touches only what it names. Widgets outside the catalog are
 * refused toward update-element, which merges raw settings.
 *
 * Writes go through the Element_Tree engine (issue #58): `expected_hash`
 * concurrency guard, snapshot-first Safe_Mutation write with verify and
 * automatic rollback, and a fresh data_hash in the response for chaining.
 */
class Update_Widget
{
    public function handle(array $args)
    {
        $element_id = (string) ($args['element_id'] ?? '');
        $params     = is_array($args['params'] ?? null) ? $args['params'] : [];

        if (! class_exists('\\Elementor\\Plugin')) {
            return new \WP_Error('elementor_not_active', 'Elementor is not active on this site.');
        }

        $read = Element_Tree::read_for_edit($args);
        if (is_wp_error($read)) {
            return $read;
        }
        [$post_id, $elements] = $read;

        if ('' === $element_id) {
            return new \WP_Error('missing_element_id', 'An element_id is required.');
        }

        if ([] === $params) {
            return new \WP_Error('missing_params', 'A non-empty params object is required.');
        }

        $element = Elementor_Page_Data::find($elements, $element_id);
        if (null === $element) {
            return new \WP_Error('element_not_found', "No element found with id '{$element_id}'.");
        }

        if ('widget' !== ($element['elType'] ?? '')) {
            return new \WP_Error(
                'not_a_widget',
                "Element '{$element_id}' is a {$element['elType']}, not a widget; use update-container or update-element."
            );
        }

        $widget_type = (string) ($element['widgetType'] ?? '');

        if (! Widget_Catalog::has($widget_type)) {
            return new \WP_Error(
                'not_cataloged',
                "Widget type '{$widget_type}' is not in the curated catalog; use update-element to merge raw settings."
            );
        }

        $error = Widget_Catalog::validate($widget_type, $params, false);
        if (null !== $error) {
            return $error;
        }

        $patch = Widget_Catalog::build_settings($widget_type, $params);

        Elementor_Page_Data::update_settings($elements, $element_id, $patch);

        $out = Element_Tree::write($post_id, $elements, 'update-widget', $args);
        if (is_wp_error($out)) {
            return $out;
        }

        $updated = Elementor_Page_Data::find($elements, $element_id);

        return $out + [
            'element_id' => $element_id,
            'settings'   => $updated['settings'] ?? [],
        ];
    }
}
