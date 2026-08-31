<?php

namespace WPMCP\Tools\ThemeBuilder\Render;

use WPMCP\Tools\ThemeBuilder\Template_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Front-end entry point for the theme-builder render slice (issue #70).
 * Wired from Plugin::register_builder_runtime_hooks() behind the same
 * theme_builder group gate as the abilities, so the adapters are reachable
 * code rather than files that only ship.
 */
class Adapters
{
    /** @return Adapter[] most specific first. */
    public static function all(): array
    {
        return [new Block_Adapter(), new Classic_Adapter()];
    }

    /** The adapter serving the active theme, or null when none does. */
    public static function active(): ?Adapter
    {
        foreach (self::all() as $adapter) {
            if ($adapter->supports()) {
                return $adapter;
            }
        }
        return null;
    }

    public static function boot(): void
    {
        if (is_admin()) {
            return;
        }
        $adapter = self::active();
        if (null === $adapter) {
            return;
        }
        foreach (Template_Store::PART_TYPES as $part_type) {
            $adapter->register($part_type);
        }
    }
}
