<?php

namespace WPMCP\Tests\Pro\Media;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Media\Optimize_Media;
use WPMCP\Tools\Media\Optimize_Media_Job;

require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';

/**
 * optimize-media background runs (issue #432): background:true queues a
 * WP-Cron job over the whole library that works through it one batch per
 * run, records progress readable by job_id, and puts every change in one
 * rollback session.
 *
 * Cron is simulated by calling the job's hook handler directly, with no
 * current user, the way WP-Cron runs it.
 */
class OptimizeMediaBackgroundTest extends \WP_UnitTestCase
{
    use OptimizeMediaFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->set_up_optimize_fixture();
        delete_option(Optimize_Media_Job::OPTION);
    }

    protected function tearDown(): void
    {
        wp_clear_scheduled_hook(Optimize_Media_Job::HOOK);
        delete_option(Optimize_Media_Job::OPTION);
        $this->tear_down_optimize_fixture();
        parent::tearDown();
    }

    /** One WP-Cron run of the job, as cron runs it: with no user logged in. */
    private function tick(int $job_id): void
    {
        $user = get_current_user_id();
        wp_set_current_user(0);
        (new Optimize_Media_Job())->handle($job_id);
        wp_set_current_user($user);
    }

    private function status(int $job_id): array
    {
        return (new Optimize_Media())->handle(['job_id' => $job_id]);
    }

    public function test_background_queues_a_job_over_the_library_and_changes_nothing_yet(): void
    {
        $ids    = [$this->upload_image(), $this->upload_image(), $this->upload_image()];
        $before = $this->file_hashes($ids[0]);

        $out = (new Optimize_Media())->handle(['background' => true, 'quality' => 40]);

        $this->assertIsInt($out['job_id']);
        $this->assertSame('queued', $out['status']);
        $this->assertSame(3, $out['total']);
        $this->assertNotSame('', $out['session_id']);
        $this->assertNotFalse(wp_next_scheduled(Optimize_Media_Job::HOOK, [$out['job_id']]));
        $this->assertSame($before, $this->file_hashes($ids[0]));
        $this->assertSame('', get_post_meta($ids[0], Optimize_Media::META_KEY, true));
    }

    public function test_a_background_run_works_in_batches_with_progress_until_done(): void
    {
        $ids = [$this->upload_image(), $this->upload_image(), $this->upload_image()];
        add_filter('wpmcp_optimize_media_batch_size', static fn () => 2);

        $job_id = (new Optimize_Media())->handle(['background' => true, 'quality' => 40])['job_id'];

        $this->tick($job_id);
        $job = $this->status($job_id);
        $this->assertSame('running', $job['status']);
        $this->assertSame(2, $job['progress']['done']);
        $this->assertSame(3, $job['progress']['total']);
        $this->assertNotFalse(wp_next_scheduled(Optimize_Media_Job::HOOK, [$job_id]), 'The job reschedules itself while work remains.');

        $this->tick($job_id);
        $job = $this->status($job_id);
        $this->assertSame('completed', $job['status']);
        $this->assertSame(3, $job['progress']['done']);
        $this->assertSame(100, $job['progress']['percent']);
        $this->assertGreaterThan(0, $job['totals']['saved_bytes']);
        $this->assertFalse(wp_next_scheduled(Optimize_Media_Job::HOOK, [$job_id]));

        foreach ($ids as $id) {
            $this->assertIsArray(get_post_meta($id, Optimize_Media::META_KEY, true), "attachment {$id} was not optimized");
        }
    }

    public function test_one_rollback_session_undoes_the_whole_run(): void
    {
        $this->require_webp();
        $ids    = [$this->upload_image(), $this->upload_image(), $this->upload_image()];
        $before = array_map([$this, 'file_hashes'], $ids);
        add_filter('wpmcp_optimize_media_batch_size', static fn () => 2);
        // A history cap below the run's size: the run must not prune its
        // own undo points while it works.
        add_filter('wpmcp_snapshot_history_limit', static fn () => 2);

        $out = (new Optimize_Media())->handle(['background' => true, 'quality' => 40, 'formats' => ['webp']]);
        $this->tick($out['job_id']);
        $this->tick($out['job_id']);
        $this->assertSame('completed', $this->status($out['job_id'])['status']);
        $this->assertFileExists(get_attached_file($ids[0]) . '.webp');
        $this->assertNotSame($before[0], $this->file_hashes($ids[0]));

        Rollback_Service::restore_session($out['session_id']);

        foreach ($ids as $i => $id) {
            $this->assertSame($before[ $i ], $this->file_hashes($id), "attachment {$id} not restored");
            $this->assertFileDoesNotExist(get_attached_file($id) . '.webp');
            $this->assertSame('', get_post_meta($id, Optimize_Media::META_KEY, true));
        }
    }

    public function test_writes_between_runs_do_not_prune_the_runs_undo_points(): void
    {
        $ids    = [$this->upload_image(), $this->upload_image(), $this->upload_image()];
        $before = array_map([$this, 'file_hashes'], $ids);
        add_filter('wpmcp_optimize_media_batch_size', static fn () => 1);
        add_filter('wpmcp_snapshot_history_limit', static fn () => 1);

        $out = (new Optimize_Media())->handle(['background' => true, 'quality' => 40]);
        for ($i = 0; $i < 3; $i++) {
            $this->tick($out['job_id']);
            if ($i < 2) {
                // Any other write on the site between two cron runs prunes.
                Snapshot_Store::prune();
            }
        }
        $this->assertSame('completed', $this->status($out['job_id'])['status']);

        Rollback_Service::restore_session($out['session_id']);

        foreach ($ids as $i => $id) {
            $this->assertSame($before[ $i ], $this->file_hashes($id), "attachment {$id} not restored");
        }
    }

    /**
     * Issue #439: once the run has finished nothing holds pruning any more,
     * and the flat cap used to delete most of the run's undo points on the
     * next writes. The finished run is one session and stays whole.
     */
    public function test_writes_after_a_finished_run_do_not_prune_the_runs_undo_points(): void
    {
        $ids    = [$this->upload_image(), $this->upload_image(), $this->upload_image()];
        $before = array_map([$this, 'file_hashes'], $ids);
        add_filter('wpmcp_optimize_media_batch_size', static fn () => 1);
        add_filter('wpmcp_snapshot_history_limit', static fn () => 1);

        $out = (new Optimize_Media())->handle(['background' => true, 'quality' => 40]);
        for ($i = 0; $i < 3; $i++) {
            $this->tick($out['job_id']);
        }
        $this->assertSame('completed', $this->status($out['job_id'])['status']);
        $this->assertFalse(Optimize_Media_Job::holds_pruning(false));

        $post = self::factory()->post->create();
        foreach (['default', 'after-run-a', 'default', 'after-run-b'] as $i => $session) {
            Safe_Mutation::run(
                ['object_type' => 'post', 'object_id' => $post, 'session_id' => $session, 'tool_name' => 'update-post', 'args' => [$i]],
                static fn () => wp_update_post(['ID' => $post, 'post_content' => "after {$i}"])
            );
        }

        Rollback_Service::restore_session($out['session_id']);

        foreach ($ids as $i => $id) {
            $this->assertSame($before[ $i ], $this->file_hashes($id), "attachment {$id} not restored");
        }
    }

    public function test_a_stalled_job_does_not_hold_pruning_forever(): void
    {
        $this->upload_image();
        $job_id = (new Optimize_Media())->handle(['background' => true])['job_id'];
        $this->assertTrue(Optimize_Media_Job::holds_pruning(false));

        $stored = get_option(Optimize_Media_Job::OPTION);
        $stored['jobs'][ $job_id ]['updated_at'] = time() - 2 * HOUR_IN_SECONDS;
        update_option(Optimize_Media_Job::OPTION, $stored);

        $this->assertFalse(Optimize_Media_Job::holds_pruning(false));
    }

    public function test_a_running_job_can_be_cancelled_and_stops(): void
    {
        $ids = [$this->upload_image(), $this->upload_image(), $this->upload_image()];
        add_filter('wpmcp_optimize_media_batch_size', static fn () => 1);

        $job_id = (new Optimize_Media())->handle(['background' => true, 'quality' => 40])['job_id'];
        $this->tick($job_id);

        $cancelled = (new Optimize_Media())->handle(['job_id' => $job_id, 'cancel' => true]);
        $this->assertSame('canceled', $cancelled['status']);
        $this->assertFalse(wp_next_scheduled(Optimize_Media_Job::HOOK, [$job_id]));

        $this->tick($job_id);
        $this->assertSame(1, $this->status($job_id)['progress']['done']);
        $this->assertSame('', get_post_meta($ids[2], Optimize_Media::META_KEY, true));
    }

    public function test_refuses_a_second_background_run_while_one_is_active(): void
    {
        $this->upload_image();
        (new Optimize_Media())->handle(['background' => true]);

        $this->expectException(\InvalidArgumentException::class);
        (new Optimize_Media())->handle(['background' => true]);
    }

    public function test_background_does_not_take_a_media_id(): void
    {
        $id = $this->upload_image();
        $this->expectException(\InvalidArgumentException::class);
        (new Optimize_Media())->handle(['background' => true, 'media_id' => $id]);
    }

    public function test_background_defers_to_an_active_optimizer_without_queuing(): void
    {
        $this->upload_image();
        $this->pin_optimizer_plugin('Acme Image Compressor');

        $out = (new Optimize_Media())->handle(['background' => true]);

        $this->assertSame(['Acme Image Compressor'], $out['deferred_to']);
        $this->assertArrayNotHasKey('job_id', $out);
        $this->assertSame([], (array) get_option(Optimize_Media_Job::OPTION, []));
    }

    public function test_a_run_that_meets_a_newly_active_optimizer_stops_with_the_reason(): void
    {
        $this->upload_image();
        $job_id = (new Optimize_Media())->handle(['background' => true])['job_id'];
        $this->pin_optimizer_plugin('Acme Image Compressor');

        $this->tick($job_id);

        $job = $this->status($job_id);
        $this->assertSame('failed', $job['status']);
        $this->assertStringContainsString('Acme Image Compressor', (string) $job['error']);
        $this->assertFalse(wp_next_scheduled(Optimize_Media_Job::HOOK, [$job_id]));
    }

    public function test_another_user_cannot_read_or_cancel_the_job(): void
    {
        $this->upload_image();
        $job_id = (new Optimize_Media())->handle(['background' => true])['job_id'];

        wp_set_current_user(self::factory()->user->create(['role' => 'author']));
        $this->expectException(\InvalidArgumentException::class);
        (new Optimize_Media())->handle(['job_id' => $job_id, 'cancel' => true]);
    }

    public function test_an_unknown_job_is_an_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Optimize_Media())->handle(['job_id' => 424242]);
    }
}
