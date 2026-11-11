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

    /**
     * Control types that only lay out the editor panel and hold no setting.
     */
    private const LAYOUT_TYPES = [ 'section', 'tabs', 'tab', 'heading', 'raw_html', 'divider', 'alert', 'notice', 'deprecated_notice', 'button' ];

    /** Control types whose options are the values the setting accepts. */
    private const CHOICE_TYPES = [ 'select', 'select2', 'choose', 'visual_choice' ];

    /**
     * A compact summary of a widget's settings, keyed by control name: type,
     * then label and default when the control has them, and for select-like
     * controls the option keys (the values the setting accepts). Controls
     * that only lay out the editor panel are left out, as are empty labels
     * and defaults.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function control_summary(\Elementor\Widget_Base $widget): array
    {
        $summary = [];

        foreach ($widget->get_controls() as $name => $control) {
            $type = (string) ($control['type'] ?? '');
            if (in_array($type, self::LAYOUT_TYPES, true)) {
                continue;
            }
            $row = [ 'type' => $type ];
            if (isset($control['label']) && '' !== $control['label']) {
                $row['label'] = $control['label'];
            }
            if (isset($control['default']) && '' !== $control['default'] && [] !== $control['default']) {
                $row['default'] = $control['default'];
            }
            if (in_array($type, self::CHOICE_TYPES, true) && isset($control['options']) && is_array($control['options'])) {
                $row['options'] = array_map('strval', array_keys($control['options']));
            }
            $summary[ (string) $name ] = $row;
        }

        return $summary;
    }

    /**
     * A widget's complete control definitions as Elementor holds them,
     * keyed by control name, with anything that is not plain data (such as
     * a callback) dropped so the result encodes as JSON.
     *
     * @return array<string, mixed>
     */
    public static function control_definitions(\Elementor\Widget_Base $widget): array
    {
        $definitions = [];

        foreach ($widget->get_controls() as $name => $control) {
            $definitions[ (string) $name ] = self::plain($control);
        }

        return $definitions;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function plain($value)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                if (! is_object($item)) {
                    $out[ $key ] = self::plain($item);
                }
            }
            return $out;
        }

        return is_object($value) ? null : $value;
    }
}
