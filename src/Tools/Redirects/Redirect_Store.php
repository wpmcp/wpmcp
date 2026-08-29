<?php

namespace WPMCP\Tools\Redirects;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Storage layer for the managed redirect table (issue #128).
 *
 * A redirect row is identified two ways: by its auto-increment `id` (what
 * the update/delete tools address it by) and by its `source_path`, which is
 * UNIQUE and is the real natural key: the front-end handler looks a request
 * up by normalized source path, and the Safety layer snapshots a redirect by
 * source path too (see Snapshot::capture_redirect()), the same way it
 * snapshots an option by name rather than by row id.
 *
 * Everything in the "pure helpers" block is DB-free so it can be unit tested
 * without touching MySQL, and so the front-end hot path does no work beyond
 * one indexed lookup.
 *
 * Nothing in this class writes a snapshot: the tool classes own that, so the
 * store stays a dumb, testable persistence layer and every write that
 * reaches it has already been wrapped by Safe_Mutation.
 */
class Redirect_Store
{
    /** Longest source path we will index (the column is VARCHAR(191) for utf8mb4 key limits). */
    public const MAX_SOURCE_LENGTH = 191;

    /** How many hops create-redirect/update-redirect will follow when flattening a chain. */
    public const MAX_CHAIN_DEPTH = 10;

    /** The only redirect status codes this manager will issue. */
    public const ALLOWED_STATUS_CODES = [301, 302, 307, 308];

    /** Schema version, and the option it is recorded in, for self-healing upgrades. */
    public const DB_VERSION        = 1;
    public const DB_VERSION_OPTION = 'wpmcp_redirects_db_version';

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wpmcp_redirects';
    }

    /**
     * Create the table. Called from Activator::activate() and, in the test
     * harness, once per run from tests/bootstrap.php: DDL implicitly commits
     * in MySQL, so a lazy first CREATE inside a test would silently end that
     * test's isolation transaction (the same trap documented for the
     * snapshots table).
     */
    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_path VARCHAR(191) NOT NULL,
            target_url TEXT NOT NULL,
            target_post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            status_code SMALLINT(5) UNSIGNED NOT NULL DEFAULT 301,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            hits BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            last_hit_at DATETIME NULL,
            notes VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY source_path (source_path),
            KEY enabled (enabled)
        ) {$charset};");
    }

    /**
     * Create the table on a site that upgraded into this feature without
     * re-activating the plugin (activation hooks only fire on activation).
     * Hooked to admin_init, never to a front-end request: the guard is one
     * autoloaded option read, but DDL belongs nowhere near the hot path.
     */
    public static function maybe_install(): void
    {
        if (self::DB_VERSION === (int) get_option(self::DB_VERSION_OPTION, 0)) {
            return;
        }
        self::install();
    }

    // -----------------------------------------------------------------
    // Pure helpers (no database access)
    // -----------------------------------------------------------------

    /**
     * Reduce a URL or path to the comparable, home-relative source path the
     * table is keyed on: host and scheme dropped, the install's home path
     * prefix removed (subdirectory installs), percent-decoded, lowercased,
     * duplicate slashes collapsed, exactly one leading slash, trailing slash
     * and query/fragment dropped. The site root normalizes to '/'.
     *
     * Lowercasing is deliberate and slightly lossy: WordPress slugs are
     * lowercase by sanitize_title(), and a case-sensitive key would let
     * /About and /about be two different redirects, which is a footgun with
     * no upside for a redirect manager.
     */
    public static function normalize_path(string $url_or_path): string
    {
        $value = trim($url_or_path);
        if ('' === $value) {
            return '/';
        }

        $parts = wp_parse_url($value);
        $path  = is_array($parts) && isset($parts['path']) ? (string) $parts['path'] : '';
        if ('' === $path && (! is_array($parts) || ! isset($parts['host']))) {
            // Not URL-shaped at all (e.g. "old-page"): treat the whole string as the path.
            $path = $value;
        }

        $home = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        if ('' !== $home && '/' !== $home && 0 === strpos($path, $home)) {
            $path = substr($path, strlen(rtrim($home, '/')));
        }

        $path = rawurldecode($path);
        $path = strtolower($path);
        $path = (string) preg_replace('#/+#', '/', $path);
        $path = '/' . ltrim($path, '/');
        $path = rtrim($path, '/');

        return '' === $path ? '/' : $path;
    }

    /**
     * True when a target is a link into this site (relative, or absolute on
     * the home host). Off-site targets are legal redirect targets and are
     * simply never chain-flattened or loop-checked against local sources.
     */
    public static function is_internal(string $target): bool
    {
        $host = strtolower((string) wp_parse_url(trim($target), PHP_URL_HOST));
        if ('' === $host) {
            return true;
        }
        return $host === strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    }

    /** Clamp an arbitrary status code to one this manager is willing to issue. */
    public static function clamp_status_code($code): int
    {
        $code = (int) $code;
        return in_array($code, self::ALLOWED_STATUS_CODES, true) ? $code : 301;
    }

    /**
     * The URL a row actually sends visitors to. A row with target_post_id set
     * resolves to that post's CURRENT permalink at match time, which is what
     * makes the redirect survive the target's own later slug changes; if that
     * post has since been deleted, trashed or unpublished the row resolves to
     * '' and the handler treats it as inactive rather than sending visitors
     * to a URL that no longer serves anything.
     */
    public static function resolve_target(array $row): string
    {
        $post_id = (int) ($row['target_post_id'] ?? 0);
        if ($post_id > 0) {
            if ('publish' !== get_post_status($post_id)) {
                return '';
            }
            $link = get_permalink($post_id);
            return is_string($link) ? $link : '';
        }
        return (string) ($row['target_url'] ?? '');
    }

    // -----------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public static function get(int $id): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpmcp_redirects is this plugin's own table; the read must reflect the live row (it feeds snapshots and the undo path).
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table_name(), $id),
            ARRAY_A
        );
        return $row ? self::cast($row) : null;
    }

    /** @return array<string,mixed>|null */
    public static function find_by_source(string $source_path): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpmcp_redirects is this plugin's own table; the front-end matcher must see the live row for a source path.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE source_path = %s',
                self::table_name(),
                self::normalize_path($source_path)
            ),
            ARRAY_A
        );
        return $row ? self::cast($row) : null;
    }

    /**
     * Redirects, newest first.
     *
     * @param array{enabled?:bool|null,search?:string,limit?:int,offset?:int} $filters
     * @return array<int, array<string,mixed>>
     */
    public static function all(array $filters = []): array
    {
        global $wpdb;
        [$where, $params] = self::where_clause($filters);

        $limit  = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $sql = 'SELECT * FROM ' . self::table_name() . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpmcp_redirects is this plugin's own table; listings must reflect live rows.
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is literal fragments plus where_clause() output; every value goes through a %s/%d placeholder.
            $wpdb->prepare($sql, array_merge($params, [$limit, $offset])),
            ARRAY_A
        );

        return array_map([self::class, 'cast'], is_array($rows) ? $rows : []);
    }

    /** @param array{enabled?:bool|null,search?:string} $filters */
    public static function count(array $filters = []): int
    {
        global $wpdb;
        [$where, $params] = self::where_clause($filters);
        $sql = 'SELECT COUNT(*) FROM ' . self::table_name() . $where;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- wpmcp_redirects is this plugin's own table; $sql is literal fragments plus where_clause() output with %s/%d placeholders, and the count must match live rows.
        return (int) ($params ? $wpdb->get_var($wpdb->prepare($sql, $params)) : $wpdb->get_var($sql));
    }

    /**
     * @param array{enabled?:bool|null,search?:string} $filters
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function where_clause(array $filters): array
    {
        global $wpdb;
        $clauses = [];
        $params  = [];

        if (array_key_exists('enabled', $filters) && null !== $filters['enabled']) {
            $clauses[] = 'enabled = %d';
            $params[]  = $filters['enabled'] ? 1 : 0;
        }
        if (! empty($filters['search'])) {
            $like      = '%' . $wpdb->esc_like((string) $filters['search']) . '%';
            $clauses[] = '(source_path LIKE %s OR target_url LIKE %s)';
            $params[]  = $like;
            $params[]  = $like;
        }

        return [$clauses ? ' WHERE ' . implode(' AND ', $clauses) : '', $params];
    }

    // -----------------------------------------------------------------
    // Writes
    //
    // These are plain row writes. Every caller in the tool layer wraps them
    // in Safe_Mutation, so an undo is always available; nothing here writes
    // a snapshot itself.
    // -----------------------------------------------------------------

    /**
     * Insert a row from an already-validated field map and return the new id.
     *
     * @param array<string,mixed> $fields
     */
    public static function insert(array $fields): int
    {
        global $wpdb;
        $now = current_time('mysql', true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- wpmcp_redirects is this plugin's own table; undo is handled by Safe_Mutation at the tool layer (see block comment above).
        $wpdb->insert(self::table_name(), array_merge(self::defaults(), $fields, [
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        return (int) $wpdb->insert_id;
    }

    /** @param array<string,mixed> $fields */
    public static function update(int $id, array $fields): void
    {
        global $wpdb;
        $fields['updated_at'] = current_time('mysql', true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpmcp_redirects is this plugin's own table; undo is handled by Safe_Mutation at the tool layer (see block comment above).
        $wpdb->update(self::table_name(), $fields, ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpmcp_redirects is this plugin's own table; undo is handled by Safe_Mutation at the tool layer (see block comment above).
        $wpdb->delete(self::table_name(), ['id' => $id], ['%d']);
    }

    public static function delete_by_source(string $source_path): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpmcp_redirects is this plugin's own table; undo is handled by Safe_Mutation at the tool layer (see block comment above).
        $wpdb->delete(self::table_name(), ['source_path' => self::normalize_path($source_path)], ['%s']);
    }

    /**
     * Re-insert a captured row verbatim, INCLUDING its original id, so a
     * rollback of delete-redirect resurrects the same row rather than a copy
     * with a new id. Only the rollback path calls this.
     *
     * @param array<string,mixed> $row
     */
    public static function insert_raw(array $row): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- wpmcp_redirects is this plugin's own table; this IS the rollback path, resurrecting a captured row verbatim.
        $wpdb->insert(self::table_name(), self::writable_columns($row));
    }

    /**
     * Overwrite an existing row (addressed by id) with a captured row's
     * values. Only the rollback path calls this.
     *
     * @param array<string,mixed> $row
     */
    public static function overwrite(int $id, array $row): void
    {
        global $wpdb;
        $fields = self::writable_columns($row);
        unset($fields['id']);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpmcp_redirects is this plugin's own table; this IS the rollback path, restoring a captured row's values.
        $wpdb->update(self::table_name(), $fields, ['id' => $id]);
    }

    /**
     * Record a front-end hit. Deliberately NOT snapshot-wrapped: hits and
     * last_hit_at are usage telemetry about a redirect, not part of its
     * configuration, so an undo has nothing meaningful to restore and a
     * snapshot per front-end request would be a performance disaster.
     */
    public static function record_hit(int $id): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpmcp_redirects is this plugin's own table; atomic hit-counter increment on the front-end hot path (see docblock).
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET hits = hits + 1, last_hit_at = %s WHERE id = %d',
            self::table_name(),
            current_time('mysql', true),
            $id
        ));
    }

    /** @return array<string,mixed> */
    private static function defaults(): array
    {
        return [
            'source_path'    => '',
            'target_url'     => '',
            'target_post_id' => 0,
            'status_code'    => 301,
            'enabled'        => 1,
            'hits'           => 0,
            'last_hit_at'    => null,
            'notes'          => '',
        ];
    }

    /**
     * Restrict an arbitrary array to real table columns AND coerce each one
     * back to its column type, so a captured (and therefore potentially
     * stale, and definitely already cast()) snapshot row can never introduce
     * an unknown column or a PHP bool into a $wpdb write.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function writable_columns(array $row): array
    {
        $casts = [
            'id'             => 'int',
            'source_path'    => 'string',
            'target_url'     => 'string',
            'target_post_id' => 'int',
            'status_code'    => 'int',
            'enabled'        => 'bool_int',
            'hits'           => 'int',
            'last_hit_at'    => 'nullable_string',
            'notes'          => 'string',
            'created_at'     => 'string',
            'updated_at'     => 'string',
        ];

        $out = [];
        foreach ($casts as $column => $cast) {
            if (! array_key_exists($column, $row)) {
                continue;
            }
            $value = $row[ $column ];
            if ('int' === $cast) {
                $out[ $column ] = (int) $value;
            } elseif ('bool_int' === $cast) {
                $out[ $column ] = $value ? 1 : 0;
            } elseif ('nullable_string' === $cast) {
                $out[ $column ] = null === $value ? null : (string) $value;
            } else {
                $out[ $column ] = (string) $value;
            }
        }
        return $out;
    }

    /**
     * $wpdb hands every column back as a string; cast the row into the shape
     * the tools and the handler advertise so callers never have to.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function cast(array $row): array
    {
        return [
            'id'             => (int) $row['id'],
            'source_path'    => (string) $row['source_path'],
            'target_url'     => (string) $row['target_url'],
            'target_post_id' => (int) $row['target_post_id'],
            'status_code'    => (int) $row['status_code'],
            'enabled'        => (bool) (int) $row['enabled'],
            'hits'           => (int) $row['hits'],
            'last_hit_at'    => null === $row['last_hit_at'] ? null : (string) $row['last_hit_at'],
            'notes'          => (string) $row['notes'],
            'created_at'     => (string) $row['created_at'],
            'updated_at'     => (string) $row['updated_at'],
        ];
    }
}
