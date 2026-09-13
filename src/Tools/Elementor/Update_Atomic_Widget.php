<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Update an Elementor 4.0+ atomic widget's settings by element id. Friendly
 * params are mapped to typed $$type props for known types (only the params you
 * pass are changed, untouched props survive); raw $$type-wrapped settings are
 * also accepted, as is a flat `style` object, which rewrites the element's
 * generated local v4 style class. Requires expected_hash; written
 * snapshot-first, undoable.
 */
class Update_Atomic_Widget
{
    public function handle(array $args)
    {
        $unsupported = Atomic_Element::require_supported();
        if (null !== $unsupported) {
            return $unsupported;
        }

        $element_id = (string) ($args['element_id'] ?? '');
        if ('' === $element_id) {
            return new \WP_Error('missing_element_id', 'An element_id is required.');
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

        // Typed atomic props and a `styles` blob mean nothing to the classic v3
        // renderer, so decorating a classic widget or container with them would
        // report success for styling that never appears. Refuse by name.
        if (! Atomic_Element::is_atomic_node($element)) {
            return new \WP_Error(
                'not_an_atomic_element',
                sprintf(
                    "Element '%s' is %s, a classic (v3) element: use update-widget or update-container. update-atomic-widget only edits Elementor 4.0+ atomic elements.",
                    $element_id,
                    Atomic_Element::describe_node($element)
                )
            );
        }

        if (is_array($args['settings'] ?? null) && [] !== $args['settings']) {
            $patch = $args['settings'];
        } else {
            $widget_type = (string) ($element['widgetType'] ?? '');
            $params      = is_array($args['params'] ?? null) ? $args['params'] : [];
            $patch       = Atomic_Widget_Map::partial($widget_type, $params);
        }

        $has_style = isset($args['style']) && [] !== $args['style'];

        if ([] === $patch && ! $has_style) {
            return new \WP_Error('missing_settings', 'Provide params (for a known atomic type), raw settings, or a style object to update.');
        }

        // Same shared mapper as add-atomic-widget and the builder dialect of
        // build-page, so a patch cannot introduce a prop shape the v4 editor
        // refuses to read (issue #137).
        $mapped = Atomic_Props::map((string) ($element['widgetType'] ?? ''), $patch);
        if ([] === $mapped['settings'] && ! $has_style) {
            return new \WP_Error(
                'unmappable_settings',
                'None of the supplied props could be stored on this element: ' . implode(' ', $mapped['warnings'])
            );
        }

        if ([] !== $mapped['settings']) {
            Elementor_Page_Data::update_settings($elements, $element_id, $mapped['settings']);
        }

        // A style update rewrites this element's generated local class rather
        // than minting a second one, so repeated updates cannot leave a trail
        // of dead class ids in `styles` (see Atomic_Styles::attach).
        if ($has_style) {
            $built = Atomic_Styles::build($element_id, $args['style']);
            if (is_wp_error($built)) {
                return $built;
            }

            $node = &Elementor_Page_Data::find($elements, $element_id);
            $node = Atomic_Styles::attach($node, $built);
            unset($node);

            $mapped['warnings'] = array_merge($mapped['warnings'] ?? [], $built['warnings']);
        }

        $out = Atomic_Element::write($post_id, $elements, 'update-atomic-widget', $args);
        if (is_wp_error($out)) {
            return $out;
        }

        return $out + ['element_id' => $element_id] + Atomic_Element::report($mapped);
    }
}
