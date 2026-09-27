<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Blast-radius scanner for a global class: which posts actually reference it.
 *
 * Atomic elements record the classes applied to them as a `classes` prop whose
 * value is a plain list of class ids inside `_elementor_data`
 * ({"$$type":"classes","value":["g-1a2b3c4"]}), so a quoted-id match over that
 * meta finds every page, template, popup and header/footer that uses the
 * class. It is deliberately a scan of the stored document rather than a read
 * of Elementor's relations index: the index is maintained by the editor and
 * can lag, and for a delete confirmation we want what the content actually
 * says.
 *
 * EMCP's delete asks for confirm:true without ever telling the agent what
 * breaks; this is the report that makes that confirmation an informed one.
 */
class Global_Class_Usage
{
    /** Hard cap on listed posts, so a class used site-wide cannot blow up a tool response. */
    public const LIMIT = 50;

    /**
     * @return array{total:int,listed:int,truncated:bool,posts:array<int,array>}
     */
    public static function scan(string $class_id, int $limit = self::LIMIT): array
    {
        global $wpdb;

        $limit = max(1, min($limit, self::LIMIT));
        $empty = ['total' => 0, 'listed' => 0, 'truncated' => false, 'posts' => []];

        if ('' === $class_id || ! $wpdb instanceof \wpdb) {
            return $empty;
        }

        // The id is matched with its surrounding quotes so "g-1a2" cannot
        // match the unrelated class "g-1a2b3c4".
        $like = '%' . $wpdb->esc_like('"' . $class_id . '"') . '%';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LIKE scan of _elementor_data postmeta has no WP_Query equivalent; the usage report must reflect current content.
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = '_elementor_data'
                   AND pm.meta_value LIKE %s
                   AND p.post_status != 'trash'",
                $like
            )
        );

        if ($total <= 0) {
            return $empty;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LIKE scan of _elementor_data postmeta has no WP_Query equivalent; the usage report must reflect current content.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title, p.post_type, p.post_status, pm.meta_value
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = '_elementor_data'
                   AND pm.meta_value LIKE %s
                   AND p.post_status != 'trash'
                 ORDER BY p.ID ASC
                 LIMIT %d",
                $like,
                $limit
            ),
            ARRAY_A
        );

        $rows  = is_array($rows) ? $rows : [];
        $posts = [];
        foreach ($rows as $row) {
            $posts[] = [
                'post_id'     => (int) $row['ID'],
                'title'       => (string) $row['post_title'],
                'post_type'   => (string) $row['post_type'],
                'post_status' => (string) $row['post_status'],
                'occurrences' => substr_count((string) $row['meta_value'], '"' . $class_id . '"'),
                'edit_url'    => get_edit_post_link((int) $row['ID'], 'raw'),
            ];
        }

        return [
            'total'     => $total,
            'listed'    => count($posts),
            'truncated' => $total > count($posts),
            'posts'     => $posts,
        ];
    }
}
