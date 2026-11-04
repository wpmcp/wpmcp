<?php

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The parts of a term snapshot that put back what wp_delete_term() changes
 * AROUND the deleted term, beyond the term's own rows and relationships.
 *
 * wp_delete_term() makes side writes the term restore did not undo:
 *  - an object whose only term in the taxonomy was the deleted one is filed
 *    under the taxonomy's default term (Uncategorized, for categories), so
 *    after a plain refile it sat in both (issue #595).
 *
 * Snapshot::capture_term() records what these need through capture(), and
 * Rollback_Service::apply_term_snapshot() calls after_refile() once a
 * deleted term is back with its objects. Kept out of Rollback_Service so the
 * term-specific restore lives in one small place.
 */
class Term_Restore
{
    /**
     * The taxonomy's default term, the one wp_delete_term() refiles orphaned
     * objects under, or 0 when it has none. Mirrors the lookup in core:
     * default_category for categories, default_term_{taxonomy} for a
     * taxonomy registered with a default_term.
     */
    public static function default_term_id(string $taxonomy): int
    {
        $default = 0;
        if ('category' === $taxonomy) {
            $default = (int) get_option('default_category', 0);
        }
        $object = get_taxonomy($taxonomy);
        if ($object && ! empty($object->default_term)) {
            $default = (int) get_option('default_term_' . $taxonomy, 0);
        }
        return $default;
    }

    /**
     * The extra state a term snapshot carries for after_refile().
     *
     * @param int[] $objects The objects the snapshot will refile.
     * @return array<string, mixed>
     */
    public static function capture(int $term_id, string $taxonomy, array $objects): array
    {
        $default = self::default_term_id($taxonomy);

        // Which of the term's objects were ALREADY in the default term. Only
        // the others can have been put there by the delete, so only they lose
        // it on undo, and a post that was filed under both keeps both.
        $in_default = [];
        if ($default > 0 && $default !== $term_id && [] !== $objects) {
            $rows = get_terms([
                'taxonomy'               => $taxonomy,
                'object_ids'             => $objects,
                'include'                => [$default],
                'fields'                 => 'all_with_object_id',
                'hide_empty'             => false,
                'update_term_meta_cache' => false,
            ]);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if ($row instanceof \WP_Term && isset($row->object_id)) {
                        $in_default[] = (int) $row->object_id;
                    }
                }
            }
        }

        return [
            'default_term_id'    => $default,
            'objects_in_default' => array_values(array_unique($in_default)),
        ];
    }

    /**
     * Undo the side writes of wp_delete_term() once the term is back at its
     * own id and its objects are refiled under it.
     *
     * @return string[] Warnings for the rollback result.
     */
    public static function after_refile(int $term_id, string $taxonomy, array $data): array
    {
        $warnings = [];
        self::remove_added_default($term_id, $taxonomy, $data);
        return $warnings;
    }

    /**
     * Take the default term back off every refiled object the delete put in
     * it. Only an object that holds the restored term again loses the
     * default, so none is left without a term, and a snapshot written before
     * objects_in_default was recorded is left as it was.
     */
    private static function remove_added_default(int $term_id, string $taxonomy, array $data): void
    {
        if (! array_key_exists('objects_in_default', $data)) {
            return;
        }
        $default = (int) ($data['default_term_id'] ?? 0);
        if ($default <= 0 || $default === $term_id) {
            return;
        }

        $already = array_flip(array_map('intval', (array) $data['objects_in_default']));
        foreach (array_map('intval', (array) ($data['objects'] ?? [])) as $object_id) {
            if ($object_id <= 0 || isset($already[ $object_id ])) {
                continue;
            }
            $current = wp_get_object_terms($object_id, $taxonomy, ['fields' => 'ids']);
            if (! is_array($current)) {
                continue;
            }
            $current = array_map('intval', $current);
            if (in_array($default, $current, true) && in_array($term_id, $current, true)) {
                wp_remove_object_terms($object_id, [$default], $taxonomy);
            }
        }
    }
}
