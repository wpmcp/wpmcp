<?php

namespace WPMCP\Tests\Free\Comments;

use WPMCP\MCP\Confirmation_Required;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Tools\Comments\Moderate_Comment;

/**
 * Issue #718 follow-up: bulk moderation through moderate-comment ids[] and an
 * Akismet recheck of pending comments (status:recheck), each comment
 * snapshotted on its own so every change can be rolled back.
 */
class BulkModerateAndRecheckTest extends \WP_UnitTestCase
{
    private int $admin;

    public function setUp(): void
    {
        parent::setUp();
        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);
    }

    public function tearDown(): void
    {
        remove_all_filters('wpmcp_akismet_recheck_callback');
        parent::tearDown();
    }

    private function comment(string $approved, string $content = 'hello'): int
    {
        return self::factory()->comment->create([
            'comment_post_ID'  => self::factory()->post->create(),
            'comment_approved' => $approved,
            'comment_content'  => $content,
        ]);
    }

    /** Fake Akismet: comments whose content contains "buy" come back as spam. */
    private function fake_akismet(): void
    {
        add_filter('wpmcp_akismet_recheck_callback', static function () {
            return static function (int $id): string {
                $c = get_comment($id);
                if (false !== strpos((string) $c->comment_content, 'buy')) {
                    wp_set_comment_status($id, 'spam');
                    update_comment_meta($id, 'akismet_result', 'true');
                    return 'true';
                }
                update_comment_meta($id, 'akismet_result', 'false');
                return 'false';
            };
        });
    }

    public function test_bulk_approve_moderates_each_comment_with_its_own_operation(): void
    {
        $a = $this->comment('0');
        $b = $this->comment('0');

        $out = (new Moderate_Comment())->handle(['ids' => [ $a, $b ], 'status' => 'approve']);

        $this->assertSame(2, $out['counts']['done']);
        $this->assertSame(0, $out['counts']['failed']);
        $this->assertCount(2, $out['operation_ids']);
        $this->assertSame('1', get_comment($a)->comment_approved);
        $this->assertSame('1', get_comment($b)->comment_approved);
    }

    public function test_bulk_reports_missing_ids_and_keeps_going(): void
    {
        $a = $this->comment('0');

        $out = (new Moderate_Comment())->handle(['ids' => [ $a, 999999 ], 'status' => 'spam']);

        $this->assertSame(1, $out['counts']['done']);
        $this->assertSame(1, $out['counts']['failed']);
        $this->assertSame('spam', get_comment($a)->comment_approved);
        $failed = array_values(array_filter($out['results'], static fn ($r) => isset($r['error'])));
        $this->assertSame(999999, $failed[0]['id']);
    }

    public function test_bulk_is_capped_at_100(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Moderate_Comment())->handle(['ids' => range(1, 101), 'status' => 'approve']);
    }

    public function test_bulk_change_rolls_back_per_comment(): void
    {
        $a = $this->comment('0');
        $out = (new Moderate_Comment())->handle(['ids' => [ $a ], 'status' => 'trash']);
        $this->assertSame('trash', get_comment($a)->comment_approved);

        Rollback_Service::restore_operation($out['operation_ids'][0]);

        $this->assertSame('0', get_comment($a)->comment_approved);
    }

    public function test_recheck_needs_confirm(): void
    {
        $this->fake_akismet();
        $this->comment('0', 'buy pills');

        $this->expectException(Confirmation_Required::class);
        (new Moderate_Comment())->handle(['status' => 'recheck']);
    }

    public function test_recheck_refuses_without_akismet(): void
    {
        add_filter('wpmcp_akismet_recheck_callback', '__return_null');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Akismet/');
        (new Moderate_Comment())->handle(['status' => 'recheck', 'confirm' => true]);
    }

    public function test_recheck_queue_sends_pending_comments_and_is_undoable(): void
    {
        $this->fake_akismet();
        $spam     = $this->comment('0', 'buy pills now');
        $ham      = $this->comment('0', 'nice post');
        $approved = $this->comment('1', 'buy this too');

        $out = (new Moderate_Comment())->handle(['status' => 'recheck', 'confirm' => true]);

        $this->assertSame(2, $out['counts']['processed']);
        $this->assertSame(1, $out['counts']['spam']);
        $this->assertSame(1, $out['counts']['ham']);
        $this->assertSame('spam', get_comment($spam)->comment_approved);
        $this->assertSame('0', get_comment($ham)->comment_approved);
        $this->assertSame('1', get_comment($approved)->comment_approved, 'only pending comments are rechecked');

        $op = null;
        foreach ($out['results'] as $r) {
            if ($r['id'] === $spam) {
                $op = $r['operation_id'];
            }
        }
        Rollback_Service::restore_operation($op);
        $this->assertSame('0', get_comment($spam)->comment_approved);
        $this->assertSame('', get_comment_meta($spam, 'akismet_result', true));
    }

    public function test_recheck_with_ids_skips_comments_that_are_not_pending(): void
    {
        $this->fake_akismet();
        $pending  = $this->comment('0', 'buy');
        $approved = $this->comment('1', 'buy');

        $out = (new Moderate_Comment())->handle(['ids' => [ $pending, $approved ], 'status' => 'recheck', 'confirm' => true]);

        $this->assertSame(1, $out['counts']['spam']);
        $this->assertSame(1, $out['counts']['skipped']);
        $this->assertSame('1', get_comment($approved)->comment_approved);
    }
}
