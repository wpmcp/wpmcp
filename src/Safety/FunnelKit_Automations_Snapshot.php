<?php

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Snapshots of the FunnelKit Automations state one write changes (issue
 * #831), keyed "<kind>:<id>":
 *  - contact:    a contact's tag and list ids, which FunnelKit keeps as JSON
 *                arrays of id strings in the tags and lists columns of its
 *                bwf_contact row;
 *  - automation: an automation's status column in bwfan_automations
 *                (1 active, 2 inactive).
 *
 * The write records the state it left ('after', record()), so an undo takes
 * back only that write: for a contact, the tags and lists it added are
 * removed and the ones it removed are put back, while a tag or list someone
 * added or removed since is left as it is, and so is every other column of
 * the row (names, the subscription status). A removed tag or list whose term
 * was deleted since is not put back. An automation goes back to its saved
 * status only while it still has the status the write left; one changed
 * since keeps it. Each row left alone is named in the restore's warning.
 *
 * Rows are written back raw rather than through FunnelKit, so a restore
 * fires none of FunnelKit's tag or list hooks and starts no automation.
 * Restoring takes FunnelKit's own access check: one of the capabilities its
 * REST API accepts (manage_options unless bwfan_crm_api_access_caps adds
 * more), the check its contact and automation writes go through.
 */
final class FunnelKit_Automations_Snapshot
{
    public const TYPE = 'funnelkit_automations';

    /** The contact columns a contact snapshot covers. */
    private const TERM_COLUMNS = [ 'tags', 'lists' ];

    /** The snapshot key for one object. */
    public static function key(string $kind, int $id): string
    {
        return $kind . ':' . max(0, $id);
    }

    /** @return array{kind: string, id: int} */
    public static function parse(string $key): array
    {
        [$kind, $id] = array_pad(explode(':', $key, 2), 2, '');
        return [ 'kind' => in_array($kind, [ 'contact', 'automation' ], true) ? $kind : '', 'id' => max(0, (int) $id) ];
    }

    /**
     * Whether the current user passes FunnelKit's own access check, the one
     * its REST API applies to every contact and automation write.
     */
    public static function may_manage(): bool
    {
        $caps = [ 'manage_options' ];
        if (class_exists('\\BWFAN_Common') && method_exists('\\BWFAN_Common', 'access_capabilities')) {
            $caps = (array) \BWFAN_Common::access_capabilities();
        }
        foreach ($caps as $cap) {
            if (is_string($cap) && '' !== $cap && current_user_can($cap)) {
                return true;
            }
        }
        return false;
    }

    public static function capture(string $key): array
    {
        $parsed = self::parse($key);
        $state  = '' === $parsed['kind'] ? null : self::state($parsed['kind'], $parsed['id']);
        return [
            'object_type' => self::TYPE,
            'object_id'   => $key,
            'data'        => [
                'kind'   => $parsed['kind'],
                'id'     => $parsed['id'],
                'exists' => null !== $state,
                'before' => $state,
            ],
        ];
    }

    /**
     * Record the state a write left into its snapshot, so the undo takes
     * back only what that write changed.
     */
    public static function record(string $operation_id): void
    {
        $row = '' === $operation_id ? null : Snapshot_Store::get_by_operation($operation_id);
        if (null === $row || self::TYPE !== ($row['snapshot']['object_type'] ?? '')) {
            return;
        }
        $snapshot              = (array) $row['snapshot'];
        $data                  = (array) ($snapshot['data'] ?? []);
        $data['after']         = self::state((string) ($data['kind'] ?? ''), (int) ($data['id'] ?? 0));
        $snapshot['data']      = $data;
        Snapshot_Store::update_snapshot($operation_id, $snapshot);
    }

    /**
     * Take back what the write changed. Returns a warning naming what was
     * left as it is, or null.
     */
    public static function restore(array $snapshot): ?string
    {
        $data   = (array) ($snapshot['data'] ?? []);
        $kind   = (string) ($data['kind'] ?? '');
        $id     = (int) ($data['id'] ?? 0);
        $before = $data['before'] ?? null;
        $after  = $data['after'] ?? null;
        if ('' === $kind || $id <= 0 || empty($data['exists']) || ! is_array($before)) {
            return null;
        }
        if (! self::may_manage()) {
            throw new Mutation_Failed('Rollback refused: restoring FunnelKit Automations data requires FunnelKit\'s own access (manage_options by default).');
        }
        if (! is_array($after)) {
            return sprintf('FunnelKit %s %d was left as it is: this operation did not record what it changed.', $kind, $id);
        }
        $now = self::state($kind, $id);
        if (null === $now) {
            return sprintf('FunnelKit %s %d was deleted since the write and was not restored.', $kind, $id);
        }
        return 'automation' === $kind ? self::restore_automation($id, (int) $before['status'], (int) $after['status'], (int) $now['status']) : self::restore_contact($id, $before, $after, $now);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private static function restore_automation(int $id, int $before, int $after, int $now): ?string
    {
        global $wpdb;

        if ($now !== $after) {
            return sprintf('FunnelKit automation %d was left as it is: its status was changed since the write (now %d).', $id, $now);
        }
        if ($now === $before) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; the saved status set back raw.
        if (false === $wpdb->update(self::table('bwfan_automations'), [ 'status' => $before ], [ 'ID' => $id ], [ '%d' ], [ '%d' ])) {
            throw new Mutation_Failed('Could not restore a FunnelKit automation status: ' . esc_html($wpdb->last_error));
        }
        return null;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<string, mixed> $now
     */
    private static function restore_contact(int $id, array $before, array $after, array $now): ?string
    {
        global $wpdb;

        $kept    = [];
        $changes = [];
        foreach (self::TERM_COLUMNS as $column) {
            $was     = self::ids((array) ($before[ $column ] ?? []));
            $left    = self::ids((array) ($after[ $column ] ?? []));
            $current = self::ids((array) ($now[ $column ] ?? []));
            $added   = array_diff($left, $was);
            $removed = array_diff($was, $left);
            $back    = [];
            foreach ($removed as $term) {
                if (in_array($term, $current, true)) {
                    continue;
                }
                if (! self::term_exists((int) $term)) {
                    $kept[] = sprintf('%s %s (deleted since, not put back)', 'tags' === $column ? 'tag' : 'list', $term);
                    continue;
                }
                $back[] = $term;
            }
            $next = array_values(array_merge(array_diff($current, $added), $back));
            if ($next !== $current) {
                $changes[ $column ] = (string) wp_json_encode($next);
            }
        }
        if ([] !== $changes) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; only the tag and list columns are set back.
            if (false === $wpdb->update(self::table('bwf_contact'), $changes, [ 'id' => $id ])) {
                throw new Mutation_Failed('Could not restore a FunnelKit contact\'s tags and lists: ' . esc_html($wpdb->last_error));
            }
        }
        return [] === $kept ? null : sprintf('FunnelKit contact %d: left as it is: %s.', $id, implode(', ', $kept));
    }

    /**
     * The state a kind covers for one id, or null when it does not exist.
     *
     * @return array<string, mixed>|null
     */
    private static function state(string $kind, int $id): ?array
    {
        global $wpdb;

        if ($id <= 0) {
            return null;
        }
        if ('automation' === $kind) {
            if (! self::table_exists('bwfan_automations')) {
                return null;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, read live around a write.
            $status = $wpdb->get_var($wpdb->prepare('SELECT status FROM %i WHERE ID = %d', self::table('bwfan_automations'), $id));
            return null === $status ? null : [ 'status' => (int) $status ];
        }
        if ('contact' !== $kind || ! self::table_exists('bwf_contact')) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, read live around a write.
        $row = $wpdb->get_row($wpdb->prepare('SELECT tags, lists FROM %i WHERE id = %d', self::table('bwf_contact'), $id), ARRAY_A);
        if (! is_array($row)) {
            return null;
        }
        return [ 'tags' => self::decode($row['tags'] ?? null), 'lists' => self::decode($row['lists'] ?? null) ];
    }

    /** A JSON id list as FunnelKit stores it, as id strings. @return string[] */
    public static function decode($raw): array
    {
        $ids = is_string($raw) && '' !== $raw ? json_decode($raw, true) : [];
        return is_array($ids) ? self::ids($ids) : [];
    }

    /** @return string[] positive ids as strings, in order, without repeats */
    private static function ids(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && ! in_array((string) $id, $out, true)) {
                $out[] = (string) $id;
            }
        }
        return $out;
    }

    private static function term_exists(int $id): bool
    {
        global $wpdb;

        if (! self::table_exists('bwfan_terms')) {
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, read live during a restore.
        return null !== $wpdb->get_var($wpdb->prepare('SELECT ID FROM %i WHERE ID = %d', self::table('bwfan_terms'), $id));
    }

    /** The prefixed table for one of the names this type covers. */
    private static function table(string $name): string
    {
        global $wpdb;

        if (! in_array($name, [ 'bwf_contact', 'bwfan_automations', 'bwfan_terms' ], true)) {
            throw new \InvalidArgumentException('Not a FunnelKit Automations table this snapshot type covers.');
        }
        return $wpdb->prefix . $name;
    }

    private static function table_exists(string $name): bool
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- probing a third-party table's live shape; nothing to cache.
        $columns = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', self::table($name)));
        $wpdb->suppress_errors($suppress);
        return [] !== (array) $columns;
    }
}
