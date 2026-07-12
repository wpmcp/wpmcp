<?php

namespace WPMCP\Tools\Menus;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Add an item to a navigation menu.
 *
 * A menu item is a nav_menu_item post created via wp_update_nav_menu_item()
 * with id 0. Creation has no prior state to snapshot, so this is exempt from
 * Safe_Mutation (matching add-order-note / create-*): a mistaken item can be
 * removed with remove-menu-item. Supports the common custom-link case (title +
 * url) plus optional parent and position; object links (post/term) can be
 * added by passing type, object, and object_id.
 */
class Add_Menu_Item
{
    public function handle(array $args): array
    {
        $menu_id = (int) ($args['menu_id'] ?? 0);
        if ($menu_id <= 0) {
            throw new \InvalidArgumentException('A menu_id is required.');
        }

        if (! wp_get_nav_menu_object($menu_id)) {
            throw new \RuntimeException('Menu not found.');
        }

        $data = [
            'menu-item-title'     => (string) ($args['title'] ?? ''),
            'menu-item-url'       => (string) ($args['url'] ?? ''),
            'menu-item-parent-id' => (int) ($args['parent'] ?? 0),
            'menu-item-status'    => 'publish',
        ];

        if (isset($args['type'])) {
            $data['menu-item-type'] = (string) $args['type'];
        }
        if (isset($args['object'])) {
            $data['menu-item-object'] = (string) $args['object'];
        }
        if (isset($args['object_id'])) {
            $data['menu-item-object-id'] = (int) $args['object_id'];
        }
        if (isset($args['position'])) {
            $data['menu-item-position'] = (int) $args['position'];
        }

        $item_id = wp_update_nav_menu_item($menu_id, 0, $data);
        if (is_wp_error($item_id)) {
            throw new \RuntimeException('Could not add the menu item: ' . $item_id->get_error_message());
        }

        return [
            'menu_id' => $menu_id,
            'item_id' => (int) $item_id,
        ];
    }
}
