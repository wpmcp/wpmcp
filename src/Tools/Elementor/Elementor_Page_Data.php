<?php

namespace WPMCP\Tools\Elementor;

use WPMCP\Tools\Builders\Elementor_Cache;

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
        // wp_slash before the write: update_post_meta runs wp_unslash on the
        // value, which would strip the backslashes JSON adds to escape any
        // embedded double quote (e.g. a dynamic-tag "[elementor-tag id=\"..\"]"
        // value), corrupting the stored JSON. Slashing here makes the two cancel.
        update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($elements)));

        // The render cache and generated CSS still describe the old tree;
        // drop them for this document, as Elementor's own save does.
        Elementor_Cache::invalidate_document($post_id);
    }

    /** Whether Elementor treats the page as built with it (it renders `_elementor_data` only then). */
    public static function is_built_with_elementor(int $post_id): bool
    {
        return (bool) get_post_meta($post_id, '_elementor_edit_mode', true);
    }

    /**
     * Refusal for a page-tree write that would change what the page shows
     * beyond the edit itself (issue #868). Elementor renders `_elementor_data`
     * only once `_elementor_edit_mode` is set, and setting it hides the
     * page's WordPress editor content. A page with no flag and no such
     * content is safe to initialise; one that has content (never opened in
     * Elementor, or switched back to the WordPress editor) is refused, with
     * nothing written, rather than having that content silently replaced.
     */
    public static function builder_mode_refusal(int $post_id): ?\WP_Error
    {
        if (self::is_built_with_elementor($post_id)) {
            return null;
        }

        $post = get_post($post_id);
        if (! $post || '' === trim((string) $post->post_content)) {
            return null;
        }

        return new \WP_Error(
            'not_an_elementor_page',
            "Page {$post_id} is not built with Elementor and has WordPress editor content, which Elementor would hide once the page becomes an Elementor page. Nothing was written. "
            . 'To keep that content, open the page once in the Elementor editor (Elementor converts it into a Text Editor widget), then re-read it with get-elementor-data and retry. '
            . 'To replace it, clear the content with update-post first, or build a new page with build-page.'
        );
    }

    /**
     * Mark the page as built with Elementor, as Elementor's editor does when
     * a page is first opened in it, so the stored tree renders (issue #868).
     * Called inside the snapshotted mutation, so rollback removes the meta it
     * adds. Leaves a page that already has the flag and version untouched.
     */
    public static function mark_built_with_elementor(int $post_id): void
    {
        if (! self::is_built_with_elementor($post_id)) {
            // The string, not a boolean, matching Elementor's own write.
            update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        }

        if (defined('ELEMENTOR_VERSION') && '' === (string) get_post_meta($post_id, '_elementor_version', true)) {
            update_post_meta($post_id, '_elementor_version', (string) ELEMENTOR_VERSION);
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
                $item['settings'] = array_merge($item['settings'], Classic_Dimensions::normalize($settings));
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
