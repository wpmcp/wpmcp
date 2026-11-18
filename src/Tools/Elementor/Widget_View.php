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
            $row = self::summary_row((array) $control);
            if (null !== $row) {
                $summary[ (string) $name ] = $row;
            }
        }

        return $summary;
    }

    /**
     * The summary row of one control, or null for a control that only lays
     * out the editor panel.
     *
     * @param array<string, mixed> $control
     * @return array<string, mixed>|null
     */
    private static function summary_row(array $control): ?array
    {
        $type = (string) ($control['type'] ?? '');
        if (in_array($type, self::LAYOUT_TYPES, true)) {
            return null;
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
        return $row;
    }

    /** How Elementor's Group_Control_Base marks every field it adds: group type, then field id. */
    private const GROUP_CLASSES = '/^elementor-group-control-(\S+) elementor-group-control elementor-group-control-(\S+)$/';

    /** How a shared summary reads, given once per answer. */
    private const SHARED_NOTE = 'Rows as in control_summary. common: Elementor\'s Advanced tab controls, which every widget listed also has. groups: one entry per group control by setting prefix; its settings are prefix + field for each field of group_fields[type], except omitted ones, with changed rows in place of the shared ones. detail:"full" with one widget gives the complete definitions.';

    /**
     * Control summaries for several widgets with what they share given once
     * (issues #878, #862). Elementor merges its common Advanced tab controls
     * into every widget's stack and expands each group control (background,
     * border, typography and the like) into dozens of fields, so per widget
     * most of a plain summary repeats. Here a control whose row is the
     * common widget's own row goes to 'common' once; the fields of each
     * group control instance collapse to one 'groups' entry keyed by its
     * setting prefix, with the field rows in 'group_fields' once per type
     * (the row most instances share) and only what differs per instance
     * ('changed', 'omitted'). The remaining controls are exactly the plain
     * summary's rows, and putting the parts back together gives each
     * widget's plain summary exactly.
     *
     * @param array<string, \Elementor\Widget_Base> $widgets keyed by widget name
     * @param array<int, array<string, mixed>>      $common_stacks the control stacks of Elementor's common widgets
     * @return array{widgets: array<string, array<string, mixed>>, common?: array<string, mixed>, group_fields?: array<string, mixed>, note?: string}
     */
    public static function shared_summary(array $widgets, array $common_stacks): array
    {
        $shared = [];
        foreach ($common_stacks as $i => $stack) {
            foreach ($stack as $name => $control) {
                $row = self::summary_row((array) $control);
                if (null !== $row) {
                    $shared[ $i ][ (string) $name ] = $row;
                }
            }
        }

        $common = [];
        $units  = [];
        foreach ($widgets as $key => $widget) {
            $own = [];
            foreach ($widget->get_controls() as $name => $control) {
                $control = (array) $control;
                $row     = self::summary_row($control);
                if (null === $row) {
                    continue;
                }
                $name = (string) $name;
                if (self::is_common($name, $row, $shared) && (! isset($common[ $name ]) || $common[ $name ][0] === $row)) {
                    $common[ $name ] = [ $row, $control ];
                    continue;
                }
                $own[ $name ] = [ $row, $control ];
            }
            $units[ (string) $key ] = self::split_groups($own);
        }
        $common_unit = self::split_groups($common);

        // The row each group field has in most instances, first seen on a tie.
        $counts = [];
        foreach (array_merge([ $common_unit ], array_values($units)) as $unit) {
            foreach ($unit['groups'] as $group) {
                foreach ($group['fields'] as $field => $row) {
                    $seen = (string) wp_json_encode($row);
                    $counts[ $group['type'] ][ $field ][ $seen ] = [ ( $counts[ $group['type'] ][ $field ][ $seen ][0] ?? 0 ) + 1, $row ];
                }
            }
        }
        $fields = [];
        foreach ($counts as $type => $by_field) {
            foreach ($by_field as $field => $rows) {
                $best = null;
                foreach ($rows as [ $count, $row ]) {
                    if (null === $best || $count > $best[0]) {
                        $best = [ $count, $row ];
                    }
                }
                $fields[ $type ][ $field ] = $best[1];
            }
        }

        $out = [ 'widgets' => [] ];
        foreach ($units as $key => $unit) {
            $out['widgets'][ $key ] = self::shared_unit($unit, $fields);
        }
        if ([] !== $common) {
            $out['common'] = self::shared_unit($common_unit, $fields);
        }
        if ([] !== $fields) {
            $out['group_fields'] = $fields;
        }
        if ([] !== $common || [] !== $fields) {
            $out['note'] = self::SHARED_NOTE;
        }
        return $out;
    }

    /** @param array<int, array<string, array<string, mixed>>> $shared */
    private static function is_common(string $name, array $row, array $shared): bool
    {
        foreach ($shared as $rows) {
            if (isset($rows[ $name ]) && $rows[ $name ] === $row) {
                return true;
            }
        }
        return false;
    }

    /**
     * Split summary rows into plain controls and group control instances
     * (prefix => type and field rows), by the classes Elementor puts on
     * every group field. A field whose name does not end in its field id
     * (a responsive copy, say) or that would mix two group types under one
     * prefix stays a plain control.
     *
     * @param array<string, array{0: array<string, mixed>, 1: array<string, mixed>}> $pairs name => [row, control]
     * @return array{controls: array<string, array<string, mixed>>, groups: array<string, array{type: string, fields: array<string, array<string, mixed>>}>}
     */
    private static function split_groups(array $pairs): array
    {
        $controls = [];
        $groups   = [];
        foreach ($pairs as $name => [ $row, $control ]) {
            $classes = is_string($control['classes'] ?? null) ? $control['classes'] : '';
            if (1 === preg_match(self::GROUP_CLASSES, $classes, $match) && strlen($name) > strlen($match[2]) && substr($name, -strlen($match[2])) === $match[2]) {
                $prefix = substr($name, 0, -strlen($match[2]));
                if (! isset($groups[ $prefix ]) || $groups[ $prefix ]['type'] === $match[1]) {
                    $groups[ $prefix ]['type']                = $match[1];
                    $groups[ $prefix ]['fields'][ $match[2] ] = $row;
                    continue;
                }
            }
            $controls[ $name ] = $row;
        }
        return [ 'controls' => $controls, 'groups' => $groups ];
    }

    /**
     * One widget's (or the common) part of a shared summary: its plain
     * controls and, when it has any, its groups with what differs from the
     * shared field rows.
     *
     * @param array{controls: array<string, mixed>, groups: array<string, array{type: string, fields: array<string, mixed>}>} $unit
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    private static function shared_unit(array $unit, array $fields): array
    {
        $out = [ 'controls' => $unit['controls'] ];
        foreach ($unit['groups'] as $prefix => $group) {
            $entry   = [ 'type' => $group['type'] ];
            $changed = [];
            foreach ($group['fields'] as $field => $row) {
                if ($fields[ $group['type'] ][ $field ] !== $row) {
                    $changed[ $field ] = $row;
                }
            }
            $omitted = array_values(array_diff(array_map('strval', array_keys($fields[ $group['type'] ])), array_map('strval', array_keys($group['fields']))));
            if ([] !== $changed) {
                $entry['changed'] = $changed;
            }
            if ([] !== $omitted) {
                $entry['omitted'] = $omitted;
            }
            $out['groups'][ (string) $prefix ] = $entry;
        }
        return $out;
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
