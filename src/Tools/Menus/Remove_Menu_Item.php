<?php

namespace WPMCP\Tools\Menus;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Remove an item from a navigation menu.
 *
 * Removing a menu item force-deletes its nav_menu_item POST (this is what
 * WordPress's own wp_delete_nav_menu_item() does). Because it is a post, this
 * routes through Safe_Mutation with the existing object_type 'post': the full
 * pre-delete post (row, meta, terms including its nav_menu assignment) is
 * snapshotted, so rollback-operation resurrects the item at its original id
 * and re-attaches it to its menu. No change to the safety core.
 */
class Remove_Menu_Item
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

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $item_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'remove-menu-item',
                'args'        => $args,
            ],
            function () use ($item_id): void {
                if (false === wp_delete_post($item_id, true)) {
                    throw new \RuntimeException('Could not remove the menu item.');
                }
            }
        );

        return [
            'item_id'      => $item_id,
            'operation_id' => $out['operation_id'],
            'recoverable'  => true,
        ];
    }
}
