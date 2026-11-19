<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;
use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * wpDataTables and Ninja Tables (issue #706) on the plugin-data pair,
 * paid-tier, so they add no top-level tools. The ops carry the TablePress
 * op names (list-tables, get-table, set-cells, insert-rows, delete-rows)
 * under a wpdatatables- or ninjatables- prefix.
 *
 * wpDataTables (WDT_ROOT_PATH) keeps a row per table in `wpdatatables`
 * (title, table_type, content, mysql_table_name) and its column settings in
 * `wpdatatables_columns`. Only a simple table keeps its cells in WordPress:
 * one JSON row ({"cells":[{"data","hidden","type"}]}) per table row in
 * `wpdatatables_rows`, in id order, with the table's size in its content
 * (rowNumber, colNumber) and its merged cells there too. A manual table keeps
 * its rows in a MySQL table of its own (`<prefix>wpdatatable_<n>`), read
 * here; every other type (CSV, Excel, Google Sheets, SQL query, JSON, XML)
 * reads its data from that source when it renders, so only its columns are
 * read. Writes edit simple tables only, the way its own simple table editor
 * saves them: cells change in place, and inserting or deleting rows rewrites
 * the table's rows in order and its rowNumber. A row change that would
 * shift merged cells is refused.
 *
 * Ninja Tables (NINJA_TABLES_VERSION) keeps a table as a `ninja-table` post
 * with its columns in the _ninja_table_columns meta ({key, name, data_type})
 * and, for its default data source and default (FooTable) engine, each row
 * as a JSON object keyed by column key in `ninja_table_items`. Rows are
 * addressed by their row id, as Ninja Tables does. Tables fed by another
 * source (Google Sheets, CSV, Fluent Forms, SQL, posts) or kept by its
 * DataTables engine in a table of their own are listed and their columns
 * read, but their rows are not written here. After every write and every
 * undo the table's render cache is cleared through Ninja Tables' own
 * ninjaTablesClearTableDataCache().
 *
 * Snapshots are 'plugin_table_rows' images:
 *  - wpdatatables-set-cells: every row of the table (kind wpdatatables_rows,
 *    keyed by table_id), rollback-operation;
 *  - wpdatatables-insert-rows and -delete-rows: those rows and the table's
 *    own row (kind wpdatatables, which holds rowNumber), one session,
 *    rollback-session;
 *  - ninjatables-set-cells and -delete-rows: the rows named, keyed by row
 *    id; ninjatables-insert-rows: the rows it made, named once inserted, so
 *    the undo removes exactly them. rollback-operation.
 *
 * Every op takes manage_options, the capability both plugins gate their
 * table editors behind. Cell values from a user without unfiltered_html go
 * through wp_kses_post(), as in both plugins' own editors. Writes are off
 * until the site opts in (wpmcp_enable_data_tables_write). Presence is
 * filterable (wpmcp_wpdatatables_active, wpmcp_ninja_tables_active).
 */
final class Data_Tables_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads and writes the plugins' own tables live; neither has a cached read API for them.

    public const CAPABILITY = 'manage_options';

    public const OPT_IN_FILTER = 'wpmcp_enable_data_tables_write';

    public const MAX_PER_PAGE = 100;

    public const MAX_ROWS_PER_READ = 500;

    public const MAX_CELLS = 1000;

    /** A simple table larger than this is not rewritten (its snapshot holds every row). */
    public const MAX_TABLE_ROWS = 5000;

    public const NINJA_POST_TYPE = 'ninja-table';

    public static function wpdatatables_active(): bool
    {
        return Registrar::tier_permitted('pro') && (bool) apply_filters('wpmcp_wpdatatables_active', defined('WDT_ROOT_PATH'));
    }

    public static function ninja_tables_active(): bool
    {
        return Registrar::tier_permitted('pro') && (bool) apply_filters('wpmcp_ninja_tables_active', defined('NINJA_TABLES_VERSION'));
    }

    public static function active(): bool
    {
        return self::wpdatatables_active() || self::ninja_tables_active();
    }

    // ------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return self::wpdatatables_operations() + self::ninja_tables_operations();
    }

    /** Shared input schema parts. */
    private static function schema(): array
    {
        $index = [ 'type' => 'integer', 'minimum' => 0 ];
        return [
            'table'  => [ 'table_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
            'index'  => $index,
            'value'  => [ 'type' => [ 'string', 'number', 'integer', 'boolean', 'null' ] ],
            'list'   => [
                'type'       => 'object',
                'properties' => [
                    'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                    'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                    'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                ],
            ],
            'window' => [ 'offset' => $index, 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_ROWS_PER_READ ] ],
        ];
    }

    /** The parts every op of one plugin shares, and its writes add. */
    private static function bases(callable $presence): array
    {
        $base = [ 'tier' => 'pro', 'capability' => self::CAPABILITY, 'requires' => $presence ];
        return [
            $base,
            $base + [
                'mode'               => 'write',
                'enabled_by_default' => (bool) apply_filters('wpmcp_enable_data_tables_write', false),
                'opt_in_filter'      => self::OPT_IN_FILTER,
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function wpdatatables_operations(): array
    {
        $s               = self::schema();
        [ $base, $write ] = self::bases(static fn () => Ops_Status_Packs::presence(self::wpdatatables_active(), 'wpdatatables_inactive', 'wpDataTables'));
        $grid            = [ 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_CELLS, 'items' => [ 'type' => 'array', 'items' => $s['value'] ] ];
        $editable        = static fn (array $args): ?array => self::wdt_editable_refusal((int) $args['table_id']);

        return [
            'wpdatatables-list-tables' => $base + [
                'mode'         => 'read',
                'description'  => 'List wpDataTables tables: id, title, type, shortcode, rows and columns, and whether its cells are editable here (simple tables). search matches the title; page, per_page (max 100)',
                'input_schema' => $s['list'],
                'handler'      => static fn (array $args): array => self::wdt_list($args),
            ],
            'wpdatatables-get-table'   => $base + [
                'mode'         => 'read',
                'description'  => 'One wpDataTables table: columns and rows from offset (0-based; limit default 100, max 500). Simple tables give cell rows, manual tables their rows by column; other types read their source when shown, so only columns',
                'input_schema' => [ 'type' => 'object', 'properties' => $s['table'] + $s['window'], 'required' => [ 'table_id' ] ],
                'validate'     => static fn (array $args): ?array => self::wdt_table_refusal((int) $args['table_id']),
                'handler'      => static fn (array $args): array => self::wdt_get($args),
            ],
            'wpdatatables-set-cells'   => $write + [
                'description'  => 'Set cells of a wpDataTables simple table by 0-based row and column (max 1000; out of range refuses). Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $s['table'] + [
                        'cells' => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'maxItems' => self::MAX_CELLS,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => [ 'row' => $s['index'], 'column' => $s['index'], 'value' => $s['value'] ],
                                'required'   => [ 'row', 'column', 'value' ],
                            ],
                        ],
                    ],
                    'required'   => [ 'table_id', 'cells' ],
                ],
                'validate'     => static fn (array $args): ?array => $editable($args) ?? self::wdt_cells_refusal($args),
                'snapshot'     => static fn (array $args): array => [
                    'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                    'object_id'   => Plugin_Table_Rows_Snapshot::key('wpdatatables_rows', 'rows', [ (int) $args['table_id'] ]),
                ],
                'handler'      => static fn (array $args): array => self::wdt_set_cells($args),
            ],
            'wpdatatables-insert-rows' => $write + [
                'self_snapshotting' => true,
                'description'       => 'Insert rows into a wpDataTables simple table (each as long as the table is wide) or count empty rows, at position (0-based; default the end). Undo: rollback-session',
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => $s['table'] + [ 'rows' => $grid, 'count' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_CELLS ], 'position' => $s['index'] ],
                    'required'   => [ 'table_id' ],
                ],
                'validate'          => static fn (array $args): ?array => $editable($args) ?? self::wdt_insert_refusal($args),
                'handler'           => static fn (array $args, array $context): array => self::wdt_rewrite($args, $context, static fn (array $rows, array $content): array => self::wdt_insert($rows, $content, $args)),
            ],
            'wpdatatables-delete-rows' => $write + [
                'self_snapshotting' => true,
                'description'       => 'Delete rows of a wpDataTables simple table by 0-based index (at least one row must remain). Undo: rollback-session',
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => $s['table'] + [ 'rows' => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_CELLS, 'items' => $s['index'] ] ],
                    'required'   => [ 'table_id', 'rows' ],
                ],
                'validate'          => static fn (array $args): ?array => $editable($args) ?? self::wdt_delete_refusal($args),
                'handler'           => static fn (array $args, array $context): array => self::wdt_rewrite($args, $context, static function (array $rows) use ($args): array {
                    $drop = array_flip(array_map('intval', (array) $args['rows']));
                    return array_values(array_filter($rows, static fn ($i): bool => ! isset($drop[ $i ]), ARRAY_FILTER_USE_KEY));
                }),
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function ninja_tables_operations(): array
    {
        $s               = self::schema();
        [ $base, $write ] = self::bases(static fn () => Ops_Status_Packs::presence(self::ninja_tables_active(), 'ninjatables_inactive', 'Ninja Tables'));
        $row_id          = [ 'type' => 'integer', 'minimum' => 1 ];
        $editable        = static fn (array $args): ?array => self::ninja_editable_refusal((int) $args['table_id']);
        $target          = static fn (array $args, array $ids): array => [
            'object_type'         => Plugin_Table_Rows_Snapshot::TYPE,
            'object_id'           => Plugin_Table_Rows_Snapshot::key('ninja_table', 'items', $ids),
            'extra_snapshot_data' => [ 'table_ids' => [ (int) $args['table_id'] ] ],
        ];

        return [
            'ninjatables-list-tables' => $base + [
                'mode'         => 'read',
                'description'  => 'List Ninja Tables tables: id, title, status, shortcode, data source, engine, rows and columns, and whether its rows are editable here (default source and engine). search matches the title; page, per_page (max 100)',
                'input_schema' => $s['list'],
                'handler'      => static fn (array $args): array => self::ninja_list($args),
            ],
            'ninjatables-get-table'   => $base + [
                'mode'         => 'read',
                'description'  => 'One Ninja Tables table: columns (key, name, type) and rows as {row_id, values by column key} in its display order from offset (limit default 100, max 500). Tables fed by another source give columns only',
                'input_schema' => [ 'type' => 'object', 'properties' => $s['table'] + $s['window'], 'required' => [ 'table_id' ] ],
                'validate'     => static fn (array $args): ?array => self::ninja_table_refusal((int) $args['table_id']),
                'handler'      => static fn (array $args): array => self::ninja_get($args),
            ],
            'ninjatables-set-cells'   => $write + [
                'description'  => 'Set Ninja Tables cells by row_id and column key (max 1000). Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $s['table'] + [
                        'cells' => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'maxItems' => self::MAX_CELLS,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => [ 'row_id' => $row_id, 'column' => [ 'type' => 'string', 'minLength' => 1 ], 'value' => $s['value'] ],
                                'required'   => [ 'row_id', 'column', 'value' ],
                            ],
                        ],
                    ],
                    'required'   => [ 'table_id', 'cells' ],
                ],
                'validate'     => static fn (array $args): ?array => $editable($args) ?? self::ninja_rows_refusal((int) $args['table_id'], array_column((array) $args['cells'], 'row_id'))
                    ?? self::ninja_columns_refusal((int) $args['table_id'], array_column((array) $args['cells'], 'column')),
                'snapshot'     => static fn (array $args): array => $target($args, array_column((array) $args['cells'], 'row_id')),
                'handler'      => static fn (array $args): array => self::ninja_set_cells($args),
            ],
            'ninjatables-insert-rows' => $write + [
                'description'  => 'Append rows to a Ninja Tables table, each an object of values by column key (missing columns stay empty; max 1000). Undo: rollback-operation removes them',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $s['table'] + [
                        'rows' => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_CELLS, 'items' => [ 'type' => 'object', 'additionalProperties' => $s['value'] ] ],
                    ],
                    'required'   => [ 'table_id', 'rows' ],
                ],
                'validate'     => static function (array $args) use ($editable): ?array {
                    $keys = [];
                    foreach ((array) $args['rows'] as $row) {
                        $keys = array_merge($keys, array_map('strval', array_keys((array) $row)));
                    }
                    return $editable($args) ?? self::ninja_columns_refusal((int) $args['table_id'], $keys);
                },
                // The ids are not known until the rows exist; the handler names them.
                'snapshot'     => static fn (array $args): array => $target($args, []),
                'handler'      => static fn (array $args, array $context): array => self::ninja_insert($args, $context),
            ],
            'ninjatables-delete-rows' => $write + [
                'description'  => 'Delete Ninja Tables rows by row_id (max 1000). Undo: rollback-operation restores them at their ids',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $s['table'] + [ 'row_ids' => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_CELLS, 'items' => $row_id ] ],
                    'required'   => [ 'table_id', 'row_ids' ],
                ],
                'validate'     => static fn (array $args): ?array => $editable($args) ?? self::ninja_rows_refusal((int) $args['table_id'], (array) $args['row_ids']),
                'snapshot'     => static fn (array $args): array => $target($args, (array) $args['row_ids']),
                'handler'      => static fn (array $args): array => self::ninja_delete($args),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

    /** @return array{code: string, message: string, data: array} */
    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /** @return array{0: int, 1: int, 2: int} page, per_page, offset */
    private static function paging(array $args): array
    {
        $page     = max(1, (int) ($args['page'] ?? 1));
        $per_page = min(self::MAX_PER_PAGE, max(1, (int) ($args['per_page'] ?? 20)));
        return [ $page, $per_page, ($page - 1) * $per_page ];
    }

    /** @return array{0: int, 1: int} offset, limit */
    private static function window(array $args): array
    {
        return [ max(0, (int) ($args['offset'] ?? 0)), min(self::MAX_ROWS_PER_READ, max(1, (int) ($args['limit'] ?? 100))) ];
    }

    /** A cell value as both plugins store it: a string, filtered for a user without unfiltered_html. */
    private static function cell_value($value): string
    {
        if (is_bool($value)) {
            $value = $value ? '1' : '';
        }
        $value = null === $value ? '' : (string) $value;
        return current_user_can('unfiltered_html') ? $value : wp_kses_post($value);
    }

    // ------------------------------------------------------------------
    // wpDataTables
    // ------------------------------------------------------------------

    private static function wdt_tables_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wpdatatables';
    }

    private static function wdt_rows_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wpdatatables_rows';
    }

    /** @return array<string, mixed>|null The table's own row. */
    private static function wdt_table(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::wdt_tables_table(), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** A simple table's content (rowNumber, colNumber, colHeaders, mergedCells ...). */
    private static function wdt_content(array $table): array
    {
        $content = json_decode((string) ($table['content'] ?? ''), true);
        return is_array($content) ? $content : [];
    }

    /** @return array<int, array{id: int, data: array}> a simple table's rows in order. */
    private static function wdt_rows(int $id): array
    {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT id, data FROM %i WHERE table_id = %d ORDER BY id ASC', self::wdt_rows_table(), $id), ARRAY_A);
        $out  = [];
        foreach ($rows as $row) {
            $data  = json_decode((string) $row['data'], true);
            $out[] = [ 'id' => (int) $row['id'], 'data' => is_array($data) ? $data : [ 'cells' => [] ] ];
        }
        return $out;
    }

    /** @return string[] a stored row's cell values. */
    private static function wdt_row_values(array $data): array
    {
        return array_map(static fn ($cell): string => is_array($cell) ? (string) ($cell['data'] ?? '') : '', array_values((array) ($data['cells'] ?? [])));
    }

    private static function wdt_column_count(array $content, array $rows): int
    {
        if (isset($content['colNumber'])) {
            return (int) $content['colNumber'];
        }
        return [] === $rows ? 0 : count((array) ($rows[0]['data']['cells'] ?? []));
    }

    /** @return array<int, array{key: string, label: string, type: string}> a non-simple table's columns. */
    private static function wdt_columns(int $id): array
    {
        global $wpdb;
        $suppress = $wpdb->suppress_errors(true);
        $rows     = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE table_id = %d ORDER BY pos ASC, id ASC', $wpdb->prefix . 'wpdatatables_columns', $id), ARRAY_A);
        $wpdb->suppress_errors($suppress);
        return array_map(static fn (array $c): array => [
            'key'     => (string) $c['orig_header'],
            'label'   => (string) $c['display_header'],
            'type'    => (string) ($c['column_type'] ?? ''),
            'visible' => 1 === (int) ($c['visible'] ?? 1),
        ], $rows);
    }

    /** A manual table's MySQL table, or null unless it is one of wpDataTables' own on this site and exists. */
    private static function wdt_manual_table(array $table): ?string
    {
        global $wpdb;
        $name = (string) ($table['mysql_table_name'] ?? '');
        if (1 !== preg_match('/^' . preg_quote($wpdb->prefix . 'wpdatatable_', '/') . '\d+$/', $name)) {
            return null;
        }
        return Plugin_Table_Rows_Snapshot::table_exists($name) ? $name : null;
    }

    private static function wdt_summary(array $table): array
    {
        $id     = (int) $table['id'];
        $simple = 'simple' === (string) $table['table_type'];
        if ($simple) {
            $content = self::wdt_content($table);
            $rows    = (int) ($content['rowNumber'] ?? 0);
            $columns = (int) ($content['colNumber'] ?? 0);
        } else {
            $rows    = null;
            $columns = count(self::wdt_columns($id));
        }
        return [
            'table_id'   => $id,
            'title'      => (string) $table['title'],
            'table_type' => (string) $table['table_type'],
            'shortcode'  => '[wpdatatable id=' . $id . ']',
            'rows'       => $rows,
            'columns'    => $columns,
            'editable'   => $simple,
        ];
    }

    private static function wdt_list(array $args): array
    {
        global $wpdb;
        [ $page, $per_page, $offset ] = self::paging($args);
        $search = trim((string) ($args['search'] ?? ''));
        $like   = '%' . $wpdb->esc_like($search) . '%';

        if ('' === $search) {
            $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', self::wdt_tables_table()));
            $rows  = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', self::wdt_tables_table(), $per_page, $offset), ARRAY_A);
        } else {
            $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE title LIKE %s', self::wdt_tables_table(), $like));
            $rows  = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE title LIKE %s ORDER BY id DESC LIMIT %d OFFSET %d', self::wdt_tables_table(), $like, $per_page, $offset), ARRAY_A);
        }

        return [
            'tables'   => array_map([ self::class, 'wdt_summary' ], (array) $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    private static function wdt_get(array $args): array
    {
        global $wpdb;
        $id                 = (int) $args['table_id'];
        $table              = (array) self::wdt_table($id);
        [ $offset, $limit ] = self::window($args);
        $out                = self::wdt_summary($table) + [ 'offset' => $offset, 'limit' => $limit ];
        unset($out['rows']);

        if ('simple' === (string) $table['table_type']) {
            $content = self::wdt_content($table);
            $rows    = self::wdt_rows($id);
            return $out + [
                'column_headers' => array_values((array) ($content['colHeaders'] ?? [])),
                'merged_cells'   => array_values((array) ($content['mergedCells'] ?? [])),
                'row_count'      => count($rows),
                'column_count'   => self::wdt_column_count($content, $rows),
                'rows_readable'  => true,
                'rows'           => array_map(static fn (array $row): array => self::wdt_row_values($row['data']), array_slice($rows, $offset, $limit)),
            ];
        }

        $out['columns'] = self::wdt_columns($id);
        $manual         = 'manual' === (string) $table['table_type'] ? self::wdt_manual_table($table) : null;
        if (null === $manual) {
            return $out + [
                'rows_readable' => false,
                'row_count'     => null,
                'rows'          => [],
                'note'          => 'manual' === (string) $table['table_type']
                    ? 'This manual table does not name one of wpDataTables\' own tables on this site, so its rows are not read.'
                    : sprintf('This %s table reads its data from its source when it is shown; its rows are not stored in WordPress.', (string) $table['table_type']),
            ];
        }

        $order = in_array('wdt_ID', Plugin_Table_Rows_Snapshot::columns($manual), true);
        $rows  = $order
            ? $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY %i ASC LIMIT %d OFFSET %d', $manual, 'wdt_ID', $limit, $offset), ARRAY_A)
            : $wpdb->get_results($wpdb->prepare('SELECT * FROM %i LIMIT %d OFFSET %d', $manual, $limit, $offset), ARRAY_A);

        return $out + [
            'rows_readable' => true,
            'row_count'     => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $manual)),
            'rows'          => array_values((array) $rows),
        ];
    }

    private static function wdt_table_refusal(int $id): ?array
    {
        return null !== self::wdt_table($id) ? null : self::refusal('table_not_found', sprintf('There is no wpDataTables table %d.', $id), [ 'table_id' => $id ]);
    }

    private static function wdt_editable_refusal(int $id): ?array
    {
        $table = self::wdt_table($id);
        if (null === $table) {
            return self::wdt_table_refusal($id);
        }
        $type = (string) $table['table_type'];
        if ('simple' !== $type) {
            return self::refusal(
                'table_not_editable',
                sprintf('wpDataTables table %d has type "%s": its data comes from that source, not from cells stored in WordPress. Only simple tables are edited here; change the source, or edit the table in wpDataTables.', $id, $type),
                [ 'table_id' => $id, 'table_type' => $type ]
            );
        }
        if (count(self::wdt_rows($id)) > self::MAX_TABLE_ROWS) {
            return self::refusal('table_too_large', sprintf('wpDataTables table %d has more than %d rows; edit it in wpDataTables.', $id, self::MAX_TABLE_ROWS), [ 'table_id' => $id ]);
        }
        return null;
    }

    /** @return array{0: int, 1: int, 2: array} rows, columns, merged cells of a simple table. */
    private static function wdt_shape(int $id): array
    {
        $content = self::wdt_content((array) self::wdt_table($id));
        $rows    = self::wdt_rows($id);
        return [ count($rows), self::wdt_column_count($content, $rows), array_values((array) ($content['mergedCells'] ?? [])) ];
    }

    private static function wdt_cells_refusal(array $args): ?array
    {
        [ $rows, $columns ] = self::wdt_shape((int) $args['table_id']);
        foreach ((array) $args['cells'] as $cell) {
            if ((int) $cell['row'] >= $rows || (int) $cell['column'] >= $columns) {
                return self::refusal('cell_out_of_range', sprintf('Cell (%d, %d) is outside the table, which has %d rows and %d columns (0-based).', (int) $cell['row'], (int) $cell['column'], $rows, $columns), [ 'rows' => $rows, 'columns' => $columns ]);
            }
        }
        return null;
    }

    private static function wdt_insert_refusal(array $args): ?array
    {
        [ $rows, $columns, $merged ] = self::wdt_shape((int) $args['table_id']);
        if (isset($args['rows']) === isset($args['count'])) {
            return self::refusal('rows_or_count', 'Pass either rows (the cells of each new row) or count (empty rows), not both and not neither.');
        }
        foreach ((array) ($args['rows'] ?? []) as $i => $row) {
            if (count((array) $row) !== $columns) {
                return self::refusal('row_length_mismatch', sprintf('Row %d has %d cells; the table is %d columns wide.', (int) $i, count((array) $row), $columns), [ 'columns' => $columns ]);
            }
        }
        $position = (int) ($args['position'] ?? $rows);
        if ($position > $rows) {
            return self::refusal('cell_out_of_range', sprintf('Position %d is past the end of the table, which has %d rows.', $position, $rows), [ 'rows' => $rows ]);
        }
        if ([] !== $merged && $position < $rows) {
            return self::refusal('merged_cells', 'This table has merged cells, which rows inserted above its last row would shift. Append at the end instead, or unmerge them in wpDataTables first.');
        }
        return null;
    }

    private static function wdt_delete_refusal(array $args): ?array
    {
        [ $rows, , $merged ] = self::wdt_shape((int) $args['table_id']);
        $drop                = array_unique(array_map('intval', (array) $args['rows']));
        foreach ($drop as $index) {
            if ($index >= $rows) {
                return self::refusal('cell_out_of_range', sprintf('Row %d is outside the table, which has %d rows (0-based).', $index, $rows), [ 'rows' => $rows ]);
            }
        }
        if (count($drop) >= $rows) {
            return self::refusal('last_row', 'At least one row must remain in a wpDataTables table.');
        }
        if ([] !== $merged) {
            return self::refusal('merged_cells', 'This table has merged cells, which deleting rows would shift. Unmerge them in wpDataTables first.');
        }
        return null;
    }

    /** A new simple table row from its values. */
    private static function wdt_new_row(array $values, int $columns): array
    {
        $cells = [];
        for ($i = 0; $i < $columns; $i++) {
            $cells[] = [ 'data' => self::cell_value($values[ $i ] ?? ''), 'hidden' => false, 'type' => 'text' ];
        }
        return [ 'cells' => $cells ];
    }

    /** @return array<int, array> rows with the new ones spliced in. */
    private static function wdt_insert(array $rows, array $content, array $args): array
    {
        $columns  = self::wdt_column_count($content, array_map(static fn (array $data): array => [ 'data' => $data ], $rows));
        $values   = isset($args['rows']) ? array_values((array) $args['rows']) : array_fill(0, (int) $args['count'], []);
        $new      = array_map(static fn ($row): array => self::wdt_new_row(array_values((array) $row), $columns), $values);
        $position = (int) ($args['position'] ?? count($rows));
        array_splice($rows, $position, 0, $new);
        return $rows;
    }

    private static function wdt_set_cells(array $args): array
    {
        global $wpdb;
        $id      = (int) $args['table_id'];
        $rows    = self::wdt_rows($id);
        $changed = [];
        foreach ((array) $args['cells'] as $cell) {
            $r = (int) $cell['row'];
            $c = (int) $cell['column'];
            $existing                              = (array) ($rows[ $r ]['data']['cells'][ $c ] ?? [ 'hidden' => false, 'type' => 'text' ]);
            $rows[ $r ]['data']['cells'][ $c ]     = [ 'data' => self::cell_value($cell['value']) ] + $existing;
            $changed[ $r ]                         = true;
        }
        foreach (array_keys($changed) as $r) {
            ksort($rows[ $r ]['data']['cells']);
            $rows[ $r ]['data']['cells'] = array_values($rows[ $r ]['data']['cells']);
            if (false === $wpdb->update(self::wdt_rows_table(), [ 'data' => wp_json_encode($rows[ $r ]['data']) ], [ 'id' => $rows[ $r ]['id'] ], [ '%s' ], [ '%d' ])) {
                throw new Operation_Refused('write_failed', 'wpDataTables could not save the row: ' . $wpdb->last_error);
            }
        }
        do_action('wpdatatables_after_save_table', $id); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- wpDataTables' own action, fired as its own save does.
        return [ 'table_id' => $id, 'cells_set' => count((array) $args['cells']) ];
    }

    /**
     * Rewrite a simple table's rows in order and its rowNumber, the way its
     * simple table editor saves, under two snapshots in one session: the
     * table's own row, then every row of the table.
     */
    private static function wdt_rewrite(array $args, array $context, callable $change): array
    {
        global $wpdb;
        $id      = (int) $args['table_id'];
        $common  = [
            'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
            'session_id'  => (string) $context['session_id'],
            'tool_name'   => (string) $context['tool_name'],
            'args'        => [ 'operation' => (string) $context['operation'], 'args' => $args ],
        ];
        $rows_op = '';
        $meta    = Safe_Mutation::run(
            $common + [ 'object_id' => Plugin_Table_Rows_Snapshot::key('wpdatatables', 'tables', [ $id ]) ],
            static function () use ($id, $common, $change, &$rows_op, $wpdb): int {
                $content = self::wdt_content((array) self::wdt_table($id));
                $inner   = Safe_Mutation::run(
                    $common + [ 'object_id' => Plugin_Table_Rows_Snapshot::key('wpdatatables_rows', 'rows', [ $id ]) ],
                    static function () use ($id, $content, $change, $wpdb): int {
                        $rows = array_column(self::wdt_rows($id), 'data');
                        $rows = array_values($change($rows, $content));
                        if (false === $wpdb->delete(self::wdt_rows_table(), [ 'table_id' => $id ], [ '%d' ])) {
                            throw new Operation_Refused('write_failed', 'wpDataTables could not clear the table rows: ' . $wpdb->last_error);
                        }
                        foreach ($rows as $row) {
                            if (false === $wpdb->insert(self::wdt_rows_table(), [ 'table_id' => $id, 'data' => wp_json_encode($row) ], [ '%d', '%s' ])) {
                                throw new Operation_Refused('write_failed', 'wpDataTables could not save a row: ' . $wpdb->last_error);
                            }
                        }
                        return count($rows);
                    }
                );
                $rows_op              = (string) $inner['operation_id'];
                $content['rowNumber'] = (int) $inner['result'];
                if (false === $wpdb->update(self::wdt_tables_table(), [ 'content' => wp_json_encode($content) ], [ 'id' => $id ], [ '%s' ], [ '%d' ])) {
                    throw new Operation_Refused('write_failed', 'wpDataTables could not save the table size: ' . $wpdb->last_error);
                }
                do_action('wpdatatables_after_save_table', $id); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- wpDataTables' own action, fired as its own save does.
                return (int) $inner['result'];
            }
        );

        return [
            'result'        => [ 'table_id' => $id, 'row_count' => (int) $meta['result'] ],
            'operation_ids' => [ (string) $meta['operation_id'], $rows_op ],
        ];
    }

    // ------------------------------------------------------------------
    // Ninja Tables
    // ------------------------------------------------------------------

    private static function ninja_items_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ninja_table_items';
    }

    private static function ninja_provider(int $id): string
    {
        $provider = (string) get_post_meta($id, '_ninja_tables_data_provider', true);
        return '' === $provider ? 'default' : $provider;
    }

    private static function ninja_settings(int $id): array
    {
        $settings = get_post_meta($id, '_ninja_table_settings', true);
        return is_array($settings) ? $settings : [];
    }

    /** footable or datatables, the way Ninja Tables' Engine::of() decides. */
    private static function ninja_engine(int $id): string
    {
        return 'default' === self::ninja_provider($id) && 'datatables' === (self::ninja_settings($id)['library'] ?? '') ? 'datatables' : 'footable';
    }

    private static function ninja_editable(int $id): bool
    {
        return 'default' === self::ninja_provider($id) && 'footable' === self::ninja_engine($id);
    }

    /** @return array<int, array{key: string, name: string, data_type: string}> */
    private static function ninja_columns(int $id): array
    {
        $columns = get_post_meta($id, '_ninja_table_columns', true);
        $out     = [];
        foreach (is_array($columns) ? $columns : [] as $column) {
            if (is_array($column) && isset($column['key'])) {
                $out[] = [ 'key' => (string) $column['key'], 'name' => (string) ($column['name'] ?? $column['key']), 'data_type' => (string) ($column['data_type'] ?? 'text') ];
            }
        }
        return $out;
    }

    private static function ninja_count(int $id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE table_id = %d', self::ninja_items_table(), $id));
    }

    private static function ninja_summary(\WP_Post $post): array
    {
        $id       = (int) $post->ID;
        $editable = self::ninja_editable($id);
        return [
            'table_id'    => $id,
            'title'       => (string) $post->post_title,
            'status'      => (string) $post->post_status,
            'shortcode'   => '[ninja_tables id="' . $id . '"]',
            'data_source' => self::ninja_provider($id),
            'engine'      => self::ninja_engine($id),
            'rows'        => $editable ? self::ninja_count($id) : null,
            'columns'     => count(self::ninja_columns($id)),
            'editable'    => $editable,
        ];
    }

    private static function ninja_list(array $args): array
    {
        [ $page, $per_page ] = self::paging($args);
        $query               = new \WP_Query([
            'post_type'           => self::NINJA_POST_TYPE,
            'post_status'         => [ 'publish', 'draft', 'pending', 'private', 'future' ],
            's'                   => trim((string) ($args['search'] ?? '')),
            'posts_per_page'      => $per_page,
            'paged'               => $page,
            'orderby'             => 'ID',
            'order'               => 'DESC',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => false,
        ]);
        return [
            'tables'   => array_map([ self::class, 'ninja_summary' ], array_values(array_filter($query->posts, static fn ($p): bool => $p instanceof \WP_Post))),
            'total'    => (int) $query->found_posts,
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    private static function ninja_table_refusal(int $id): ?array
    {
        $post = get_post($id);
        if ($post instanceof \WP_Post && self::NINJA_POST_TYPE === $post->post_type && 'trash' !== $post->post_status) {
            return null;
        }
        return self::refusal('table_not_found', sprintf('There is no Ninja Tables table %d.', $id), [ 'table_id' => $id ]);
    }

    private static function ninja_editable_refusal(int $id): ?array
    {
        $missing = self::ninja_table_refusal($id);
        if (null !== $missing || self::ninja_editable($id)) {
            return $missing;
        }
        $provider = self::ninja_provider($id);
        return self::refusal(
            'table_not_editable',
            'default' !== $provider
                ? sprintf('Ninja Tables table %d takes its rows from its %s data source, so they are not edited here; change the source, or edit the table in Ninja Tables.', $id, $provider)
                : sprintf('Ninja Tables table %d uses the DataTables engine, which keeps its rows in a table of its own; edit it in Ninja Tables.', $id),
            [ 'table_id' => $id, 'data_source' => $provider, 'engine' => self::ninja_engine($id) ]
        );
    }

    /** @param array<int, mixed> $row_ids */
    private static function ninja_rows_refusal(int $id, array $row_ids): ?array
    {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $row_ids))));
        if ([] === $ids) {
            return self::refusal('row_not_found', 'Name at least one row_id.');
        }
        $in    = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
        $found   = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM %i WHERE table_id = %d AND id IN ($in)", self::ninja_items_table(), $id, ...$ids)));
        $missing = array_values(array_diff($ids, $found));
        if ([] !== $missing) {
            return self::refusal('row_not_found', sprintf('Ninja Tables table %d has no row %s.', $id, implode(', ', $missing)), [ 'table_id' => $id, 'missing' => $missing ]);
        }
        return null;
    }

    /** @param array<int, mixed> $keys */
    private static function ninja_columns_refusal(int $id, array $keys): ?array
    {
        $known   = array_column(self::ninja_columns($id), 'key');
        $unknown = array_values(array_unique(array_diff(array_map('strval', $keys), $known)));
        if ([] !== $unknown) {
            return self::refusal('unknown_column', sprintf('Not a column of Ninja Tables table %d: %s.', $id, implode(', ', $unknown)), [ 'unknown' => $unknown, 'columns' => $known ]);
        }
        return null;
    }

    /** @return array{0: string, 1: string} the ORDER BY of the table's display order. */
    private static function ninja_order(int $id): array
    {
        $settings = self::ninja_settings($id);
        if ('manual_sort' === ($settings['sorting_type'] ?? '')) {
            return [ 'position', 'ASC' ];
        }
        return [ 'created_at', 'new_first' === ($settings['default_sorting'] ?? '') ? 'DESC' : 'ASC' ];
    }

    private static function ninja_get(array $args): array
    {
        global $wpdb;
        $id                 = (int) $args['table_id'];
        $post               = get_post($id);
        [ $offset, $limit ] = self::window($args);
        $out                = self::ninja_summary($post) + [ 'offset' => $offset, 'limit' => $limit ];
        $out['columns']     = self::ninja_columns($id);
        unset($out['rows']);
        if (! $out['editable']) {
            return $out + [ 'rows_readable' => false, 'row_count' => null, 'rows' => [], 'note' => 'Rows of this table come from its data source or its DataTables engine table, not from Ninja Tables\' rows in WordPress.' ];
        }

        [ $field, $direction ] = self::ninja_order($id);
        $sql                   = 'SELECT id, value FROM %i WHERE table_id = %d ORDER BY %i ' . ('DESC' === $direction ? 'DESC, id DESC' : 'ASC, id ASC') . ' LIMIT %d OFFSET %d';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql holds only placeholders and a fixed sort direction.
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, self::ninja_items_table(), $id, $field, $limit, $offset), ARRAY_A);

        return $out + [
            'rows_readable' => true,
            'row_count'     => self::ninja_count($id),
            'rows'          => array_map(static function (array $row): array {
                $values = json_decode((string) $row['value'], true);
                return [ 'row_id' => (int) $row['id'], 'values' => is_array($values) ? $values : [] ];
            }, $rows),
        ];
    }

    private static function ninja_clear_cache(int $id): void
    {
        Plugin_Table_Rows_Snapshot::clear_ninja_tables_cache([ $id ]);
    }

    private static function ninja_set_cells(array $args): array
    {
        global $wpdb;
        $id      = (int) $args['table_id'];
        $by_row  = [];
        foreach ((array) $args['cells'] as $cell) {
            $by_row[ (int) $cell['row_id'] ][ (string) $cell['column'] ] = self::cell_value($cell['value']);
        }
        foreach ($by_row as $row_id => $values) {
            $current = json_decode((string) $wpdb->get_var($wpdb->prepare('SELECT value FROM %i WHERE id = %d AND table_id = %d', self::ninja_items_table(), $row_id, $id)), true);
            $merged  = array_merge(is_array($current) ? $current : [], $values);
            $written = $wpdb->update(
                self::ninja_items_table(),
                [ 'value' => wp_json_encode($merged, JSON_UNESCAPED_UNICODE), 'updated_at' => gmdate('Y-m-d H:i:s') ],
                [ 'id' => $row_id, 'table_id' => $id ],
                [ '%s', '%s' ],
                [ '%d', '%d' ]
            );
            if (false === $written) {
                throw new Operation_Refused('write_failed', 'Ninja Tables could not save row ' . $row_id . ': ' . $wpdb->last_error);
            }
        }
        self::ninja_clear_cache($id);
        return [ 'table_id' => $id, 'cells_set' => count((array) $args['cells']), 'rows_changed' => array_keys($by_row) ];
    }

    private static function ninja_insert(array $args, array $context): array
    {
        global $wpdb;
        $id       = (int) $args['table_id'];
        $columns  = array_column(self::ninja_columns($id), 'key');
        $rows     = array_values((array) $args['rows']);
        $manual   = 'position' === self::ninja_order($id)[0];
        $position = $manual ? (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(position) FROM %i WHERE table_id = %d', self::ninja_items_table(), $id)) : 0;
        // Ninja Tables' own bulk insert stamps rows a second apart, ending now.
        $stamp = time() - count($rows);
        $ids   = [];
        foreach ($rows as $i => $row) {
            $values = [];
            foreach ($columns as $key) {
                $values[ $key ] = self::cell_value(((array) $row)[ $key ] ?? '');
            }
            $item = [
                'table_id'   => $id,
                'attribute'  => 'value',
                'owner_id'   => get_current_user_id(),
                'value'      => wp_json_encode($values, JSON_UNESCAPED_UNICODE),
                'created_at' => gmdate('Y-m-d H:i:s', $stamp + $i + 1),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ];
            if ($manual) {
                $item['position'] = $position + $i + 1;
            }
            if (false === $wpdb->insert(self::ninja_items_table(), $item)) {
                self::ninja_name_inserted($context, $id, $ids);
                throw new Operation_Refused('write_failed', 'Ninja Tables could not save a row: ' . $wpdb->last_error);
            }
            $ids[] = (int) $wpdb->insert_id;
        }
        self::ninja_name_inserted($context, $id, $ids);
        self::ninja_clear_cache($id);
        return [ 'table_id' => $id, 'row_ids' => $ids ];
    }

    /** Name the rows an insert made in its undo point, so the undo removes exactly them. */
    private static function ninja_name_inserted(array $context, int $table_id, array $ids): void
    {
        $operation_id = (string) ($context['operation_id'] ?? '');
        $row          = '' === $operation_id ? null : Snapshot_Store::get_by_operation($operation_id);
        if (null === $row || ! is_array($row['snapshot'] ?? null) || [] === $ids) {
            return;
        }
        $snapshot                      = $row['snapshot'];
        $snapshot['object_id']         = Plugin_Table_Rows_Snapshot::key('ninja_table', 'items', $ids);
        $snapshot['data']['ids']       = $ids;
        $snapshot['data']['rows']      = [];
        $snapshot['data']['table_ids'] = [ $table_id ];
        Snapshot_Store::update_snapshot($operation_id, $snapshot);
    }

    private static function ninja_delete(array $args): array
    {
        global $wpdb;
        $id  = (int) $args['table_id'];
        $ids = array_values(array_unique(array_map('intval', (array) $args['row_ids'])));
        do_action('ninja_table_before_items_deleted', $ids, $id); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Ninja Tables' own action, fired as its own delete does.
        $in = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
        if (false === $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE table_id = %d AND id IN ($in)", self::ninja_items_table(), $id, ...$ids))) {
            throw new Operation_Refused('write_failed', 'Ninja Tables could not delete the rows: ' . $wpdb->last_error);
        }
        do_action('ninja_table_after_items_deleted', $ids, $id); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Ninja Tables' own action, fired as its own delete does.
        self::ninja_clear_cache($id);
        return [ 'table_id' => $id, 'deleted_row_ids' => $ids, 'row_count' => self::ninja_count($id) ];
    }
    // phpcs:enable
}
