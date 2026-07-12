<?php

namespace WPMCP\Tests\Free\Revisions;

use WPMCP\Tools\Revisions\Restore_Revision;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Safety\Snapshot_Store;

class RestoreRevisionTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    public function test_restores_post_to_a_prior_revision(): void
    {
        $id = self::factory()->post->create(['post_title' => 'T0', 'post_content' => 'V0']);
        wp_update_post(['ID' => $id, 'post_title' => 'T1', 'post_content' => 'V1']);
        wp_update_post(['ID' => $id, 'post_title' => 'T2', 'post_content' => 'V2']);

        // WordPress only saves a revision on each update, capturing the state
        // as of that save; there is no revision for the original creation
        // (T0/V0). The oldest revision here is the one saved by the T1 update.
        $revisions = wp_get_post_revisions($id); // newest first
        $revisions = array_values($revisions);
        $target    = end($revisions); // the oldest revision, capturing T1/V1

        $out = (new Restore_Revision())->handle([
            'post_id'     => $id,
            'revision_id' => $target->ID,
            'session_id'  => 's1',
        ]);

        $this->assertArrayHasKey('operation_id', $out);
        $this->assertSame($id, $out['post_id']);
        $this->assertSame('T1', get_post($id)->post_title);
        $this->assertSame('V1', get_post($id)->post_content);
    }

    public function test_not_found_throws(): void
    {
        $id = self::factory()->post->create();
        $this->expectException(\InvalidArgumentException::class);
        (new Restore_Revision())->handle(['post_id' => $id, 'revision_id' => 999999]);
    }

    public function test_restore_is_undoable_via_rollback_operation(): void
    {
        $id = self::factory()->post->create(['post_title' => 'T0', 'post_content' => 'V0']);
        wp_update_post(['ID' => $id, 'post_title' => 'T1', 'post_content' => 'V1']);
        wp_update_post(['ID' => $id, 'post_title' => 'T2', 'post_content' => 'V2']);

        // WordPress only saves a revision on each update; the oldest revision
        // here is the one saved by the T1 update, capturing T1/V1.
        $revisions = wp_get_post_revisions($id); // newest first
        $revisions = array_values($revisions);
        $target    = end($revisions); // capturing T1/V1

        $pre_restore_title   = get_post($id)->post_title; // T2
        $pre_restore_content = get_post($id)->post_content; // V2

        $out = (new Restore_Revision())->handle([
            'post_id'     => $id,
            'revision_id' => $target->ID,
            'session_id'  => 's1',
        ]);

        $this->assertSame('T1', get_post($id)->post_title);
        $this->assertSame('V1', get_post($id)->post_content);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertSame($pre_restore_title, get_post($id)->post_title);
        $this->assertSame($pre_restore_content, get_post($id)->post_content);
    }
}
