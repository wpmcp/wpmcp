<?php

namespace WPMCP\Tools\Elementor;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read/write seam for Elementor 4.0+ global classes (the Class Manager).
 *
 * Reads and writes go through Elementor's own Global_Classes_Repository, so
 * this keeps working across the storage change Elementor made in 4.2 (classes
 * moved out of the kit's `_elementor_global_classes` meta into a dedicated
 * post type, with order and labels still on the kit). Legacy kit meta is still
 * read as a fallback so a not-yet-migrated site is not reported as empty.
 *
 * Every write is snapshot-first. The snapshot is a dedicated
 * 'elementor_global_classes' object type holding the COMPLETE prior class set
 * (items + order), because that is the only capture that can undo the full
 * range of writes: with class content living in its own posts, a kit-post
 * snapshot would not bring a deleted class back. Rollback_Service replays the
 * captured set through the same repository, so rollback-operation resurrects a
 * deleted class, its variants and its position in one step.
 */
class Global_Classes_Store
{
    public const REPOSITORY = '\\Elementor\\Modules\\GlobalClasses\\Global_Classes_Repository';
    public const META_KEY   = '_elementor_global_classes';
    public const UPDATE_CAP = 'elementor_global_classes_update_class';

    /** Snapshot object type; Rollback_Service::apply_snapshot() dispatches on it. */
    public const SNAPSHOT_TYPE = 'elementor_global_classes';

    /**
     * Whether this install can author global classes: Elementor 4.0+ with the
     * global classes repository AND the v4 style schema every write is
     * validated against. Without both, the write tools do not register.
     */
    public static function is_supported(): bool
    {
        return class_exists(self::REPOSITORY) && Global_Class_Schema::is_supported();
    }

    /**
     * Elementor grants `elementor_global_classes_update_class` to
     * administrators; manage_options is accepted as the equivalent so a site
     * that has not run Elementor's capability migration still works. The
     * ability itself is registered with manage_options, so this is a
     * defence-in-depth re-check at execution time rather than the only gate.
     */
    public static function can_edit(): bool
    {
        return current_user_can(self::UPDATE_CAP) || current_user_can('manage_options');
    }

    /**
     * The current class set.
     *
     * Elementor's repository is the source of truth when present. An empty
     * repository falls back to the legacy `_elementor_global_classes` kit meta
     * so a site Elementor has not migrated to the 4.2 post storage (or one
     * running an Elementor too old to have the repository at all) still lists
     * its classes instead of looking empty.
     *
     * @return array{kit_id:int,items:array<string,array>,order:array<int,string>}|\WP_Error
     */
    public static function read()
    {
        $kit_id = Elementor_Kit_Data::active_kit_id();
        if ($kit_id <= 0) {
            return new \WP_Error('kit_not_found', 'No active Elementor kit was found.');
        }

        $items = [];
        $order = [];

        if (class_exists(self::REPOSITORY)) {
            try {
                $repo  = call_user_func([self::REPOSITORY, 'make']);
                $items = self::normalize_items(self::unwrap_items($repo->all()));
                $order = self::normalize_order((array) $repo->get_order(), $items);
            } catch (\Throwable $e) {
                return new \WP_Error('read_failed', 'Elementor could not read the global classes: ' . $e->getMessage());
            }
        }

        if ([] === $items) {
            [$items, $order] = self::read_kit_meta($kit_id);
        }

        return ['kit_id' => $kit_id, 'items' => $items, 'order' => $order];
    }

    /**
     * Optimistic-lock fingerprint of the whole class set. Every write is a
     * read-modify-write of the complete items map, so a stale caller could
     * otherwise delete a class another agent added between read and write;
     * list-global-classes hands this back as `state_hash` and the write tools
     * refuse a mismatch. EMCP has no equivalent and simply clobbers.
     */
    public static function state_hash(array $items, array $order): string
    {
        return hash('sha256', (string) wp_json_encode([
            'items' => self::sorted($items),
            'order' => array_values($order),
        ]));
    }

    /**
     * Read the class set and enforce the shared write preconditions:
     * capability, availability, and a matching expected_hash.
     *
     * @return array{kit_id:int,items:array<string,array>,order:array<int,string>}|\WP_Error
     */
    public static function guard(array $args)
    {
        if (! self::is_supported()) {
            return new \WP_Error(
                'unsupported',
                'Global classes need Elementor 4.0+ with the v4 class manager; this site does not expose it.'
            );
        }
        if (! self::can_edit()) {
            return new \WP_Error('forbidden', 'You are not allowed to edit Elementor global classes.');
        }

        $state = self::read();
        if (is_wp_error($state)) {
            return $state;
        }

        $expected = (string) ($args['expected_hash'] ?? '');
        if ('' === $expected) {
            return new \WP_Error(
                'missing_expected_hash',
                '"expected_hash" is required: read the classes with list-global-classes first and pass back its state_hash.'
            );
        }
        if (! hash_equals(self::state_hash($state['items'], $state['order']), $expected)) {
            return new \WP_Error(
                'stale_expected_hash',
                'Stale expected_hash: the global classes changed since they were read. Nothing was written. '
                . 'Re-read with list-global-classes and retry.'
            );
        }

        return $state;
    }

    /**
     * Snapshot the current class set, then persist the new one and verify it
     * landed. Any failure restores the captured set, so a half-applied write
     * cannot leave the Class Manager inconsistent.
     *
     * @param array $before the guard() state, captured for rollback.
     * @return array|\WP_Error
     */
    public static function write(array $before, array $items, array $order, string $tool_name, array $args)
    {
        $operation_id = wp_generate_uuid4();
        $kit_id       = (int) $before['kit_id'];

        Snapshot_Store::save(
            $operation_id,
            (string) ($args['session_id'] ?? 'default'),
            [
                'object_type' => self::SNAPSHOT_TYPE,
                'object_id'   => $kit_id,
                'data'        => [
                    'kit_id' => $kit_id,
                    'items'  => $before['items'],
                    'order'  => $before['order'],
                ],
            ],
            $tool_name,
            hash('sha256', (string) wp_json_encode($args))
        );
        Snapshot_Store::prune(Snapshot_Store::history_limit());

        try {
            self::persist($items, $order);
        } catch (\Throwable $e) {
            Rollback_Service::restore_operation($operation_id);
            return new \WP_Error(
                'mutation_failed',
                'The global class write failed and the previous class set was restored: ' . $e->getMessage()
            );
        }

        $after = self::read();
        if (is_wp_error($after) || ! self::matches($items, $order, $after['items'], $after['order'])) {
            Rollback_Service::restore_operation($operation_id);
            return new \WP_Error(
                'mutation_failed',
                'The write did not store the intended global classes; the previous class set was restored.'
            );
        }

        return [
            'operation_id' => $operation_id,
            'kit_id'       => $kit_id,
            'state_hash'   => self::state_hash($after['items'], $after['order']),
        ];
    }

    /** Write the class set through Elementor's repository. */
    public static function persist(array $items, array $order): void
    {
        $repo = call_user_func([self::REPOSITORY, 'make']);
        $repo->put($items, array_values($order));

        if (class_exists('\\Elementor\\Plugin') && isset(\Elementor\Plugin::instance()->files_manager)) {
            \Elementor\Plugin::instance()->files_manager->clear_cache();
        }
    }

    /**
     * Did the store end up holding what we asked it to?
     *
     * Elementor normalizes what it persists (it may add its own keys, e.g. a
     * null custom_css), so this checks the parts the caller controls: the same
     * class ids in the same order, each with the intended label and the
     * intended props per variant meta.
     */
    private static function matches(array $items, array $order, array $stored_items, array $stored_order): bool
    {
        if (array_values($order) !== array_values($stored_order)) {
            return false;
        }
        if (array_keys(self::sorted($items)) !== array_keys(self::sorted($stored_items))) {
            return false;
        }

        foreach ($items as $id => $item) {
            $stored = $stored_items[$id] ?? null;
            if (! is_array($stored) || ($item['label'] ?? null) !== ($stored['label'] ?? null)) {
                return false;
            }
            if (self::variant_props($item) !== self::variant_props($stored)) {
                return false;
            }
        }

        return true;
    }

    /** "breakpoint:state" => props, so variant order differences do not matter. */
    private static function variant_props(array $item): array
    {
        $map = [];
        foreach ((array) ($item['variants'] ?? []) as $variant) {
            $meta      = (array) (((array) $variant)['meta'] ?? []);
            $key       = ($meta['breakpoint'] ?? 'desktop') . ':' . (string) ($meta['state'] ?? '');
            $map[$key] = self::deep_sort((array) (((array) $variant)['props'] ?? []));
        }
        ksort($map);

        return $map;
    }

    /**
     * Recursively key-sort an array so the comparison is about values, not the
     * order Elementor happened to serialize a typed prop's fields in.
     */
    private static function deep_sort(array $value): array
    {
        foreach ($value as $key => $inner) {
            if (is_array($inner)) {
                $value[$key] = self::deep_sort($inner);
            }
        }
        ksort($value);

        return $value;
    }

    /** Legacy { items, order } kit meta, used when the repository is empty. */
    private static function read_kit_meta(int $kit_id): array
    {
        $store = get_post_meta($kit_id, self::META_KEY, true);
        $items = is_array($store) && is_array($store['items'] ?? null) ? $store['items'] : [];
        $items = self::normalize_items($items);
        $order = self::normalize_order(is_array($store['order'] ?? null) ? $store['order'] : [], $items);

        return [$items, $order];
    }

    /** Elementor returns a Global_Classes collection; get at the id => item map. */
    private static function unwrap_items($all): array
    {
        if (is_object($all) && method_exists($all, 'get_items')) {
            $all = $all->get_items();
        }
        if (is_object($all) && method_exists($all, 'all')) {
            $all = $all->all();
        }

        return (array) $all;
    }

    /** @return array<string,array> */
    private static function normalize_items(array $items): array
    {
        $normalized = [];
        foreach ($items as $id => $item) {
            if (is_string($id) && '' !== $id && (is_array($item) || is_object($item))) {
                $normalized[$id] = (array) $item;
            }
        }

        return $normalized;
    }

    /**
     * The stored order, with every known class present exactly once: an order
     * array that has drifted from the items map must never silently drop a
     * class out of the Class Manager.
     *
     * @return array<int,string>
     */
    private static function normalize_order(array $order, array $items): array
    {
        $final = [];
        foreach ($order as $id) {
            $id = (string) $id;
            if (isset($items[$id]) && ! in_array($id, $final, true)) {
                $final[] = $id;
            }
        }
        foreach (array_keys($items) as $id) {
            if (! in_array($id, $final, true)) {
                $final[] = (string) $id;
            }
        }

        return $final;
    }

    /** @return array<string,array> items keyed and sorted by id, for stable hashing. */
    private static function sorted(array $items): array
    {
        ksort($items);

        return $items;
    }
}
