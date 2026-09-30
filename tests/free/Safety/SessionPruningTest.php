<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Session;

/**
 * Issue #439: snapshot pruning works on whole sessions. A bulk run writes one
 * undo point per item under one session, and a flat row cap used to delete
 * most of them on the next write anywhere, so rollback-session silently undid
 * only part of the run.
 *
 * Rules pinned here:
 * - rows in the catch-all 'default' session (and an empty one) are ordinary
 *   single writes and are pruned row by row to history_limit(), as before;
 * - a named session is kept or dropped whole, never partly;
 * - the newest named session, and the newest session larger than the limit
 *   (the last bulk run), are always kept;
 * - a dropped session makes rollback-session refuse with a reason.
 */
class SessionPruningTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        delete_option(Snapshot_Store::PRUNED_SESSIONS_OPTION);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_snapshot_history_limit');
        delete_option(Snapshot_Store::PRUNED_SESSIONS_OPTION);
        parent::tearDown();
    }

    /** One real, snapshotted post edit, pruning as every tool write does. */
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

    /** A bare ledger row, for the shape tests that need no real object. */
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

    private function rows(string $session): int
    {
        return count(Snapshot_Store::list_by_session($session));
    }

    private function content(int $post_id): string
    {
        clean_post_cache($post_id);
        return (string) get_post($post_id)->post_content;
    }

    /** Other writes on the site after a run: some in 'default', some each in their own session. */
    private function other_writes(int $count): void
    {
        $post = self::factory()->post->create(['post_content' => 'other']);
        for ($i = 0; $i < $count; $i++) {
            $this->edit($post, 0 === $i % 2 ? 'default' : wp_generate_uuid4(), "other {$i}");
        }
    }

    public function test_a_200_item_run_stays_fully_undoable_after_later_writes(): void
    {
        $posts = [];
        for ($i = 0; $i < 200; $i++) {
            $posts[] = self::factory()->post->create(['post_content' => "original {$i}"]);
        }
        foreach ($posts as $i => $post) {
            $this->edit($post, 'bulk-run-439', "bulk {$i}");
        }

        $this->other_writes(30);

        $this->assertSame(200, $this->rows('bulk-run-439'), 'No undo point of the run may be pruned');
        $this->assertSame(0, Snapshot_Store::pruned_rows_for_session('bulk-run-439'));
        $this->assertLessThanOrEqual(
            Snapshot_Store::history_limit() + 200,
            Snapshot_Store::row_count(),
            'Ordinary history stays within the limit on top of the kept run'
        );

        $out = (new Rollback_Session())->handle(['session_id' => 'bulk-run-439']);
        $this->assertSame(200, $out['restored_count']);
        foreach ($posts as $i => $post) {
            $this->assertSame("original {$i}", $this->content($post), "item {$i} of the run was not restored");
        }
    }

    public function test_ordinary_single_writes_still_prune_to_the_history_limit(): void
    {
        add_filter('wpmcp_snapshot_history_limit', static fn () => 5);
        $post = self::factory()->post->create();
        for ($i = 0; $i < 12; $i++) {
            $this->edit($post, 'default', "d {$i}");
        }
        $this->assertSame(5, Snapshot_Store::row_count());

        // One write per session is still one write: the cap holds too.
        for ($i = 0; $i < 12; $i++) {
            $this->edit($post, wp_generate_uuid4(), "s {$i}");
        }
        $this->assertSame(5, Snapshot_Store::row_count());
    }

    public function test_a_session_straddling_the_cap_is_dropped_whole_not_cut(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->row('s1', $i);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->row('s2', $i);
        }
        $this->row('s3', 1);

        // 7 rows, keep 5: the old flat prune kept one row of s1.
        $this->assertSame(3, Snapshot_Store::prune(5));
        $this->assertSame(0, $this->rows('s1'));
        $this->assertSame(3, $this->rows('s2'));
        $this->assertSame(1, $this->rows('s3'));
        $this->assertSame(3, Snapshot_Store::pruned_rows_for_session('s1'));
    }

    public function test_a_newer_bulk_run_replaces_the_previous_one(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->row('old', $i);
        }
        for ($i = 0; $i < 8; $i++) {
            $this->row('bulk-1', $i);
        }
        for ($i = 0; $i < 8; $i++) {
            $this->row('bulk-2', $i);
        }

        Snapshot_Store::prune(5);

        $this->assertSame(0, $this->rows('old'));
        $this->assertSame(0, $this->rows('bulk-1'));
        $this->assertSame(8, $this->rows('bulk-2'), 'The newest run is kept whole even though it is larger than the cap');
        $this->assertSame(8, Snapshot_Store::pruned_rows_for_session('bulk-1'));
    }

    public function test_the_last_bulk_run_survives_newer_small_sessions(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->row('bulk', $i);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->row('small-' . $i, $i);
            Snapshot_Store::prune(5);
        }

        $this->assertSame(8, $this->rows('bulk'));
        // The five newest single-row sessions fill the cap, so the oldest
        // small one goes; the bulk run is kept on top of them.
        $this->assertSame(0, $this->rows('small-0'));
        $this->assertSame(1, $this->rows('small-5'));
    }

    public function test_rollback_of_a_dropped_session_refuses_with_a_reason(): void
    {
        $post = self::factory()->post->create(['post_content' => 'original']);
        $this->edit($post, 'gone', 'edited');
        for ($i = 0; $i < 6; $i++) {
            $this->row('bulk-a', $i);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->row('bulk-b', $i);
        }
        Snapshot_Store::prune(5);
        $this->assertSame(0, $this->rows('gone'));

        try {
            (new Rollback_Session())->handle(['session_id' => 'gone']);
            $this->fail('A pruned session must not be reported as rolled back');
        } catch (Mutation_Failed $e) {
            $this->assertStringContainsString('pruned', $e->getMessage());
            $this->assertStringContainsString('gone', $e->getMessage());
        }
        $this->assertSame('edited', $this->content($post));
    }

    public function test_a_dropped_session_larger_than_one_batch_is_finished_by_the_next_prunes(): void
    {
        $big = Snapshot_Store::PRUNE_BATCH_LIMIT + 50;
        for ($i = 0; $i < $big; $i++) {
            $this->row('huge', $i);
        }
        for ($i = 0; $i < 10; $i++) {
            $this->row('newer-bulk', $i);
        }

        $this->assertSame(Snapshot_Store::PRUNE_BATCH_LIMIT, Snapshot_Store::prune(5));
        $this->assertSame(50, $this->rows('huge'));
        $this->assertSame(10, $this->rows('newer-bulk'));

        // Half deleted is already not undoable: refuse rather than undo part.
        $this->expectException(Mutation_Failed::class);
        try {
            Rollback_Service::restore_session('huge');
        } finally {
            $this->assertSame(50, Snapshot_Store::prune(5));
            $this->assertSame(0, $this->rows('huge'));
            $this->assertSame(10, $this->rows('newer-bulk'));
            $this->assertSame($big, Snapshot_Store::pruned_rows_for_session('huge'));
        }
    }

    public function test_the_default_session_is_pruned_row_by_row_and_still_rolls_back(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->row('default', $i);
        }
        $this->assertSame(3, Snapshot_Store::prune(5));
        $this->assertSame(5, $this->rows('default'));

        // The catch-all session is never whole, so it is never refused.
        $this->assertIsInt(Rollback_Service::restore_session('default'));
    }
}
