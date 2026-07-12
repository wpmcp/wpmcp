<?php

namespace WPMCP\Tools\Menus;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delete a navigation menu (a nav_menu term), detaching its items.
 *
 * This is the one menu operation the post/option safety engine does not
 * cleanly cover: deleting the menu removes a nav_menu TERM (and WordPress
 * force-deletes its nav_menu_item posts and clears any location assignments as
 * a side effect). Rather than add a new 'term' object type to the safety core,
 * this tool is honest about being irreversible:
 *
 *  - Disabled by default: a site must opt in with
 *    add_filter('wpmcp_enable_delete_menu', '__return_true').
 *  - Requires confirm:true from the caller.
 *  - Before deleting, it captures the menu name and the list of items it
 *    contained and returns them alongside recoverable:false and a plain
 *    recoverability_note, so the caller has the information needed to rebuild
 *    the menu manually (there is no automated rollback for this operation).
 */
class Delete_Menu
{
    public static function is_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_delete_menu', false);
    }

    public function handle(array $args): array
    {
        if (! self::is_enabled()) {
            throw new \RuntimeException('The delete-menu tool is disabled. Enable it with the wpmcp_enable_delete_menu filter.');
        }

        $id   = (int) ($args['id'] ?? 0);
        $menu = $id ? wp_get_nav_menu_object($id) : false;
        if (! $menu) {
            throw new \RuntimeException('Menu not found.');
        }

        if (true !== ($args['confirm'] ?? null)) {
            throw new \InvalidArgumentException('Deleting a menu is not reversible. Pass confirm:true to proceed.');
        }

        $name  = $menu->name;
        $items = [];
        foreach ((array) wp_get_nav_menu_items($id) as $item) {
            $items[] = [
                'title' => $item->title,
                'url'   => $item->url,
                'type'  => $item->type,
            ];
        }

        $deleted = wp_delete_nav_menu($id);
        if (is_wp_error($deleted) || false === $deleted) {
            throw new \RuntimeException('Could not delete the menu.');
        }

        return [
            'id'                  => $id,
            'name'                => $name,
            'items'               => $items,
            'deleted'             => true,
            'recoverable'         => false,
            'recoverability_note' => 'This menu was permanently deleted and cannot be rolled back automatically. Its former name and items are captured above so it can be rebuilt manually with create-menu and add-menu-item.',
        ];
    }
}
