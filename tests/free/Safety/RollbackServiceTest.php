<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Safety\{Mutation_Failed, Rollback_Service, Safe_Mutation, Snapshot, Snapshot_Store};
use WPMCP\Tools\Content\Delete_Post;

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

    /**
     * Regression test: wp_insert_post()'s 'import_id' is only honored if
     * that ID is still free at insert time; on a collision it silently
     * returns a NEW auto-increment ID instead. Before the fix, the
     * resurrection code ignored wp_insert_post()'s return value entirely,
     * so a rollback could land at the wrong ID with no error at all. Here
     * the original ID is reclaimed by a different post before rollback
     * runs, forcing that exact collision, and the rollback must now raise
     * Mutation_Failed instead of silently creating a wrong-ID post.
     */
    public function test_restore_operation_throws_on_import_id_collision(): void
    {
        global $wpdb;

        $id  = self::factory()->post->create(['post_title' => 'keep me', 'post_status' => 'publish']);
        $out = (new Delete_Post())->handle(['post_id' => $id, 'force' => true, 'session_id' => 's1']);
        $this->assertNull(get_post($id));

        // Reclaim the freed ID with an unrelated post, simulating the
        // collision window between the force-delete and the rollback. A
        // deliberately distant post_date_gmt (WordPress's immutable
        // creation-time identity marker) keeps this deterministic even if
        // the whole test runs within the same wall-clock second.
        $wpdb->insert($wpdb->posts, [
            'ID'            => $id,
            'post_author'   => 0,
            'post_title'    => 'someone else now owns this id',
            'post_status'   => 'publish',
            'post_type'     => 'post',
            'post_name'     => 'someone-else',
            'post_date'     => '2001-01-01 00:00:00',
            'post_date_gmt' => '2001-01-01 00:00:00',
        ]);
        clean_post_cache($id);
        $this->assertNotNull(get_post($id));
        $this->assertSame('someone else now owns this id', get_post($id)->post_title);

        $this->expectException(Mutation_Failed::class);
        try {
            Rollback_Service::restore_operation($out['operation_id']);
        } finally {
            // The colliding post must be left untouched, not silently
            // overwritten or joined with the resurrection attempt.
            $this->assertSame('someone else now owns this id', get_post($id)->post_title);
        }
    }

    public function test_apply_snapshot_restores_previous_option_value(): void
    {
        update_option('blogname', 'Original Name');
        $snapshot = Snapshot::capture('option', 'blogname');

        update_option('blogname', 'Changed Name');
        $this->assertSame('Changed Name', get_option('blogname'));

        Rollback_Service::apply_snapshot($snapshot);
        $this->assertSame('Original Name', get_option('blogname'));
    }

    public function test_apply_snapshot_deletes_option_that_did_not_exist_before(): void
    {
        delete_option('wpmcp_test_new_option');
        $snapshot = Snapshot::capture('option', 'wpmcp_test_new_option');

        update_option('wpmcp_test_new_option', 'added by mutation');
        $this->assertSame('added by mutation', get_option('wpmcp_test_new_option'));

        Rollback_Service::apply_snapshot($snapshot);
        $this->assertFalse(get_option('wpmcp_test_new_option'));
    }
}
