<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read/write access to a page's `_elementor_data` element tree, plus the
 * tree-mutation primitives (find/insert/update/remove by id) shared by every
 * Elementor deep-editing tool.
 *
 * `_elementor_data` is read and written directly as postmeta JSON rather than
 * through Elementor's Document::save(), matching the fallback path Elementor
 * itself uses in non-browser contexts (WP-CLI, REST, and this plugin's own
 * tool calls). Because it is ordinary postmeta on the page post, the existing
 * post snapshot in Safe_Mutation::run() already captures and restores it, so
 * no safety-core change is needed for these edits to be undoable.
 */
class Elementor_Page_Data
{
    public static function get(int $post_id): array
    {
        $raw = get_post_meta($post_id, '_elementor_data', true);

        if (empty($raw) || ! is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function save(int $post_id, array $elements): void
    {
        update_post_meta($post_id, '_elementor_data', wp_json_encode($elements));

        // Invalidate Elementor's generated CSS cache so the change renders on
        // next view. Guarded: the files_manager service only exists when
        // Elementor is fully bootstrapped, which is always true here since
        // these tools already require Elementor to be active, but a guard
        // keeps this safe if that ever changes.
        if (class_exists('\\Elementor\\Plugin') && isset(\Elementor\Plugin::instance()->files_manager)) {
            \Elementor\Plugin::instance()->files_manager->clear_cache();
        }
    }

    /**
     * Recursively find an element by id within a tree, returning a reference
     * to its containing array's entry so callers can mutate it in place.
     */
    public static function &find(array &$elements, string $id): ?array
    {
        $null = null;

        foreach ($elements as &$element) {
            if (($element['id'] ?? null) === $id) {
                return $element;
            }

            if (! empty($element['elements']) && is_array($element['elements'])) {
                $found = &self::find($element['elements'], $id);
                if (null !== $found) {
                    return $found;
                }
            }
        }

        return $null;
    }

    /** Insert $element as a child of $parent_id, or at the top level when $parent_id is empty. */
    public static function insert(array &$elements, string $parent_id, array $element): bool
    {
        if ('' === $parent_id) {
            $elements[] = $element;
            return true;
        }

        foreach ($elements as &$item) {
            if (($item['id'] ?? null) === $parent_id) {
                if (! isset($item['elements']) || ! is_array($item['elements'])) {
                    $item['elements'] = [];
                }
                $item['elements'][] = $element;
                return true;
            }

            if (! empty($item['elements']) && is_array($item['elements'])) {
                if (self::insert($item['elements'], $parent_id, $element)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function remove(array &$elements, string $id): bool
    {
        foreach ($elements as $index => &$item) {
            if (($item['id'] ?? null) === $id) {
                array_splice($elements, $index, 1);
                return true;
            }

            if (! empty($item['elements']) && is_array($item['elements'])) {
                if (self::remove($item['elements'], $id)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Count every element in the tree, recursively including nested children. */
    public static function count_all(array $elements): int
    {
        $count = 0;

        foreach ($elements as $element) {
            $count++;

            if (! empty($element['elements']) && is_array($element['elements'])) {
                $count += self::count_all($element['elements']);
            }
        }

        return $count;
    }

    public static function update_settings(array &$elements, string $id, array $settings): bool
    {
        foreach ($elements as &$item) {
            if (($item['id'] ?? null) === $id) {
                if (! isset($item['settings']) || ! is_array($item['settings'])) {
                    $item['settings'] = [];
                }
                $item['settings'] = array_merge($item['settings'], $settings);
                return true;
            }

            if (! empty($item['elements']) && is_array($item['elements'])) {
                if (self::update_settings($item['elements'], $id, $settings)) {
                    return true;
                }
            }
        }

        return false;
    }
}
