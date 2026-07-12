<?php

namespace WPMCP\Tools\Menus;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: return one navigation menu with its ordered items.
 *
 * The menu is a nav_menu term; its items are nav_menu_item posts, fetched via
 * wp_get_nav_menu_items() (already sorted by menu_order). Each item is reduced
 * to a safe summary (id, title, url, type, object, parent, order). Reads have
 * nothing to roll back, so this never touches Safe_Mutation.
 */
class Get_Menu
{
    public function handle(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('A menu id is required.');
        }

        $menu = wp_get_nav_menu_object($id);
        if (! $menu) {
            throw new \RuntimeException('Menu not found.');
        }

        $items = wp_get_nav_menu_items($id);
        $rows  = [];

        foreach ((array) $items as $item) {
            $rows[] = [
                'id'          => (int) $item->ID,
                'title'       => $item->title,
                'url'         => $item->url,
                'type'        => $item->type,
                'object'      => $item->object,
                'object_id'   => (int) $item->object_id,
                'parent'      => (int) $item->menu_item_parent,
                'menu_order'  => (int) $item->menu_order,
            ];
        }

        return [
            'id'    => (int) $menu->term_id,
            'name'  => $menu->name,
            'slug'  => $menu->slug,
            'items' => $rows,
        ];
    }
}
