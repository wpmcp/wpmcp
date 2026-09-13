<?php

namespace WPMCP\Tools\Menus;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Assign a navigation menu to a registered theme location.
 *
 * The location-to-menu map is the nav_menu_locations theme_mod, which
 * WordPress stores inside the theme_mods_{stylesheet} OPTION. This routes
 * through Safe_Mutation with the existing object_type 'option', snapshotting
 * that whole option before the change; the mutation itself calls
 * set_theme_mod('nav_menu_locations', ...) so WordPress writes it the standard
 * way, and rollback-operation restores the prior option value (the entire
 * theme_mods array, including the previous nav_menu_locations). No change to
 * the safety core.
 *
 * Snapshotting the full theme_mods option, not just nav_menu_locations, is the
 * honest choice: the option is the recoverable unit, and restoring it puts the
 * assignment map back exactly as it was.
 */
class Assign_Menu_To_Location
{
    public function handle(array $args): array
    {
        $menu_id  = (int) ($args['menu_id'] ?? 0);
        $location = (string) ($args['location'] ?? '');

        if ($menu_id <= 0) {
            throw new \InvalidArgumentException('A menu_id is required.');
        }
        if ('' === $location) {
            throw new \InvalidArgumentException('A location is required.');
        }

        $registered = get_registered_nav_menus();
        if (! isset($registered[ $location ])) {
            throw new \InvalidArgumentException('Unknown theme location: ' . esc_html($location));
        }

        if (! wp_get_nav_menu_object($menu_id)) {
            throw new \RuntimeException('Menu not found.');
        }

        $option_name = 'theme_mods_' . get_stylesheet();

        $out = Safe_Mutation::run(
            [
                'object_type' => 'option',
                'object_id'   => $option_name,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'assign-menu-to-location',
                'args'        => $args,
            ],
            function () use ($menu_id, $location): void {
                $locations              = (array) get_theme_mod('nav_menu_locations', []);
                $locations[ $location ] = $menu_id;
                set_theme_mod('nav_menu_locations', $locations);
            }
        );

        return [
            'menu_id'      => $menu_id,
            'location'     => $location,
            'operation_id' => $out['operation_id'],
            'recoverable'  => true,
        ];
    }
}
