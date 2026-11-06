<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Admin\Audit_Log_Page;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Cron\Schedule_Event;
use WPMCP\Tools\Filesystem\File_Write_Operation;
use WPMCP\Tools\List_Operations;
use WPMCP\Tools\Packages\Activate_Plugin;
use WPMCP\Tools\Terms\Create_Term;
use WPMCP\Tools\Terms\Delete_Term;

/**
 * Issue #723: writes whose object has no numeric id (terms, files, options,
 * plugins, cron) were listed as object 0, so the Audit Log said "(#0)" and
 * list-operations named no object. Each row now carries an object_ref, and
 * the tool name is always the short one.
 */
class LedgerObjectRefTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $_GET = [];
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        wpmcp_test_grant_network_admin(get_current_user_id());
    }

    protected function tearDown(): void
    {
        deactivate_plugins([ 'akismet/akismet.php' ], true);
        $_GET = [];
        parent::tearDown();
    }

    /** @return array<string, array<string, mixed>> operation_id => row */
    private function ops(string $session): array
    {
        $ops = (new List_Operations())->handle([ 'session_id' => $session, 'limit' => 50 ])['operations'];
        return array_column($ops, null, 'operation_id');
    }

    private function audit_html(): string
    {
        ob_start();
        (new Audit_Log_Page())->render();
        return (string) ob_get_clean();
    }

    public function test_term_rows_name_the_term_in_list_operations_and_the_audit_log(): void
    {
        $created = (new Create_Term())->handle([ 'taxonomy' => 'category', 'name' => 'H2 parent', 'session_id' => 'sess-723-t' ]);
        $deleted = (new Delete_Term())->handle([ 'taxonomy' => 'category', 'term_id' => $created['term']['term_id'],'session_id' => 'sess-723-t' ]);

        $ops = $this->ops('sess-723-t');
        $this->assertSame('category:h2-parent', $ops[ $created['operation_id'] ]['object_ref']);
        $this->assertSame('category:h2-parent', $ops[ $deleted['operation_id'] ]['object_ref']);
        $this->assertSame('category:h2-parent', List_Operations::object_label($ops[ $deleted['operation_id'] ]));

        $html = $this->audit_html();
        $this->assertStringContainsString('create-term (category:h2-parent)', $html);
        $this->assertStringContainsString('delete-term (category:h2-parent)', $html);
        $this->assertStringNotContainsString('(#0)', $html);
    }

    public function test_an_option_row_names_the_option(): void
    {
        Snapshot_Store::save('op-723-o', 'sess-723-o', [ 'object_type' => 'option', 'object_id' => 'blogname', 'data' => [ 'name' => 'blogname', 'exists' => true, 'value' => 'x' ] ], 'update-option', str_repeat('a', 64));

        $this->assertSame('blogname', $this->ops('sess-723-o')['op-723-o']['object_ref']);
    }

    public function test_a_numeric_row_keeps_its_id_and_has_no_ref(): void
    {
        Snapshot_Store::save('op-723-n', 'sess-723-n', [ 'object_type' => 'post', 'object_id' => 16, 'data' => [ 'post' => null, 'meta' => [] ] ], 'update-blocks', str_repeat('a', 64));

        $op = $this->ops('sess-723-n')['op-723-n'];
        $this->assertSame(16, $op['object_id']);
        $this->assertSame('', $op['object_ref']);
        $this->assertSame('#16', List_Operations::object_label($op));
    }

    public function test_an_activate_plugin_row_names_the_plugin_not_the_option(): void
    {
        $out = (new Activate_Plugin())->handle([ 'plugin' => 'akismet/akismet.php', 'session_id' => 'sess-723-p' ]);

        $this->assertSame('akismet/akismet.php', $this->ops('sess-723-p')[ $out['operation_id'] ]['object_ref']);
        $this->assertStringContainsString('activate-plugin (akismet/akismet.php)', $this->audit_html());
    }

    public function test_a_schedule_event_row_names_the_hook(): void
    {
        $out = (new Schedule_Event())->handle([ 'hook' => 'wpmcp_test_723_hook', 'session_id' => 'sess-723-c' ]);
        $ops = $this->ops('sess-723-c');

        $this->assertCount(1, $ops);
        $this->assertSame('wpmcp_test_723_hook', array_values($ops)[0]['object_ref']);
        unset($out);
        wp_clear_scheduled_hook('wpmcp_test_723_hook');
    }

    public function test_a_file_write_row_names_the_file_not_its_operation_id(): void
    {
        $dir = wp_upload_dir()['basedir'];
        wp_mkdir_p($dir);
        $abs = $dir . '/wpmcp-723-' . getmypid() . '.txt';

        $out = File_Write_Operation::run('write-file', $abs, [ 'session_id' => 'sess-723-f' ], static function () use ($abs) {
            return file_put_contents($abs, 'hello');
        });
        @unlink($abs);

        $ref = $this->ops('sess-723-f')[ $out['operation_id'] ]['object_ref'];
        $this->assertStringEndsWith('wpmcp-723-' . getmypid() . '.txt', $ref);
        $this->assertStringNotContainsString($out['operation_id'], $ref);
    }

    public function test_a_prefixed_tool_name_is_filed_under_its_short_name(): void
    {
        Snapshot_Store::save('op-723-w', 'sess-723-w', [ 'object_type' => 'post', 'object_id' => 88, 'data' => [ 'post' => null, 'meta' => [] ] ], 'wpmcp/delete-site-part', str_repeat('a', 64));

        $this->assertSame('delete-site-part', $this->ops('sess-723-w')['op-723-w']['tool_name']);
        $this->assertSame('delete-site-part', Snapshot_Store::get_by_operation('op-723-w')['tool_name']);
        $html = $this->audit_html();
        $this->assertStringContainsString('delete-site-part (#88)', $html);
        $this->assertStringNotContainsString('wpmcp/delete-site-part', $html);
    }
}
