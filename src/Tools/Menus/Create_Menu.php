<?php

namespace WPMCP\Tools\Menus;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create a new navigation menu (a nav_menu term).
 *
 * Creation has no prior state to snapshot, so this is exempt from
 * Safe_Mutation (matching create-product): a mistaken menu can be removed with
 * delete-menu. wp_create_nav_menu() returns a WP_Error when the name is empty
 * or already in use, which we surface as an exception.
 */
class Create_Menu
{
    public function handle(array $args): array
    {
        $name = trim((string) ($args['name'] ?? ''));
        if ('' === $name) {
            throw new \InvalidArgumentException('A menu name is required.');
        }

        $result = wp_create_nav_menu($name);
        if (is_wp_error($result)) {
            throw new \RuntimeException('Could not create the menu: ' . esc_html($result->get_error_message()));
        }

        $menu = wp_get_nav_menu_object((int) $result);

        return [
            'id'   => (int) $result,
            'name' => $menu ? $menu->name : $name,
            'slug' => $menu ? $menu->slug : '',
        ];
    }
}
