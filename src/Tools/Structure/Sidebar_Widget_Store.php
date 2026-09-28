<?php

namespace WPMCP\Tools\Structure;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shared plumbing for the classic sidebar widget writes (issue #285).
 *
 * A classic widget instance lives in two options: its settings in
 * widget_{id_base} (keyed by instance number) and its placement in
 * sidebars_widgets (sidebar id => list of "{id_base}-{number}" ids). Every
 * write here goes through Safe_Mutation with ONE 'option_set' snapshot of
 * both, so a rollback can never restore one half without the other.
 *
 * Only widget types registered with $wp_widget_factory (WP_Widget
 * subclasses, including core's block widget stored in widget_block) are
 * accepted, and settings always pass through the widget's own update()
 * callback, exactly as the Widgets screen does. sidebars_widgets is read and
 * written as the option itself: core's wp_get_sidebars_widgets() and
 * wp_set_sidebars_widgets() are private helpers (see List_Sidebar_Widgets).
 */
class Sidebar_Widget_Store
{
    public const INACTIVE = 'wp_inactive_widgets';

    /** The registered WP_Widget for $id_base, or a refusal naming it. */
    public static function widget_type(string $id_base): \WP_Widget
    {
        global $wp_widget_factory;
        if ('' !== $id_base && $wp_widget_factory instanceof \WP_Widget_Factory) {
            foreach ($wp_widget_factory->widgets as $widget) {
                if ($widget instanceof \WP_Widget && $widget->id_base === $id_base) {
                    return $widget;
                }
            }
        }
        throw new \InvalidArgumentException(sprintf(
            'Unknown widget type "%s": it is not registered with the widget factory.',
            esc_html($id_base)
        ));
    }

    /** @return array{0: \WP_Widget, 1: int} The widget type and instance number of a stored instance. */
    public static function instance(string $widget_id): array
    {
        if (! preg_match('/^(.+)-(\d+)$/', $widget_id, $m)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a widget id like "text-2".', esc_html($widget_id)));
        }
        $widget   = self::widget_type($m[1]);
        $number   = (int) $m[2];
        $settings = self::settings($widget);
        if (! isset($settings[ $number ]) || ! is_array($settings[ $number ])) {
            throw new \InvalidArgumentException(sprintf('Widget "%s" does not exist.', esc_html($widget_id)));
        }
        return [ $widget, $number ];
    }

    /** Refuse a sidebar that is neither registered nor the inactive widgets area. */
    public static function assert_sidebar(string $sidebar_id): void
    {
        global $wp_registered_sidebars;
        if (self::INACTIVE === $sidebar_id || isset($wp_registered_sidebars[ $sidebar_id ])) {
            return;
        }
        $message = sprintf('Sidebar "%s" is not registered; use list-sidebars, or wp_inactive_widgets.', $sidebar_id);
        if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
            $message .= ' Block themes place widgets through template parts, not sidebars.';
        }
        throw new \InvalidArgumentException(esc_html($message));
    }

    public static function sidebars(): array
    {
        $sidebars = get_option('sidebars_widgets', []);
        return is_array($sidebars) ? $sidebars : [];
    }

    /** The sidebar holding $widget_id, or null when it is not placed anywhere. */
    public static function locate(array $sidebars, string $widget_id): ?string
    {
        foreach ($sidebars as $sidebar_id => $ids) {
            if ('array_version' !== $sidebar_id && is_array($ids) && in_array($widget_id, $ids, true)) {
                return (string) $sidebar_id;
            }
        }
        return null;
    }

    /** $sidebars with $widget_id removed from every sidebar. */
    public static function without(array $sidebars, string $widget_id): array
    {
        foreach ($sidebars as $sidebar_id => $ids) {
            if ('array_version' !== $sidebar_id && is_array($ids)) {
                $sidebars[ $sidebar_id ] = array_values(array_diff($ids, [ $widget_id ]));
            }
        }
        return $sidebars;
    }

    /**
     * Place $widget_id in $sidebar_id at $position (0-based, clamped), or at
     * the end when $position is null.
     *
     * @return array{0: array, 1: int} The new sidebars map and the position used.
     */
    public static function place(array $sidebars, string $sidebar_id, string $widget_id, $position): array
    {
        $ids      = array_values((array) ($sidebars[ $sidebar_id ] ?? []));
        $position = null === $position ? count($ids) : max(0, min(count($ids), (int) $position));
        array_splice($ids, $position, 0, [ $widget_id ]);
        $sidebars[ $sidebar_id ] = $ids;
        return [ $sidebars, $position ];
    }

    /**
     * Run $new through the widget's own update() callback and core's
     * widget_update_callback filter, as the Widgets screen does.
     */
    public static function sanitize(\WP_Widget $widget, int $number, array $new, array $old): array
    {
        $widget->_set($number);
        $instance = $widget->update($new, $old);
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own widget_update_callback filter, applied exactly as WP_Widget::update_callback() does.
        $instance = apply_filters('widget_update_callback', $instance, $new, $old, $widget);
        if (! is_array($instance)) {
            throw new \InvalidArgumentException(sprintf(
                'The "%s" widget rejected these settings.',
                esc_html($widget->id_base)
            ));
        }
        return $instance;
    }

    /**
     * A widget type's stored instances, read WITHOUT WP_Widget::get_settings():
     * that method saves an empty option when none exists, which would change
     * the state before the snapshot captures it and make the undo inexact.
     */
    public static function settings(\WP_Widget $widget): array
    {
        $settings = get_option($widget->option_name);
        if (! is_array($settings)) {
            return [];
        }
        unset($settings['_multiwidget'], $settings['__i__']);
        return $settings;
    }

    /** The next free instance number for a widget type (core starts at 2). */
    public static function next_number(\WP_Widget $widget): int
    {
        global $wp_registered_widgets;
        $max = max(1, ...array_map('intval', array_keys(self::settings($widget)) ?: [ 1 ]));
        $ids = array_keys((array) $wp_registered_widgets);
        foreach (self::sidebars() as $sidebar_id => $placed) {
            if ('array_version' !== $sidebar_id && is_array($placed)) {
                $ids = array_merge($ids, $placed);
            }
        }
        $pattern = '/^' . preg_quote($widget->id_base, '/') . '-(\d+)$/';
        foreach ($ids as $id) {
            if (preg_match($pattern, (string) $id, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        return $max + 1;
    }

    /**
     * Run $mutation under one option_set snapshot of sidebars_widgets and the
     * widget type's option. Returns the operation id.
     */
    public static function run(string $tool, array $args, \WP_Widget $widget, callable $mutation): string
    {
        $out = Safe_Mutation::run(
            [
                'object_type' => 'option_set',
                'object_id'   => Snapshot::option_set_id([ 'sidebars_widgets', $widget->option_name ]),
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => $tool,
                'args'        => $args,
            ],
            $mutation
        );
        return (string) $out['operation_id'];
    }
}
