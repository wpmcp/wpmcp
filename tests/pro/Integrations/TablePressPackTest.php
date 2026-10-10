<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\TablePress_Pack;
use WPMCP\MCP\Registrar;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/tablepress-stubs.php';

/**
 * TablePress tables (issue #481): paid-tier ops on the plugin-data pair.
 *
 * TablePress is not loaded in the shared test core. Presence is driven
 * through wpmcp_tablepress_active and the storage is the stand-in in
 * tests/support/tablepress-stubs.php, kept the way TablePress 3.4 keeps a
 * table (a tablepress_table post holding the data as JSON, its options and
 * visibility in post meta, the id map in the tablepress_tables option). The
 * same ops run through the real TablePress in TablePressLiveTest.
 */
class TablePressPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'tablepress-list-tables'     => 'read',
        'tablepress-get-table'       => 'read',
        'tablepress-export-table'    => 'read',
        'tablepress-render-table'    => 'read',
        'tablepress-find-usage'      => 'read',
        'tablepress-create-table'    => 'write',
        'tablepress-update-table'    => 'write',
        'tablepress-set-cells'       => 'write',
        'tablepress-insert-rows'     => 'write',
        'tablepress-delete-rows'     => 'write',
        'tablepress-insert-columns'  => 'write',
        'tablepress-delete-columns'  => 'write',
        'tablepress-copy-table'      => 'write',
        'tablepress-change-table-id' => 'write',
        'tablepress-delete-table'    => 'destructive',
        'tablepress-import-table'    => 'write',
        'tablepress-place-table'     => 'write',
    ];

    /** Every other plugin-data plugin, off so only TablePress decides whether the pair is available. */
    private const OTHER_FILTERS = [ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active', 'wpmcp_buddypress_active', 'wpmcp_tutor_active', 'wpmcp_lifterlms_active' ];

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        delete_option('tablepress_tables');
        wpmcp_test_tablepress_boot();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        $user        = get_userdata($this->admin);
        foreach (wpmcp_test_tablepress_caps() as $cap) {
            $user->add_cap($cap);
        }
        wp_set_current_user($this->admin);
        add_filter('wpmcp_tablepress_active', '__return_true');
        foreach (self::OTHER_FILTERS as $filter) {
            add_filter($filter, '__return_false');
        }
    }

    protected function tearDown(): void
    {
        foreach (self::OTHER_FILTERS as $filter) {
            remove_all_filters($filter);
        }
        remove_all_filters('wpmcp_tablepress_active');
        wpmcp_test_tablepress_shutdown();
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args, array $extra = []): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ] + $extra);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function code(array $out): ?string
    {
        return $out['error']['code'] ?? null;
    }

    /** A table made through the op, returning its id. */
    private function table(array $data = [ [ 'Plan', 'Price' ], [ 'Basic', '10' ], [ 'Pro', '20' ] ], string $name = 'Pricing'): string
    {
        $out = $this->ok($this->write('tablepress-create-table', [ 'name' => $name, 'data' => $data ]));
        return (string) $out['result']['table']['id'];
    }

    /** The stored post row and meta of a table, to compare before and after a rollback. */
    private function stored(string $table_id): array
    {
        $post_id = TablePress_Pack::post_id_of($table_id);
        $post    = get_post($post_id);
        return [
            'post_id'     => $post_id,
            'content'     => $post->post_content,
            'title'       => $post->post_title,
            'excerpt'     => $post->post_excerpt,
            'options'     => get_post_meta($post_id, '_tablepress_table_options', true),
            'visibility'  => get_post_meta($post_id, '_tablepress_table_visibility', true),
        ];
    }

    private function data(string $table_id): array
    {
        return \TablePress::$model_table->load($table_id, true, true)['data'];
    }

    // ---------------------------------------------------------------
    // Catalog, gating and the tool surface
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_tablepress_op_with_its_mode_on_the_plugin_data_pair(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
        }
        $this->assertSame('tablepress_edit_tables', $ops['tablepress-set-cells']['capability']);
        $this->assertSame('tablepress_delete_tables', $ops['tablepress-delete-table']['capability']);
    }

    public function test_the_pair_is_available_with_tablepress_alone_and_not_without_it_or_without_pro(): void
    {
        $this->assertTrue((new Plugin_Data_Integration())->is_available());

        remove_all_filters('wpmcp_tablepress_active');
        add_filter('wpmcp_tablepress_active', '__return_false');
        $this->assertFalse((new Plugin_Data_Integration())->is_available());

        remove_all_filters('wpmcp_tablepress_active');
        add_filter('wpmcp_tablepress_active', '__return_true');
        Gate::set_pro_for_tests(false);
        $integration = new Plugin_Data_Integration();
        $this->assertFalse($integration->is_available());
        $this->assertNotContains('tablepress-list-tables', array_column($integration->catalog()['operations'], 'name'));
    }

    public function test_while_tablepress_is_inactive_its_ops_answer_tablepress_inactive(): void
    {
        remove_all_filters('wpmcp_tablepress_active');
        add_filter('wpmcp_tablepress_active', '__return_false');
        add_filter('wpmcp_buddypress_active', '__return_true');
        $out = $this->read('tablepress-list-tables');
        remove_all_filters('wpmcp_buddypress_active');
        $this->assertSame('tablepress_inactive', $this->code($out));
    }

    /**
     * On a site where TablePress is the only plugin-data plugin, the pair
     * registers (two tools) through the real registration path, without the
     * forced registration the manifest helpers use, and the whole tools/list
     * payload stays within the byte budget ToolsListBudgetTest pins.
     */
    public function test_with_tablepress_alone_the_pair_registers_and_tools_list_stays_within_budget(): void
    {
        $plugin   = Plugin::instance();
        $prop     = new \ReflectionProperty(Plugin::class, 'registrar');
        $original = $prop->getValue($plugin);
        $prop->setValue($plugin, new Registrar());
        try {
            $plugin->register_abilities();
            $abilities = $plugin->registrar()->all();
        } finally {
            $prop->setValue($plugin, $original);
        }
        $names = array_map(static fn ($a) => $a->name, $abilities);
        $this->assertContains('wpmcp/plugin-data-read', $names);
        $this->assertContains('wpmcp/plugin-data-write', $names);

        $payload = [];
        foreach ($abilities as $ability) {
            $payload[] = [
                'name'        => $ability->name,
                'description' => $ability->description,
                'inputSchema' => $ability->input_schema,
                'annotations' => [ 'readOnlyHint' => $ability->read_only_hint, 'destructiveHint' => $ability->destructive_hint, 'idempotentHint' => $ability->idempotent_hint ],
            ];
        }
        $budget = (new \ReflectionClassConstant(\WPMCP\Tests\Free\Platform\ToolsListBudgetTest::class, 'TOOLS_LIST_BYTE_BUDGET'))->getValue();
        $bytes  = strlen((string) wp_json_encode($payload));
        $this->assertLessThanOrEqual($budget, $bytes, sprintf('%d bytes over %d tools', $bytes, count($payload)));
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_get_export_and_render_read_a_table_through_tablepress(): void
    {
        $id    = $this->table();
        $other = $this->table([ [ 'a' ] ], 'Schedule');

        $list = $this->ok($this->read('tablepress-list-tables'))['result'];
        $this->assertSame(2, $list['total']);
        $this->assertSame([ $id, $other ], array_column($list['tables'], 'id'));
        $this->assertSame([ 3, 2 ], [ $list['tables'][0]['rows'], $list['tables'][0]['columns'] ]);
        $this->assertSame([ $other ], array_column($this->ok($this->read('tablepress-list-tables', [ 'search' => 'sched' ]))['result']['tables'], 'id'));
        $this->assertSame([ $other ], array_column($this->ok($this->read('tablepress-list-tables', [ 'page' => 2, 'per_page' => 1 ]))['result']['tables'], 'id'));

        $get = $this->ok($this->read('tablepress-get-table', [ 'table_id' => (int) $id, 'offset' => 1, 'limit' => 1 ]))['result'];
        $this->assertSame('Pricing', $get['name']);
        $this->assertSame([ [ 'Basic', '10' ] ], $get['data']);
        $this->assertSame([ 1, 1, 1 ], $get['visibility']['rows']);
        $this->assertSame(1, $get['options']['table_head']);

        $csv = $this->ok($this->read('tablepress-export-table', [ 'table_id' => $id, 'format' => 'csv', 'csv_delimiter' => 'tab' ]))['result'];
        $this->assertStringContainsString("\"Plan\"\t\"Price\"", $csv['content']);
        $json = $this->ok($this->read('tablepress-export-table', [ 'table_id' => $id, 'format' => 'json' ]))['result'];
        $this->assertSame([ [ 'Plan', 'Price' ], [ 'Basic', '10' ], [ 'Pro', '20' ] ], json_decode($json['content'], true));

        $html = $this->ok($this->read('tablepress-render-table', [ 'table_id' => $id ]))['result']['html'];
        $this->assertStringContainsString('<td>Basic</td>', $html);

        $this->assertSame('table_not_found', $this->code($this->read('tablepress-get-table', [ 'table_id' => '99' ])));
    }

    // ---------------------------------------------------------------
    // Create, edit, rectangular shape, cache and rollback
    // ---------------------------------------------------------------

    public function test_create_stores_the_table_the_way_tablepress_does_and_rollback_session_removes_it(): void
    {
        $out = $this->ok($this->write('tablepress-create-table', [ 'name' => 'Grid', 'description' => 'd', 'rows' => 2, 'columns' => 3, 'options' => [ 'table_head' => 0 ] ]));
        $id  = (string) $out['result']['table']['id'];
        $this->assertSame('1', $id);
        $this->assertCount(2, $out['operation_ids']);
        $this->assertSame([ [ '', '', '' ], [ '', '', '' ] ], $this->data($id));
        $post = get_post(TablePress_Pack::post_id_of($id));
        $this->assertSame('tablepress_table', $post->post_type);
        $this->assertSame('[["","",""],["","",""]]', $post->post_content);
        $this->assertSame(0, json_decode(get_post_meta($post->ID, '_tablepress_table_options', true), true)['table_head']);

        Rollback_Service::restore_session($out['session_id']);
        $this->assertFalse(\TablePress::$model_table->table_exists($id));
        $this->assertSame('trash', get_post_status($post->ID));
        $this->assertSame(0, json_decode((string) get_option('tablepress_tables'), true)['last_id']);
    }

    public function test_create_refuses_rows_of_unequal_length_unknown_options_and_custom_commands_without_unfiltered_html(): void
    {
        $out = $this->write('tablepress-create-table', [ 'name' => 'Bad', 'data' => [ [ 'a', 'b' ], [ 'c' ] ] ]);
        $this->assertSame('table_not_rectangular', $this->code($out));
        $this->assertSame([ 2, 1 ], $out['error']['data']['lengths']);
        $this->assertSame('unknown_option', $this->code($this->write('tablepress-create-table', [ 'name' => 'Bad', 'options' => [ 'colour' => 'red' ] ])));
        $this->assertSame('invalid_option', $this->code($this->write('tablepress-create-table', [ 'name' => 'Bad', 'options' => [ 'table_head' => [ 1 ] ] ])));
        $this->assertSame('invalid_args', $this->code($this->write('tablepress-create-table', [ 'name' => 'Bad', 'data' => [ [ 'a' ] ], 'rows' => 2 ])));
        $this->assertSame('invalid_args', $this->code($this->write('tablepress-create-table', [ 'name' => 'Bad', 'data' => [ [] ] ])));
        $this->assertSame('table_too_large', $this->code($this->write('tablepress-create-table', [ 'name' => 'Bad', 'rows' => 1000, 'columns' => 11 ])));

        $deny = static fn (array $caps): array => array_diff_key($caps, [ 'unfiltered_html' => true ]);
        add_filter('user_has_cap', $deny);
        $out = $this->write('tablepress-create-table', [ 'name' => 'Bad', 'options' => [ 'datatables_custom_commands' => 'alert(1)' ] ]);
        remove_filter('user_has_cap', $deny);
        $this->assertSame('operation_denied', $this->code($out));
        $this->assertSame([], \TablePress::$model_table->load_all());
    }

    public function test_set_cells_edits_through_the_model_clears_the_render_cache_and_rollback_operation_restores_exactly(): void
    {
        $id     = $this->table();
        $before = $this->stored($id);
        wp_set_current_user(0);
        $this->assertStringContainsString('<td>10</td>', tablepress_get_table([ 'id' => $id ]));
        $this->assertStringContainsString('from cache', tablepress_get_table([ 'id' => $id ]));
        wp_set_current_user($this->admin);

        $out = $this->ok($this->write('tablepress-set-cells', [ 'table_id' => $id, 'cells' => [ [ 'row' => 1, 'column' => 1, 'value' => 12 ], [ 'row' => 2, 'column' => 0, 'value' => null ] ] ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame([ [ 'Plan', 'Price' ], [ 'Basic', '12' ], [ '', '20' ] ], $this->data($id));
        wp_set_current_user(0);
        $html = tablepress_get_table([ 'id' => $id ]);
        $this->assertStringContainsString('<td>12</td>', $html);
        $this->assertStringNotContainsString('from cache', $html);
        $this->assertStringContainsString('from cache', tablepress_get_table([ 'id' => $id ]));
        wp_set_current_user($this->admin);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']), implode('; ', Rollback_Service::take_warnings()));
        $this->assertSame($before, $this->stored($id));
        wp_set_current_user(0);
        $html = tablepress_get_table([ 'id' => $id ]);
        $this->assertStringContainsString('<td>10</td>', $html);
        $this->assertStringNotContainsString('from cache', $html);
    }

    public function test_set_cells_outside_the_table_is_refused_with_nothing_written(): void
    {
        $id     = $this->table();
        $before = $this->stored($id);
        $out    = $this->write('tablepress-set-cells', [ 'table_id' => $id, 'cells' => [ [ 'row' => 0, 'column' => 0, 'value' => 'x' ], [ 'row' => 3, 'column' => 0, 'value' => 'y' ] ] ]);
        $this->assertSame('cell_out_of_range', $this->code($out));
        $this->assertSame($before, $this->stored($id));
    }

    public function test_rows_insert_and_delete_keep_the_table_rectangular_and_visibility_in_sync(): void
    {
        $id = $this->table();
        $this->ok($this->write('tablepress-insert-rows', [ 'table_id' => $id, 'position' => 1, 'rows' => [ [ 'Free', 0 ] ] ]));
        $this->ok($this->write('tablepress-insert-rows', [ 'table_id' => $id, 'count' => 2 ]));
        $this->assertSame([ [ 'Plan', 'Price' ], [ 'Free', '0' ], [ 'Basic', '10' ], [ 'Pro', '20' ], [ '', '' ], [ '', '' ] ], $this->data($id));
        $this->assertSame([ 1, 1, 1, 1, 1, 1 ], \TablePress::$model_table->load($id)['visibility']['rows']);

        $this->assertSame('table_not_rectangular', $this->code($this->write('tablepress-insert-rows', [ 'table_id' => $id, 'rows' => [ [ 'only one' ] ] ])));
        $this->assertSame('invalid_args', $this->code($this->write('tablepress-insert-rows', [ 'table_id' => $id ])));
        $this->assertSame('position_out_of_range', $this->code($this->write('tablepress-insert-rows', [ 'table_id' => $id, 'count' => 1, 'position' => 7 ])));

        $this->ok($this->write('tablepress-delete-rows', [ 'table_id' => $id, 'rows' => [ 4, 5, 1 ] ]));
        $this->assertSame([ [ 'Plan', 'Price' ], [ 'Basic', '10' ], [ 'Pro', '20' ] ], $this->data($id));
        $this->assertSame([ 1, 1, 1 ], \TablePress::$model_table->load($id)['visibility']['rows']);
        $this->assertSame('table_would_be_empty', $this->code($this->write('tablepress-delete-rows', [ 'table_id' => $id, 'rows' => [ 0, 1, 2 ] ])));
        $this->assertSame('position_out_of_range', $this->code($this->write('tablepress-delete-rows', [ 'table_id' => $id, 'rows' => [ 3 ] ])));
    }

    public function test_deleting_rows_keeps_the_header_and_footer_counts_within_the_rows(): void
    {
        $id = $this->table([ [ 'h' ], [ 'b' ], [ 'f' ] ]);
        $this->ok($this->write('tablepress-update-table', [ 'table_id' => $id, 'options' => [ 'table_head' => 2, 'table_foot' => 1 ] ]));
        $this->ok($this->write('tablepress-delete-rows', [ 'table_id' => $id, 'rows' => [ 1 ] ]));
        $options = \TablePress::$model_table->load($id)['options'];
        $this->assertSame([ 2, 0 ], [ $options['table_head'], $options['table_foot'] ]);
    }

    public function test_columns_insert_and_delete_keep_the_table_rectangular_and_visibility_in_sync(): void
    {
        $id = $this->table();
        $this->ok($this->write('tablepress-insert-columns', [ 'table_id' => $id, 'position' => 1, 'columns' => [ [ 'Seats', '1', '5' ] ] ]));
        $this->ok($this->write('tablepress-insert-columns', [ 'table_id' => $id, 'count' => 1 ]));
        $this->assertSame([ [ 'Plan', 'Seats', 'Price', '' ], [ 'Basic', '1', '10', '' ], [ 'Pro', '5', '20', '' ] ], $this->data($id));
        $this->assertSame([ 1, 1, 1, 1 ], \TablePress::$model_table->load($id)['visibility']['columns']);
        $this->assertSame('table_not_rectangular', $this->code($this->write('tablepress-insert-columns', [ 'table_id' => $id, 'columns' => [ [ 'short' ] ] ])));

        $out = $this->ok($this->write('tablepress-delete-columns', [ 'table_id' => $id, 'columns' => [ 3, 0 ] ]));
        $this->assertSame([ [ 'Seats', 'Price' ], [ '1', '10' ], [ '5', '20' ] ], $this->data($id));
        $this->assertSame([ 1, 1 ], \TablePress::$model_table->load($id)['visibility']['columns']);
        $this->assertSame(2, $out['result']['table']['columns']);
        $this->assertSame('table_would_be_empty', $this->code($this->write('tablepress-delete-columns', [ 'table_id' => $id, 'columns' => [ 0, 1 ] ])));
    }

    public function test_update_table_sets_name_description_and_options_and_rollback_restores_them(): void
    {
        $id     = $this->table();
        $before = $this->stored($id);
        $out    = $this->ok($this->write('tablepress-update-table', [ 'table_id' => $id, 'name' => 'Plans', 'description' => 'June prices', 'options' => [ 'use_datatables' => false, 'datatables_custom_commands' => 'x' ] ]));
        $table  = \TablePress::$model_table->load($id);
        $this->assertSame([ 'Plans', 'June prices', false, 'x' ], [ $table['name'], $table['description'], $table['options']['use_datatables'], $table['options']['datatables_custom_commands'] ]);
        $this->assertSame('unknown_option', $this->code($this->write('tablepress-update-table', [ 'table_id' => $id, 'options' => [ 'nope' => 1 ] ])));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']), implode('; ', Rollback_Service::take_warnings()));
        $this->assertSame($before, $this->stored($id));
    }

    public function test_a_corrupted_table_is_not_edited(): void
    {
        $id = $this->table();
        wp_update_post([ 'ID' => TablePress_Pack::post_id_of($id), 'post_content' => '[[not json' ]);
        $this->assertSame('table_corrupted', $this->code($this->write('tablepress-set-cells', [ 'table_id' => $id, 'cells' => [ [ 'row' => 0, 'column' => 0, 'value' => 'x' ] ] ])));
    }

    // ---------------------------------------------------------------
    // Copy, change id, delete, import
    // ---------------------------------------------------------------

    public function test_copy_adds_a_table_and_rollback_session_removes_the_copy(): void
    {
        $id  = $this->table();
        $out = $this->ok($this->write('tablepress-copy-table', [ 'table_id' => $id ]));
        $new = (string) $out['result']['table']['id'];
        $this->assertSame('Copy of Pricing', $out['result']['table']['name']);
        $this->assertSame($this->data($id), $this->data($new));

        Rollback_Service::restore_session($out['session_id']);
        $this->assertFalse(\TablePress::$model_table->table_exists($new));
        $this->assertTrue(\TablePress::$model_table->table_exists($id));
    }

    public function test_change_table_id_moves_the_table_reports_old_placements_and_rollback_operation_puts_the_id_back(): void
    {
        $id   = $this->table();
        $page = self::factory()->post->create([ 'post_type' => 'page', 'post_content' => '[table id=' . $id . ' /]' ]);
        $this->assertSame('invalid_table_id', $this->code($this->write('tablepress-change-table-id', [ 'table_id' => $id, 'new_id' => 'a b' ])));
        $other = $this->table([ [ 'x' ] ]);
        $this->assertSame('table_id_taken', $this->code($this->write('tablepress-change-table-id', [ 'table_id' => $id, 'new_id' => $other ])));

        $out = $this->ok($this->write('tablepress-change-table-id', [ 'table_id' => $id, 'new_id' => 'pricing' ]));
        $this->assertSame([ $page ], array_column($out['result']['placements_of_old_id'], 'id'));
        $this->assertFalse(\TablePress::$model_table->table_exists($id));
        $this->assertSame('Pricing', \TablePress::$model_table->load('pricing')['name']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']), implode('; ', Rollback_Service::take_warnings()));
        $this->assertTrue(\TablePress::$model_table->table_exists($id));
        $this->assertFalse(\TablePress::$model_table->table_exists('pricing'));
    }

    public function test_delete_needs_confirm_and_rollback_session_restores_the_table_at_its_ids(): void
    {
        $id     = $this->table();
        $before = $this->stored($id);
        $this->assertSame('confirmation_required', $this->code($this->write('tablepress-delete-table', [ 'table_id' => $id ])));

        $out = $this->ok($this->write('tablepress-delete-table', [ 'table_id' => $id ], [ 'confirm' => true ]));
        $this->assertSame('Pricing', $out['result']['deleted']['name']);
        $this->assertFalse(\TablePress::$model_table->table_exists($id));
        $this->assertNull(get_post($before['post_id']));

        Rollback_Service::restore_session($out['session_id']);
        $this->assertTrue(\TablePress::$model_table->table_exists($id));
        $this->assertSame($before, $this->stored($id));
        $this->assertSame('Pricing', \TablePress::$model_table->load($id)['name']);
    }

    public function test_import_adds_replaces_and_appends_through_the_importer_and_each_rolls_back(): void
    {
        $add = $this->ok($this->write('tablepress-import-table', [ 'data' => "Plan,Price\nBasic,10", 'name' => 'Imported' ]));
        $id  = (string) $add['result']['table']['id'];
        $this->assertSame('Imported', $add['result']['table']['name']);
        $this->assertSame([ [ 'Plan', 'Price' ], [ 'Basic', '10' ] ], $this->data($id));

        $before  = $this->stored($id);
        $replace = $this->ok($this->write('tablepress-import-table', [ 'table_id' => $id, 'data' => '[["A","B","C"]]' ]));
        $this->assertSame('replace', $replace['result']['mode']);
        $this->assertSame([ [ 'A', 'B', 'C' ] ], $this->data($id));
        $this->assertSame([ 1, 1, 1 ], \TablePress::$model_table->load($id)['visibility']['columns']);
        Rollback_Service::restore_session($replace['session_id']);
        $this->assertSame($before, $this->stored($id));

        $this->ok($this->write('tablepress-import-table', [ 'table_id' => $id, 'mode' => 'append', 'data' => '<table><tr><td>Pro</td><td>20</td></tr></table>' ]));
        $this->assertSame([ [ 'Plan', 'Price' ], [ 'Basic', '10' ], [ 'Pro', '20' ] ], $this->data($id));

        Rollback_Service::restore_session($add['session_id']);
        $this->assertFalse(\TablePress::$model_table->table_exists($id));
    }

    public function test_import_refuses_mismatched_arguments_and_reports_a_failed_parse(): void
    {
        $id = $this->table();
        $this->assertSame('invalid_args', $this->code($this->write('tablepress-import-table', [ 'table_id' => $id, 'mode' => 'add', 'data' => 'a' ])));
        $this->assertSame('invalid_args', $this->code($this->write('tablepress-import-table', [ 'mode' => 'replace', 'data' => 'a' ])));
        $this->assertSame('invalid_args', $this->code($this->write('tablepress-import-table', [ 'table_id' => $id, 'name' => 'x', 'data' => 'a' ])));
        $this->assertSame('import_failed', $this->code($this->write('tablepress-import-table', [ 'data' => '[not json' ])));
    }

    // ---------------------------------------------------------------
    // Placement and usage
    // ---------------------------------------------------------------

    public function test_place_table_inserts_a_block_or_shortcode_find_usage_reports_both_and_rollback_removes_it(): void
    {
        $id     = $this->table();
        $block  = self::factory()->post->create([ 'post_type' => 'page', 'post_content' => "<!-- wp:paragraph -->\n<p>Prices</p>\n<!-- /wp:paragraph -->" ]);
        $legacy = self::factory()->post->create([ 'post_content' => 'Classic text' ]);
        $empty  = self::factory()->post->create([ 'post_content' => '' ]);

        $out = $this->ok($this->write('tablepress-place-table', [ 'table_id' => $id, 'post_id' => $block ]));
        $this->assertStringEndsWith('<!-- wp:tablepress/table {"id":"' . $id . '"} /-->', get_post($block)->post_content);
        $this->ok($this->write('tablepress-place-table', [ 'table_id' => $id, 'post_id' => $legacy, 'as' => 'shortcode', 'position' => 'start' ]));
        $this->assertStringStartsWith('[table id=' . $id . ' /]', get_post($legacy)->post_content);
        $this->ok($this->write('tablepress-place-table', [ 'table_id' => $id, 'post_id' => $empty, 'as' => 'shortcode' ]));
        $this->assertSame("<!-- wp:shortcode -->\n[table id={$id} /]\n<!-- /wp:shortcode -->", get_post($empty)->post_content);

        $usage = array_column($this->ok($this->read('tablepress-find-usage', [ 'table_id' => $id ]))['result']['posts'], 'as', 'id');
        $this->assertSame([ $block => [ 'block' ], $legacy => [ 'shortcode' ], $empty => [ 'shortcode' ] ], $usage);
        $this->assertSame([], $this->ok($this->read('tablepress-find-usage', [ 'table_id' => $id . '0' ]))['result']['posts']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']), implode('; ', Rollback_Service::take_warnings()));
        $this->assertSame("<!-- wp:paragraph -->\n<p>Prices</p>\n<!-- /wp:paragraph -->", get_post($block)->post_content);
    }

    public function test_place_table_refuses_an_elementor_page_and_a_missing_table(): void
    {
        $id   = $this->table();
        $page = self::factory()->post->create([ 'post_type' => 'page' ]);
        update_post_meta($page, '_elementor_edit_mode', 'builder');
        $this->assertSame('builder_managed', $this->code($this->write('tablepress-place-table', [ 'table_id' => $id, 'post_id' => $page ])));
        $this->assertSame('table_not_found', $this->code($this->write('tablepress-place-table', [ 'table_id' => 'nope', 'post_id' => $page ])));
    }

    // ---------------------------------------------------------------
    // Capabilities
    // ---------------------------------------------------------------

    public function test_tablepress_capabilities_gate_every_op_and_table_posts_map_onto_them(): void
    {
        $id     = $this->table();
        $editor = self::factory()->user->create([ 'role' => 'editor' ]);
        wp_set_current_user($editor);
        $this->assertSame('operation_denied', $this->code($this->read('tablepress-list-tables')));
        $this->assertSame('operation_denied', $this->code($this->write('tablepress-set-cells', [ 'table_id' => $id, 'cells' => [ [ 'row' => 0, 'column' => 0, 'value' => 'x' ] ] ])));
        $this->assertFalse(current_user_can('edit_post', TablePress_Pack::post_id_of($id)));

        // A user holding the type-wide capability but refused this one table by TablePress's own mapping.
        get_userdata($editor)->add_cap('tablepress_edit_tables');
        get_userdata($editor)->add_cap('tablepress_list_tables');
        wp_set_current_user(0);
        wp_set_current_user($editor);
        $deny = static fn (array $caps, string $cap): array => 'tablepress_edit_table' === $cap ? [ 'do_not_allow' ] : $caps;
        add_filter('map_meta_cap', $deny, 20, 2);
        $out = $this->write('tablepress-set-cells', [ 'table_id' => $id, 'cells' => [ [ 'row' => 0, 'column' => 0, 'value' => 'x' ] ] ]);
        remove_filter('map_meta_cap', $deny, 20);
        $this->assertSame('operation_denied', $this->code($out));
        $this->assertSame('capability', $out['error']['data']['reason']);

        $this->assertTrue(current_user_can('edit_post', TablePress_Pack::post_id_of($id)));
        $this->assertTrue(current_user_can('read_post', TablePress_Pack::post_id_of($id)));
        $this->assertFalse(current_user_can('delete_post', TablePress_Pack::post_id_of($id)));
        $this->assertTrue(current_user_can('edit_others_tablepress_tables'));
        $this->assertTrue(current_user_can('edit_post', self::factory()->post->create([ 'post_author' => $editor ])));
    }
}
