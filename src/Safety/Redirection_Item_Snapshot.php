<?php

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Snapshots of one row in the Redirection plugin's `redirection_items` table
 * (issue #300), keyed by the redirect's id.
 *
 * The whole row is captured verbatim (id, hit counter and NULLs included),
 * so the restore puts back exactly that row: an edit goes back in place, a
 * deleted redirect returns at its own id, and when there was no row (a
 * create, whose id the write reserves before the snapshot is taken) the
 * restore removes whatever the write put there. Not db_rows: that type
 * restores only rows a WHERE matched, so it cannot undo an insert.
 *
 * Lives in the safety layer, apart from the adapter in src/Integrations, so
 * a restore needs none of the adapter's classes on any build. Restoring takes
 * manage_options, the capability the adapter's writes require and the one
 * the Redirection plugin gates its own admin behind by default.
 */
final class Redirection_Item_Snapshot
{
    public const TYPE = 'redirection_item';

    /** The prefixed redirect table. */
    public static function items_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'redirection_items';
    }

    /** The prefixed group table. */
    public static function groups_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'redirection_groups';
    }

    /**
     * Whether a table exists. SHOW COLUMNS rather than SHOW TABLES, which does
     * not list temporary tables.
     */
    public static function table_exists(string $table): bool
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- probing a third-party table's live shape; nothing to cache.
        $columns = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table));
        $wpdb->suppress_errors($suppress);

        return [] !== (array) $columns;
    }

    /**
     * One redirect row exactly as the database holds it, or null.
     *
     * @return array<string, string|null>|null
     */
    public static function row(int $id): ?array
    {
        global $wpdb;

        if ($id <= 0) {
            return null;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a snapshot must read the live row of a third-party table; there is no WP API for it.
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::items_table(), $id),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * The columns that say which redirect a row is: its source, its target
     * and its match type. A create records them in its snapshot so the undo
     * can tell its own row from a different redirect that later took the id.
     *
     * @return array{url: ?string, action_data: ?string, match_type: ?string}
     */
    public static function identity(array $row): array
    {
        $out = [];
        foreach ([ 'url', 'action_data', 'match_type' ] as $column) {
            $value          = $row[ $column ] ?? null;
            $out[ $column ] = null === $value ? null : (string) $value;
        }
        return $out;
    }

    public static function capture(int $id): array
    {
        $exists = self::table_exists(self::items_table());

        return [
            'object_type' => self::TYPE,
            'object_id'   => $id,
            'data'        => [
                'id'           => $id,
                'table_exists' => $exists,
                'row'          => $exists ? self::row($id) : null,
            ],
        ];
    }

    /**
     * Put the captured row back, or remove the row when there was none. Any
     * failure is a Mutation_Failed. Returns a warning when a creation's row
     * was left in place, or null.
     *
     * Undoing a create removes the row only while it is still the redirect
     * the create wrote (same source, target and match type, recorded as
     * 'created' in the snapshot). A row that no longer matches is someone
     * else's redirect, or ours changed outside this undo, so it is left
     * alone and reported (issue #334).
     */
    public static function restore(array $snapshot): ?string
    {
        if (! current_user_can('manage_options')) {
            throw new Mutation_Failed('Rollback refused: restoring a Redirection redirect requires the manage_options capability.');
        }

        $data = (array) ($snapshot['data'] ?? []);
        $id   = (int) ($data['id'] ?? 0);
        if ($id <= 0 || empty($data['table_exists']) || ! self::table_exists(self::items_table())) {
            return null;
        }

        global $wpdb;
        $current = self::row($id);
        $row     = is_array($data['row'] ?? null) ? (array) $data['row'] : null;

        if (null === $row && is_array($data['created'] ?? null)) {
            if (null === $current) {
                return null; // Already gone: nothing to undo.
            }
            if (self::identity((array) $data['created']) !== self::identity($current)) {
                return sprintf(
                    'Redirection redirect %d is not the redirect this operation created (its source, target or match type differs); it was left untouched.',
                    $id
                );
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- third-party table with no WP API; Redirection's own caches are flushed below.
        if (false === $wpdb->delete(self::items_table(), [ 'id' => $id ], [ '%d' ])) {
            throw new Mutation_Failed('Could not clear Redirection redirect ' . (int) $id . '.');
        }
        if (null !== $row) {
            $row['id'] = $id;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- third-party table with no WP API.
            if (false === $wpdb->insert(self::items_table(), $row)) {
                throw new Mutation_Failed('Could not restore Redirection redirect ' . (int) $id . ': ' . esc_html($wpdb->last_error));
            }
        }

        self::flush((int) ($row['group_id'] ?? 0), (int) ($current['group_id'] ?? 0));
        return null;
    }

    /**
     * Tell Redirection its redirects changed, the way its own model does
     * after a write: flush the module each touched group belongs to (the
     * Apache and Nginx modules regenerate their server rules) and move the
     * redirect cache key on, so no cached lookup outlives the change. Both
     * are no-ops while the plugin is not loaded.
     */
    public static function flush(int ...$group_ids): void
    {
        if (class_exists('Red_Module') && method_exists('Red_Module', 'flush')) {
            foreach (array_unique(array_filter($group_ids)) as $group_id) {
                \Red_Module::flush($group_id);
            }
        }

        if (function_exists('red_set_options')) {
            $options = get_option('redirection_options');
            if (is_array($options) && (int) ($options['cache_key'] ?? 0) > 0) {
                red_set_options([ 'cache_key' => time() ]);
            }
        }
    }
}
