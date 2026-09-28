<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Add a widget element to a page's `_elementor_data`, under a parent
 * element or at the top level, at an optional position (issue #59).
 *
 * Any type in the curated catalog (Widget_Catalog) is accepted with typed
 * `params`, which are validated (unknown params, missing required params,
 * enum and type violations are refused before anything is written) and then
 * built into Elementor's real settings shapes. Non-cataloged registered
 * widgets remain insertable through the raw `settings` escape hatch; when
 * both are given, built params win over raw settings key-by-key. Cataloged
 * widgets that need Elementor Pro refuse cleanly when the real Pro widget is
 * not installed - free Elementor's promotion placeholders never count.
 *
 * Writes go through the Element_Tree engine (issue #58): `expected_hash`
 * concurrency guard, snapshot-first Safe_Mutation write with verify and
 * automatic rollback, and a fresh data_hash in the response for chaining.
 */
class Add_Widget
{
    public function handle(array $args)
    {
        $widget_type = (string) ($args['widget_type'] ?? '');

        if ('' === $widget_type) {
            return new \WP_Error('missing_widget_type', 'A widget_type is required.');
        }

        if (! class_exists('\\Elementor\\Plugin')) {
            return new \WP_Error('elementor_not_active', 'Elementor is not active on this site.');
        }

        $read = Element_Tree::read_for_edit($args);
        if (is_wp_error($read)) {
            return $read;
        }
        [$post_id, $elements] = $read;

        $settings = $this->resolve_settings($widget_type, $args);
        if (is_wp_error($settings)) {
            return $settings;
        }

        $parent_id = (string) ($args['parent_id'] ?? '');
        $position  = isset($args['position']) ? (int) $args['position'] : null;

        if ('' !== $parent_id) {
            $parent = Elementor_Page_Data::find($elements, $parent_id);
            if (null === $parent) {
                return new \WP_Error('parent_not_found', "No element found with id '{$parent_id}'.");
            }
            if ('widget' === ($parent['elType'] ?? '')) {
                return new \WP_Error(
                    'invalid_parent',
                    "Element '{$parent_id}' is a widget; widgets cannot contain other widgets."
                );
            }
        }

        $element_id = Element_Id::generate();
        $element    = [
            'id'         => $element_id,
            'elType'     => 'widget',
            'widgetType' => $widget_type,
            'settings'   => $settings,
            'elements'   => [],
        ];

        Element_Tree::insert_at($elements, $parent_id, $element, $position);

        $out = Element_Tree::write($post_id, $elements, 'add-widget', $args);
        if (is_wp_error($out)) {
            return $out;
        }

        return $out + ['element_id' => $element_id, 'element' => $element];
    }

    /**
     * Validate availability and build the settings array from curated
     * params and/or raw settings.
     *
     * @return array|\WP_Error
     */
    private function resolve_settings(string $widget_type, array $args)
    {
        $params   = is_array($args['params'] ?? null) ? $args['params'] : [];
        $raw      = is_array($args['settings'] ?? null) ? $args['settings'] : [];
        $entry    = Widget_Catalog::get($widget_type);
        $installed = Widget_Catalog::installed_widget($widget_type);

        if (null === $installed) {
            if (null !== $entry && 'elementor-pro' === $entry['requires']) {
                return new \WP_Error(
                    'requires_elementor_pro',
                    "Widget type '{$widget_type}' requires Elementor Pro, which is not installed on this site."
                );
            }
            return new \WP_Error('invalid_widget_type', "Unknown Elementor widget type '{$widget_type}'.");
        }

        if ([] !== $params) {
            if (null === $entry) {
                return new \WP_Error(
                    'not_cataloged',
                    "Widget type '{$widget_type}' is not in the curated catalog; pass raw `settings` instead of `params` "
                    . '(get-widget-schema with full:true shows its control stack).'
                );
            }

            $error = Widget_Catalog::validate($widget_type, $params);
            if (null !== $error) {
                return $error;
            }

            return array_merge($raw, Widget_Catalog::build_settings($widget_type, $params));
        }

        if (null !== $entry && [] === $raw) {
            // Cataloged type with neither params nor raw settings: enforce
            // required params so a bare insert cannot produce a widget the
            // builder renders empty. (Raw settings are trusted to satisfy
            // requirements by control name.)
            $error = Widget_Catalog::validate($widget_type, []);
            if (null !== $error) {
                return $error;
            }
        }

        return $raw;
    }
}
