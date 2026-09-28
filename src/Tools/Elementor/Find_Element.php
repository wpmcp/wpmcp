<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only search over a page's element tree (issue #58) by element type,
 * widget type, setting value, and CSS class token - AND-combined. Each
 * match reports its id, types, navigator label, and ancestor id path, and
 * the response carries the current data_hash so a structural mutation can
 * be chained without a second read. Never mutates anything, so it is not
 * routed through the safety core.
 */
class Find_Element
{
    public function handle(array $args)
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        if ($post_id <= 0) {
            return new \WP_Error('missing_post_id', 'A post_id is required.');
        }
        if (! get_post($post_id)) {
            return new \WP_Error('post_not_found', "No post found with id {$post_id}.");
        }

        $el_type       = isset($args['el_type']) ? (string) $args['el_type'] : null;
        $widget_type   = isset($args['widget_type']) ? (string) $args['widget_type'] : null;
        $setting_key   = isset($args['setting_key']) ? (string) $args['setting_key'] : null;
        $setting_value = isset($args['setting_value']) ? $args['setting_value'] : null;
        $css_class     = isset($args['css_class']) ? (string) $args['css_class'] : null;

        $has_setting_criterion = null !== $setting_key && null !== $setting_value;

        if ((null !== $setting_key) xor (null !== $setting_value)) {
            return new \WP_Error(
                'missing_criteria',
                'setting_key and setting_value must be passed together.'
            );
        }

        if (null === $el_type && null === $widget_type && ! $has_setting_criterion && null === $css_class) {
            return new \WP_Error(
                'missing_criteria',
                'At least one search criterion is required: el_type, widget_type, setting_key + setting_value, or css_class.'
            );
        }

        $matches = [];
        $this->walk(
            Elementor_Page_Data::get($post_id),
            [],
            static function (array $element, array $path) use (&$matches, $el_type, $widget_type, $setting_key, $setting_value, $has_setting_criterion, $css_class): void {
                $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];

                if (null !== $el_type && ($element['elType'] ?? '') !== $el_type) {
                    return;
                }
                if (null !== $widget_type && ($element['widgetType'] ?? null) !== $widget_type) {
                    return;
                }
                if ($has_setting_criterion) {
                    $stored = $settings[ $setting_key ] ?? null;
                    if (! is_scalar($stored) || (string) $stored !== (string) $setting_value) {
                        return;
                    }
                }
                if (null !== $css_class && ! self::has_class_token($settings, $css_class)) {
                    return;
                }

                $label = $settings['_title'] ?? null;

                $matches[] = [
                    'element_id'  => (string) ($element['id'] ?? ''),
                    'el_type'     => (string) ($element['elType'] ?? ''),
                    'widget_type' => isset($element['widgetType']) ? (string) $element['widgetType'] : null,
                    'label'       => is_string($label) && '' !== $label ? $label : null,
                    'path'        => $path,
                ];
            }
        );

        return [
            'post_id'     => $post_id,
            'matches'     => $matches,
            'match_count' => count($matches),
            'data_hash'   => Element_Tree::data_hash($post_id),
        ];
    }

    /** Depth-first walk calling $visit($element, $ancestor_ids) for every node. */
    private function walk(array $elements, array $path, callable $visit): void
    {
        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }
            $visit($element, $path);
            if (! empty($element['elements']) && is_array($element['elements'])) {
                $this->walk($element['elements'], array_merge($path, [(string) ($element['id'] ?? '')]), $visit);
            }
        }
    }

    /** Token match against both class settings: `css_classes` (layout elements) and `_css_classes` (widget advanced tab). */
    private static function has_class_token(array $settings, string $class): bool
    {
        $haystack = trim(
            (string) ($settings['css_classes'] ?? '') . ' ' . (string) ($settings['_css_classes'] ?? '')
        );

        if ('' === $haystack) {
            return false;
        }

        return in_array($class, preg_split('/\s+/', $haystack) ?: [], true);
    }
}
