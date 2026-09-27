<?php

namespace WPMCP\Integrations;

use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shared base for the forms adapters (issue #66).
 *
 * What every forms adapter agrees on lives here rather than being re-derived
 * in each one, and tests/support/Forms_Adapter_Conformance.php holds every
 * subclass to it:
 *
 *  - Registration follows the host plugin. A forms pair registers only while
 *    its plugin is loaded, so a site without Gravity Forms is not offered two
 *    tools that could only ever answer integration_unavailable. The dispatcher
 *    contract underneath is unchanged: a pair that IS registered still
 *    answers list-operations and refuses every other op in a structured way
 *    if the plugin goes away mid-request.
 *  - One paging vocabulary for entry listings: page_size (default 20, max
 *    100) plus offset.
 *  - Submissions are user data, so entry reads and writes carry a per-op
 *    capability above the pair's own edit_posts. ENTRY_CAPABILITY is the
 *    default; an adapter whose host maps entry access to a different core
 *    capability (Contact Form 7's Flamingo maps it to edit_users) uses that
 *    instead, and the conformance suite accepts either.
 *  - A status change on an entry that lives in a host plugin's own table is
 *    snapshotted as a db_rows before-image of exactly that row (see
 *    row_snapshot()), so it is restorable with rollback-operation like every
 *    other write. Restoring raw table rows is an administrator action in
 *    Rollback_Service, which is one more reason the entry ops sit at
 *    manage_options.
 */
abstract class Forms_Integration extends Integration_Dispatcher
{
    /** Default capability for submission (PII) operations. */
    public const ENTRY_CAPABILITY = 'manage_options';

    /** Default and ceiling for page_size on entry listings. */
    public const DEFAULT_PAGE_SIZE = 20;
    public const MAX_PAGE_SIZE     = 100;

    public function registers_only_when_available(): bool
    {
        return true;
    }

    /** The page_size + offset schema properties every entry listing shares. */
    protected static function paging_properties(): array
    {
        return [
            'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
            'offset'    => [ 'type' => 'integer', 'minimum' => 0 ],
        ];
    }

    /** @return array{0:int,1:int} [page_size, offset] from already-validated args. */
    protected static function page_window(array $args): array
    {
        $size = (int) ($args['page_size'] ?? self::DEFAULT_PAGE_SIZE);
        $size = max(1, min(self::MAX_PAGE_SIZE, $size));

        return [ $size, max(0, (int) ($args['offset'] ?? 0)) ];
    }

    /**
     * Snapshot target for an in-place update of ONE row in a host plugin's own
     * table: a db_rows before-image of that row, keyed by its primary key,
     * which Rollback_Service restores exactly (warning if the row changed
     * again in between).
     *
     * Returns null when there is nothing honest to snapshot: the table does
     * not exist on this site, or no row has that id. The dispatcher then runs
     * the op unsnapshotted and flags it recoverable:false, and every caller
     * of this helper also refuses a missing row itself, so the null case
     * never mutates anything in practice.
     *
     * @param string               $table_suffix Table name without $wpdb->prefix.
     * @param array<string, mixed> $set          Column => value the op will write.
     */
    protected static function row_snapshot(string $table_suffix, string $primary_key, int $id, array $set): ?array
    {
        global $wpdb;

        $table = Database_Guard::valid_table($wpdb->prefix . $table_suffix);
        if (is_wp_error($table) || $id < 1) {
            return null;
        }

        $where  = [ $primary_key => $id ];
        $before = Database_Guard::before_image($table, $where, 1);
        if ([] === $before) {
            return null;
        }

        return [
            'object_type'         => 'db_rows',
            'object_id'           => $table,
            'extra_snapshot_data' => [
                'table'       => $table,
                'operation'   => 'update',
                'primary_key' => [ $primary_key ],
                'where'       => $where,
                'set'         => $set,
                'rows'        => $before,
            ],
        ];
    }
}
