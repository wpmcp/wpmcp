<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Depicter (issue #898) on the plugin-data pair, free tier and read-only, so
 * it adds no top-level tools. Ops are prefixed depicter- because the pair is
 * shared.
 *
 * Depicter 4 keeps a slider, popup or bar as a row of its own
 * depicter_documents table (id, name, slug, type, status, sections_count,
 * dates, a builder JSON content and an optional password); a row whose
 * parent is not 0 is a saved revision of another, so only parent 0 rows are
 * documents. Display rules are a JSON `rules` row of depicter_meta
 * (relation document), and views, impressions, clicks and submissions are
 * rows of depicter_document_analytics (DocumentAnalyticsRepository).
 *
 * depicter-list-documents lists documents with their counts;
 * depicter-get-document reads one with its display rules. Neither returns
 * the builder content (only its size), the password, or any analytics row
 * (they hold visitor IPs and sessions; only counts leave), so nothing
 * needs masking.
 *
 * Writes are not part of this pack yet: Depicter publishes through
 * revisions of its builder JSON, so an undoable edit needs its own snapshot
 * kind first (follow-up to #898).
 *
 * Presence is filterable (wpmcp_depicter_active, default:
 * DEPICTER_PLUGIN_URL is defined).
 */
final class Depicter_Pack
{
    public const CAP = 'access_depicter';

    public const MAX_PAGE_SIZE = 100;

    public const PLUGIN_FILES = [ 'depicter/depicter.php' ];

    private const EVENTS = [ 'view', 'impression', 'click', 'submission' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_depicter_active', defined('DEPICTER_PLUGIN_URL'));
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        if (self::active()) {
            return true;
        }
        $installed = false;
        foreach (self::PLUGIN_FILES as $file) {
            $installed = $installed || (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $file));
        }
        $url = admin_url('plugins.php');

        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf(
                'Depicter is %s on this site. Install and activate Depicter (%s), then try again.',
                $installed ? 'installed but not active' : 'not installed',
                $url
            ),
            'data'    => [ 'plugin' => 'depicter', 'plugins_url' => $url ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $base = [
            'mode'       => 'read',
            'capability' => 'edit_posts',
            'requires'   => static fn () => self::presence(),
        ];

        return [
            'depicter-list-documents' => $base + [
                'description'  => 'List Depicter sliders, popups and bars: id, name, type, status, sections, dates, views, impressions, clicks, submissions, shortcode. Filter by type, status, search. page, per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'type'     => [ 'type' => 'string', 'maxLength' => 50 ],
                        'status'   => [ 'type' => 'string', 'enum' => [ 'publish', 'draft' ] ],
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                    ],
                ],
                'validate'     => static fn (): ?array => self::refusal(),
                'handler'      => static fn (array $args): array => self::list_documents($args),
            ],
            'depicter-get-document'   => $base + [
                'description'  => 'Read one Depicter document: settings, display rules and counts (no builder content)',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'document_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'document_id' ] ],
                'validate'     => static fn (array $args): ?array => self::refusal() ?? self::document_refusal((int) $args['document_id']),
                'handler'      => static fn (array $args): array => [ 'document' => self::view(self::document_row((int) $args['document_id']), true) ],
            ],
        ];
    }

    // ------------------------------------------------------------ refusals

    private static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'depicter_' . $name;
    }

    private static function refusal(): ?array
    {
        if (! current_user_can('manage_options') && ! current_user_can(self::CAP)) {
            return [ 'code' => 'operation_denied', 'message' => sprintf('This needs the %s capability (Depicter grants it to administrators).', self::CAP), 'data' => [ 'reason' => 'capability', 'capability' => self::CAP ] ];
        }
        if (! Plugin_Table_Rows_Snapshot::table_exists(self::table('documents'))) {
            return [ 'code' => 'tables_missing', 'message' => 'Depicter has not created its documents table yet. Open Depicter in wp-admin once, then try again.', 'data' => [] ];
        }
        return null;
    }

    private static function document_refusal(int $id): ?array
    {
        return null === self::document_row($id) ? [ 'code' => 'document_not_found', 'message' => sprintf('%d is not a Depicter document.', $id), 'data' => [ 'document_id' => $id ] ] : null;
    }

    // ------------------------------------------------------------ reads

    /** @return array<string, mixed>|null */
    private static function document_row(int $id): ?array
    {
        global $wpdb;
        // Never the builder content (only its size) or the password.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Depicter's own table.
        $row = $wpdb->get_row($wpdb->prepare('SELECT id, name, slug, type, status, sections_count, created_at, modified_at, CHAR_LENGTH(content) AS content_length FROM %i WHERE id = %d AND parent = 0', self::table('documents'), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * Event counts per document.
     *
     * @param int[] $ids
     * @return array<int, array<string, int>>
     */
    private static function counts(array $ids): array
    {
        global $wpdb;
        $table = self::table('document_analytics');
        if ([] === $ids || ! Plugin_Table_Rows_Snapshot::table_exists($table)) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT source_id, event_type, COUNT(*) AS total FROM %i WHERE source_id IN ($in) GROUP BY source_id, event_type", $table, ...$ids), ARRAY_A);
        $out  = [];
        foreach ((array) $rows as $row) {
            $out[ (int) $row['source_id'] ][ (string) $row['event_type'] ] = (int) $row['total'];
        }
        return $out;
    }

    /** @return array<string, mixed>|string|null */
    private static function rules(int $id)
    {
        global $wpdb;
        $table = self::table('meta');
        if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Depicter's own meta table (MetaRepository::get()).
        $raw = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM %i WHERE relation = 'document' AND relation_id = %d AND meta_key = 'rules' ORDER BY id DESC LIMIT 1", $table, $id));
        if (null === $raw || '' === $raw) {
            return null;
        }
        $json = json_decode((string) $raw, true);
        return is_array($json) ? $json : (string) $raw;
    }

    /** @return array<string, mixed> */
    private static function view(array $row, bool $full, ?array $counts = null): array
    {
        $id     = (int) $row['id'];
        $counts = $counts ?? (self::counts([ $id ])[ $id ] ?? []);
        $out    = [
            'id'             => $id,
            'name'           => (string) $row['name'],
            'slug'           => (string) $row['slug'],
            'type'           => (string) $row['type'],
            'status'         => (string) $row['status'],
            'sections_count' => (int) $row['sections_count'],
            'created'        => (string) $row['created_at'],
            'modified'       => (string) $row['modified_at'],
            'shortcode'      => sprintf('[depicter id="%d"]', $id),
        ];
        foreach (self::EVENTS as $event) {
            $out[ $event . 's' ] = (int) ($counts[ $event ] ?? 0);
        }
        if ($full) {
            $out['content_length'] = (int) $row['content_length'];
            $out['display_rules']  = self::rules($id);
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function list_documents(array $args): array
    {
        global $wpdb;
        $where  = [ 'parent = 0' ];
        $values = [ self::table('documents') ];
        if (isset($args['type']) && '' !== (string) $args['type']) {
            $where[]  = 'type = %s';
            $values[] = (string) $args['type'];
        }
        if (isset($args['status'])) {
            $where[]  = 'status = %s';
            $values[] = (string) $args['status'];
        }
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $where[]  = 'name LIKE %s';
            $values[] = '%' . $wpdb->esc_like(trim((string) $args['search'])) . '%';
        }
        $per_page = (int) ($args['per_page'] ?? 20);
        $offset   = ((int) ($args['page'] ?? 1) - 1) * $per_page;
        $sql      = implode(' AND ', $where);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sql joins fixed clauses with placeholders only.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE $sql", ...$values));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- as above; the values carry every placeholder.
        $rows   = (array) $wpdb->get_results($wpdb->prepare("SELECT id, name, slug, type, status, sections_count, created_at, modified_at, CHAR_LENGTH(content) AS content_length FROM %i WHERE $sql ORDER BY modified_at DESC, id DESC LIMIT %d OFFSET %d", ...array_merge($values, [ $per_page, $offset ])), ARRAY_A);
        $counts = self::counts(array_map(static fn (array $r): int => (int) $r['id'], $rows));

        return [
            'documents' => array_map(static fn (array $r): array => self::view($r, false, $counts[ (int) $r['id'] ] ?? []), $rows),
            'total'     => $total,
        ];
    }
}
