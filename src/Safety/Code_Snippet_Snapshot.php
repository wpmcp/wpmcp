<?php

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the undo reads and writes the Code Snippets row image live; a cached read would restore a stale image.

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Snapshot of one Code Snippets snippet (the 'code_snippet' type), taken
 * before every Code_Snippets_Pack write.
 *
 * Code Snippets 4.1 keeps a site's snippets in one table (its
 * code_snippets()->db->get_table_name(false), {prefix}snippets) and the
 * time each one was last switched off in the recently_active_snippets
 * option. The snapshot is the snippet's whole row, every column verbatim
 * (NULLs included), plus its entry in that option, so the undo puts back
 * exactly what was there: the code, name, description, tags, scope,
 * priority, modified time, revision and authorship columns. The table is
 * always rebuilt from Code Snippets' own helper (or the site prefix), never
 * read from the snapshot, so a snapshot can never name another table.
 *
 * A snapshot taken before a create holds no row (existed:false) and is
 * re-keyed to the new id once Code Snippets assigned it (created()); its
 * undo deletes that snippet through Code Snippets' own delete_snippet(), so
 * its flat file and caches go with it, provided the row is still the one
 * the create wrote (its modified time matches).
 *
 * THE ACTIVE FLAG IS NEVER RESTORED AS ACTIVE, as with the plugin's own
 * stored snippets (Rollback_Service::apply_php_snippet_snapshot()): an undo
 * does not pass the PHP execution gate, so restoring active=1 would make
 * rollback a second, ungated door to running code. A snippet that is
 * active when the undo runs is first switched off through Code Snippets'
 * own deactivate_snippet() (its hooks and flat files follow), then the row
 * image goes back with active=0; a trashed row (active=-1) stays trashed.
 * So undoing an activation deactivates, and undoing a deactivation leaves
 * the snippet off; activation always goes back through the gated op.
 */
final class Code_Snippet_Snapshot
{
    public const TYPE = 'code_snippet';

    /** Code Snippets' option of snippet id => time it was last switched off. */
    public const RECENTLY_ACTIVE = 'recently_active_snippets';

    /** The site's snippets table, from Code Snippets' own helper when it is loaded. */
    public static function table(): string
    {
        global $wpdb;
        if (function_exists('Code_Snippets\code_snippets')) {
            $plugin = \Code_Snippets\code_snippets();
            if (isset($plugin->db) && is_object($plugin->db) && method_exists($plugin->db, 'get_table_name')) {
                $table = (string) $plugin->db->get_table_name(false);
                if (1 === preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                    return $table;
                }
            }
        }
        return $wpdb->prefix . 'snippets';
    }

    /** One snippet row as stored, or null. */
    public static function row(int $id): ?array
    {
        global $wpdb;
        if ($id <= 0) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table(), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function capture(int $id): array
    {
        $row = self::row($id);
        return [
            'object_type' => self::TYPE,
            'object_id'   => $id,
            'data'        => [
                'id'              => $id,
                'existed'         => null !== $row,
                'row'             => $row,
                'recently_active' => self::recently_active($id),
            ],
        ];
    }

    /** The snapshot of a snippet a create just wrote, keyed by its new id. */
    public static function created(int $id): array
    {
        $row = self::row($id);
        return [
            'object_type' => self::TYPE,
            'object_id'   => $id,
            'data'        => [
                'id'       => $id,
                'existed'  => false,
                'row'      => null,
                'modified' => (string) ($row['modified'] ?? ''),
            ],
        ];
    }

    /** Undo one Code Snippets write. Returns a warning, or null. */
    public static function restore(array $snapshot): ?string
    {
        global $wpdb;

        $data = (array) ($snapshot['data'] ?? []);
        $id   = (int) ($snapshot['object_id'] ?? 0);
        if ($id <= 0) {
            return null; // A create that never wrote a row: nothing to undo.
        }
        $table   = self::table();
        $current = self::row($id);

        if (empty($data['existed'])) {
            if (null === $current) {
                return null; // Already gone.
            }
            if ((string) ($data['modified'] ?? '') !== (string) ($current['modified'] ?? '')) {
                return "Code snippet {$id} was changed after this operation created it; it was left in place.";
            }
            self::switch_off($id, $current);
            $deleted = function_exists('Code_Snippets\delete_snippet') && \Code_Snippets\delete_snippet($id, false);
            if (! $deleted) {
                $wpdb->delete($table, [ 'id' => $id ], [ '%d' ]);
            }
            self::put_recently_active($id, null);
            self::flush($table);
            return null === self::row($id) ? null : "Code snippet {$id} created by this operation could not be deleted; it was left in place.";
        }

        $image = $data['row'] ?? null;
        if (! is_array($image) || (int) ($image['id'] ?? 0) !== $id) {
            return "The snapshot of code snippet {$id} is malformed; nothing was restored.";
        }
        self::switch_off($id, $current);
        if (1 === (int) ($image['active'] ?? 0)) {
            $image['active'] = 0;
        }
        if (false === $wpdb->replace($table, $image)) {
            self::flush($table);
            return "Code snippet {$id} could not be restored" . ($wpdb->last_error ? ' (' . $wpdb->last_error . ')' : '') . '.';
        }
        self::put_recently_active($id, $data['recently_active'] ?? null);
        self::flush($table);
        return null;
    }

    /** Switch a currently active snippet off through Code Snippets, so its hooks and flat files follow. */
    private static function switch_off(int $id, ?array $current): void
    {
        global $wpdb;
        if (null === $current || 1 !== (int) ($current['active'] ?? 0)) {
            return;
        }
        if (function_exists('Code_Snippets\deactivate_snippet')) {
            \Code_Snippets\deactivate_snippet($id, false);
        }
        if (1 === (int) (self::row($id)['active'] ?? 0)) {
            $wpdb->update(self::table(), [ 'active' => 0 ], [ 'id' => $id ], [ '%d' ], [ '%d' ]);
        }
    }

    /** This snippet's entry in recently_active_snippets, or null. */
    private static function recently_active(int $id)
    {
        $all = get_option(self::RECENTLY_ACTIVE, []);
        return is_array($all) && array_key_exists($id, $all) ? $all[ $id ] : null;
    }

    /** Put this snippet's recently_active_snippets entry back as captured. */
    private static function put_recently_active(int $id, $value): void
    {
        $all = get_option(self::RECENTLY_ACTIVE, []);
        $all = is_array($all) ? $all : [];
        if (null === $value) {
            if (! array_key_exists($id, $all)) {
                return;
            }
            unset($all[ $id ]);
        } else {
            if (array_key_exists($id, $all) && $all[ $id ] === $value) {
                return;
            }
            $all[ $id ] = $value;
        }
        update_option(self::RECENTLY_ACTIVE, $all);
    }

    /** Drop Code Snippets' cached snippet lists, as its own writes do. */
    private static function flush(string $table): void
    {
        if (function_exists('Code_Snippets\clean_snippets_cache')) {
            \Code_Snippets\clean_snippets_cache($table);
        }
    }
}
