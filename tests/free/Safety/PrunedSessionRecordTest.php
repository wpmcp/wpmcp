<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\Sync\Change_Set_Builder;

/**
 * Issue #442: telling "this session was pruned" from "this session never
 * existed" must not depend on the session still being among the last few
 * pruned ones. The per-session prune counts are kept for only the newest
 * sessions, so a run dropped long ago used to fall out of that record and
 * rollback-session then answered "0 restored" as if there had been nothing
 * to undo.
 */
class PrunedSessionRecordTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        delete_option(Snapshot_Store::PRUNED_SESSIONS_OPTION);
        delete_option(Snapshot_Store::PRUNED_SESSION_FILTER_OPTION);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        delete_option(Snapshot_Store::PRUNED_SESSIONS_OPTION);
        delete_option(Snapshot_Store::PRUNED_SESSION_FILTER_OPTION);
        parent::tearDown();
    }

    private function edit(int $post_id, string $session, string $content): void
    {
        Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => $session,
                'tool_name'   => 'update-post',
                'args'        => ['content' => $content],
            ],
            static fn () => wp_update_post(['ID' => $post_id, 'post_content' => $content], true)
        );
    }

    private function row(string $session, int $object_id): void
    {
        Snapshot_Store::save(
            wp_generate_uuid4(),
            $session,
            ['object_type' => 'post', 'object_id' => $object_id, 'data' => ['post' => null, 'meta' => []]],
            'update-blocks',
            str_repeat('a', 64)
        );
    }

    /** Drop 'session' whole, then push $others single-row sessions through the pruner after it. */
    private function drop_then_bury(string $session, int $others): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->row('bulk-newer', $i);
        }
        Snapshot_Store::prune(5);
        $this->assertSame([], Snapshot_Store::list_by_session($session), 'Precondition: the run was dropped');

        for ($i = 0; $i < $others; $i++) {
            $this->row('single-' . $i, $i);
            Snapshot_Store::prune(5);
        }
        $this->assertSame(
            0,
            Snapshot_Store::pruned_rows_for_session($session),
            'Precondition: the run has aged out of the recent per-session counts'
        );
    }

    public function test_a_dropped_run_still_refuses_after_more_than_100_other_sessions_were_pruned(): void
    {
        $post = self::factory()->post->create(['post_content' => 'original']);
        $this->edit($post, 'old-run-442', 'edited');
        $this->edit($post, 'old-run-442', 'edited twice');

        $this->drop_then_bury('old-run-442', 130);

        try {
            (new Rollback_Session())->handle(['session_id' => 'old-run-442']);
            $this->fail('A run pruned long ago must be refused, not reported as nothing to undo');
        } catch (Mutation_Failed $e) {
            $this->assertStringContainsString('pruned', $e->getMessage());
            $this->assertStringContainsString('old-run-442', $e->getMessage());
        }
        clean_post_cache($post);
        $this->assertSame('edited twice', get_post($post)->post_content);
    }

    public function test_rolling_back_a_session_that_never_existed_says_so(): void
    {
        $this->drop_then_bury('some-other-run', 10);

        $out = (new Rollback_Session())->handle(['session_id' => 'never-existed-442']);

        $this->assertSame(0, $out['restored_count']);
        $this->assertNotEmpty($out['warnings'], 'An unknown session must be reported, not answered with a bare 0');
        $this->assertStringContainsString('never-existed-442', implode(' ', $out['warnings']));
        $this->assertStringContainsString('No undo points', implode(' ', $out['warnings']));
    }

    public function test_the_catch_all_session_is_never_reported_as_unknown(): void
    {
        $out = (new Rollback_Session())->handle(['session_id' => 'default']);

        $this->assertSame(0, $out['restored_count']);
        $this->assertSame([], $out['warnings']);
    }

    public function test_a_change_set_of_a_long_pruned_session_is_reported_as_truncated(): void
    {
        $post = self::factory()->post->create();
        $this->row('old-build-442', $post);
        $this->row('old-build-442', $post);

        $this->drop_then_bury('old-build-442', 130);

        $set = (new Change_Set_Builder())->build(['session_id' => 'old-build-442']);

        $this->assertCount(0, $set['objects']);
        $this->assertTrue($set['truncated']['truncated'], 'An empty change set of a pruned session is not a complete one');
        $this->assertStringContainsString('pruned', (string) $set['truncated']['reason']);
    }

    public function test_the_record_stays_small_however_many_sessions_are_pruned(): void
    {
        $this->drop_then_bury('first', 130);

        $size = strlen((string) maybe_serialize(get_option(Snapshot_Store::PRUNED_SESSIONS_OPTION)))
            + strlen((string) maybe_serialize(get_option(Snapshot_Store::PRUNED_SESSION_FILTER_OPTION)));
        $this->assertLessThan(32 * 1024, $size, 'The prune record is bounded, not a growing list');
    }
}
