<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\TablePress_Pack;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #481: the TablePress ops against the real TablePress.
 *
 * It runs only in the local gate's live TablePress leg (bin/test-local.sh, or
 * bin/test-local.sh --live-tablepress alone), which sets
 * WPMCP_LIVE_TABLEPRESS=1, installs TablePress from wordpress.org on a
 * separate WordPress install, loads it in tests/bootstrap.php and gives
 * administrators its capabilities the way its activation does.
 *
 * Tables made and edited through the ops must load back through TablePress's
 * own model, render through its own shortcode and block (the render cache
 * dropped after every write and every rollback), import and export through
 * its own importer and exporter, and roll back to the exact stored post and
 * options.
 *
 * Everywhere else TablePress is absent and the test is skipped; the live leg
 * runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group tablepress-live
 */
class TablePressLiveTest extends \WP_UnitTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('TABLEPRESS_ABSPATH') || ! class_exists('TablePress', false) || ! isset(\TablePress::$model_table)) {
            $this->markTestSkipped('Needs the real TablePress (bin/test-local.sh --live-tablepress, WPMCP_LIVE_TABLEPRESS=1).');
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        wp_set_current_user($this->admin);
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function call(string $half, string $op, array $args, array $extra = []): array
    {
        $call = [ 'operation' => $op, 'args' => $args ] + $extra;
        $out  = 'read' === $half ? (new Plugin_Data_Integration())->handle_read($call) : (new Plugin_Data_Integration())->handle_write($call);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function refused(string $op, array $args): string
    {
        $out = (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
        return (string) ($out['error']['code'] ?? '');
    }

    private function create(array $data, string $name = 'Pricing'): string
    {
        return (string) $this->call('write', 'tablepress-create-table', [ 'name' => $name, 'data' => $data ])['result']['table']['id'];
    }

    private function stored(string $table_id): array
    {
        $post_id = TablePress_Pack::post_id_of($table_id);
        $post    = get_post($post_id);
        return [
            'post_id'    => $post_id,
            'content'    => $post->post_content,
            'title'      => $post->post_title,
            'excerpt'    => $post->post_excerpt,
            'options'    => get_post_meta($post_id, '_tablepress_table_options', true),
            'visibility' => get_post_meta($post_id, '_tablepress_table_visibility', true),
        ];
    }

    /** TablePress's own model view of a table. */
    private function model(string $table_id): array
    {
        $table = \TablePress::$model_table->load($table_id, true, true);
        $this->assertIsArray($table, 'TablePress could not load table ' . $table_id);
        return $table;
    }

    /** What a visitor who is not logged in gets: TablePress caches it. */
    private function visitor_render(string $table_id): string
    {
        wp_set_current_user(0);
        try {
            return tablepress_get_table([ 'id' => $table_id ]);
        } finally {
            wp_set_current_user($this->admin);
        }
    }

    public function test_cell_edits_go_through_tablepress_render_at_once_and_roll_back_exactly(): void
    {
        $this->assertTrue((new Plugin_Data_Integration())->is_available());
        $id     = $this->create([ [ 'Plan', 'Price' ], [ 'Basic', '10' ], [ 'Pro', '20' ] ]);
        $before = $this->stored($id);
        $this->assertSame([ [ 'Plan', 'Price' ], [ 'Basic', '10' ], [ 'Pro', '20' ] ], $this->model($id)['data']);
        $this->assertSame([ 1, 1, 1 ], $this->model($id)['visibility']['rows']);

        // TablePress caches a visitor's render in a transient and lists the
        // transients per table; a write or rollback must drop that list.
        $cache = 'tablepress_c_' . md5($id);
        $this->assertStringContainsString('>10<', $this->visitor_render($id));
        $this->assertNotFalse(get_transient($cache));

        $out = $this->call('write', 'tablepress-set-cells', [ 'table_id' => $id, 'cells' => [ [ 'row' => 1, 'column' => 1, 'value' => '12' ] ] ]);
        $this->assertSame('12', $this->model($id)['data'][1][1]);
        $this->assertFalse(get_transient($cache));
        $this->assertStringContainsString('>12<', $this->visitor_render($id));
        $this->assertNotFalse(get_transient($cache));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']), implode('; ', Rollback_Service::take_warnings()));
        $this->assertSame($before, $this->stored($id));
        $this->assertFalse(get_transient($cache));
        $this->assertStringContainsString('>10<', $this->visitor_render($id));
    }

    public function test_rows_and_columns_stay_rectangular_through_tablepress_prepare_table(): void
    {
        $id = $this->create([ [ 'Plan', 'Price' ], [ 'Basic', '10' ] ]);
        $this->call('write', 'tablepress-insert-rows', [ 'table_id' => $id, 'rows' => [ [ 'Pro', '20' ] ] ]);
        $this->call('write', 'tablepress-insert-columns', [ 'table_id' => $id, 'position' => 1, 'columns' => [ [ 'Seats', '1', '5' ] ] ]);
        $this->call('write', 'tablepress-delete-rows', [ 'table_id' => $id, 'rows' => [ 1 ] ]);
        $out   = $this->call('write', 'tablepress-delete-columns', [ 'table_id' => $id, 'columns' => [ 2 ] ]);
        $table = $this->model($id);
        $this->assertSame([ [ 'Plan', 'Seats' ], [ 'Pro', '5' ] ], $table['data']);
        $this->assertSame([ 'rows' => [ 1, 1 ], 'columns' => [ 1, 1 ] ], $table['visibility']);
        $this->assertSame('table_not_rectangular', $this->refused('tablepress-insert-rows', [ 'table_id' => $id, 'rows' => [ [ 'a', 'b', 'c' ] ] ]));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame([ [ 'Plan', 'Seats', 'Price' ], [ 'Pro', '5', '20' ] ], $this->model($id)['data']);
    }

    public function test_update_export_and_import_use_tablepress_and_roll_back(): void
    {
        $id     = $this->create([ [ 'Plan', 'Price' ], [ 'Basic', '10' ] ]);
        $before = $this->stored($id);
        $out    = $this->call('write', 'tablepress-update-table', [ 'table_id' => $id, 'name' => 'Plans', 'options' => [ 'use_datatables' => false, 'table_head' => 1 ] ]);
        $table  = $this->model($id);
        $this->assertSame([ 'Plans', false ], [ $table['name'], $table['options']['use_datatables'] ]);
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, $this->stored($id));

        $csv = $this->call('read', 'tablepress-export-table', [ 'table_id' => $id, 'format' => 'csv' ])['result']['content'];
        $this->assertStringContainsString('Basic', $csv);
        $json = json_decode($this->call('read', 'tablepress-export-table', [ 'table_id' => $id, 'format' => 'json' ])['result']['content'], true);
        $this->assertSame([ [ 'Plan', 'Price' ], [ 'Basic', '10' ] ], $json['data'] ?? $json);
        $this->assertStringContainsString('<table', $this->call('read', 'tablepress-export-table', [ 'table_id' => $id, 'format' => 'html' ])['result']['content']);

        $add = $this->call('write', 'tablepress-import-table', [ 'data' => "Day,Open\nMon,9\nTue,10", 'name' => 'Hours' ]);
        $new = (string) $add['result']['table']['id'];
        $this->assertSame('Hours', $this->model($new)['name']);
        $this->assertSame([ [ 'Day', 'Open' ], [ 'Mon', '9' ], [ 'Tue', '10' ] ], $this->model($new)['data']);

        $this->call('write', 'tablepress-import-table', [ 'table_id' => $new, 'mode' => 'append', 'data' => '<table><tr><td>Wed</td><td>11</td></tr></table>' ]);
        $this->assertSame([ 'Wed', '11' ], $this->model($new)['data'][3]);
        $replace = $this->call('write', 'tablepress-import-table', [ 'table_id' => $new, 'data' => '[["A","B","C"]]' ]);
        $this->assertSame([ [ 'A', 'B', 'C' ] ], $this->model($new)['data']);
        Rollback_Service::restore_session($replace['session_id']);
        $this->assertSame([ 'Wed', '11' ], $this->model($new)['data'][3]);

        Rollback_Service::restore_session($add['session_id']);
        $this->assertFalse(\TablePress::$model_table->table_exists($new));
    }

    public function test_copy_change_id_and_delete_go_through_the_model_and_roll_back(): void
    {
        $id   = $this->create([ [ 'a', 'b' ] ]);
        $copy = $this->call('write', 'tablepress-copy-table', [ 'table_id' => $id ]);
        $new  = (string) $copy['result']['table']['id'];
        $this->assertSame([ [ 'a', 'b' ] ], $this->model($new)['data']);
        Rollback_Service::restore_session($copy['session_id']);
        $this->assertFalse(\TablePress::$model_table->table_exists($new));

        $moved = $this->call('write', 'tablepress-change-table-id', [ 'table_id' => $id, 'new_id' => 'live-' . $id ]);
        $this->assertSame([ [ 'a', 'b' ] ], $this->model('live-' . $id)['data']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $moved['operation_id']));
        $this->assertTrue(\TablePress::$model_table->table_exists($id));
        $this->assertFalse(\TablePress::$model_table->table_exists('live-' . $id));

        $before  = $this->stored($id);
        $deleted = $this->call('write', 'tablepress-delete-table', [ 'table_id' => $id ], [ 'confirm' => true ]);
        $this->assertFalse(\TablePress::$model_table->table_exists($id));
        Rollback_Service::restore_session($deleted['session_id']);
        $this->assertSame($before, $this->stored($id));
        $this->assertSame([ [ 'a', 'b' ] ], $this->model($id)['data']);
        $this->assertStringContainsString('>a<', $this->visitor_render($id));
    }

    public function test_a_placed_block_and_shortcode_render_the_table_and_find_usage_reports_them(): void
    {
        $id    = $this->create([ [ 'Plan', 'Price' ], [ 'Basic', '10' ] ]);
        $page  = self::factory()->post->create([ 'post_type' => 'page', 'post_content' => "<!-- wp:paragraph -->\n<p>Prices</p>\n<!-- /wp:paragraph -->" ]);
        $post  = self::factory()->post->create([ 'post_content' => 'Classic' ]);
        $this->call('write', 'tablepress-place-table', [ 'table_id' => $id, 'post_id' => $page ]);
        $this->call('write', 'tablepress-place-table', [ 'table_id' => $id, 'post_id' => $post, 'as' => 'shortcode' ]);

        $this->assertStringContainsString('>Basic<', do_blocks(get_post($page)->post_content));
        $this->assertStringContainsString('>Basic<', do_shortcode(get_post($post)->post_content));

        $usage = array_column($this->call('read', 'tablepress-find-usage', [ 'table_id' => $id ])['result']['posts'], 'as', 'id');
        $this->assertSame([ $page => [ 'block' ], $post => [ 'shortcode' ] ], $usage);
        $this->assertStringContainsString('>Basic<', $this->call('read', 'tablepress-render-table', [ 'table_id' => $id ])['result']['html']);
    }
}
