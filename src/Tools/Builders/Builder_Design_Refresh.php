<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Brings a builder's derived state in line after its global design data
 * changed outside its editor (issue #391): a design write through
 * update-builder-content, or the rollback of one, which puts the whole
 * option back. Both call after_options() with the options they wrote, so
 * the forward write and its undo refresh the same way.
 *
 * - Bricks regenerates its color palette and global variables CSS files
 *   itself on update_option. Its style manager file (palette and scale
 *   variables) is only rebuilt by its own saves, so it is rebuilt here, and
 *   the global classes timestamp and user it compares against to warn an
 *   open editor of a concurrent change are bumped the way its own class
 *   save does.
 * - Breakdance renders its selectors and global settings into CSS files
 *   only on its own saves, so they are rebuilt through the engine.
 *
 * Lives apart from the writer because the always-loaded rollback path
 * calls it, and every build flavor ships it. A no-op for any other option,
 * and for a builder that is not loaded.
 */
final class Builder_Design_Refresh
{
    private const BRICKS_STYLE_MANAGER = ['bricks_color_palette', 'bricks_global_variables'];

    private const BREAKDANCE_GLOBAL = ['breakdance_breakdance_classes_json_string', 'breakdance_global_settings_json_string'];

    /** @param string[] $names option names just written or restored */
    public static function after_options(array $names): void
    {
        if (in_array('bricks_global_classes', $names, true)) {
            update_option('bricks_global_classes_timestamp', time());
            update_option('bricks_global_classes_user', get_current_user_id());
        }

        if ([] !== array_intersect(self::BRICKS_STYLE_MANAGER, $names)) {
            self::bricks_style_manager();
        }

        if ([] !== array_intersect(self::BREAKDANCE_GLOBAL, $names)) {
            self::breakdance_global();
        }
    }

    private static function bricks_style_manager(): void
    {
        if (! defined('BRICKS_VERSION') || ! is_callable(['Bricks\\Ajax', 'generate_style_manager_css_file'])) {
            return;
        }
        if (! class_exists('Bricks\\Assets') || empty(\Bricks\Assets::$css_dir)) {
            return;
        }

        try {
            \Bricks\Ajax::generate_style_manager_css_file();
        } catch (\Throwable $e) {
            // A stale stylesheet must never fail the write it follows.
            unset($e);
        }
    }

    private static function breakdance_global(): void
    {
        if (! Breakdance_Cache::plugin_active('breakdance') || ! function_exists('Breakdance\\Render\\generateCacheForGlobalSettings')) {
            return;
        }

        try {
            \Breakdance\Render\generateCacheForGlobalSettings();
        } catch (\Throwable $e) {
            unset($e);
        }
    }
}
