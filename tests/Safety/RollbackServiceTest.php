<?php

namespace WPMCP\Tests\Safety;

use WPMCP\Safety\{Rollback_Service, Safe_Mutation, Snapshot_Store};

class RollbackServiceTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    private function edit(int $id, string $to, string $sess): array
    {
        return Safe_Mutation::run(
            ['object_type' => 'post', 'object_id' => $id, 'session_id' => $sess, 'tool_name' => 'update-blocks', 'args' => []],
            function () use ($id, $to) {
                wp_update_post(['ID' => $id, 'post_content' => $to]);
                return 'ok';
            }
        );
    }

    public function test_restore_operation(): void
    {
        $id = self::factory()->post->create(['post_content' => 'V0']);
        $op = $this->edit($id, 'V1', 's');
        $this->assertTrue(Rollback_Service::restore_operation($op['operation_id']));
        $this->assertSame('V0', get_post($id)->post_content);
        $this->assertFalse(Rollback_Service::restore_operation('nope'));
    }

    public function test_restore_session_unwinds_all(): void
    {
        $id = self::factory()->post->create(['post_content' => 'V0']);
        $this->edit($id, 'V1', 'sess');
        $this->edit($id, 'V2', 'sess');
        $this->edit($id, 'V3', 'sess');
        $this->assertSame(3, Rollback_Service::restore_session('sess'));
        $this->assertSame('V0', get_post($id)->post_content);
    }

    /**
     * Regression test: a mutation that ADDS a new post-meta key must be fully
     * undone by restore_operation(), including deleting the newly-added key.
     * The brief's sample apply_snapshot() is additive-only for meta (it
     * re-adds snapshotted values but never deletes keys that didn't exist in
     * the snapshot), which leaves an orphan meta key behind after "rollback".
     * That violates the safety invariant that a restored object must match
     * its pre-mutation state EXACTLY.
     */
    public function test_restore_operation_purges_newly_added_meta_key(): void
    {
        $id = self::factory()->post->create(['post_content' => 'V0']);

        $op = Safe_Mutation::run(
            ['object_type' => 'post', 'object_id' => $id, 'session_id' => 'meta-sess', 'tool_name' => 'add-meta', 'args' => []],
            function () use ($id) {
                add_post_meta($id, 'brand_new_key', 'brand_new_value');
                return 'ok';
            }
        );

        // Sanity check: the meta key exists right after the mutation.
        $this->assertSame('brand_new_value', get_post_meta($id, 'brand_new_key', true));

        $this->assertTrue(Rollback_Service::restore_operation($op['operation_id']));

        // The key must be entirely gone, not merely emptied.
        $this->assertSame('', get_post_meta($id, 'brand_new_key', true));
        $this->assertArrayNotHasKey('brand_new_key', get_post_meta($id));
    }
}
