<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Blast-radius scanner for a global variable: which posts reference it.
 *
 * A style prop bound to a variable stores the variable id as its value
 * ({"$$type":"global-color-variable","value":"e-gv-1a2b3c4"}), both in element
 * styles inside `_elementor_data` and in global class definitions, so a
 * quoted-id match over those metas finds every page, template and class that
 * uses it. Same shape and cap as Global_Class_Usage.
 */
class Global_Variable_Usage
{
    public const LIMIT = 50;

    /** Where Elementor stores style props that can point at a variable. */
    public const META_KEYS = [
        '_elementor_data',
        '_elementor_global_class_data',
        '_elementor_global_class_data_preview',
        '_elementor_global_classes',
    ];

    /**
     * @return array{total:int,listed:int,truncated:bool,posts:array<int,array>}
     */
    public static function scan(string $variable_id, int $limit = self::LIMIT): array
    {
        global $wpdb;

        $limit = max(1, min($limit, self::LIMIT));
        $empty = ['total' => 0, 'listed' => 0, 'truncated' => false, 'posts' => []];

        if ('' === $variable_id || ! $wpdb instanceof \wpdb) {
            return $empty;
        }

        // Quoted, so "e-gv-1a2" cannot match "e-gv-1a2b3c4".
        $needle = '"' . $variable_id . '"';
        $like   = '%' . $wpdb->esc_like($needle) . '%';
        $keys   = implode(', ', array_fill(0, count(self::META_KEYS), '%s'));

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- LIKE scan of Elementor style meta has no WP_Query equivalent; $keys is only %s placeholders.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title, p.post_type, p.post_status, pm.meta_value
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key IN ($keys)
                   AND pm.meta_value LIKE %s
                   AND p.post_status != 'trash'
                 ORDER BY p.ID ASC",
                array_merge(self::META_KEYS, [$like])
            ),
            ARRAY_A
        );
        // phpcs:enable

        $by_post = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $id = (int) $row['ID'];
            if (! isset($by_post[$id])) {
                $by_post[$id] = [
                    'post_id'     => $id,
                    'title'       => (string) $row['post_title'],
                    'post_type'   => (string) $row['post_type'],
                    'post_status' => (string) $row['post_status'],
                    'occurrences' => 0,
                    'edit_url'    => get_edit_post_link($id, 'raw'),
                ];
            }
            $by_post[$id]['occurrences'] += substr_count((string) $row['meta_value'], $needle);
        }

        $total = count($by_post);
        $posts = array_slice(array_values($by_post), 0, $limit);

        return [
            'total'     => $total,
            'listed'    => count($posts),
            'truncated' => $total > count($posts),
            'posts'     => $posts,
        ];
    }
}
