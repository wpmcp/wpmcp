<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;
use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Save_Filters;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * TablePress tables (issue #481) on the plugin-data pair, paid-tier, so they
 * add no top-level tools where the pair already registers.
 *
 * A TablePress table is a `tablepress_table` post whose content is the cell
 * data as JSON, two post meta fields holding its options and its row and
 * column visibility as JSON, and an entry in the `tablepress_tables` option
 * that maps the table id (what the shortcode and block name) to that post.
 * Editing any of these by hand easily corrupts a table or desyncs it, so
 * every read and write here goes through TablePress's own table model
 * (TablePress::$model_table: load, prepare_table, save, add, copy,
 * change_table_id, delete), its exporter, its importer and its render
 * function. Its save and delete clear the table's render cache; a rollback
 * clears it too (after_post_restored, after_options_restored).
 *
 * Cell, row and column edits rebuild the whole data array and pass it
 * through prepare_table() with TablePress's size check, after checking the
 * table is rectangular, so a write that would leave rows of unequal length
 * is refused before anything is stored. Visibility grows and shrinks with the
 * rows and columns, and the header and footer row counts are kept within the
 * row count.
 *
 * Snapshots:
 *  - an edit of one table (name, description, options, cells, rows, columns,
 *    an import that replaces or appends) is a post snapshot of the table's
 *    post, which holds its data, options and visibility: rollback-operation;
 *  - change-table-id changes only the id map: an option snapshot of
 *    `tablepress_tables`, rollback-operation;
 *  - create, copy and an import that adds a table snapshot the id map and
 *    record the new post (its undo trashes it); delete snapshots the id map
 *    and the table's post (its undo puts both back at the same ids). One
 *    call is one session: rollback-session.
 *  - place-table is a post snapshot of the page it edits.
 *
 * Capabilities are TablePress's own, on top of the pair's: listing, reading,
 * rendering and finding a table take tablepress_list_tables, exporting takes
 * tablepress_export_table, and each write takes the table capability
 * TablePress maps for it (tablepress_edit_table, tablepress_copy_table,
 * tablepress_delete_table, tablepress_edit_table_id, tablepress_add_tables,
 * tablepress_import_tables), checked per table so TablePress's
 * tablepress_map_meta_caps filter applies. DataTables custom commands are
 * JavaScript, so only a user with unfiltered_html may change them, as in
 * TablePress's own editor. TablePress registers its post type without core's
 * meta capability mapping, so map_post_caps() maps edit_post, delete_post and
 * read_post for a table post onto those same capabilities, which is what a
 * rollback of a table snapshot asks for.
 */
final class TablePress_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    /** The option TablePress keeps the table id => post id map in. */
    public const TABLES_OPTION = 'tablepress_tables';

    public const MAX_PER_PAGE = 100;

    public const MAX_ROWS_PER_READ = 500;

    public const MAX_CELLS = 1000;

    public const MAX_USAGE = 100;

    /** Whether TablePress is loaded (filterable) and this install may run the paid tier. */
    public static function active(): bool
    {
        return Registrar::tier_permitted('pro')
            && (bool) apply_filters('wpmcp_tablepress_active', defined('TABLEPRESS_ABSPATH') && class_exists('TablePress', false) && isset(\TablePress::$model_table));
    }

    // ------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $table  = [ 'table_id' => [ 'type' => [ 'string', 'integer' ] ] ];
        $index  = [ 'type' => 'integer', 'minimum' => 0 ];
        $row    = [ 'type' => 'array', 'items' => [ 'type' => [ 'string', 'number', 'integer', 'boolean', 'null' ] ] ];
        $grid   = [ 'type' => 'array', 'minItems' => 1, 'items' => $row ];
        $base   = [ 'tier' => 'pro', 'requires' => static fn () => Ops_Status_Packs::presence(self::active(), 'tablepress_inactive', 'TablePress') ];
        $edit   = static fn (array $args): ?array => self::table_refusal($args['table_id'] ?? '', 'tablepress_edit_table', true);
        $post   = static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => self::post_id_of(self::table_id($args)) ];
        $option = static fn (array $args): array => [ 'object_type' => 'option', 'object_id' => self::TABLES_OPTION ];

        return [
            'tablepress-list-tables'    => $base + [
                'mode'         => 'read',
                'capability'   => 'tablepress_list_tables',
                'description'  => 'List TablePress tables: id, name, description, rows, columns, last modified. search matches id or name; page, per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search'   => [ 'type' => 'string' ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                    ],
                ],
                'handler'      => static fn (array $args): array => self::list_tables($args),
            ],
            'tablepress-get-table'      => $base + [
                'mode'         => 'read',
                'capability'   => 'tablepress_list_tables',
                'description'  => 'One table: name, description, options, visibility, size and its rows from offset (0-based; limit default 100, max 500)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $table + [
                        'offset' => $index,
                        'limit'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_ROWS_PER_READ ],
                    ],
                    'required'   => [ 'table_id' ],
                ],
                'validate'     => static fn (array $args): ?array => self::table_refusal($args['table_id'], null),
                'handler'      => static fn (array $args): array => self::get_table($args),
            ],
            'tablepress-export-table'   => $base + [
                'mode'         => 'read',
                'capability'   => 'tablepress_export_tables',
                'description'  => 'Export a table as csv (csv_delimiter , ; or tab), json or html, through TablePress\'s exporter',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $table + [
                        'format'        => [ 'type' => 'string', 'enum' => [ 'csv', 'json', 'html' ] ],
                        'csv_delimiter' => [ 'type' => 'string', 'enum' => [ ',', ';', 'tab' ] ],
                    ],
                    'required'   => [ 'table_id', 'format' ],
                ],
                'validate'     => static fn (array $args): ?array => self::table_refusal($args['table_id'], 'tablepress_export_table'),
                'handler'      => static fn (array $args): array => self::export_table($args),
            ],
            'tablepress-render-table'   => $base + [
                'mode'         => 'read',
                'capability'   => 'tablepress_list_tables',
                'description'  => 'The HTML TablePress renders for a table (its shortcode output)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $table,
                    'required'   => [ 'table_id' ],
                ],
                'validate'     => static fn (array $args): ?array => self::table_refusal($args['table_id'], null),
                'handler'      => static fn (array $args): array => [ 'table_id' => self::table_id($args), 'html' => self::render(self::table_id($args)) ],
            ],
            'tablepress-find-usage'     => $base + [
                'mode'         => 'read',
                'capability'   => 'tablepress_list_tables',
                'description'  => 'Posts and pages whose content places a table as a TablePress block or [table] shortcode (max 100, the ones you may read)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $table,
                    'required'   => [ 'table_id' ],
                ],
                'handler'      => static fn (array $args): array => [ 'table_id' => self::table_id($args), 'posts' => self::usage(self::table_id($args)) ],
            ],
            'tablepress-create-table'   => $base + [
                'mode'              => 'write',
                'capability'        => 'tablepress_add_tables',
                'self_snapshotting' => true,
                'description'       => 'Create a table from data (rows of cells, equal length) or an empty rows x columns grid, with optional options. Undo: rollback-session',
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'name'        => [ 'type' => 'string', 'minLength' => 1 ],
                        'description' => [ 'type' => 'string' ],
                        'data'        => $grid,
                        'rows'        => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_CELLS ],
                        'columns'     => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_CELLS ],
                        'options'     => [ 'type' => 'object' ],
                    ],
                    'required'   => [ 'name' ],
                ],
                'validate'          => static fn (array $args): ?array => self::create_refusal($args),
                'handler'           => static fn (array $args, array $context): array => self::create_table($args, $context),
            ],
            'tablepress-update-table'   => $base + [
                'mode'         => 'write',
                'capability'   => 'tablepress_edit_tables',
                'description'  => 'Set a table\'s name, description and/or options (TablePress option keys; DataTables custom commands need unfiltered_html). Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $table + [
                        'name'        => [ 'type' => 'string' ],
                        'description' => [ 'type' => 'string' ],
                        'options'     => [ 'type' => 'object' ],
                    ],
                    'required'   => [ 'table_id' ],
                ],
                'validate'     => static fn (array $args): ?array => $edit($args) ?? self::options_refusal((array) ($args['options'] ?? [])),
                'snapshot'     => $post,
                'handler'      => static fn (array $args): array => self::edit($args, static function (array $t) use ($args): array {
                    foreach ([ 'name', 'description' ] as $field) {
                        if (array_key_exists($field, $args)) {
                            $t[ $field ] = (string) $args[ $field ];
                        }
                    }
                    $t['options'] = array_merge($t['options'], (array) ($args['options'] ?? []));
                    return $t;
                }),
            ],
            'tablepress-set-cells'      => $base + [
                'mode'         => 'write',
                'capability'   => 'tablepress_edit_tables',
                'description'  => 'Set cells by 0-based row and column (max 1000 per call; out of range refuses). Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $table + [
                        'cells' => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'maxItems' => self::MAX_CELLS,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => [ 'row' => $index, 'column' => $index, 'value' => [ 'type' => [ 'string', 'number', 'integer', 'boolean', 'null' ] ] ],
                                'required'   => [ 'row', 'column', 'value' ],
                            ],
                        ],
                    ],
                    'required'   => [ 'table_id', 'cells' ],
                ],
                'validate'     => static fn (array $args): ?array => $edit($args) ?? self::cells_refusal($args),
                'snapshot'     => $post,
                'handler'      => static fn (array $args): array => self::edit($args, static function (array $t) use ($args): array {
                    foreach ((array) $args['cells'] as $cell) {
                        $t['data'][ (int) $cell['row'] ][ (int) $cell['column'] ] = self::cell($cell['value']);
                    }
                    return $t;
                }),
            ],
            'tablepress-insert-rows'    => $base + [
                'mode'         => 'write',
                'capability'   => 'tablepress_edit_tables',
                'description'  => 'Insert rows (each as long as the table is wide) or count empty rows at position (0-based; default the end). Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $table + [ 'rows' => $grid, 'count' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_CELLS ], 'position' => $index ],
                    'required'   => [ 'table_id' ],
                ],
                'validate'     => static fn (array $args): ?array => $edit($args) ?? self::insert_refusal($args, 'rows'),
                'snapshot'     => $post,
                'handler'      => static fn (array $args): array => self::edit($args, static fn (array $t): array => self::insert_rows($t, $args)),
            ],
            'tablepress-delete-rows'    => $base + [
                'mode'         => 'write',
                'capability'   => 'tablepress_edit_tables',
                'description'  => 'Delete rows by 0-based index (at least one row must remain). Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $table + [ 'rows' => [ 'type' => 'array', 'minItems' => 1, 'items' => $index ] ],
                    'required'   => [ 'table_id', 'rows' ],
                ],
                'validate'     => static fn (array $args): ?array => $edit($args) ?? self::delete_refusal($args, 'rows'),
                'snapshot'     => $post,
                'handler'      => static fn (array $args): array => self::edit($args, static fn (array $t): array => self::delete_rows($t, (array) $args['rows'])),
            ],
            'tablepress-insert-columns' => $base + [
                'mode'         => 'write',
                'capability'   => 'tablepress_edit_tables',
                'description'  => 'Insert columns (each a list of cells top to bottom, as long as the table is tall) or count empty columns at position (0-based; default the end). Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $table + [ 'columns' => $grid, 'count' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_CELLS ], 'position' => $index ],
                    'required'   => [ 'table_id' ],
                ],
                'validate'     => static fn (array $args): ?array => $edit($args) ?? self::insert_refusal($args, 'columns'),
                'snapshot'     => $post,
                'handler'      => static fn (array $args): array => self::edit($args, static fn (array $t): array => self::insert_columns($t, $args)),
            ],
            'tablepress-delete-columns' => $base + [
                'mode'         => 'write',
                'capability'   => 'tablepress_edit_tables',
                'description'  => 'Delete columns by 0-based index (at least one column must remain). Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $table + [ 'columns' => [ 'type' => 'array', 'minItems' => 1, 'items' => $index ] ],
                    'required'   => [ 'table_id', 'columns' ],
                ],
                'validate'     => static fn (array $args): ?array => $edit($args) ?? self::delete_refusal($args, 'columns'),
                'snapshot'     => $post,
                'handler'      => static fn (array $args): array => self::edit($args, static fn (array $t): array => self::delete_columns($t, (array) $args['columns'])),
            ],
            'tablepress-copy-table'     => $base + [
                'mode'              => 'write',
                'capability'        => 'tablepress_copy_tables',
                'self_snapshotting' => true,
                'description'       => 'Copy a table to a new id ("Copy of" its name). Undo: rollback-session',
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => $table,
                    'required'   => [ 'table_id' ],
                ],
                'validate'          => static fn (array $args): ?array => self::table_refusal($args['table_id'], 'tablepress_copy_table'),
                'handler'           => static fn (array $args, array $context): array => self::copy_table($args, $context),
            ],
            'tablepress-change-table-id' => $base + [
                'mode'         => 'write',
                'capability'   => 'tablepress_edit_tables',
                'description'  => 'Give a table a new id (letters, digits, - and _; unused). Placements naming the old id are not rewritten: the response lists them. Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $table + [ 'new_id' => [ 'type' => [ 'string', 'integer' ] ] ],
                    'required'   => [ 'table_id', 'new_id' ],
                ],
                'validate'     => static fn (array $args): ?array => self::table_refusal($args['table_id'], 'tablepress_edit_table_id') ?? self::new_id_refusal((string) $args['new_id']),
                'snapshot'     => $option,
                'handler'      => static fn (array $args): array => self::change_table_id($args),
            ],
            'tablepress-delete-table'   => $base + [
                'mode'              => 'destructive',
                'capability'        => 'tablepress_delete_tables',
                'self_snapshotting' => true,
                'description'       => 'Delete a table (confirm:true). Placements of it then render nothing. Undo: rollback-session restores it at the same id',
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => $table,
                    'required'   => [ 'table_id' ],
                ],
                'validate'          => static fn (array $args): ?array => self::table_refusal($args['table_id'], 'tablepress_delete_table'),
                'handler'           => static fn (array $args, array $context): array => self::delete_table($args, $context),
            ],
            'tablepress-import-table'   => $base + [
                'mode'              => 'write',
                'capability'        => 'tablepress_import_tables',
                'self_snapshotting' => true,
                'description'       => 'Import CSV, JSON or HTML data (format detected by TablePress\'s importer) as a new table (mode add, optional name), or replace or append to table_id. Undo: rollback-session',
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => $table + [
                        'data' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 2097152 ],
                        'mode' => [ 'type' => 'string', 'enum' => [ 'add', 'replace', 'append' ] ],
                        'name' => [ 'type' => 'string', 'minLength' => 1 ],
                    ],
                    'required'   => [ 'data' ],
                ],
                'validate'          => static fn (array $args): ?array => self::import_refusal($args),
                'handler'           => static fn (array $args, array $context): array => self::import_table($args, $context),
            ],
            'tablepress-place-table'    => $base + [
                'mode'         => 'write',
                'capability'   => 'tablepress_list_tables',
                'objects'      => [ 'post_id' => 'post' ],
                'description'  => 'Place a table in a post\'s content as a TablePress block (default) or [table] shortcode, at the end (default) or start. Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $table + [
                        'post_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
                        'as'       => [ 'type' => 'string', 'enum' => [ 'block', 'shortcode' ] ],
                        'position' => [ 'type' => 'string', 'enum' => [ 'end', 'start' ] ],
                    ],
                    'required'   => [ 'table_id', 'post_id' ],
                ],
                'validate'     => static fn (array $args): ?array => self::table_refusal($args['table_id'], null) ?? self::place_refusal((int) $args['post_id']),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['post_id'] ],
                'handler'      => static fn (array $args): array => self::place_table($args),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // TablePress access
    // ------------------------------------------------------------------

    /** TablePress's table model. */
    private static function model(): object
    {
        return \TablePress::$model_table;
    }

    /** The table id an op names, as TablePress keys it. */
    private static function table_id(array $args): string
    {
        return trim((string) ($args['table_id'] ?? ''));
    }

    /** @return array<string, int> table id => post id, in TablePress's order. */
    private static function id_map(): array
    {
        $map = (array) (self::model()->_debug_get_tables()['table_post'] ?? []);
        $out = [];
        foreach ($map as $id => $post_id) {
            $out[ (string) $id ] = (int) $post_id;
        }
        return $out;
    }

    /** The post holding a table, or 0. */
    public static function post_id_of(string $table_id): int
    {
        return self::id_map()[ $table_id ] ?? 0;
    }

    /** The id of the table a post holds, or null. */
    public static function table_id_of_post(int $post_id): ?string
    {
        $found = array_search($post_id, self::id_map(), true);
        return false === $found ? null : (string) $found;
    }

    /**
     * A table as TablePress loads it.
     *
     * @throws Operation_Refused When it cannot be loaded.
     */
    private static function load(string $table_id, bool $data = true): array
    {
        $table = self::model()->load($table_id, $data, true);
        if (is_wp_error($table) || ! is_array($table)) {
            throw new Operation_Refused('table_not_found', sprintf('TablePress table "%s" could not be loaded.', $table_id));
        }
        return $table;
    }

    /** The HTML TablePress renders for a table. */
    private static function render(string $table_id): string
    {
        return function_exists('tablepress_get_table') ? (string) tablepress_get_table([ 'id' => $table_id ]) : '';
    }

    /** A cell as TablePress stores it: always a string. */
    private static function cell($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        return null === $value ? '' : (string) $value;
    }

    /** @return string[][] */
    private static function cells(array $rows): array
    {
        return array_map(static fn ($row): array => array_map([ self::class, 'cell' ], array_values((array) $row)), array_values($rows));
    }

    /** The table summary a list or a write returns. */
    private static function summary(array $table): array
    {
        $data = (array) ($table['data'] ?? []);
        return [
            'id'            => (string) $table['id'],
            'name'          => (string) $table['name'],
            'description'   => (string) $table['description'],
            'rows'          => count($data),
            'columns'       => count((array) ($data[0] ?? [])),
            'last_modified' => (string) ($table['last_modified'] ?? ''),
            'author'        => (int) ($table['author'] ?? 0),
        ];
    }

    // ------------------------------------------------------------------
    // Checks
    // ------------------------------------------------------------------

    /** @return array{code: string, message: string, data: array} */
    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /**
     * Refusal for a table that does not exist, that the caller may not act
     * on with $capability (checked for this table, so TablePress's own
     * mapping applies), or, when $writable, whose data TablePress reports as
     * corrupted. Null when the table may be used.
     */
    private static function table_refusal($table_id, ?string $capability, bool $writable = false): ?array
    {
        $table_id = trim((string) $table_id);
        if ('' === $table_id || ! self::model()->table_exists($table_id)) {
            return self::refusal('table_not_found', sprintf('There is no TablePress table "%s".', $table_id), [ 'table_id' => $table_id ]);
        }
        if (null !== $capability && ! current_user_can($capability, $table_id)) {
            return self::refusal('operation_denied', sprintf('You are not allowed to do this to TablePress table "%s" (%s).', $table_id, $capability), [ 'reason' => 'capability' ]);
        }
        if ($writable) {
            $table = self::model()->load($table_id, true, true);
            if (is_wp_error($table) || ! is_array($table)) {
                return self::refusal('table_not_found', sprintf('TablePress table "%s" could not be loaded.', $table_id), [ 'table_id' => $table_id ]);
            }
            if (! empty($table['is_corrupted'])) {
                return self::refusal('table_corrupted', sprintf('TablePress reports the data of table "%s" as corrupted; repair it in TablePress first.', $table_id), [ 'table_id' => $table_id ]);
            }
        }
        return null;
    }

    /** Refusal for options TablePress does not have, or custom commands from a user without unfiltered_html. */
    private static function options_refusal(array $options): ?array
    {
        $known   = array_diff(array_keys((array) self::model()->get_table_template()['options']), [ 'last_editor' ]);
        $unknown = array_values(array_diff(array_map('strval', array_keys($options)), $known));
        if ([] !== $unknown) {
            return self::refusal('unknown_option', sprintf('Not a TablePress table option: %s.', implode(', ', $unknown)), [ 'unknown' => $unknown, 'options' => array_values($known) ]);
        }
        foreach ($options as $key => $value) {
            if (is_array($value) || is_object($value)) {
                return self::refusal('invalid_option', sprintf('TablePress option "%s" takes a single value.', $key), [ 'option' => $key ]);
            }
        }
        if (array_key_exists('datatables_custom_commands', $options) && ! current_user_can('unfiltered_html')) {
            return self::refusal('operation_denied', 'DataTables custom commands are JavaScript; changing them needs the unfiltered_html capability.', [ 'reason' => 'capability' ]);
        }
        return null;
    }

    /** Refusal for a list of rows that is not rectangular, or not $width wide when given. */
    private static function shape_refusal(array $rows, ?int $width, string $what): ?array
    {
        $width   = $width ?? count((array) ($rows[0] ?? []));
        $lengths = array_map(static fn ($row): int => count((array) $row), array_values($rows));
        foreach ($lengths as $i => $length) {
            if ($length !== $width) {
                return self::refusal('table_not_rectangular', sprintf('Every one of the %s must have %d cells; number %d has %d. A table keeps rows of equal length.', $what, $width, $i, $length), [ 'expected' => $width, 'lengths' => $lengths ]);
            }
        }
        return null;
    }

    private static function create_refusal(array $args): ?array
    {
        if (isset($args['data'])) {
            if (isset($args['rows']) || isset($args['columns'])) {
                return self::refusal('invalid_args', 'Give data, or rows and columns for an empty table, not both.');
            }
            $refusal = self::shape_refusal((array) $args['data'], null, 'rows');
            if (null !== $refusal) {
                return $refusal;
            }
            if (0 === count((array) $args['data'][0])) {
                return self::refusal('invalid_args', 'A table needs at least one column.');
            }
        }
        $size = isset($args['data']) ? count((array) $args['data']) * count((array) $args['data'][0]) : (int) ($args['rows'] ?? 1) * (int) ($args['columns'] ?? 1);
        if ($size > self::MAX_CELLS * 10) {
            return self::refusal('table_too_large', sprintf('Create at most %d cells in one call; import larger data instead.', self::MAX_CELLS * 10));
        }
        return self::options_refusal((array) ($args['options'] ?? []));
    }

    private static function cells_refusal(array $args): ?array
    {
        $table   = self::load(self::table_id($args));
        $rows    = count($table['data']);
        $columns = count($table['data'][0]);
        foreach ((array) $args['cells'] as $i => $cell) {
            if ((int) $cell['row'] >= $rows || (int) $cell['column'] >= $columns) {
                return self::refusal('cell_out_of_range', sprintf('Cell %d (row %d, column %d) is outside the table, which has %d rows and %d columns (0-based). Insert rows or columns first.', $i, (int) $cell['row'], (int) $cell['column'], $rows, $columns), [ 'rows' => $rows, 'columns' => $columns ]);
            }
        }
        return null;
    }

    /** Refusal for an insert of rows or columns that does not fit the table. */
    private static function insert_refusal(array $args, string $kind): ?array
    {
        if (isset($args[ $kind ]) === isset($args['count'])) {
            return self::refusal('invalid_args', sprintf('Give either %s or count.', $kind));
        }
        $table = self::load(self::table_id($args));
        $size  = 'rows' === $kind ? count($table['data']) : count($table['data'][0]);
        if ((int) ($args['position'] ?? $size) > $size) {
            return self::refusal('position_out_of_range', sprintf('position may be at most %d, the number of %s.', $size, $kind), [ $kind => $size ]);
        }
        if (isset($args[ $kind ])) {
            $span = 'rows' === $kind ? count($table['data'][0]) : count($table['data']);
            return self::shape_refusal((array) $args[ $kind ], $span, $kind);
        }
        return null;
    }

    /** Refusal for a delete of rows or columns that names one outside the table or would remove them all. */
    private static function delete_refusal(array $args, string $kind): ?array
    {
        $table   = self::load(self::table_id($args));
        $size    = 'rows' === $kind ? count($table['data']) : count($table['data'][0]);
        $indexes = array_unique(array_map('intval', (array) $args[ $kind ]));
        $outside = array_values(array_filter($indexes, static fn (int $i): bool => $i >= $size));
        if ([] !== $outside) {
            return self::refusal('position_out_of_range', sprintf('The table has %d %s (0-based); %s is outside it.', $size, $kind, implode(', ', $outside)), [ $kind => $size ]);
        }
        if (count($indexes) >= $size) {
            return self::refusal('table_would_be_empty', sprintf('A TablePress table keeps at least one row and one column; delete the table instead of all its %s.', $kind));
        }
        return null;
    }

    private static function new_id_refusal(string $new_id): ?array
    {
        $new_id = trim($new_id);
        if ('' === $new_id || 1 === preg_match('/[^a-zA-Z0-9_-]/', $new_id)) {
            return self::refusal('invalid_table_id', 'A table id may hold only letters, digits, - and _.', [ 'new_id' => $new_id ]);
        }
        if (self::model()->table_exists($new_id)) {
            return self::refusal('table_id_taken', sprintf('Table id "%s" is already in use.', $new_id), [ 'new_id' => $new_id ]);
        }
        return null;
    }

    private static function import_refusal(array $args): ?array
    {
        $mode = (string) ($args['mode'] ?? (isset($args['table_id']) ? 'replace' : 'add'));
        if ('add' === $mode) {
            return isset($args['table_id']) ? self::refusal('invalid_args', 'mode add creates a new table; leave out table_id, or use replace or append.') : null;
        }
        if (! isset($args['table_id'])) {
            return self::refusal('invalid_args', sprintf('mode %s needs table_id.', $mode));
        }
        if (isset($args['name'])) {
            return self::refusal('invalid_args', 'TablePress keeps the name of a table it replaces or appends to; leave out name.');
        }
        return self::table_refusal($args['table_id'], 'tablepress_edit_table', true);
    }

    private static function place_refusal(int $post_id): ?array
    {
        if ('builder' === get_post_meta($post_id, '_elementor_edit_mode', true)) {
            return self::refusal('builder_managed', sprintf('Post %d is built with Elementor, which renders its own data rather than the post content; place the table with TablePress\'s Elementor widget instead.', $post_id), [ 'post_id' => $post_id ]);
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    private static function list_tables(array $args): array
    {
        $search   = strtolower(trim((string) ($args['search'] ?? '')));
        $page     = (int) ($args['page'] ?? 1);
        $per_page = (int) ($args['per_page'] ?? 20);
        $ids      = [];
        foreach (self::model()->load_all(true, false) as $id) {
            $id = (string) $id;
            if ('' !== $search) {
                $table = self::model()->load($id, false, false);
                if (! is_array($table) || (false === strpos(strtolower($id), $search) && false === strpos(strtolower((string) $table['name']), $search))) {
                    continue;
                }
            }
            $ids[] = $id;
        }
        $tables = [];
        foreach (array_slice($ids, ($page - 1) * $per_page, $per_page) as $id) {
            $table = self::model()->load($id, true, false);
            if (is_array($table)) {
                $tables[] = self::summary($table);
            }
        }
        return [ 'total' => count($ids), 'page' => $page, 'per_page' => $per_page, 'tables' => $tables ];
    }

    private static function get_table(array $args): array
    {
        $table  = self::load(self::table_id($args));
        $offset = (int) ($args['offset'] ?? 0);
        $limit  = (int) ($args['limit'] ?? 100);
        return self::summary($table) + [
            'options'    => (array) $table['options'],
            'visibility' => (array) $table['visibility'],
            'offset'     => $offset,
            'data'       => array_slice((array) $table['data'], $offset, $limit),
        ];
    }

    private static function export_table(array $args): array
    {
        $format    = (string) $args['format'];
        $delimiter = (string) ($args['csv_delimiter'] ?? ',');
        $exporter  = \TablePress::load_class('TablePress_Export', 'class-export.php', 'classes');
        $content   = (string) $exporter->export_table(self::load(self::table_id($args)), $format, [ 'csv_delimiter' => 'tab' === $delimiter ? "\t" : $delimiter ]);
        return [ 'table_id' => self::table_id($args), 'format' => $format, 'content' => $content ];
    }

    /**
     * The posts whose content places a table: a TablePress block naming its
     * id, or the [table] shortcode with that id. Revisions, auto-drafts and
     * posts the caller may not read are left out.
     *
     * @return array<int, array{id: int, title: string, type: string, status: string, as: string[]}>
     */
    public static function usage(string $table_id): array
    {
        global $wpdb;
        $shortcode = self::shortcode();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a content search across every post type, read fresh; core has no API for it.
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_content FROM %i WHERE post_type NOT IN ('revision', 'tablepress_table') AND post_status NOT IN ('auto-draft', 'inherit') AND (post_content LIKE %s OR post_content LIKE %s) ORDER BY ID ASC",
            $wpdb->posts,
            '%' . $wpdb->esc_like('wp:tablepress/table') . '%',
            '%' . $wpdb->esc_like('[' . $shortcode) . '%'
        ), ARRAY_A);

        $quoted = preg_quote($table_id, '/');
        $code   = '/\[' . preg_quote($shortcode, '/') . '\s[^\]]*\bid\s*=\s*(["\']?)' . $quoted . '\1(?=[\s\/\]])/';
        $out    = [];
        foreach ($rows as $row) {
            $content = (string) $row['post_content'];
            $as      = [];
            if (false !== strpos($content, 'wp:tablepress/table') && self::block_names($content, $table_id)) {
                $as[] = 'block';
            }
            if (1 === preg_match($code, $content)) {
                $as[] = 'shortcode';
            }
            $id = (int) $row['ID'];
            if ([] === $as || ! current_user_can('read_post', $id)) {
                continue;
            }
            $post  = get_post($id);
            $out[] = [ 'id' => $id, 'title' => (string) $post->post_title, 'type' => (string) $post->post_type, 'status' => (string) $post->post_status, 'as' => $as ];
            if (count($out) >= self::MAX_USAGE) {
                break;
            }
        }
        return $out;
    }

    /** Whether a TablePress block in $content names $table_id. */
    private static function block_names(string $content, string $table_id): bool
    {
        foreach (parse_blocks($content) as $block) {
            if (self::block_tree_names($block, $table_id)) {
                return true;
            }
        }
        return false;
    }

    private static function block_tree_names(array $block, string $table_id): bool
    {
        if ('tablepress/table' === ($block['blockName'] ?? '') && $table_id === (string) ($block['attrs']['id'] ?? '')) {
            return true;
        }
        foreach ((array) ($block['innerBlocks'] ?? []) as $inner) {
            if (self::block_tree_names((array) $inner, $table_id)) {
                return true;
            }
        }
        return false;
    }

    /** The shortcode TablePress renders tables with ("table" unless filtered). */
    private static function shortcode(): string
    {
        return isset(\TablePress::$shortcode) && '' !== (string) \TablePress::$shortcode ? (string) \TablePress::$shortcode : 'table';
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /**
     * Change one table through TablePress's model: load it, let $change
     * rebuild it, check it is still rectangular and its visibility matches,
     * and save it (TablePress clears its render cache). The dispatcher has
     * already snapshotted the table's post.
     */
    private static function edit(array $args, callable $change): array
    {
        $table_id = self::table_id($args);
        $table    = self::load($table_id);
        $new      = $change($table);
        $saved    = self::save($table, $new);
        return [ 'table' => self::summary($saved) ];
    }

    /**
     * Save $new over $table: TablePress's prepare_table() with its size
     * check, then its save().
     *
     * @throws Operation_Refused When the data is not rectangular or TablePress refuses it.
     */
    private static function save(array $table, array $new): array
    {
        $new['data'] = self::cells((array) $new['data']);
        $refusal     = self::shape_refusal($new['data'], null, 'rows');
        if (null !== $refusal || [] === $new['data'] || [] === $new['data'][0]) {
            throw new Operation_Refused('table_not_rectangular', (string) ($refusal['message'] ?? 'A table needs at least one row and one column.'));
        }
        $rows                = count($new['data']);
        $columns             = count($new['data'][0]);
        $new['number']       = [ 'rows' => $rows, 'columns' => $columns ];
        $new['options']      = self::fit_options((array) $new['options'], $rows);
        $prepared            = self::model()->prepare_table($table, $new, true);
        if (is_wp_error($prepared)) {
            throw new Operation_Refused('table_rejected', sprintf('TablePress refused the table (%s).', $prepared->get_error_code()));
        }
        $saved = self::model()->save($prepared);
        if (is_wp_error($saved)) {
            throw new Operation_Refused('save_failed', sprintf('TablePress could not save table "%s" (%s).', (string) $table['id'], $saved->get_error_code()));
        }
        return self::load((string) $table['id']);
    }

    /** Header and footer row counts kept within $rows. */
    private static function fit_options(array $options, int $rows): array
    {
        foreach ([ 'table_head', 'table_foot' ] as $key) {
            if (isset($options[ $key ]) && ! is_bool($options[ $key ])) {
                $options[ $key ] = absint($options[ $key ]);
            }
        }
        $head = (int) ($options['table_head'] ?? 0);
        $foot = (int) ($options['table_foot'] ?? 0);
        if ($head + $foot > $rows) {
            $options['table_head'] = min($head, $rows);
            $options['table_foot'] = max(0, $rows - (int) $options['table_head']);
            $options['table_foot'] = min($foot, $options['table_foot']);
        }
        return $options;
    }

    private static function insert_rows(array $table, array $args): array
    {
        $columns = count($table['data'][0]);
        $rows    = isset($args['rows']) ? self::cells((array) $args['rows']) : array_fill(0, (int) $args['count'], array_fill(0, $columns, ''));
        $at      = (int) ($args['position'] ?? count($table['data']));
        array_splice($table['data'], $at, 0, $rows);
        array_splice($table['visibility']['rows'], $at, 0, array_fill(0, count($rows), 1));
        return $table;
    }

    private static function delete_rows(array $table, array $indexes): array
    {
        $drop                        = array_flip(array_map('intval', $indexes));
        $table['data']               = array_values(array_diff_key($table['data'], $drop));
        $table['visibility']['rows'] = array_values(array_diff_key((array) $table['visibility']['rows'], $drop));
        return $table;
    }

    private static function insert_columns(array $table, array $args): array
    {
        $height  = count($table['data']);
        $columns = isset($args['columns']) ? self::cells((array) $args['columns']) : array_fill(0, (int) $args['count'], array_fill(0, $height, ''));
        $at      = (int) ($args['position'] ?? count($table['data'][0]));
        foreach ($table['data'] as $r => $row) {
            array_splice($row, $at, 0, array_column($columns, $r));
            $table['data'][ $r ] = $row;
        }
        array_splice($table['visibility']['columns'], $at, 0, array_fill(0, count($columns), 1));
        return $table;
    }

    private static function delete_columns(array $table, array $indexes): array
    {
        $drop = array_flip(array_map('intval', $indexes));
        foreach ($table['data'] as $r => $row) {
            $table['data'][ $r ] = array_values(array_diff_key($row, $drop));
        }
        $table['visibility']['columns'] = array_values(array_diff_key((array) $table['visibility']['columns'], $drop));
        return $table;
    }

    /**
     * Run $write, which changes the table id map, under an option snapshot
     * of it in this call's session; returns [operation id, $write's result].
     */
    private static function under_map_snapshot(array $context, array $args, callable $write): array
    {
        $out = Safe_Mutation::run(
            [
                'object_type' => 'option',
                'object_id'   => self::TABLES_OPTION,
                'session_id'  => (string) $context['session_id'],
                'tool_name'   => (string) $context['tool_name'],
                'args'        => [ 'operation' => (string) $context['operation'], 'args' => $args ],
            ],
            $write
        );
        return [ (string) $out['operation_id'], $out['result'] ];
    }

    /** Record a table a call just added (its undo trashes the post) and return its operation id. */
    private static function record_created(string $table_id, array $context, array $args): string
    {
        return Post_Creation_Snapshot::record((string) $context['tool_name'], [ self::post_id_of($table_id) ], [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']);
    }

    /** @return string The new table id. */
    private static function checked_id($result, string $what): string
    {
        if (is_wp_error($result) || ! is_string($result) || '' === $result) {
            throw new Operation_Refused($what . '_failed', sprintf('TablePress could not %s the table (%s).', $what, is_wp_error($result) ? $result->get_error_code() : 'no id'));
        }
        return $result;
    }

    private static function create_table(array $args, array $context): array
    {
        $template = self::model()->get_table_template();
        $data     = isset($args['data']) ? self::cells((array) $args['data']) : array_fill(0, (int) ($args['rows'] ?? 1), array_fill(0, (int) ($args['columns'] ?? 1), ''));
        $new      = [
            'id'          => false,
            'name'        => (string) $args['name'],
            'description' => (string) ($args['description'] ?? ''),
            'data'        => $data,
            'options'     => self::fit_options(array_merge((array) $template['options'], (array) ($args['options'] ?? [])), count($data)),
            'visibility'  => [ 'rows' => array_fill(0, count($data), 1), 'columns' => array_fill(0, count($data[0]), 1) ],
        ];
        $prepared = self::model()->prepare_table($template, $new, false);
        if (is_wp_error($prepared)) {
            throw new Operation_Refused('table_rejected', sprintf('TablePress refused the table (%s).', $prepared->get_error_code()));
        }
        [ $map_op, $id ] = self::under_map_snapshot($context, $args, static fn () => self::model()->add($prepared));
        $id              = self::checked_id($id, 'create');
        $ids             = [ $map_op, self::record_created($id, $context, $args) ];
        return [ 'result' => [ 'table' => self::summary(self::load($id)) ], 'operation_ids' => $ids ];
    }

    private static function copy_table(array $args, array $context): array
    {
        [ $map_op, $id ] = self::under_map_snapshot($context, $args, static fn () => self::model()->copy(self::table_id($args)));
        $id              = self::checked_id($id, 'copy');
        $ids             = [ $map_op, self::record_created($id, $context, $args) ];
        return [ 'result' => [ 'table' => self::summary(self::load($id)), 'copied_from' => self::table_id($args) ], 'operation_ids' => $ids ];
    }

    private static function change_table_id(array $args): array
    {
        $old  = self::table_id($args);
        $new  = trim((string) $args['new_id']);
        $done = self::model()->change_table_id($old, $new);
        if (is_wp_error($done)) {
            throw new Operation_Refused('change_id_failed', sprintf('TablePress could not change the id of table "%s" (%s).', $old, $done->get_error_code()));
        }
        self::model()->invalidate_table_output_cache($old);
        return [ 'table' => self::summary(self::load($new)), 'old_id' => $old, 'placements_of_old_id' => self::usage($old) ];
    }

    private static function delete_table(array $args, array $context): array
    {
        $table_id = self::table_id($args);
        $post_id  = self::post_id_of($table_id);
        $summary  = self::summary(self::load($table_id));
        $post_op  = '';
        [ $map_op ] = self::under_map_snapshot($context, $args, static function () use ($table_id, $post_id, $context, $args, &$post_op): bool {
            $out     = Safe_Mutation::run(
                [
                    'object_type' => 'post',
                    'object_id'   => $post_id,
                    'session_id'  => (string) $context['session_id'],
                    'tool_name'   => (string) $context['tool_name'],
                    'args'        => [ 'operation' => (string) $context['operation'], 'args' => $args ],
                ],
                static fn () => self::model()->delete($table_id)
            );
            $post_op = (string) $out['operation_id'];
            if (true !== $out['result']) {
                throw new Operation_Refused('delete_failed', sprintf('TablePress could not delete table "%s".', $table_id));
            }
            return true;
        });
        return [ 'result' => [ 'deleted' => $summary, 'placements' => self::usage($table_id) ], 'operation_ids' => [ $map_op, $post_op ] ];
    }

    /**
     * Import through TablePress's importer: it detects the format, parses
     * the data and adds, replaces or appends through the table model.
     */
    private static function import_table(array $args, array $context): array
    {
        $mode     = (string) ($args['mode'] ?? (isset($args['table_id']) ? 'replace' : 'add'));
        $existing = 'add' === $mode ? '' : self::table_id($args);
        $import   = static function () use ($args, $mode, $existing) {
            $importer = \TablePress::load_class('TablePress_Import', 'class-import.php', 'classes');
            $out      = $importer->run([
                'source'         => 'form-field',
                'form-field'     => (string) $args['data'],
                'type'           => $mode,
                'existing_table' => $existing,
                'legacy_import'  => false,
            ]);
            $tables = is_wp_error($out) ? [] : (array) ($out['tables'] ?? []);
            if ([] === $tables || empty($tables[0]['id'])) {
                $error = is_wp_error($out) ? $out->get_error_code() : self::import_error($out);
                throw new Operation_Refused('import_failed', sprintf('TablePress could not import the data (%s).', $error));
            }
            return (string) $tables[0]['id'];
        };

        if ('add' !== $mode) {
            $out = Safe_Mutation::run(
                [
                    'object_type' => 'post',
                    'object_id'   => self::post_id_of($existing),
                    'session_id'  => (string) $context['session_id'],
                    'tool_name'   => (string) $context['tool_name'],
                    'args'        => [ 'operation' => (string) $context['operation'], 'args' => $args ],
                ],
                $import
            );
            return [ 'result' => [ 'table' => self::summary(self::load((string) $out['result'])), 'mode' => $mode ], 'operation_ids' => [ (string) $out['operation_id'] ] ];
        }

        [ $map_op, $id ] = self::under_map_snapshot($context, $args, $import);
        $ids             = [ $map_op, self::record_created($id, $context, $args) ];
        if (isset($args['name'])) {
            $table         = self::load($id);
            $named         = $table;
            $named['name'] = (string) $args['name'];
            self::save($table, $named);
        }
        return [ 'result' => [ 'table' => self::summary(self::load($id)), 'mode' => $mode ], 'operation_ids' => $ids ];
    }

    /** The first error TablePress's importer reported, as a code. */
    private static function import_error($out): string
    {
        foreach ((array) ($out['errors'] ?? []) as $file) {
            $error = is_object($file) ? ($file->error ?? null) : null;
            if (is_wp_error($error)) {
                return implode(', ', $error->get_error_codes());
            }
        }
        return 'no table';
    }

    /** Add the table's block or shortcode to the post (the dispatcher snapshotted the post). */
    private static function place_table(array $args): array
    {
        $table_id = self::table_id($args);
        $post_id  = (int) $args['post_id'];
        $post     = get_post($post_id);
        $content  = (string) $post->post_content;
        $as       = (string) ($args['as'] ?? 'block');
        $blocks   = has_blocks($content) || '' === trim($content);
        $code     = '[' . self::shortcode() . ' id=' . $table_id . ' /]';
        if ('block' === $as) {
            $markup = '<!-- wp:tablepress/table ' . wp_json_encode([ 'id' => $table_id ]) . ' /-->';
        } else {
            $markup = $blocks ? "<!-- wp:shortcode -->\n" . $code . "\n<!-- /wp:shortcode -->" : $code;
        }
        $joined = '' === trim($content) ? $markup : ('start' === ($args['position'] ?? 'end') ? $markup . "\n\n" . $content : $content . "\n\n" . $markup);
        $saved  = Save_Filters::update_post([ 'ID' => $post_id, 'post_content' => wp_slash($joined) ], true);
        if (is_wp_error($saved)) {
            throw new Operation_Refused('update_failed', $saved->get_error_message());
        }
        clean_post_cache($post_id);
        return [ 'post_id' => $post_id, 'table_id' => $table_id, 'as' => $as, 'markup' => $markup ];
    }

    // ------------------------------------------------------------------
    // Hooks
    // ------------------------------------------------------------------

    /**
     * Map core's post capabilities for TablePress's post type onto
     * TablePress's table capabilities. TablePress registers its post type
     * with map_meta_cap off and never asks for these itself; a rollback of
     * a table snapshot does (edit_post, delete_post, or the type's
     * edit_posts for a table post that is gone).
     *
     * @param string[] $caps
     * @param mixed[]  $args
     * @return string[]
     */
    public static function map_post_caps(array $caps, $cap, int $user_id, array $args): array
    {
        if (! is_string($cap) || 0 === preg_match('/^(edit|delete|read)_/', $cap)) {
            return $caps;
        }
        $type = get_post_type_object('tablepress_table');
        if (! $type instanceof \WP_Post_Type || 'tablepress_table' !== $type->capability_type) {
            return $caps;
        }
        // Core hands a type without meta mapping its own capability names
        // (edit_tablepress_table for edit_post, edit_tablepress_tables for
        // edit_posts): each maps onto the TablePress capability it means.
        $map = [
            'tablepress_edit_tables'   => [ 'edit_post', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts' ],
            'tablepress_delete_tables' => [ 'delete_post', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts' ],
            'tablepress_list_tables'   => [ 'read_post', 'read_private_posts' ],
        ];
        $primitives = (array) $type->cap;
        foreach ($map as $tablepress => $keys) {
            foreach ($keys as $key) {
                if (($primitives[ $key ] ?? null) === $cap) {
                    return [ $tablepress ];
                }
            }
        }
        return $caps;
    }

    /** A rollback put a table post back: drop TablePress's render cache for it. */
    public static function after_post_restored(int $post_id): void
    {
        if (! self::active() || 'tablepress_table' !== get_post_type($post_id)) {
            return;
        }
        $table_id = self::table_id_of_post($post_id);
        if (null !== $table_id) {
            self::model()->invalidate_table_output_cache($table_id);
        }
    }

    /**
     * A rollback put the table id map back. TablePress's model holds the map
     * it read when it loaded, so it takes the restored one too, and every
     * table either map names drops its render cache.
     *
     * @param string[] $names
     */
    public static function after_options_restored(array $names): void
    {
        if (! self::active() || ! in_array(self::TABLES_OPTION, $names, true)) {
            return;
        }
        $before = array_keys(self::id_map());
        $stored = get_option(self::TABLES_OPTION, null);
        $fresh  = is_string($stored) ? json_decode($stored, true) : null;
        if (! is_array($fresh)) {
            $fresh = [ 'last_id' => 0, 'table_post' => [] ];
        }
        if (self::model()->_debug_get_tables() != $fresh) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- the map round-trips through JSON, so key order and int/string ids may differ while the content is the same.
            self::model()->_debug_update_tables($fresh);
        }
        foreach (array_unique(array_merge($before, array_map('strval', array_keys((array) ($fresh['table_post'] ?? []))))) as $table_id) {
            self::model()->invalidate_table_output_cache((string) $table_id);
        }
    }
    // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
