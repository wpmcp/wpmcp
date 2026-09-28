<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shape an Elementor Widget_Base instance into the safe summary row and the
 * control-schema row shared by list-widgets and get-widget-schema.
 */
class Widget_View
{
    /**
     * @return array{name: string, title: string, categories: array<int, string>, icon: string, tier: string, available: bool}
     */
    public static function summary(\Elementor\Widget_Base $widget): array
    {
        return [
            'name'       => $widget->get_name(),
            'title'      => $widget->get_title(),
            'categories' => $widget->get_categories(),
            'icon'       => $widget->get_icon(),
            'tier'       => self::tier($widget),
            'available'  => ! self::is_promotion($widget),
        ];
    }

    /**
     * Elementor Pro widgets are namespaced under ElementorPro\...; every
     * bundled Elementor (free) widget lives under the Elementor\ namespace.
     * Free Elementor also registers PROMOTION placeholders under Pro widget
     * names (Elementor\Modules\Promotions\...): those represent pro widgets
     * that are not installed, so they report as 'pro' - never as free
     * widgets a caller could insert.
     */
    public static function tier(\Elementor\Widget_Base $widget): string
    {
        if (0 === strpos(get_class($widget), 'ElementorPro\\') || self::is_promotion($widget)) {
            return 'pro';
        }

        return 'free';
    }

    /** Whether this registration is a Pro-promotion placeholder, not a real widget. */
    public static function is_promotion(\Elementor\Widget_Base $widget): bool
    {
        return 0 === strpos(get_class($widget), 'Elementor\\Modules\\Promotions\\');
    }

    /**
     * Curate a widget's control stack down to name, type, label, default, and
     * section grouping, keyed by control name.
     *
     * @return array<string, array{type: mixed, label: mixed, default: mixed, section: mixed}>
     */
    public static function controls(\Elementor\Widget_Base $widget): array
    {
        $controls = [];

        foreach ($widget->get_controls() as $name => $control) {
            $controls[ $name ] = [
                'type'    => $control['type'] ?? null,
                'label'   => $control['label'] ?? null,
                'default' => $control['default'] ?? null,
                'section' => $control['section'] ?? null,
            ];
        }

        return $controls;
    }
}
