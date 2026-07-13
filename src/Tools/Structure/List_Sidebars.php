<?php

namespace WPMCP\Tools\Structure;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: enumerate the registered sidebars/widget areas from the global
 * $wp_registered_sidebars, each reduced to id, name, and description.
 */
class List_Sidebars
{
    public function handle(array $args): array
    {
        global $wp_registered_sidebars;

        $sidebars = [];
        foreach ((array) $wp_registered_sidebars as $id => $sidebar) {
            $sidebars[] = [
                'id'          => (string) $id,
                'name'        => (string) ($sidebar['name'] ?? ''),
                'description' => (string) ($sidebar['description'] ?? ''),
            ];
        }

        return ['sidebars' => $sidebars];
    }
}
