<?php

namespace WPMCP\Tools\Menus;

use WPMCP\Safety\Post_Creation_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Add an item to a navigation menu.
 *
 * A menu item is a nav_menu_item post created via wp_update_nav_menu_item()
 * with id 0. Creation has no prior state to snapshot, so like create-post it
 * records a creation row under the caller's session_id (issue #598,
 * Post_Creation_Snapshot) and returns its operation_id: rollback-operation
 * and rollback-session remove the item again, as remove-menu-item does.
 * Supports the common custom-link case (title +
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

        // wp_update_nav_menu_item() stores through wp_insert_post() and the
        // meta writers, which all unslash.
        $item_id = wp_update_nav_menu_item($menu_id, 0, wp_slash($data));
        if (is_wp_error($item_id)) {
            throw new \RuntimeException('Could not add the menu item: ' . esc_html($item_id->get_error_message()));
        }

        $operation_id = Post_Creation_Snapshot::record('add-menu-item', [ (int) $item_id ], $args, (string) ($args['session_id'] ?? 'default'));

        return [
            'operation_id' => $operation_id,
            'menu_id'      => $menu_id,
            'item_id'      => (int) $item_id,
        ];
    }
}
