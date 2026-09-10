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
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        parent::tearDown();
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

    /**
     * A filter value that sanitizes down to the empty string (an array-valued
     * param, or markup-only text) must be treated as "no filter" rather than
     * being passed through to get_operations() as ''. Pins the sanitize-then-
     * compare ordering in filters_from_request().
     */
    public function test_render_ignores_a_filter_that_sanitizes_to_empty(): void
    {
        Snapshot_Store::save('op-1', 'sess', $this->snapshot(), 'delete-post', str_repeat('a', 64));
        $_GET['tool_name'] = '<b></b>';

        ob_start();
        (new Audit_Log_Page())->render();
        $html = ob_get_clean();

        // The row survives: no tool_name filter was applied.
        $this->assertStringContainsString('delete-post', $html);
        $this->assertStringContainsString('<input type="text" name="tool_name" placeholder="Tool name" value="" />', $html);
    }

    public function test_render_round_trips_a_real_filter_value_into_the_form(): void
    {
        Snapshot_Store::save('op-1', 'sess', $this->snapshot(), 'delete-post', str_repeat('a', 64));
        Snapshot_Store::save('op-2', 'sess', $this->snapshot(2), 'update-user', str_repeat('a', 64));
        $_GET['tool_name'] = 'update-user';

        ob_start();
        (new Audit_Log_Page())->render();
        $html = ob_get_clean();

        $this->assertStringContainsString('value="update-user"', $html);
        $this->assertStringNotContainsString('delete-post', $html);
    }

    public function test_render_ignores_an_array_valued_filter_param(): void
    {
        Snapshot_Store::save('op-1', 'sess', $this->snapshot(), 'delete-post', str_repeat('a', 64));
        $_GET['tool_name'] = ['delete-post'];

        ob_start();
        (new Audit_Log_Page())->render();
        $html = ob_get_clean();

        $this->assertStringContainsString('delete-post', $html);
        $this->assertStringContainsString('name="tool_name" placeholder="Tool name" value="" />', $html);
    }

    /**
     * The hidden page field is the registered submenu slug, not an echo of
     * whatever $_GET['page'] happened to carry.
     */
    public function test_render_filter_form_emits_the_registered_slug_not_the_request(): void
    {
        Snapshot_Store::save('op-1', 'sess', $this->snapshot(), 'delete-post', str_repeat('a', 64));
        $_GET['page'] = 'not-this-page';

        ob_start();
        (new Audit_Log_Page())->render();
        $html = ob_get_clean();

        $this->assertStringContainsString('<input type="hidden" name="page" value="' . Audit_Log_Page::SLUG . '" />', $html);
        $this->assertStringNotContainsString('not-this-page', $html);
    }
}
