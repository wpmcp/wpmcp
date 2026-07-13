<?php

namespace WPMCP\Tools\Elementor;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Generate a valid Elementor widget element (id, elType='widget',
 * widgetType, and a real settings object) from a curated widget-type schema
 * (Widget_Schema) and insert it into a target post's `_elementor_data`, as a
 * child of a given parent element or at the top level when no parent_id is
 * given. Reads `_elementor_data`, mutates the tree, and writes it back
 * through Safe_Mutation::run() with object_type='post': `_elementor_data` is
 * ordinary postmeta on the page, so the existing post snapshot captures and
 * restores it, making this edit undoable with no change to the safety core.
 *
 * The generated element id is deterministic rather than random: it is
 * derived from a caller-supplied seed when given, or otherwise from the
 * number of elements already in the tree, so repeated calls against the same
 * starting state always produce the same id.
 */
class Generate_Widget
{
    public function handle(array $args)
    {
        $post_id     = (int) ($args['post_id'] ?? 0);
        $parent_id   = (string) ($args['parent_id'] ?? '');
        $widget_type = (string) ($args['widget_type'] ?? '');
        $settings    = is_array($args['settings'] ?? null) ? $args['settings'] : [];
        $seed        = array_key_exists('seed', $args) ? (string) $args['seed'] : null;

        if ($post_id <= 0) {
            return new \WP_Error('missing_post_id', 'A post_id is required.');
        }

        if ('' === $widget_type) {
            return new \WP_Error('missing_widget_type', 'A widget_type is required.');
        }

        if (! Widget_Schema::supports($widget_type)) {
            return new \WP_Error('unknown_widget_type', "Unknown widget type '{$widget_type}'. Supported types: " . implode(', ', Widget_Schema::supported_types()) . '.');
        }

        $missing = Widget_Schema::missing_required_keys($widget_type, $settings);

        if (! empty($missing)) {
            return new \WP_Error('missing_required_setting', "Widget type '{$widget_type}' requires: " . implode(', ', $missing) . '.');
        }

        if (! get_post($post_id)) {
            return new \WP_Error('invalid_post', "No post found with id '{$post_id}'.");
        }

        if (! metadata_exists('post', $post_id, '_elementor_data')) {
            return new \WP_Error('no_elementor_data', "Post '{$post_id}' has no Elementor data.");
        }

        $elements = Elementor_Page_Data::get($post_id);

        if ('' !== $parent_id && null === Elementor_Page_Data::find($elements, $parent_id)) {
            return new \WP_Error('parent_not_found', "No element found with id '{$parent_id}'.");
        }

        $built_settings = Widget_Schema::build_settings($widget_type, $settings);
        $element_id     = self::generate_id($seed, $elements);
        $element        = [
            'id'         => $element_id,
            'elType'     => 'widget',
            'widgetType' => $widget_type,
            'settings'   => $built_settings,
            'elements'   => [],
        ];

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'generate-widget',
                'args'        => $args,
            ],
            function () use ($post_id, $parent_id, $element) {
                $elements = Elementor_Page_Data::get($post_id);
                Elementor_Page_Data::insert($elements, $parent_id, $element);
                Elementor_Page_Data::save($post_id, $elements);
                return true;
            }
        );

        return [
            'operation_id' => $out['operation_id'],
            'post_id'      => $post_id,
            'element_id'   => $element_id,
            'element'      => $element,
        ];
    }

    /**
     * Derive a deterministic 7-character hex id, matching Elementor's own id
     * format (see Element_Id::generate()). A given $seed always maps to the
     * same id. Without a seed, the id is derived from how many elements
     * already exist in the tree, so the first widget generated against a
     * given starting state always gets the same id as any other first call
     * against that same starting state, and each subsequent call in the same
     * session advances to a new, still-unique id.
     */
    private static function generate_id(?string $seed, array $elements): string
    {
        $basis = $seed ?? ('count:' . Elementor_Page_Data::count_all($elements));

        return substr(hash('crc32b', $basis), 0, 7);
    }
}
