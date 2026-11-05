<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\Audit_Log_Page;
use WPMCP\MCP\Registrar;
use WPMCP\Plugin;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #590: the Mutations tab's Domain column was blank on every row. In
 * a wp-admin request no ability registration hook has fired, so the
 * registrar's exposed surface (all()) is empty, and List_Operations mapped
 * every tool to a null domain. This runs the screen with that empty
 * registrar, as an admin request has it, not an MCP request.
 */
class AuditLogDomainColumnTest extends \WP_UnitTestCase
{
    private ?Registrar $original = null;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $_GET = [];
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));

        // An admin page load: nothing has registered abilities yet.
        $prop           = new \ReflectionProperty(Plugin::class, 'registrar');
        $this->original = $prop->getValue(Plugin::instance());
        $prop->setValue(Plugin::instance(), new Registrar());

        $snapshot = [ 'object_type' => 'post', 'object_id' => 16, 'data' => [ 'post' => null, 'meta' => [] ] ];
        Snapshot_Store::save('op-590-a', 'sess-590', $snapshot, 'set-post-terms', str_repeat('a', 64));
        Snapshot_Store::save('op-590-b', 'sess-590', [ 'object_type' => 'option', 'object_id' => 'blogname', 'data' => [ 'name' => 'blogname', 'exists' => true, 'value' => 'x' ] ], 'update-settings', str_repeat('b', 64));
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Plugin::class, 'registrar'))->setValue(Plugin::instance(), $this->original);
        $_GET = [];
        parent::tearDown();
    }

    public function test_the_domain_column_shows_each_rows_domain_on_the_admin_screen(): void
    {
        ob_start();
        (new Audit_Log_Page())->render();
        $html = (string) ob_get_clean();

        $this->assertMatchesRegularExpression('#set-post-terms \(\#16\)</td>\s*<td>content</td>#', $html);
        $this->assertMatchesRegularExpression('#update-settings \(\#0\)</td>\s*<td>settings</td>#', $html);
    }

    public function test_get_operations_reports_the_domain_and_filters_by_it(): void
    {
        $ops = (new Audit_Log_Page())->get_operations([])['operations'];
        $by  = array_column($ops, 'domain', 'operation_id');
        $this->assertSame('content', $by['op-590-a']);
        $this->assertSame('settings', $by['op-590-b']);

        $filtered = (new Audit_Log_Page())->get_operations([ 'domain' => 'content' ])['operations'];
        $this->assertSame([ 'op-590-a' ], array_column($filtered, 'operation_id'));
    }
}
