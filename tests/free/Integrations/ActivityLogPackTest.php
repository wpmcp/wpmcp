<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Activity_Log_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;

require_once __DIR__ . '/../../support/activity-log-stubs.php';
require_once __DIR__ . '/../../support/wsal-alert-stub.php';

/**
 * Activity logs (issue #698): read-only free-tier ops on the plugin-data
 * dispatcher pair, so they add no top-level tool.
 *
 * Neither plugin is loaded in the shared test core. Presence is driven
 * through wpmcp_wp_activity_log_active and wpmcp_simple_history_active, and
 * the storage is built the way the plugins create it
 * (tests/support/activity-log-stubs.php).
 */
class ActivityLogPackTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_activity_log_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_activity_log_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        global $wpdb;
        foreach (wpmcp_test_activity_log_tables() as $table) {
            $wpdb->query("DELETE FROM `{$table}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
        add_filter('wpmcp_wp_activity_log_active', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_wp_activity_log_active');
        remove_all_filters('wpmcp_simple_history_active');
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'] ?? $out;
    }

    public function test_the_ops_are_free_tier_reads_that_need_manage_options(): void
    {
        $ops = Activity_Log_Pack::operations();
        $this->assertSame([ 'activitylog-list-activity', 'activitylog-get-activity' ], array_keys($ops));
        foreach ($ops as $def) {
            $this->assertSame('read', $def['mode']);
            $this->assertSame('manage_options', $def['capability']);
            $this->assertArrayNotHasKey('tier', $def);
        }
    }

    public function test_list_activity_masks_personal_data_and_get_activity_returns_it_in_full(): void
    {
        $id = wpmcp_test_wsal_event([], [ 'PostTitle' => 'About', 'Email' => 'ada@example.com' ]);

        $list = $this->ok($this->read('activitylog-list-activity'));
        $this->assertSame('wp-activity-log', $list['source']);
        $this->assertSame(1, $list['total']);
        $this->assertTrue($list['masked']);
        $event = $list['events'][0];
        $this->assertSame($id, $event['id']);
        $this->assertSame('2026-11-06T21:20:00Z', $event['time']);
        $this->assertSame('203.0.*.*', $event['ip']);
        $this->assertSame('a***', $event['username'], 'a list masks the username');
        $this->assertSame(12, $event['object']['id']);
        $this->assertArrayNotHasKey('user_agent', $event);
        $this->assertArrayNotHasKey('details', $event);
        $this->assertStringNotContainsString('sess-secret', (string) wp_json_encode($list));

        $one = $this->ok($this->read('activitylog-get-activity', [ 'id' => $id ]))['event'];
        $this->assertSame('203.0.113.7', $one['ip']);
        $this->assertSame('ada', $one['username']);
        $this->assertSame('Mozilla/5.0 (X11; Linux x86_64)', $one['user_agent']);
        $this->assertSame('ada@example.com', $one['details']['Email']);
        $this->assertStringNotContainsString('sess-secret', (string) wp_json_encode($one));
    }

    public function test_list_activity_filters_and_pages_wp_activity_log(): void
    {
        wpmcp_test_wsal_event([ 'created_on' => 1794000000.0, 'user_id' => 2, 'username' => 'ada', 'post_id' => 12, 'alert_id' => 2065 ]);
        wpmcp_test_wsal_event([ 'created_on' => 1794100000.0, 'user_id' => 3, 'username' => 'bob', 'post_id' => 13, 'alert_id' => 1000 ]);
        wpmcp_test_wsal_event([ 'created_on' => 1794200000.0, 'user_id' => 3, 'username' => 'bob', 'post_id' => 14, 'alert_id' => 2065 ]);

        $all = $this->ok($this->read('activitylog-list-activity'));
        $this->assertSame([ 14, 13, 12 ], array_column(array_column($all['events'], 'object'), 'id'), 'newest first');

        $this->assertSame(2, $this->ok($this->read('activitylog-list-activity', [ 'user_id' => 3 ]))['total']);
        $this->assertSame(2, $this->ok($this->read('activitylog-list-activity', [ 'username' => 'bob' ]))['total']);
        $this->assertSame(1, $this->ok($this->read('activitylog-list-activity', [ 'object_id' => 13 ]))['total']);
        $this->assertSame(2, $this->ok($this->read('activitylog-list-activity', [ 'event' => '2065' ]))['total']);
        $dated = $this->ok($this->read('activitylog-list-activity', [ 'since' => gmdate('Y-m-d H:i:s', 1794050000), 'until' => gmdate('Y-m-d H:i:s', 1794150000) ]));
        $this->assertSame(1, $dated['total']);

        $page = $this->ok($this->read('activitylog-list-activity', [ 'page_size' => 2, 'offset' => 2 ]));
        $this->assertCount(1, $page['events']);
        $this->assertFalse($page['hasMore']);
        $this->assertTrue($this->ok($this->read('activitylog-list-activity', [ 'page_size' => 2 ]))['hasMore']);
    }

    public function test_simple_history_events_read_masked_in_a_list_and_in_full_alone(): void
    {
        remove_all_filters('wpmcp_wp_activity_log_active');
        add_filter('wpmcp_wp_activity_log_active', '__return_false');
        add_filter('wpmcp_simple_history_active', '__return_true');
        $id = wpmcp_test_simple_history_event(
            [ 'message' => 'User {user_login} ({user_email}) logged in', 'logger' => 'SimpleUserLogger' ],
            [
                '_user_id' => '2', '_user_login' => 'ada', 'user_login' => 'ada', 'user_email' => 'ada@example.com',
                '_server_remote_addr' => '2001:db8:85a3::8a2e:370:7334', '_message_key' => 'user_logged_in',
                '_server_http_user_agent' => 'Firefox/130',
            ]
        );
        wpmcp_test_simple_history_event([], [ '_user_id' => '3', '_user_login' => 'bob', 'post_id' => '55' ]);

        $list = $this->ok($this->read('activitylog-list-activity', [ 'user_id' => 2 ]));
        $this->assertSame('simple-history', $list['source']);
        $this->assertSame(1, $list['total']);
        $event = $list['events'][0];
        $this->assertSame('User a*** (a***@example.com) logged in', $event['message'], 'the username inside a message is masked too');
        $this->assertSame('2001:db8:*', $event['ip']);
        $this->assertSame('2026-11-10T09:30:00Z', $event['time']);
        $this->assertSame('SimpleUserLogger', $event['event']);
        $this->assertStringNotContainsString('Firefox', (string) wp_json_encode($list));

        $one = $this->ok($this->read('activitylog-get-activity', [ 'id' => $id ]))['event'];
        $this->assertSame('User ada (ada@example.com) logged in', $one['message']);
        $this->assertSame('2001:db8:85a3::8a2e:370:7334', $one['ip']);
        $this->assertSame('Firefox/130', $one['user_agent']);
        $this->assertSame('ada', $one['details']['_user_login']);

        $this->assertSame(1, $this->ok($this->read('activitylog-list-activity', [ 'username' => 'bob' ]))['total']);
        $this->assertSame(1, $this->ok($this->read('activitylog-list-activity', [ 'object_id' => 55 ]))['total']);
        $this->assertSame(1, $this->ok($this->read('activitylog-list-activity', [ 'event' => 'SimpleUserLogger' ]))['total']);
        $this->assertSame(0, $this->ok($this->read('activitylog-list-activity', [ 'since' => '2026-12-01' ]))['total']);
    }

    public function test_both_plugins_active_need_a_source(): void
    {
        add_filter('wpmcp_simple_history_active', '__return_true');
        wpmcp_test_wsal_event([]);
        wpmcp_test_simple_history_event([]);

        $out = $this->read('activitylog-list-activity');
        $this->assertSame('source_required', $out['error']['code'] ?? $out['code'] ?? null, (string) wp_json_encode($out));
        $this->assertSame(1, $this->ok($this->read('activitylog-list-activity', [ 'source' => 'simple-history' ]))['total']);
        $this->assertSame('wp-activity-log', $this->ok($this->read('activitylog-list-activity', [ 'source' => 'wp-activity-log' ]))['source']);
    }

    public function test_refusals_name_what_is_wrong(): void
    {
        $out = $this->read('activitylog-get-activity', [ 'id' => 99999 ]);
        $this->assertSame('activity_not_found', $out['error']['code'] ?? $out['code'] ?? null, (string) wp_json_encode($out));

        $out = $this->read('activitylog-list-activity', [ 'since' => 'not a date at all' ]);
        $this->assertSame('invalid_date', $out['error']['code'] ?? $out['code'] ?? null, (string) wp_json_encode($out));

        $out = $this->read('activitylog-list-activity', [ 'source' => 'simple-history' ]);
        $code = $out['error']['code'] ?? $out['code'] ?? null;
        $this->assertSame('plugin_missing', $code, (string) wp_json_encode($out));
        $this->assertStringContainsString('Simple History', (string) wp_json_encode($out));
    }

    public function test_without_either_plugin_the_ops_answer_plugin_missing(): void
    {
        remove_all_filters('wpmcp_wp_activity_log_active');
        add_filter('wpmcp_wp_activity_log_active', '__return_false');
        $out  = $this->read('activitylog-list-activity');
        $code = $out['error']['code'] ?? $out['code'] ?? null;
        $this->assertContains($code, [ 'plugin_missing', 'plugin_inactive' ], (string) wp_json_encode($out));
        $this->assertStringContainsString('WP Activity Log', (string) wp_json_encode($out));
    }

    /** Issue #948: a failed login's username in a Simple History message is masked in a list, full alone. */
    public function test_simple_history_failed_login_username_is_masked_in_a_list(): void
    {
        remove_all_filters('wpmcp_wp_activity_log_active');
        add_filter('wpmcp_wp_activity_log_active', '__return_false');
        add_filter('wpmcp_simple_history_active', '__return_true');
        $id = wpmcp_test_simple_history_event(
            [ 'message' => 'Failed to login with username "{login}" (incorrect password entered)', 'logger' => 'SimpleUserLogger', 'level' => 'warning', 'initiator' => 'web_user' ],
            [ 'login' => 'jane.doe', 'login_user_id' => '7', '_message_key' => 'user_login_failed', '_server_remote_addr' => '203.0.113.9' ]
        );
        wpmcp_test_simple_history_event(
            [ 'message' => 'Failed to login with username "{failed_username}" (username does not exist)', 'logger' => 'SimpleUserLogger' ],
            [ 'failed_username' => 'mallory', '_message_key' => 'user_unknown_login_failed' ]
        );
        wpmcp_test_simple_history_event(
            [ 'message' => 'Created user {created_user_login} ({created_user_email}) with role {created_user_role}', 'logger' => 'SimpleUserLogger' ],
            [ 'created_user_login' => 'newbie', 'created_user_email' => 'newbie@example.com', 'created_user_role' => 'editor' ]
        );

        $list     = $this->ok($this->read('activitylog-list-activity', [ 'event' => 'SimpleUserLogger' ]));
        $messages = array_column($list['events'], 'message', 'id');
        $this->assertSame('Failed to login with username "j***" (incorrect password entered)', $messages[ $id ]);
        $json = (string) wp_json_encode($list);
        $this->assertStringNotContainsString('jane.doe', $json);
        $this->assertStringNotContainsString('mallory', $json);
        $this->assertStringNotContainsString('newbie', $json);
        $this->assertContains('Created user n*** (n***@example.com) with role editor', $messages, 'the role stays readable');

        $one = $this->ok($this->read('activitylog-get-activity', [ 'id' => $id ]))['event'];
        $this->assertSame('Failed to login with username "jane.doe" (incorrect password entered)', $one['message']);
    }

    /** Issue #949: a 2000 (post created) event reads as the Log viewer shows it. */
    public function test_wp_activity_log_post_created_message_has_the_post_fields_one_per_line(): void
    {
        $id = wpmcp_test_wsal_event(
            [ 'alert_id' => 2000, 'post_id' => 657, 'post_type' => 'post', 'post_status' => 'draft', 'event_type' => 'created' ],
            [ 'PostTitle' => 'H6 draft', 'EditorLinkPost' => 'http://example.org/wp-admin/post.php?post=657&action=edit' ]
        );

        $expected = "Created the post H6 draft.\nPost ID: 657\nPost type: post\nPost status: draft\nView the post in editor: http://example.org/wp-admin/post.php?post=657&action=edit";
        $list     = $this->ok($this->read('activitylog-list-activity', [ 'object_id' => 657 ]));
        $this->assertSame($expected, $list['events'][0]['message']);
        $one = $this->ok($this->read('activitylog-get-activity', [ 'id' => $id ]))['event'];
        $this->assertSame($expected, $one['message']);
        $this->assertStringNotContainsString('NULL', $one['message']);
    }

    /** Issue #949: the free version's upsell and tip links are left out of a message. */
    public function test_wp_activity_log_messages_leave_out_upsell_and_tip_text(): void
    {
        $failed  = wpmcp_test_wsal_event([ 'alert_id' => 1002, 'object' => 'user', 'event_type' => 'failed-login', 'post_id' => 0 ], [ 'LoginPageURL' => 'http://example.org/wp-login.php' ]);
        $blocked = wpmcp_test_wsal_event([ 'alert_id' => 1004, 'object' => 'user', 'event_type' => 'blocked', 'post_id' => 0 ]);

        $one = $this->ok($this->read('activitylog-get-activity', [ 'id' => $failed ]))['event'];
        $this->assertSame("Failed login attempt - wrong password.\nLogin URL: http://example.org/wp-login.php", $one['message']);
        $two = $this->ok($this->read('activitylog-get-activity', [ 'id' => $blocked ]))['event'];
        $this->assertSame("Login blocked because other session(s) already exist for this user.\nIP address: 203.0.113.7", $two['message']);

        $list = (string) wp_json_encode($this->ok($this->read('activitylog-list-activity')));
        $this->assertStringNotContainsString('Premium', $list);
        $this->assertStringNotContainsString('Melapress', $list);
        $this->assertStringContainsString('IP address: 203.0.*.*', $list, 'the list still masks the IP inside a message');
    }

    public function test_masks(): void
    {
        $this->assertSame('203.0.*.*', Activity_Log_Pack::mask_ip('203.0.113.7'));
        $this->assertSame('2001:db8:*', Activity_Log_Pack::mask_ip('2001:db8::1'));
        $this->assertSame('', Activity_Log_Pack::mask_ip(''));
        $this->assertSame('j***@example.com from 10.1.*.*', Activity_Log_Pack::mask_text('jane.doe@example.com from 10.1.2.3'));
    }
}
