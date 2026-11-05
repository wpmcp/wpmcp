<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional.
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional.

namespace WPMCP\Tools\Search;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * `wpmcp/reindex-search`: build or rebuild the content search index (#83).
 *
 * The index is normally maintained incrementally (see Index_Hooks), so this
 * tool exists for the first build and for a deliberate full refresh after a
 * bulk import, a migration, or a builder-data change made outside WordPress.
 *
 * Cursor-based rather than "index the whole site in one request": each call
 * walks at most `batch_size` objects and returns `next_offset`, so a 50k-post
 * site is rebuilt by a caller looping a bounded, interruptible sequence of
 * calls instead of one request that dies on max_execution_time. `offset = 0`
 * with `full = true` clears the table first; later pages append, so a rebuild
 * in progress never wipes the work of the pages before it.
 *
 * No Safe_Mutation: this writes only derived state (see Search_Index_Store),
 * and its own inverse is another call to itself.
 */
class Reindex_Search
{
    public const DEFAULT_BATCH = 200;
    public const MAX_BATCH     = 1000;

    public function handle(array $args): array
    {
        Search_Index_Store::ensure_installed();

        $batch_size = max(1, min(self::MAX_BATCH, (int) ($args['batch_size'] ?? self::DEFAULT_BATCH)));
        $offset     = max(0, (int) ($args['offset'] ?? 0));
        $full       = (bool) ($args['full'] ?? true);
        $with_menus = (bool) ($args['include_menus'] ?? true);

        $requested  = $this->requested_post_types($args);
        $post_types = [] === $requested
            ? Content_Indexer::indexable_post_types()
            : array_values(array_intersect(Content_Indexer::indexable_post_types(), $requested));

        $unknown = array_values(array_diff($requested, $post_types));

        if ($full && 0 === $offset) {
            Search_Index_Store::truncate();
        }
        // Coverage (issue #592): only a pass over every indexable post type,
        // from the first page to the last, makes the index complete. A pass
        // limited by post_types never does, and after a truncate it leaves
        // the index partial.
        $whole_site = [] === $requested;
        if ($whole_site && 0 === $offset) {
            Search_Index_Store::mark_build_started();
        }

        $query = new \WP_Query([
            'post_type'              => [] === $post_types ? ['post'] : $post_types,
            'post_status'            => Content_Indexer::INDEXABLE_STATUSES,
            'posts_per_page'         => $batch_size,
            'offset'                 => $offset,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'fields'                 => 'ids',
            'ignore_sticky_posts'    => true,
            'no_found_rows'          => false,
            'update_post_term_cache' => false,
        ]);

        $posts_indexed = 0;
        $documents     = 0;
        foreach ($query->posts as $post_id) {
            $written = Content_Indexer::index_post((int) $post_id);
            if ($written > 0) {
                ++$posts_indexed;
                $documents += $written;
            }
        }

        $total_posts = (int) $query->found_posts;
        $next_offset = ($offset + $batch_size) < $total_posts ? $offset + $batch_size : null;
        if ($whole_site && null === $next_offset) {
            Search_Index_Store::mark_build_finished();
        }

        // Menus are a small, fixed-size set, so they ride along with the first
        // page rather than getting their own cursor.
        $menus_indexed = 0;
        if ($with_menus && 0 === $offset) {
            foreach ($this->menu_ids() as $menu_id) {
                $written = Content_Indexer::index_menu($menu_id);
                if ($written > 0) {
                    ++$menus_indexed;
                    $documents += $written;
                }
            }
        }

        return [
            'indexed'     => [
                'posts'     => $posts_indexed,
                'menus'     => $menus_indexed,
                'documents' => $documents,
            ],
            'post_types'  => $post_types,
            'unknown_post_types' => $unknown,
            'batch_size'  => $batch_size,
            'offset'      => $offset,
            'total_posts' => $total_posts,
            'next_offset' => $next_offset,
            'complete'    => null === $next_offset,
            'full_rebuild' => $full && 0 === $offset,
            'index'       => Search_Index_Store::stats(),
        ];
    }

    /** @return string[] */
    private function requested_post_types(array $args): array
    {
        $raw = $args['post_types'] ?? ($args['post_type'] ?? []);
        if (is_string($raw)) {
            $raw = '' === trim($raw) ? [] : [$raw];
        }
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $entry) {
            if (is_scalar($entry) && '' !== trim((string) $entry)) {
                $out[] = sanitize_key((string) $entry);
            }
        }
        return array_values(array_unique(array_filter($out)));
    }

    /** @return int[] */
    private function menu_ids(): array
    {
        $menus = wp_get_nav_menus();
        $ids   = [];
        foreach (is_array($menus) ? $menus : [] as $menu) {
            $ids[] = (int) $menu->term_id;
        }
        return $ids;
    }
}
