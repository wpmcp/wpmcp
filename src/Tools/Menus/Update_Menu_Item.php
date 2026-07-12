<?php

namespace WPMCP\Tools\Menus;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Update an existing navigation menu item (title, url, parent, position).
 *
 * A menu item is a nav_menu_item POST, so this routes through Safe_Mutation
 * with the existing object_type 'post' and the item's post id: the pre-edit
 * post (row, meta, terms) is snapshotted and rollback-operation restores it
 * exactly, with no change to the safety core.
 *
 * wp_update_nav_menu_item() resets any menu-item-* field not supplied to its
 * default, which would silently wipe unrelated fields. To keep the edit
 * surgical we first read the item's current values and merge the caller's
 * changes over them, so only the requested fields move.
 */
class Update_Menu_Item
{
    public function handle(array $args): array
    {
        $item_id = (int) ($args['item_id'] ?? 0);
        if ($item_id <= 0) {
            throw new \InvalidArgumentException('An item_id is required.');
        }

        $post = get_post($item_id);
        if (! $post || 'nav_menu_item' !== $post->post_type) {
            throw new \RuntimeException('Menu item not found.');
        }

        $menu_id = $this->menu_id_for_item($item_id);
        if ($menu_id <= 0) {
            throw new \RuntimeException('Menu item is not assigned to a menu.');
        }

        $current = wp_setup_nav_menu_item($post);

        $data = [
            'menu-item-title'       => $args['title'] ?? $current->title,
            'menu-item-url'         => $args['url'] ?? $current->url,
            'menu-item-parent-id'   => (int) ($args['parent'] ?? $current->menu_item_parent),
            'menu-item-position'    => (int) ($args['position'] ?? $current->menu_order),
            'menu-item-type'        => $current->type,
            'menu-item-object'      => $current->object,
            'menu-item-object-id'   => (int) $current->object_id,
            'menu-item-description' => $current->description,
            'menu-item-attr-title'  => $current->attr_title,
            'menu-item-target'      => $current->target,
            'menu-item-classes'     => implode(' ', (array) $current->classes),
            'menu-item-xfn'         => $current->xfn,
            'menu-item-status'      => 'publish',
        ];

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $item_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-menu-item',
                'args'        => $args,
            ],
            function () use ($menu_id, $item_id, $data): void {
                $result = wp_update_nav_menu_item($menu_id, $item_id, $data);
                if (is_wp_error($result)) {
                    throw new \RuntimeException('Could not update the menu item: ' . $result->get_error_message());
                }
            }
        );

        return [
            'item_id'      => $item_id,
            'menu_id'      => $menu_id,
            'operation_id' => $out['operation_id'],
            'recoverable'  => true,
        ];
    }

    /**
     * Resolve which menu (nav_menu term) a menu item belongs to. Items are
     * tied to their menu through the nav_menu taxonomy, so the item's assigned
     * term is the menu id.
     */
    private function menu_id_for_item(int $item_id): int
    {
        $terms = wp_get_object_terms($item_id, 'nav_menu', ['fields' => 'ids']);
        if (is_wp_error($terms) || empty($terms)) {
            return 0;
        }
        return (int) $terms[0];
    }
}
