<?php

namespace WPMCP\Tests\Free\Comments;

use WPMCP\Tools\Comments\Delete_Comment;
use WPMCP\Tools\Comments\List_Comments;
use WPMCP\Tools\Comments\Moderate_Comment;

/**
 * Issue #718: Akismet status, masked spam lists, spam / not-spam moderation
 * through core's spam hooks (which Akismet learns from) and a confirmed,
 * snapshotted empty-spam on the existing comment tools.
 */
class AkismetSpamToolsTest extends \WP_UnitTestCase
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
        remove_all_filters('wpmcp_enable_delete_comment');
        delete_option('wordpress_api_key');
        delete_option('akismet_strictness');
        delete_option('akismet_spam_count');
        parent::tearDown();
    }

    private function comment(string $approved, string $email = 'spam@example.com'): int
    {
        return self::factory()->comment->create([
            'comment_post_ID'      => self::factory()->post->create(),
            'comment_approved'     => $approved,
            'comment_author_email' => $email,
            'comment_author_IP'    => '203.0.113.42',
        ]);
    }

    public function test_list_masks_emails_and_ips(): void
    {
        $this->comment('spam', 'spammer@example.com');
        $out = (new List_Comments())->handle(['status' => 'spam']);

        $this->assertTrue($out['masked']);
        $row = $out['comments'][0];
        $this->assertStringNotContainsString('spammer@example.com', wp_json_encode($out));
        $this->assertStringNotContainsString('203.0.113.42', wp_json_encode($out));
        $this->assertSame('s***@example.com', $row['author_email']);
        $this->assertSame('203.0.113.x', $row['author_ip']);
    }

    public function test_akismet_view_reports_inactive_when_the_presence_filter_says_so(): void
    {
        add_filter('wpmcp_akismet_active', '__return_false');

        $out = (new List_Comments())->handle(['view' => 'akismet']);

        $this->assertFalse($out['akismet']['active']);
    }

    public function test_akismet_view_reports_status_without_the_key(): void
    {
        // Drive the presence filter instead of stubbing the class: the test core
        // ships the real akismet/class.akismet.php, which another test may load.
        add_filter('wpmcp_akismet_active', '__return_true');
        update_option('wordpress_api_key', 'abc123secretkey');
        update_option('akismet_strictness', '1');
        update_option('akismet_spam_count', '57');
        $this->comment('spam');
        $this->comment('0');

        $out  = (new List_Comments())->handle(['view' => 'akismet']);
        $json = wp_json_encode($out);

        $this->assertStringNotContainsString('abc123secretkey', $json);
        $this->assertTrue($out['akismet']['active']);
        $this->assertTrue($out['akismet']['key_configured']);
        $this->assertTrue($out['akismet']['strict']);
        $this->assertSame(57, $out['akismet']['spam_caught_total']);
        $this->assertGreaterThanOrEqual(1, $out['counts']['spam']);
        $this->assertGreaterThanOrEqual(1, $out['counts']['pending']);
    }

    public function test_spam_and_not_spam_fire_core_spam_hooks(): void
    {
        $id    = $this->comment('0');
        $fired = [];
        add_action('spam_comment', function () use (&$fired) { $fired[] = 'spam'; });
        add_action('unspam_comment', function () use (&$fired) { $fired[] = 'unspam'; });

        (new Moderate_Comment())->handle(['id' => $id, 'status' => 'spam']);
        $this->assertSame('spam', get_comment($id)->comment_approved);

        $out = (new Moderate_Comment())->handle(['id' => $id, 'status' => 'not_spam']);
        $this->assertNotSame('spam', get_comment($id)->comment_approved);
        $this->assertSame(['spam', 'unspam'], $fired);
        $this->assertNotEmpty($out['operation_id']);
    }

    public function test_empty_spam_needs_opt_in_and_confirm_and_is_undoable(): void
    {
        $spam = $this->comment('spam');
        $keep = $this->comment('1');

        try {
            (new Delete_Comment())->handle(['empty_spam' => true, 'confirm' => true]);
            $this->fail('Expected opt-in refusal');
        } catch (\WPMCP\MCP\Opt_In_Required $e) {
            $this->assertNotNull(get_comment($spam));
        }

        add_filter('wpmcp_enable_delete_comment', '__return_true');
        try {
            (new Delete_Comment())->handle(['empty_spam' => true]);
            $this->fail('Expected confirm refusal');
        } catch (\WPMCP\MCP\Confirmation_Required $e) {
            $this->assertNotNull(get_comment($spam));
        }

        $out = (new Delete_Comment())->handle(['empty_spam' => true, 'confirm' => true]);
        $this->assertSame(1, $out['deleted_count']);
        $this->assertNull(get_comment($spam));
        $this->assertNotNull(get_comment($keep));
        $this->assertCount(1, $out['operation_ids']);
        $this->assertNotEmpty($out['operation_ids'][0]);
    }
}
