<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create a layout element - container (default), section, or column - at
 * the top level or nested under a parent, at an optional position among
 * its siblings (issue #58). Columns must live inside a parent; widgets are
 * never valid parents. Hash-guarded and snapshot-first via Element_Tree.
 */
class Add_Container
{
    private const LAYOUT_TYPES = ['container', 'section', 'column'];

    public function handle(array $args)
    {
        $el_type = (string) ($args['el_type'] ?? 'container');

        if (! in_array($el_type, self::LAYOUT_TYPES, true)) {
            return new \WP_Error(
                'invalid_el_type',
                "Invalid el_type '{$el_type}': must be one of container, section, column. Widgets are added with add-widget or generate-widget."
            );
        }

        $read = Element_Tree::read_for_edit($args);
        if (is_wp_error($read)) {
            return $read;
        }
        [$post_id, $elements] = $read;

        $parent_id = (string) ($args['parent_id'] ?? '');
        $settings  = Classic_Dimensions::normalize(is_array($args['settings'] ?? null) ? $args['settings'] : []);
        $position  = isset($args['position']) ? (int) $args['position'] : null;

        if ('column' === $el_type && '' === $parent_id) {
            return new \WP_Error(
                'column_requires_parent',
                'A column cannot be created at the top level: pass the parent_id of the section it belongs in.'
            );
        }

        if ('' !== $parent_id) {
            $parent = Elementor_Page_Data::find($elements, $parent_id);
            if (null === $parent) {
                return new \WP_Error('parent_not_found', "No element found with id '{$parent_id}'.");
            }
            if ('widget' === ($parent['elType'] ?? '')) {
                return new \WP_Error(
                    'invalid_parent',
                    "Element '{$parent_id}' is a widget; widgets cannot contain layout elements."
                );
            }
        }

        $element_id = Element_Id::generate();

        Element_Tree::insert_at($elements, $parent_id, [
            'id'       => $element_id,
            'elType'   => $el_type,
            'settings' => $settings,
            'elements' => [],
            'isInner'  => '' !== $parent_id,
        ], $position);

        $out = Element_Tree::write($post_id, $elements, 'add-container', $args);
        if (is_wp_error($out)) {
            return $out;
        }

        return $out + ['element_id' => $element_id];
    }
}
