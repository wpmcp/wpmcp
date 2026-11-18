<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Data_Tables_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;

require_once dirname(__DIR__, 2) . '/support/ninja-tables-stubs.php';

/**
 * wpDataTables and Ninja Tables (issue #706): paid-tier ops on the
 * plugin-data pair, named the way the TablePress ops are, so they add no
 * top-level tools.
 *
 * Neither plugin is loaded in the shared test core. Their tables are made
 * here from the plugins' own definitions (wpDataTables 6.6:
 * wdtActivationCreateTables(); Ninja Tables 5.5: NinjaTableItemsMigrator),
 * rows are stored the way each plugin stores them (a wpDataTables simple
 * table row is {"cells":[{"data","hidden","type"}]} in wpdatatables_rows; a
 * Ninja Tables row is a JSON object keyed by column key in
 * ninja_table_items, its columns in the _ninja_table_columns post meta),
 * and presence is driven through wpmcp_wpdatatables_active and
 * wpmcp_ninja_tables_active.
 */
class DataTablesPackTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}wpdatatables (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            show_title tinyint(1) NOT NULL default '1',
            table_type varchar(55) NOT NULL,
            content text NOT NULL,
            mysql_table_name varchar(255) NOT NULL default '',
            advanced_settings TEXT NOT NULL,
            UNIQUE KEY id (id)
        )");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}wpdatatables_columns (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            table_id bigint(20) NOT NULL,
            orig_header varchar(255) NOT NULL,
            display_header varchar(255) NOT NULL,
            column_type varchar(20) NOT NULL default 'string',
            visible tinyint(1) NOT NULL default '1',
            pos int(11) NOT NULL default '0',
            UNIQUE KEY id (id)
        )");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}wpdatatables_rows (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            table_id bigint(20) NOT NULL,
            data TEXT NOT NULL,
            UNIQUE KEY id (id)
        )");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}wpdatatable_1 (
            wdt_ID INT( 11 ) NOT NULL AUTO_INCREMENT,
            name VARCHAR(255),
            city VARCHAR(255),
            UNIQUE KEY wdt_ID (wdt_ID)
        )");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}ninja_table_items (
            id BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            position int(11),
            table_id BIGINT(20) NOT NULL,
            owner_id int(11),
            attribute varchar(255) NOT NULL,
            settings longtext,
            value longtext,
            created_at timestamp NULL,
            updated_at timestamp NULL
        )");
    }

    public static function wpTearDownAfterClass(): void
    {
        global $wpdb;
        foreach ([ 'wpdatatables', 'wpdatatables_columns', 'wpdatatables_rows', 'wpdatatable_1', 'ninja_table_items' ] as $table) {
            $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $table);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_wpdatatables_active', '__return_true');
        add_filter('wpmcp_ninja_tables_active', '__return_true');
        add_filter(Data_Tables_Pack::OPT_IN_FILTER, '__return_true');
    }

    protected function tearDown(): void
    {
        global $wpdb;
        foreach ([ 'wpdatatables', 'wpdatatables_columns', 'wpdatatables_rows', 'wpdatatable_1', 'ninja_table_items' ] as $table) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $table);
        }
        remove_all_filters('wpmcp_wpdatatables_active');
        remove_all_filters('wpmcp_ninja_tables_active');
        remove_all_filters(Data_Tables_Pack::OPT_IN_FILTER);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function refused(array $out, string $code): array
    {
        $this->assertArrayHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame($code, $out['error']['code'], (string) wp_json_encode($out));
        return $out['error'];
    }

    /** A wpDataTables simple table stored the way its simple table editor stores one. */
    private function simple_table(string $title, array $grid, array $merged = []): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'wpdatatables', [
            'title'             => $title,
            'table_type'        => 'simple',
            'content'           => wp_json_encode([
                'rowNumber'     => count($grid),
                'colNumber'     => count($grid[0]),
                'colWidths'     => array_fill(0, count($grid[0]), 100),
                'colHeaders'    => array_slice([ 'A', 'B', 'C', 'D' ], 0, count($grid[0])),
                'reloadCounter' => 0,
                'mergedCells'   => $merged,
            ]),
            'advanced_settings' => wp_json_encode([ 'simpleHeader' => 1 ]),
        ]);
        $id = (int) $wpdb->insert_id;
        foreach ($grid as $row) {
            $cells = array_map(static fn ($v): array => [ 'data' => $v, 'hidden' => false, 'type' => 'text' ], $row);
            $wpdb->insert($wpdb->prefix . 'wpdatatables_rows', [ 'table_id' => $id, 'data' => wp_json_encode([ 'cells' => $cells, 'height' => 100 ]) ]);
        }
        return $id;
    }

    /** @return array{0: string, 1: array<int, array<string, string>>} the table's content and rows as stored. */
    private function stored_simple(int $id): array
    {
        global $wpdb;
        $content = (string) $wpdb->get_var($wpdb->prepare("SELECT content FROM {$wpdb->prefix}wpdatatables WHERE id = %d", $id));
        $rows    = $wpdb->get_results($wpdb->prepare("SELECT id, data FROM {$wpdb->prefix}wpdatatables_rows WHERE table_id = %d ORDER BY id", $id), ARRAY_A);
        return [ $content, $rows ];
    }

    private function grid_of(int $id): array
    {
        return $this->ok($this->read('wpdatatables-get-table', [ 'table_id' => $id ]))['result']['rows'];
    }

    /** A Ninja Tables table: its post, columns meta and rows in ninja_table_items. */
    private function ninja_table(string $title, array $rows, array $meta = []): int
    {
        global $wpdb;
        $id = self::factory()->post->create([ 'post_type' => 'ninja-table', 'post_title' => $title, 'post_status' => 'publish' ]);
        update_post_meta($id, '_ninja_table_columns', [
            [ 'key' => 'name', 'name' => 'Name', 'data_type' => 'text', 'breakpoints' => '' ],
            [ 'key' => 'city', 'name' => 'City', 'data_type' => 'text', 'breakpoints' => '' ],
        ]);
        foreach ($meta as $key => $value) {
            update_post_meta($id, $key, $value);
        }
        foreach ($rows as $i => $row) {
            $wpdb->insert($wpdb->prefix . 'ninja_table_items', [
                'table_id'   => $id,
                'attribute'  => 'value',
                'owner_id'   => 1,
                'value'      => wp_json_encode($row),
                'created_at' => gmdate('Y-m-d H:i:s', 1700000000 + $i),
                'updated_at' => gmdate('Y-m-d H:i:s', 1700000000 + $i),
            ]);
        }
        return $id;
    }

    private function ninja_rows(int $id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ninja_table_items WHERE table_id = %d ORDER BY id", $id), ARRAY_A);
    }

    // ------------------------------------------------------------------
    // Catalog and presence
    // ------------------------------------------------------------------

    public function test_the_ops_use_the_tablepress_names_and_sit_on_the_plugin_data_pair(): void
    {
        $ops = array_keys(Data_Tables_Pack::operations());
        foreach ([ 'wpdatatables', 'ninjatables' ] as $prefix) {
            foreach ([ 'list-tables', 'get-table', 'set-cells', 'insert-rows', 'delete-rows' ] as $name) {
                $this->assertContains($prefix . '-' . $name, $ops);
            }
        }
        remove_all_filters(Data_Tables_Pack::OPT_IN_FILTER);
        foreach (Data_Tables_Pack::operations() as $op => $def) {
            $this->assertSame('pro', $def['tier'], $op);
            $this->assertSame('manage_options', $def['capability'], $op);
            if ('read' !== $def['mode']) {
                $this->assertFalse($def['enabled_by_default'] ?? true, $op . ' is off until the site opts in');
                $this->assertSame(Data_Tables_Pack::OPT_IN_FILTER, $def['opt_in_filter'], $op);
            }
        }
    }

    public function test_an_inactive_plugin_is_named_in_the_refusal(): void
    {
        remove_all_filters('wpmcp_wpdatatables_active');
        add_filter('wpmcp_wpdatatables_active', '__return_false');
        $error = $this->refused($this->read('wpdatatables-list-tables'), 'wpdatatables_inactive');
        $this->assertStringContainsString('Install and activate wpDataTables', $error['message']);
        $this->assertSame('missing', $error['data']['state']);

        remove_all_filters('wpmcp_ninja_tables_active');
        add_filter('wpmcp_ninja_tables_active', '__return_false');
        $error = $this->refused($this->read('ninjatables-list-tables'), 'ninjatables_inactive');
        $this->assertStringContainsString('Install and activate Ninja Tables', $error['message']);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters(Data_Tables_Pack::OPT_IN_FILTER);
        $id = $this->simple_table('Prices', [ [ 'a', 'b' ] ]);
        $error = $this->refused($this->write('wpdatatables-set-cells', [ 'table_id' => $id, 'cells' => [ [ 'row' => 0, 'column' => 0, 'value' => 'x' ] ] ]), 'opt_in_required');
        $this->assertStringContainsString(Data_Tables_Pack::OPT_IN_FILTER, (string) wp_json_encode($error));
        $this->assertSame('a', $this->grid_of($id)[0][0]);
    }

    // ------------------------------------------------------------------
    // wpDataTables
    // ------------------------------------------------------------------

    public function test_wpdatatables_list_tables_reports_type_size_and_whether_cells_are_editable(): void
    {
        global $wpdb;
        $simple = $this->simple_table('Prices', [ [ 'Item', 'Price' ], [ 'Tea', '3' ], [ 'Cake', '5' ] ]);
        $wpdb->insert($wpdb->prefix . 'wpdatatables', [ 'title' => 'Sheet', 'table_type' => 'csv', 'content' => 'https://example.com/a.csv', 'advanced_settings' => '{}' ]);

        $out = $this->ok($this->read('wpdatatables-list-tables'))['result'];
        $this->assertSame(2, $out['total']);
        $by  = array_column($out['tables'], null, 'title');
        $this->assertSame($simple, $by['Prices']['table_id']);
        $this->assertSame('simple', $by['Prices']['table_type']);
        $this->assertSame(3, $by['Prices']['rows']);
        $this->assertSame(2, $by['Prices']['columns']);
        $this->assertTrue($by['Prices']['editable']);
        $this->assertSame('[wpdatatable id=' . $simple . ']', $by['Prices']['shortcode']);
        $this->assertFalse($by['Sheet']['editable']);

        $this->assertSame([ 'Sheet' ], array_column($this->ok($this->read('wpdatatables-list-tables', [ 'search' => 'she' ]))['result']['tables'], 'title'));
    }

    public function test_wpdatatables_get_table_reads_simple_cells_with_offset_and_limit(): void
    {
        $id  = $this->simple_table('Prices', [ [ 'Item', 'Price' ], [ 'Tea', '3' ], [ 'Cake', '5' ] ]);
        $out = $this->ok($this->read('wpdatatables-get-table', [ 'table_id' => $id, 'offset' => 1, 'limit' => 1 ]))['result'];
        $this->assertSame('simple', $out['table_type']);
        $this->assertSame(3, $out['row_count']);
        $this->assertSame(2, $out['column_count']);
        $this->assertSame(1, $out['offset']);
        $this->assertSame([ [ 'Tea', '3' ] ], $out['rows']);

        $this->refused($this->read('wpdatatables-get-table', [ 'table_id' => 9999 ]), 'table_not_found');
    }

    public function test_wpdatatables_get_table_reads_a_manual_table_from_its_own_mysql_table(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'wpdatatables', [ 'title' => 'People', 'table_type' => 'manual', 'content' => '', 'mysql_table_name' => $wpdb->prefix . 'wpdatatable_1', 'advanced_settings' => '{}' ]);
        $id = (int) $wpdb->insert_id;
        foreach ([ [ 'wdt_ID', 'wdt_ID', 0 ], [ 'name', 'Name', 1 ], [ 'city', 'City', 2 ] ] as [ $orig, $display, $pos ]) {
            $wpdb->insert($wpdb->prefix . 'wpdatatables_columns', [ 'table_id' => $id, 'orig_header' => $orig, 'display_header' => $display, 'pos' => $pos ]);
        }
        $wpdb->insert($wpdb->prefix . 'wpdatatable_1', [ 'name' => 'Ann', 'city' => 'Oslo' ]);

        $out = $this->ok($this->read('wpdatatables-get-table', [ 'table_id' => $id ]))['result'];
        $this->assertSame('manual', $out['table_type']);
        $this->assertSame([ 'wdt_ID', 'name', 'city' ], array_column($out['columns'], 'key'));
        $this->assertSame('Ann', $out['rows'][0]['name']);
        $this->assertSame(1, $out['row_count']);

        // A table name that is not one of wpDataTables' own tables is never queried.
        $wpdb->update($wpdb->prefix . 'wpdatatables', [ 'mysql_table_name' => $wpdb->users ], [ 'id' => $id ]);
        $out = $this->ok($this->read('wpdatatables-get-table', [ 'table_id' => $id ]))['result'];
        $this->assertSame([], $out['rows']);
        $this->assertFalse($out['rows_readable']);
    }

    public function test_wpdatatables_set_cells_edits_one_cell_and_rollback_operation_restores_exactly(): void
    {
        $id     = $this->simple_table('Prices', [ [ 'Item', 'Price' ], [ 'Tea', '3' ] ]);
        $before = $this->stored_simple($id);

        $out = $this->ok($this->write('wpdatatables-set-cells', [ 'table_id' => $id, 'cells' => [ [ 'row' => 1, 'column' => 1, 'value' => 4 ] ] ]));
        $this->assertSame([ [ 'Item', 'Price' ], [ 'Tea', '4' ] ], $this->grid_of($id));
        $this->assertTrue($out['recoverable']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']), implode('; ', Rollback_Service::take_warnings()));
        $this->assertSame($before, $this->stored_simple($id));
    }

    public function test_wpdatatables_set_cells_strips_scripts_without_unfiltered_html(): void
    {
        $id = $this->simple_table('Prices', [ [ 'a' ] ]);
        add_filter('map_meta_cap', static fn (array $caps, string $cap): array => 'unfiltered_html' === $cap ? [ 'do_not_allow' ] : $caps, 10, 2);
        $this->ok($this->write('wpdatatables-set-cells', [ 'table_id' => $id, 'cells' => [ [ 'row' => 0, 'column' => 0, 'value' => '<b>x</b><script>alert(1)</script>' ] ] ]));
        $this->assertSame('<b>x</b>alert(1)', $this->grid_of($id)[0][0]);
    }

    public function test_wpdatatables_out_of_range_cells_and_non_simple_tables_are_refused(): void
    {
        global $wpdb;
        $id = $this->simple_table('Prices', [ [ 'a', 'b' ] ]);
        $this->refused($this->write('wpdatatables-set-cells', [ 'table_id' => $id, 'cells' => [ [ 'row' => 1, 'column' => 0, 'value' => 'x' ] ] ]), 'cell_out_of_range');
        $this->refused($this->write('wpdatatables-set-cells', [ 'table_id' => $id, 'cells' => [ [ 'row' => 0, 'column' => 2, 'value' => 'x' ] ] ]), 'cell_out_of_range');

        $wpdb->insert($wpdb->prefix . 'wpdatatables', [ 'title' => 'Sheet', 'table_type' => 'csv', 'content' => 'https://example.com/a.csv', 'advanced_settings' => '{}' ]);
        $csv = (int) $wpdb->insert_id;
        $error = $this->refused($this->write('wpdatatables-insert-rows', [ 'table_id' => $csv, 'count' => 1 ]), 'table_not_editable');
        $this->assertStringContainsString('csv', $error['message']);
    }

    public function test_wpdatatables_insert_rows_at_a_position_and_rollback_session_restores_rows_and_size(): void
    {
        $id     = $this->simple_table('Prices', [ [ 'Item', 'Price' ], [ 'Cake', '5' ] ]);
        $before = $this->stored_simple($id);

        $out = $this->ok($this->write('wpdatatables-insert-rows', [ 'table_id' => $id, 'rows' => [ [ 'Tea', 3 ] ], 'position' => 1 ]));
        $this->assertSame([ [ 'Item', 'Price' ], [ 'Tea', '3' ], [ 'Cake', '5' ] ], $this->grid_of($id));
        $this->assertSame(3, json_decode($this->stored_simple($id)[0], true)['rowNumber']);
        $this->assertSame(3, $out['result']['row_count']);

        $this->ok($this->write('wpdatatables-insert-rows', [ 'table_id' => $id, 'count' => 2 ]));
        $this->assertSame([ '', '' ], $this->grid_of($id)[4]);

        Rollback_Service::restore_session((string) $out['session_id']);
        $this->assertSame($before[0], $this->stored_simple($id)[0]);
        $this->assertSame([ [ 'Item', 'Price' ], [ 'Cake', '5' ] ], $this->grid_of($id));

        $this->refused($this->write('wpdatatables-insert-rows', [ 'table_id' => $id, 'rows' => [ [ 'only one' ] ] ]), 'row_length_mismatch');
        $this->refused($this->write('wpdatatables-insert-rows', [ 'table_id' => $id, 'count' => 1, 'position' => 9 ]), 'cell_out_of_range');
    }

    public function test_wpdatatables_delete_rows_keeps_one_row_and_rollback_session_restores(): void
    {
        $id     = $this->simple_table('Prices', [ [ 'Item', 'Price' ], [ 'Tea', '3' ], [ 'Cake', '5' ] ]);
        $before = $this->stored_simple($id);

        $out = $this->ok($this->write('wpdatatables-delete-rows', [ 'table_id' => $id, 'rows' => [ 1 ] ]));
        $this->assertSame([ [ 'Item', 'Price' ], [ 'Cake', '5' ] ], $this->grid_of($id));
        $this->assertSame(2, json_decode($this->stored_simple($id)[0], true)['rowNumber']);

        Rollback_Service::restore_session((string) $out['session_id']);
        $this->assertSame($before, $this->stored_simple($id));

        $this->refused($this->write('wpdatatables-delete-rows', [ 'table_id' => $id, 'rows' => [ 0, 1, 2 ] ]), 'last_row');
    }

    public function test_wpdatatables_row_changes_that_would_shift_merged_cells_are_refused(): void
    {
        $id = $this->simple_table('Prices', [ [ 'a', 'b' ], [ 'c', 'd' ] ], [ [ 'row' => 0, 'col' => 0, 'rowspan' => 1, 'colspan' => 2 ] ]);
        $this->refused($this->write('wpdatatables-delete-rows', [ 'table_id' => $id, 'rows' => [ 1 ] ]), 'merged_cells');
        $this->refused($this->write('wpdatatables-insert-rows', [ 'table_id' => $id, 'count' => 1, 'position' => 0 ]), 'merged_cells');
        // Appending below every row shifts nothing.
        $this->ok($this->write('wpdatatables-insert-rows', [ 'table_id' => $id, 'count' => 1 ]));
    }

    // ------------------------------------------------------------------
    // Ninja Tables
    // ------------------------------------------------------------------

    public function test_ninjatables_list_and_get_table_read_columns_and_rows_by_row_id(): void
    {
        $id = $this->ninja_table('Team', [ [ 'name' => 'Ann', 'city' => 'Oslo' ], [ 'name' => 'Bo', 'city' => 'Rome' ] ]);
        $this->ninja_table('Synced', [], [ '_ninja_tables_data_provider' => 'google-csv' ]);

        $list = $this->ok($this->read('ninjatables-list-tables'))['result'];
        $by   = array_column($list['tables'], null, 'title');
        $this->assertSame($id, $by['Team']['table_id']);
        $this->assertSame(2, $by['Team']['rows']);
        $this->assertSame(2, $by['Team']['columns']);
        $this->assertTrue($by['Team']['editable']);
        $this->assertSame('[ninja_tables id="' . $id . '"]', $by['Team']['shortcode']);
        $this->assertFalse($by['Synced']['editable']);
        $this->assertSame('google-csv', $by['Synced']['data_source']);

        $out = $this->ok($this->read('ninjatables-get-table', [ 'table_id' => $id ]))['result'];
        $this->assertSame([ 'name', 'city' ], array_column($out['columns'], 'key'));
        $this->assertSame([ 'Ann', 'Bo' ], array_column(array_column($out['rows'], 'values'), 'name'));
        $this->assertSame(2, $out['row_count']);
        $this->assertIsInt($out['rows'][0]['row_id']);

        $this->refused($this->read('ninjatables-get-table', [ 'table_id' => self::factory()->post->create() ]), 'table_not_found');
    }

    public function test_ninjatables_set_cells_by_row_id_and_column_key_and_rollback_operation_restores(): void
    {
        $id     = $this->ninja_table('Team', [ [ 'name' => 'Ann', 'city' => 'Oslo' ], [ 'name' => 'Bo', 'city' => 'Rome' ] ]);
        $before = $this->ninja_rows($id);
        update_post_meta($id, '_ninja_table_cache_object', [ 'stale' ]);

        $out = $this->ok($this->write('ninjatables-set-cells', [ 'table_id' => $id, 'cells' => [ [ 'row_id' => (int) $before[1]['id'], 'column' => 'city', 'value' => 'Paris' ] ] ]));
        $this->assertSame([ 'name' => 'Bo', 'city' => 'Paris' ], json_decode($this->ninja_rows($id)[1]['value'], true));
        $this->assertEmpty(get_post_meta($id, '_ninja_table_cache_object', true), 'the render cache is cleared');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']), implode('; ', Rollback_Service::take_warnings()));
        $this->assertSame($before, $this->ninja_rows($id));

        $this->refused($this->write('ninjatables-set-cells', [ 'table_id' => $id, 'cells' => [ [ 'row_id' => (int) $before[0]['id'], 'column' => 'nope', 'value' => 'x' ] ] ]), 'unknown_column');
        $other = $this->ninja_table('Other', [ [ 'name' => 'Zed', 'city' => 'X' ] ]);
        $this->refused($this->write('ninjatables-set-cells', [ 'table_id' => $id, 'cells' => [ [ 'row_id' => (int) $this->ninja_rows($other)[0]['id'], 'column' => 'name', 'value' => 'x' ] ] ]), 'row_not_found');
    }

    public function test_ninjatables_insert_rows_appends_and_rollback_operation_removes_them(): void
    {
        $id     = $this->ninja_table('Team', [ [ 'name' => 'Ann', 'city' => 'Oslo' ] ]);
        $before = $this->ninja_rows($id);

        $out = $this->ok($this->write('ninjatables-insert-rows', [ 'table_id' => $id, 'rows' => [ [ 'name' => 'Cy', 'city' => 'Lima' ], [ 'name' => 'Di' ] ] ]));
        $this->assertCount(2, $out['result']['row_ids']);
        $rows = $this->ok($this->read('ninjatables-get-table', [ 'table_id' => $id ]))['result']['rows'];
        $this->assertSame([ 'Ann', 'Cy', 'Di' ], array_column(array_column($rows, 'values'), 'name'));
        $this->assertSame('', $rows[2]['values']['city']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']), implode('; ', Rollback_Service::take_warnings()));
        $this->assertSame($before, $this->ninja_rows($id));

        $this->refused($this->write('ninjatables-insert-rows', [ 'table_id' => $id, 'rows' => [ [ 'nope' => 'x' ] ] ]), 'unknown_column');
    }

    public function test_ninjatables_delete_rows_and_rollback_operation_restores_them_at_their_ids(): void
    {
        $id     = $this->ninja_table('Team', [ [ 'name' => 'Ann', 'city' => 'Oslo' ], [ 'name' => 'Bo', 'city' => 'Rome' ] ]);
        $before = $this->ninja_rows($id);

        $out = $this->ok($this->write('ninjatables-delete-rows', [ 'table_id' => $id, 'row_ids' => [ (int) $before[0]['id'] ] ]));
        $this->assertCount(1, $this->ninja_rows($id));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']), implode('; ', Rollback_Service::take_warnings()));
        $this->assertSame($before, $this->ninja_rows($id));
    }

    public function test_ninjatables_tables_fed_by_another_source_or_the_datatables_engine_are_not_written(): void
    {
        $synced = $this->ninja_table('Synced', [], [ '_ninja_tables_data_provider' => 'google-csv' ]);
        $this->refused($this->write('ninjatables-insert-rows', [ 'table_id' => $synced, 'rows' => [ [ 'name' => 'x' ] ] ]), 'table_not_editable');

        $dt = $this->ninja_table('Dt', [], [ '_ninja_table_settings' => [ 'library' => 'datatables' ] ]);
        $this->refused($this->write('ninjatables-insert-rows', [ 'table_id' => $dt, 'rows' => [ [ 'name' => 'x' ] ] ]), 'table_not_editable');
    }
}
