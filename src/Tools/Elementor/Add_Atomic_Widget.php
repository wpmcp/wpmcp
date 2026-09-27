<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Add an Elementor 4.0+ atomic widget (elType widget with an e-* widgetType:
 * e-heading, e-paragraph, e-button, e-image, ...) to a page. Friendly params
 * (title, content, text, image_url, alt, link) are converted to the typed
 * $$type prop shapes for known types; any type also accepts raw $$type-wrapped
 * settings. An optional flat `style` object (color, font_size, padding, ...)
 * becomes a local v4 style class on the element. Requires expected_hash;
 * written snapshot-first, undoable.
 */
class Add_Atomic_Widget
{
    public function handle(array $args)
    {
        $unsupported = Atomic_Element::require_supported();
        if (null !== $unsupported) {
            return $unsupported;
        }

        $widget_type = sanitize_text_field((string) ($args['widget_type'] ?? ''));
        if ('' === $widget_type) {
            return new \WP_Error('missing_widget_type', 'A widget_type (e.g. e-heading) is required.');
        }

        $settings = self::resolve_settings($widget_type, $args);
        if (is_wp_error($settings)) {
            return $settings;
        }

        // Everything an agent supplies goes through the one shared mapper, so
        // an aliased prop name, a bare string, or a value wrapped in the wrong
        // $$type lands as the prop Elementor actually declares (issue #137).
        $mapped   = Atomic_Props::map($widget_type, $settings);
        $settings = $mapped['settings'];

        $read = Element_Tree::read_for_edit($args);
        if (is_wp_error($read)) {
            return $read;
        }
        [$post_id, $elements] = $read;

        $element = Atomic_Element::widget($widget_type, $settings);

        $style_warnings = [];
        if (isset($args['style']) && [] !== $args['style']) {
            $built = Atomic_Styles::build($element['id'], $args['style']);
            if (is_wp_error($built)) {
                return $built;
            }
            $element        = Atomic_Styles::attach($element, $built);
            $style_warnings = $built['warnings'];
        }

        $parent_id = (string) ($args['parent_id'] ?? '');
        $position  = isset($args['position']) ? (int) $args['position'] : null;

        if (! Element_Tree::insert_at($elements, $parent_id, $element, $position)) {
            return new \WP_Error('parent_not_found', "No element found with id '{$parent_id}' to insert under.");
        }

        $out = Atomic_Element::write($post_id, $elements, 'add-atomic-widget', $args);
        if (is_wp_error($out)) {
            return $out;
        }

        $mapped['warnings'] = array_merge($mapped['warnings'] ?? [], $style_warnings);

        return $out
            + ['element_id' => $element['id'], 'widget_type' => $widget_type]
            + Atomic_Element::report($mapped);
    }

    /**
     * Raw $$type settings win when provided; otherwise map friendly params for
     * a known type. An unknown type with neither is an error (nothing to store).
     *
     * @return array|\WP_Error
     */
    private static function resolve_settings(string $widget_type, array $args)
    {
        if (is_array($args['settings'] ?? null) && [] !== $args['settings']) {
            return $args['settings'];
        }

        $params = is_array($args['params'] ?? null) ? $args['params'] : [];
        $mapped = Atomic_Widget_Map::settings($widget_type, $params);
        if (null !== $mapped) {
            return $mapped;
        }

        return new \WP_Error(
            'unmapped_widget_type',
            sprintf(
                '"%s" has no friendly-param mapping. Pass raw $$type-wrapped "settings" instead (known mapped types: %s).',
                $widget_type,
                implode(', ', Atomic_Widget_Map::KNOWN)
            )
        );
    }
}
