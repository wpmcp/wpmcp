<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional.
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional.

namespace WPMCP\Tools\Search;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Storage for the materialized site content search index (issue #83).
 *
 * One row per indexed FRAGMENT, not per post: a page contributes its title,
 * its excerpt, one row per Gutenberg block that carries text, and one row per
 * builder element setting that carries text. That granularity is the whole
 * point of the feature: an agent asking "where does this string live?" gets
 * back an addressable location (block path / Elementor element id / menu item
 * id), not just "somewhere on page 42".
 *
 * This table is DERIVED state: every row can be recomputed from posts,
 * postmeta, and menus. It is therefore deliberately outside the Safe_Mutation
 * snapshot path: there is nothing here a rollback could usefully restore that
 * `reindex-search` cannot rebuild from the source of truth in one call. The
 * safety core stays reserved for writes to real site content.
 *
 * The schema version is tracked in an option so an upgraded plugin repairs its
 * own table on first use, without requiring a deactivate/reactivate cycle.
 */
class Search_Index_Store
{
    public const SCHEMA_OPTION  = 'wpmcp_search_index_schema';
    public const SCHEMA_VERSION = '1';

    /** Longest text we keep for a single fragment. Bounds pathological pages. */
    public const MAX_FRAGMENT_CHARS = 2000;

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wpmcp_search_index';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            object_type VARCHAR(20) NOT NULL,
            object_id BIGINT(20) UNSIGNED NOT NULL,
            subtype VARCHAR(32) NOT NULL DEFAULT '',
            source VARCHAR(20) NOT NULL,
            node VARCHAR(64) NOT NULL DEFAULT '',
            location VARCHAR(191) NOT NULL DEFAULT '',
            field VARCHAR(100) NOT NULL DEFAULT '',
            content TEXT NOT NULL,
            weight SMALLINT(5) UNSIGNED NOT NULL DEFAULT 10,
            indexed_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY object (object_type, object_id),
            KEY source (source)
        ) {$charset};");
        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION);
    }

    /**
     * Install the table when it is missing or was built by an older schema.
     * Called from every entry point (index write, search read, reindex) so the
     * feature is self-healing on a plugin update that never re-ran activation.
     */
    public static function ensure_installed(): void
    {
        if (self::SCHEMA_VERSION === (string) get_option(self::SCHEMA_OPTION, '') && self::table_exists()) {
            return;
        }
        self::install();
    }

    public static function table_exists(): bool
    {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- existence check for wpmcp_search_index, this plugin's own table ($wpdb->prefix + literal); must see the live schema so self-healing install works.
        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /**
     * Replace every fragment stored for one object with $documents, in a
     * delete-then-insert pass. Returns the number of fragments written.
     *
     * @param array<int,array<string,mixed>> $documents
     */
    public static function replace_object(string $object_type, int $object_id, array $documents): int
    {
        global $wpdb;
        self::purge_object($object_type, $object_id);

        $now     = current_time('mysql', true);
        $written = 0;
        foreach ($documents as $document) {
            $content = (string) ($document['content'] ?? '');
            if ('' === trim($content)) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- wpmcp_search_index is this plugin's own derived-index table; WP has no API for it.
            $wpdb->insert(self::table_name(), [
                'object_type' => $object_type,
                'object_id'   => $object_id,
                'subtype'     => substr((string) ($document['subtype'] ?? ''), 0, 32),
                'source'      => substr((string) ($document['source'] ?? 'content'), 0, 20),
                'node'        => substr((string) ($document['node'] ?? ''), 0, 64),
                'location'    => substr((string) ($document['location'] ?? ''), 0, 191),
                'field'       => substr((string) ($document['field'] ?? ''), 0, 100),
                'content'     => self::clamp($content),
                'weight'      => max(1, min(255, (int) ($document['weight'] ?? 10))),
                'indexed_at'  => $now,
            ]);
            ++$written;
        }

        return $written;
    }

    public static function purge_object(string $object_type, int $object_id): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpmcp_search_index is this plugin's own derived-index table; WP has no API for it and a delete has nothing to cache.
        $wpdb->delete(self::table_name(), [
            'object_type' => $object_type,
            'object_id'   => $object_id,
        ]);
    }

    /** Drop every row. Used by a full rebuild before it re-indexes. */
    public static function truncate(): void
    {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- full clear of wpmcp_search_index, this plugin's own table; the name is $wpdb->prefix plus a literal (see table_name()) and identifiers cannot be bound.
        $wpdb->query("DELETE FROM {$table}");
    }

    public static function clamp(string $text): string
    {
        if (strlen($text) <= self::MAX_FRAGMENT_CHARS) {
            return $text;
        }
        return substr($text, 0, self::MAX_FRAGMENT_CHARS);
    }

    /**
     * Candidate fragments for a term set: every row whose content contains at
     * least one term. Ranking happens in PHP (see Search_Ranker) so relevance
     * is engine-independent and unit-testable.
     *
     * @param  string[]                            $terms
     * @param  array{object_types?:string[],sources?:string[],subtypes?:string[],max_rows?:int} $filters
     * @return array<int,array<string,mixed>>
     */
    public static function candidates(array $terms, array $filters = []): array
    {
        global $wpdb;
        if ([] === $terms) {
            return [];
        }
        self::ensure_installed();

        $table  = self::table_name();
        $where  = [];
        $params = [];

        $likes = [];
        foreach ($terms as $term) {
            $likes[]  = 'content LIKE %s';
            $params[] = '%' . $wpdb->esc_like($term) . '%';
        }
        $where[] = '(' . implode(' OR ', $likes) . ')';

        foreach (
            [
            'object_types' => 'object_type',
            'sources'      => 'source',
            'subtypes'     => 'subtype',
            ] as $key => $column
        ) {
            $values = array_values(array_filter(array_map('strval', (array) ($filters[ $key ] ?? []))));
            if ([] === $values) {
                continue;
            }
            $where[] = $column . ' IN (' . implode(',', array_fill(0, count($values), '%s')) . ')';
            $params  = array_merge($params, $values);
        }

        $max_rows = max(1, min(5000, (int) ($filters['max_rows'] ?? 2000)));
        $sql      = "SELECT object_type, object_id, subtype, source, node, location, field, content, weight
             FROM {$table}
             WHERE " . implode(' AND ', $where) . '
             ORDER BY weight DESC, id ASC
             LIMIT ' . $max_rows;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- placeholders are built above and values bound here; wpmcp_search_index is this plugin's own table and a search must read the live index, capped at max_rows (5000).
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /** @return array{documents:int,objects:int,last_indexed_at:?string,by_source:array<string,int>} */
    public static function stats(): array
    {
        global $wpdb;
        self::ensure_installed();
        $table = self::table_name();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregate stats over wpmcp_search_index, this plugin's own table; the name is $wpdb->prefix plus a literal (see table_name()) and the stats must reflect the just-written index.
        $documents = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $objects   = (int) $wpdb->get_var("SELECT COUNT(DISTINCT object_type, object_id) FROM {$table}");
        $last      = $wpdb->get_var("SELECT MAX(indexed_at) FROM {$table}");
        $rows      = $wpdb->get_results("SELECT source, COUNT(*) AS total FROM {$table} GROUP BY source", ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        $by_source = [];
        foreach ((array) $rows as $row) {
            $by_source[ (string) $row['source'] ] = (int) $row['total'];
        }
        ksort($by_source);

        return [
            'documents'       => $documents,
            'objects'         => $objects,
            'last_indexed_at' => null === $last ? null : (string) $last,
            'by_source'       => $by_source,
        ];
    }
}
