<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The ledger row for a navigation menu create-menu CREATED (issue #598).
 *
 * Like Post_Creation_Snapshot, there is no prior state to capture, so the
 * row records what the call made: the nav_menu term, with a fingerprint of
 * its name, slug, description, parent and term meta as the call left them.
 * rollback-operation and rollback-session undo it by deleting the menu, but
 * only while it is unchanged since: same fingerprint, no menu items, and
 * assigned to no theme location. Anything else is refused with the reason
 * and nothing is deleted, so an undo never takes away a menu someone has
 * since built on. Items add-menu-item added are undone on their own rows,
 * and rollback-session undoes the menu after them.
 *
 * The row is written AFTER the menu exists. If it cannot be written, the
 * menu this call just created is deleted again and the error is rethrown:
 * a creation is never left behind without its undo point.
 */
class Menu_Creation_Snapshot
{
    public const OBJECT_TYPE = 'menu_create';

    /**
     * @return string The operation id of the written row.
     * @throws Mutation_Failed When the row could not be written.
     */
    public static function record(string $tool_name, int $menu_id, array $args, string $session_id): string
    {
        $fingerprint = self::fingerprint($menu_id);
        if (null === $fingerprint) {
            throw new \InvalidArgumentException('A creation row needs the created menu.');
        }

        $operation_id = wp_generate_uuid4();
        try {
            Snapshot_Store::save(
                $operation_id,
                '' === $session_id ? 'default' : $session_id,
                [
                    'object_type' => self::OBJECT_TYPE,
                    'object_id'   => $menu_id,
                    'data'        => [ 'fingerprint' => $fingerprint ],
                ],
                $tool_name,
                hash('sha256', (string) wp_json_encode($args))
            );
        } catch (\Throwable $e) {
            // No undo point, no creation. Created by this same call a moment
            // ago, so nothing else can reference it yet.
            wp_delete_nav_menu($menu_id);
            throw $e;
        }
        Operation_Context::note($operation_id);
        Snapshot_Store::prune();

        return $operation_id;
    }

    /**
     * Why undoing this creation would delete more than the call made, or
     * null when it may go ahead (also null for a menu that is already gone:
     * there is nothing left to undo).
     */
    public static function refusal(array $snapshot): ?string
    {
        if (self::OBJECT_TYPE !== ($snapshot['object_type'] ?? '')) {
            return null;
        }
        $menu_id = (int) ($snapshot['object_id'] ?? 0);
        $current = self::fingerprint($menu_id);
        if (null === $current) {
            return null;
        }
        if (! hash_equals((string) ($snapshot['data']['fingerprint'] ?? ''), $current)) {
            return sprintf('menu %d changed since create-menu made it (its name, slug, description or settings), so it was kept.', $menu_id);
        }
        $items = count(get_objects_in_term($menu_id, 'nav_menu'));
        if ($items > 0) {
            return sprintf('menu %d has %d item(s) that this operation did not add, so it was kept. Remove them first, or undo the add-menu-item operations that added them.', $menu_id, $items);
        }
        if (in_array($menu_id, array_map('intval', (array) get_nav_menu_locations()), true)) {
            return sprintf('menu %d is assigned to a theme menu location since it was created, so it was kept.', $menu_id);
        }
        return null;
    }

    /**
     * Delete the created menu, unless refusal() objects (then warn and keep
     * it). A menu that is already gone needs nothing.
     *
     * @return string|null A warning, or null.
     */
    public static function undo_creation(array $snapshot): ?string
    {
        $menu_id = (int) ($snapshot['object_id'] ?? 0);
        if (null === self::fingerprint($menu_id)) {
            return null;
        }
        $refused = self::refusal($snapshot);
        if (null !== $refused) {
            return 'Undo of create-menu skipped: ' . $refused;
        }
        $deleted = wp_delete_nav_menu($menu_id);
        if (true !== $deleted) {
            return sprintf('Menu %d created by this operation could not be deleted; it was left in place.', $menu_id);
        }
        return null;
    }

    /**
     * The menu's own fields and term meta as one hash, or null when no
     * nav_menu term has this id.
     */
    private static function fingerprint(int $menu_id): ?string
    {
        if ($menu_id <= 0) {
            return null;
        }
        $term = get_term($menu_id, 'nav_menu');
        if (! $term instanceof \WP_Term) {
            return null;
        }
        $meta = get_term_meta($menu_id);
        $meta = is_array($meta) ? $meta : [];
        ksort($meta);
        return hash('sha256', (string) wp_json_encode([
            'name'        => (string) $term->name,
            'slug'        => (string) $term->slug,
            'description' => (string) $term->description,
            'parent'      => (int) $term->parent,
            'meta'        => $meta,
        ]));
    }
}
