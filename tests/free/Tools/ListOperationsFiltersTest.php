<?php

namespace WPMCP\Tests\Free\Tools;

use WPMCP\Tools\List_Operations;
use WPMCP\Safety\Snapshot_Store;

class ListOperationsFiltersTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        // See WPMCP\Tests\Free\PluginAbilitiesTest for why this is needed:
        // wp_abilities_api_init fires lazily on first registry access, and
        // the domain filter here resolves tool_name -> domain via the
        // Plugin's shared Registrar, which is only populated once that hook
        // has fired.
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    private function snapshot(int $objectId = 1): array
    {
        return ['object_type' => 'post', 'object_id' => $objectId, 'data' => ['post' => null, 'meta' => []]];
    }

    public function test_backward_compatible_bare_limit_call_is_unchanged(): void
    {
        Snapshot_Store::save('op-1', 'sess', $this->snapshot(), 'delete-post', str_repeat('a', 64));

        $out = (new List_Operations())->handle(['limit' => 20]);

        $this->assertCount(1, $out['operations']);
        $op = $out['operations'][0];
        $this->assertSame('op-1', $op['operation_id']);
        $this->assertSame('sess', $op['session_id']);
        $this->assertSame('delete-post', $op['tool_name']);
        $this->assertSame('post', $op['object_type']);
        $this->assertArrayHasKey('object_id', $op);
        $this->assertArrayHasKey('created_at', $op);
    }

    public function test_filters_by_tool_name_and_reports_total_count(): void
    {
        Snapshot_Store::save('op-1', 'sess', $this->snapshot(), 'delete-post', str_repeat('a', 64));
        Snapshot_Store::save('op-2', 'sess', $this->snapshot(), 'update-user', str_repeat('a', 64));
        Snapshot_Store::save('op-3', 'sess', $this->snapshot(), 'delete-post', str_repeat('a', 64));

        $out = (new List_Operations())->handle(['tool_name' => 'delete-post']);

        $this->assertCount(2, $out['operations']);
        $this->assertSame(2, $out['total_count']);
        foreach ($out['operations'] as $op) {
            $this->assertSame('delete-post', $op['tool_name']);
        }
    }

    public function test_filters_by_user_id(): void
    {
        $user_a = self::factory()->user->create();
        $user_b = self::factory()->user->create();

        wp_set_current_user($user_a);
        Snapshot_Store::save('op-1', 'sess', $this->snapshot(), 'delete-post', str_repeat('a', 64));
        wp_set_current_user($user_b);
        // Each user's own session: since issue #461 a user may not write
        // into a session another user started.
        Snapshot_Store::save('op-2', 'sess-b', $this->snapshot(), 'delete-post', str_repeat('a', 64));

        // Listed by someone who may see every user's rows.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $out = (new List_Operations())->handle(['user_id' => $user_b]);

        $this->assertCount(1, $out['operations']);
        $this->assertSame($user_b, $out['operations'][0]['user_id']);
    }

    public function test_filters_by_date_range(): void
    {
        global $wpdb;
        Snapshot_Store::save('op-1', 'sess', $this->snapshot(), 'delete-post', str_repeat('a', 64));
        $wpdb->update(Snapshot_Store::table_name(), ['created_at' => '2020-01-01 00:00:00'], ['operation_id' => 'op-1']);

        Snapshot_Store::save('op-2', 'sess', $this->snapshot(), 'delete-post', str_repeat('a', 64));
        $wpdb->update(Snapshot_Store::table_name(), ['created_at' => '2021-06-15 12:00:00'], ['operation_id' => 'op-2']);

        $out = (new List_Operations())->handle(['date_from' => '2021-01-01', 'date_to' => '2021-12-31']);

        $this->assertCount(1, $out['operations']);
        $this->assertSame('op-2', $out['operations'][0]['operation_id']);
    }

    public function test_filters_by_object_type_and_object_id(): void
    {
        Snapshot_Store::save('op-1', 'sess', $this->snapshot(42), 'delete-post', str_repeat('a', 64));
        Snapshot_Store::save('op-2', 'sess', $this->snapshot(99), 'delete-post', str_repeat('a', 64));

        $out = (new List_Operations())->handle(['object_type' => 'post', 'object_id' => 42]);

        $this->assertCount(1, $out['operations']);
        $this->assertSame('op-1', $out['operations'][0]['operation_id']);
    }

    public function test_filters_by_domain_using_registered_ability_lookup(): void
    {
        Snapshot_Store::save('op-1', 'sess', $this->snapshot(), 'delete-post', str_repeat('a', 64));
        Snapshot_Store::save('op-2', 'sess', $this->snapshot(), 'update-user', str_repeat('a', 64));

        $out = (new List_Operations())->handle(['domain' => 'content']);

        $this->assertCount(1, $out['operations']);
        $this->assertSame('op-1', $out['operations'][0]['operation_id']);
        $this->assertSame('content', $out['operations'][0]['domain']);
    }

    public function test_never_leaks_before_blob(): void
    {
        Snapshot_Store::save('op-1', 'sess', $this->snapshot(), 'delete-post', str_repeat('a', 64));

        $out = (new List_Operations())->handle([]);

        $this->assertArrayNotHasKey('before_blob', $out['operations'][0]);
    }

    public function test_rollback_available_is_true_for_known_object_types(): void
    {
        Snapshot_Store::save('op-1', 'sess', $this->snapshot(), 'delete-post', str_repeat('a', 64));

        $out = (new List_Operations())->handle([]);

        $this->assertTrue($out['operations'][0]['rollback_available']);
    }
}
