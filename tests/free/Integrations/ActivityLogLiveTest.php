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

    public function test_both_plugins_active_ask_for_a_source(): void
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => 'activitylog-list-activity', 'args' => [] ]);
        $this->assertSame('source_required', $out['error']['code'] ?? $out['code'] ?? null, (string) wp_json_encode($out));
    }
}
