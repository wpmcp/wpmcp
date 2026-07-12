<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\Audit_Log_Page;
use WPMCP\Safety\Snapshot_Store;

class AuditLogPageTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    private function snapshot(int $objectId = 1): array
    {
        return ['object_type' => 'post', 'object_id' => $objectId, 'data' => ['post' => null, 'meta' => []]];
    }

    public function test_get_operations_returns_rows_with_no_filters(): void
    {
        Snapshot_Store::save('op-1', 'sess', $this->snapshot(), 'delete-post', str_repeat('a', 64));

        $out = (new Audit_Log_Page())->get_operations([]);

        $this->assertCount(1, $out['operations']);
        $this->assertSame('op-1', $out['operations'][0]['operation_id']);
    }

    public function test_get_operations_applies_tool_name_filter(): void
    {
        Snapshot_Store::save('op-1', 'sess', $this->snapshot(), 'delete-post', str_repeat('a', 64));
        Snapshot_Store::save('op-2', 'sess', $this->snapshot(), 'update-user', str_repeat('a', 64));

        $out = (new Audit_Log_Page())->get_operations(['tool_name' => 'update-user']);

        $this->assertCount(1, $out['operations']);
        $this->assertSame('op-2', $out['operations'][0]['operation_id']);
    }

    public function test_get_operations_never_leaks_before_blob(): void
    {
        Snapshot_Store::save('op-1', 'sess', $this->snapshot(), 'delete-post', str_repeat('a', 64));

        $out = (new Audit_Log_Page())->get_operations([]);

        $this->assertArrayNotHasKey('before_blob', $out['operations'][0]);
    }

    public function test_render_outputs_a_row_per_operation(): void
    {
        Snapshot_Store::save('op-1', 'sess', $this->snapshot(), 'delete-post', str_repeat('a', 64));

        ob_start();
        (new Audit_Log_Page())->render();
        $html = ob_get_clean();

        $this->assertStringContainsString('delete-post', $html);
        $this->assertStringContainsString('wpmcp-restore', $html);
    }

    public function test_render_escapes_tool_name_output(): void
    {
        // tool_name is attacker-influenced in principle (stored from the
        // ability that ran); render() must escape it rather than echo raw.
        Snapshot_Store::save('op-1', 'sess', $this->snapshot(), '<script>alert(1)</script>', str_repeat('a', 64));

        ob_start();
        (new Audit_Log_Page())->render();
        $html = ob_get_clean();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }
}
