<?php

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The rows one database cleanup call deleted (issue #414): revisions,
 * auto-drafts, trashed posts and comments, spam comments and orphaned meta,
 * each kept verbatim (NULLs and ids included) under the core table it came
 * from, so rollback-operation puts back exactly what the call removed.
 *
 * Only the core tables in TABLES can appear, and each is always resolved from
 * $wpdb, never from the snapshot, so a forged snapshot cannot name any other
 * table. Every captured column must be a live column of its table.
 *
 * The restore re-inserts each row at its own id. A row whose id is taken
 * again (something new reclaimed it) is left alone and reported, never
 * overwritten: cleanup only ever deleted, so a row at that id now is someone
 * else's. Term counts and comment counts are recomputed for the rows put
 * back, and the object caches over them are cleared.
 *
 * Restoring takes manage_options, the capability the cleanup itself requires.
 */
final class Db_Cleanup_Snapshot
{
    public const TYPE = 'db_cleanup';

    /**
     * Snapshot key => [$wpdb table property, primary key columns], in restore
     * order (parents before the rows that point at them).
     */
    public const TABLES = [
        'posts'              => [ 'posts', [ 'ID' ] ],
        'postmeta'           => [ 'postmeta', [ 'meta_id' ] ],
        'term_relationships' => [ 'term_relationships', [ 'object_id', 'term_taxonomy_id' ] ],
        'comments'           => [ 'comments', [ 'comment_ID' ] ],
        'commentmeta'        => [ 'commentmeta', [ 'meta_id' ] ],
        'termmeta'           => [ 'termmeta', [ 'meta_id' ] ],
        'usermeta'           => [ 'usermeta', [ 'umeta_id' ] ],
    ];

    /** The empty shell Snapshot::capture() returns; the rows arrive as extra snapshot data. */
    public static function capture(): array
    {
        return [
            'object_type' => self::TYPE,
            'object_id'   => 'cleanup',
            'data'        => [ 'tables' => [] ],
        ];
    }

    /** The live table for a snapshot key, or null when the key is not allowed. */
    public static function table(string $key): ?string
    {
        global $wpdb;

        if (! isset(self::TABLES[ $key ])) {
            return null;
        }
        $property = self::TABLES[ $key ][0];
        return (string) $wpdb->$property;
    }

    /** @return string[] */
    public static function primary_key(string $key): array
    {
        return self::TABLES[ $key ][1] ?? [];
    }

    /**
     * Delete the given rows by primary key, children before parents, then
     * clear the caches over them and recount what depended on them. Any
     * failure is a Mutation_Failed.
     *
     * @param array<string, array<int, array<string, mixed>>> $tables
     */
    public static function delete_rows(array $tables): void
    {
        global $wpdb;

        foreach (array_reverse(array_keys(self::TABLES)) as $key) {
            $rows = (array) ($tables[ $key ] ?? []);
            if ([] === $rows) {
                continue;
            }
            $table = (string) self::table($key);
            foreach ($rows as $row) {
                $where = [];
                foreach (self::primary_key($key) as $column) {
                    $where[ $column ] = $row[ $column ];
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deletes exactly the snapshotted row by primary key, so the undo is exact; the caches over it are cleared right after.
                if (false === $wpdb->delete($table, $where)) {
                    throw new Mutation_Failed('Cleanup could not delete a row of ' . esc_html($table) . ': ' . esc_html($wpdb->last_error));
                }
            }
        }

        self::refresh($tables);
    }

    public static function restore(array $snapshot): ?string
    {
        global $wpdb;

        if (! current_user_can('manage_options')) {
            throw new Mutation_Failed('Rollback refused: restoring a database cleanup requires the manage_options capability.');
        }

        $tables   = (array) ($snapshot['data']['tables'] ?? []);
        $restored = [];
        $taken    = 0;

        foreach (self::TABLES as $key => $spec) {
            $rows = (array) ($tables[ $key ] ?? []);
            if ([] === $rows) {
                continue;
            }
            $table   = (string) self::table($key);
            $columns = Plugin_Table_Rows_Snapshot::columns($table);
            foreach ($rows as $row) {
                $row = (array) $row;
                foreach (array_keys($row) as $column) {
                    if (! in_array((string) $column, $columns, true)) {
                        throw new Mutation_Failed('Rollback refused: captured column "' . esc_html((string) $column) . '" is not a column of "' . esc_html($table) . '".');
                    }
                }
                $where = [];
                foreach ($spec[1] as $column) {
                    if (! isset($row[ $column ])) {
                        throw new Mutation_Failed('Rollback refused: a captured row of "' . esc_html($table) . '" has no "' . esc_html($column) . '".');
                    }
                    $where[ $column ] = $row[ $column ];
                }
                if (self::row_exists($table, $where)) {
                    $taken++;
                    continue;
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- reinserts a snapshotted row at its own id; the caches over it are cleared right after.
                if (false === $wpdb->insert($table, $row)) {
                    throw new Mutation_Failed('Rollback failed to reinsert a row into "' . esc_html($table) . '": ' . esc_html($wpdb->last_error));
                }
                $restored[ $key ][] = $row;
            }
        }

        self::refresh($restored);

        return $taken > 0
            ? sprintf('%d cleaned-up row(s) were not restored: their ids are in use again.', $taken)
            : null;
    }

    /** @param array<string, mixed> $where */
    private static function row_exists(string $table, array $where): bool
    {
        global $wpdb;

        $clauses = [];
        $values  = [ $table ];
        foreach ($where as $column => $value) {
            $clauses[] = '%i = %s';
            $values[]  = $column;
            $values[]  = (string) $value;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- live existence check before a reinsert; the clauses hold only placeholders.
        return null !== $wpdb->get_var($wpdb->prepare('SELECT 1 FROM %i WHERE ' . implode(' AND ', $clauses) . ' LIMIT 1', ...$values));
    }

    /**
     * Clear the caches over rows just deleted or reinserted, and recount the
     * term and comment counts they feed.
     *
     * @param array<string, array<int, array<string, mixed>>> $tables
     */
    private static function refresh(array $tables): void
    {
        global $wpdb;

        $posts = array_map(static fn (array $row): int => (int) $row['ID'], (array) ($tables['posts'] ?? []));
        foreach ($posts as $id) {
            wp_cache_delete($id, 'posts');
            wp_cache_delete($id, 'post_meta');
        }
        foreach ((array) ($tables['postmeta'] ?? []) as $row) {
            wp_cache_delete((int) $row['post_id'], 'post_meta');
        }
        if ([] !== $posts || [] !== (array) ($tables['postmeta'] ?? [])) {
            wp_cache_set_posts_last_changed();
        }

        $comment_posts = [];
        foreach ((array) ($tables['comments'] ?? []) as $row) {
            clean_comment_cache((int) $row['comment_ID']);
            $comment_posts[ (int) $row['comment_post_ID'] ] = true;
        }
        foreach ((array) ($tables['commentmeta'] ?? []) as $row) {
            wp_cache_delete((int) $row['comment_id'], 'comment_meta');
        }
        foreach ((array) ($tables['termmeta'] ?? []) as $row) {
            wp_cache_delete((int) $row['term_id'], 'term_meta');
        }
        foreach ((array) ($tables['usermeta'] ?? []) as $row) {
            wp_cache_delete((int) $row['user_id'], 'user_meta');
        }

        $by_taxonomy = [];
        foreach ((array) ($tables['term_relationships'] ?? []) as $row) {
            $tt_id = (int) $row['term_taxonomy_id'];
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the taxonomy of a term_taxonomy id, for the recount below.
            $taxonomy = (string) $wpdb->get_var($wpdb->prepare("SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $tt_id));
            if ('' === $taxonomy) {
                continue;
            }
            $by_taxonomy[ $taxonomy ][] = $tt_id;
            wp_cache_delete((int) $row['object_id'], $taxonomy . '_relationships');
        }
        foreach ($by_taxonomy as $taxonomy => $tt_ids) {
            wp_update_term_count_now(array_values(array_unique($tt_ids)), $taxonomy);
        }

        foreach (array_keys($comment_posts) as $post_id) {
            if ($post_id > 0 && null !== get_post($post_id)) {
                wp_update_comment_count_now($post_id);
            }
        }
    }
}
