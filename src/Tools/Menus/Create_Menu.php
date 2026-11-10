<?php

namespace WPMCP\Tools\Menus;

use WPMCP\Safety\Menu_Creation_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create a new navigation menu (a nav_menu term).
 *
 * Creation has no prior state to snapshot, so like create-post it records a
 * creation row under the caller's session_id once the menu exists (issue
 * #598, Menu_Creation_Snapshot) and returns its operation_id:
 * rollback-operation and rollback-session delete the menu again, but only
 * while it is unchanged, empty and assigned to no location.
 * wp_create_nav_menu() returns a WP_Error when the name is empty or already
 * in use, which we surface as an exception.
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

        $menu         = wp_get_nav_menu_object((int) $result);
        $operation_id = Menu_Creation_Snapshot::record('create-menu', (int) $result, $args, (string) ($args['session_id'] ?? 'default'));

        return [
            'operation_id' => $operation_id,
            'id'           => (int) $result,
            'name'         => $menu ? $menu->name : $name,
            'slug'         => $menu ? $menu->slug : '',
        ];
    }
}
