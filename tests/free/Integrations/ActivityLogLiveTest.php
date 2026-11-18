<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;

/**
 * Issue #698: the activity log ops against the real WP Activity Log and
 * Simple History, both free on wordpress.org.
 *
 * It runs only in the local gate's live activity log leg
 * (bin/test-local.sh --live-activity-log), which sets
 * WPMCP_LIVE_ACTIVITY_LOG=1, installs both plugins from wordpress.org onto a
 * separate WordPress install and loads them in tests/bootstrap.php. Events
 * are stored through the plugins' own code (WP Activity Log's
 * Occurrences_Entity::store_record(), Simple History's SimpleLogger), and
 * the ops read them back, so they agree with the plugins on both sides.
 *
 * Everywhere else the plugins are absent and the test is skipped; the live
 * leg runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group activity-log-live
 */
class ActivityLogLiveTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('WSAL_VERSION') || ! defined('SIMPLE_HISTORY_VERSION') || ! class_exists('\\WSAL\\Entities\\Occurrences_Entity') || ! function_exists('SimpleLogger')) {
            $this->markTestSkipped('Needs the real WP Activity Log and Simple History (bin/test-local.sh --live-activity-log).');
        }
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'];
    }

    public function test_a_wp_activity_log_event_is_listed_masked_and_read_in_full(): void
    {
        \WSAL\Entities\Occurrences_Entity::store_record(
            [
                'Username'      => 'ada',
                'CurrentUserID' => 2,
                'ClientIP'      => '203.0.113.7',
                'UserAgent'     => 'Mozilla/5.0 live',
                'Severity'      => 200,
                'Object'        => 'user',
                'EventType'     => 'login',
                'SessionID'     => 'live-session-secret',
                'PostID'        => 0,
                'CurrentUserRoles' => [ 'administrator' ],
            ],
            1000,
            microtime(true),
            get_current_blog_id()
        );

        $list = $this->read('activitylog-list-activity', [ 'source' => 'wp-activity-log', 'username' => 'ada' ]);
        $this->assertSame(1, $list['total'], (string) wp_json_encode($list));
        $event = $list['events'][0];
        $this->assertSame('1000', $event['event']);
        $this->assertSame('203.0.*.*', $event['ip']);
        $this->assertStringNotContainsString('WP Activity Log event', $event['message'], 'the plugin\'s own wording is used');
        $this->assertArrayNotHasKey('user_agent', $event);
        $this->assertStringNotContainsString('live-session-secret', (string) wp_json_encode($list));

        $one = $this->read('activitylog-get-activity', [ 'source' => 'wp-activity-log', 'id' => $event['id'] ])['event'];
        $this->assertSame('203.0.113.7', $one['ip']);
        $this->assertSame('Mozilla/5.0 live', $one['user_agent']);
        $this->assertSame('administrator', $one['user_roles']);
        $this->assertStringNotContainsString('live-session-secret', (string) wp_json_encode($one));
    }

    public function test_a_simple_history_event_is_listed_masked_and_read_in_full(): void
    {
        SimpleLogger()->info('Mail to {recipient} was queued', [ 'recipient' => 'ada@example.com', '_user_id' => 2, '_user_login' => 'ada', '_server_remote_addr' => '203.0.113.9' ]);

        $list = $this->read('activitylog-list-activity', [ 'source' => 'simple-history', 'username' => 'ada' ]);
        $this->assertGreaterThanOrEqual(1, $list['total'], (string) wp_json_encode($list));
        $event = $list['events'][0];
        $this->assertSame('Mail to a***@example.com was queued', $event['message']);
        $this->assertStringNotContainsString('203.0.113.9', (string) wp_json_encode($list));

        $one = $this->read('activitylog-get-activity', [ 'source' => 'simple-history', 'id' => $event['id'] ])['event'];
        $this->assertSame('Mail to ada@example.com was queued', $one['message']);
        $this->assertSame('203.0.113.9', $one['ip']);
    }

    /** Issue #949: a 2000 (post created) event reads as the Log viewer shows it, without upsell text. */
    public function test_a_wp_activity_log_post_created_event_has_its_post_fields_one_per_line(): void
    {
        $post = self::factory()->post->create([ 'post_title' => 'H6 draft', 'post_status' => 'draft' ]);
        \WSAL\Entities\Occurrences_Entity::store_record(
            [
                'Username'       => 'ada',
                'CurrentUserID'  => 2,
                'ClientIP'       => '203.0.113.7',
                'UserAgent'      => 'Mozilla/5.0 live',
                'Severity'       => 200,
                'Object'         => 'post',
                'EventType'      => 'created',
                'PostID'         => $post,
                'PostType'       => 'post',
                'PostStatus'     => 'draft',
                'PostTitle'      => 'H6 draft',
                'EditorLinkPost' => admin_url('post.php?post=' . $post . '&action=edit'),
            ],
            2000,
            microtime(true),
            get_current_blog_id()
        );

        $list    = $this->read('activitylog-list-activity', [ 'source' => 'wp-activity-log', 'object_id' => $post ]);
        $message = $list['events'][0]['message'] ?? '';
        $this->assertStringContainsString("Post ID: {$post}\nPost type: post\nPost status: draft", $message, (string) wp_json_encode($list));
        $this->assertStringNotContainsString('NULL', $message);
        $this->assertStringNotContainsString('Premium', $message);
        $this->assertStringNotContainsString('Melapress', $message);
    }

    /** Issue #948: Simple History's failed-login username is masked in a list, full alone. */
    public function test_a_simple_history_failed_login_username_is_masked_in_a_list(): void
    {
        SimpleLogger()->warning('Failed to login with username "{login}" (incorrect password entered)', [ 'login' => 'jane.doe', '_message_key' => 'user_login_failed' ]);

        $list  = $this->read('activitylog-list-activity', [ 'source' => 'simple-history', 'page_size' => 10 ]);
        $found = array_values(array_filter($list['events'], static fn (array $e): bool => false !== strpos($e['message'], 'Failed to login')));
        $this->assertNotEmpty($found, (string) wp_json_encode($list));
        $this->assertSame('Failed to login with username "j***" (incorrect password entered)', $found[0]['message']);

        $one = $this->read('activitylog-get-activity', [ 'source' => 'simple-history', 'id' => $found[0]['id'] ])['event'];
        $this->assertStringContainsString('"jane.doe"', $one['message']);
    }

    public function test_both_plugins_active_ask_for_a_source(): void
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => 'activitylog-list-activity', 'args' => [] ]);
        $this->assertSame('source_required', $out['error']['code'] ?? $out['code'] ?? null, (string) wp_json_encode($out));
    }
}
