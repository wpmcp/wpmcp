<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Cron_Registry;
use WPMCP\Integrations\Action_Scheduler_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Action Scheduler (issue #702): free-tier ops on the plugin-data dispatcher
 * pair, so they add no top-level tools.
 *
 * These run against the Action Scheduler WooCommerce bundles, through its own
 * store, so they cover whichever store the install picks. The live group
 * (ActionSchedulerLiveTest, WPMCP_LIVE_ACTION_SCHEDULER=1) runs the same ops
 * against the standalone plugin from wordpress.org.
 *
 * The writes are off until the site opts in
 * (wpmcp_enable_action_scheduler_write); cancel is undone in a test, run-now
 * is not undoable and needs confirm:true.
 */
class ActionSchedulerPackTest extends \WP_UnitTestCase
{
    private const HOOK  = 'wpmcp_test_as_hook';
    private const GROUP = 'wpmcp-test-as';

    private const OPS = [
        'actionscheduler-list-actions'  => 'read',
        'actionscheduler-get-action'    => 'read',
        'actionscheduler-cancel-action' => 'write',
        'actionscheduler-run-action'    => 'destructive',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists('ActionScheduler') || ! function_exists('as_schedule_single_action') || ! \ActionScheduler::is_initialized()) {
            $this->markTestSkipped('Action Scheduler is not loaded in this run.');
        }
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_action_scheduler_write', '__return_true');
        $this->clear_queue();
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_action_scheduler_write');
        remove_all_filters('wpmcp_action_scheduler_active');
        remove_all_actions(self::HOOK);
        remove_all_actions(self::HOOK . '_b');
        if (class_exists('ActionScheduler') && \ActionScheduler::is_initialized()) {
            $this->clear_queue();
        }
        parent::tearDown();
    }

    private function clear_queue(): void
    {
        $store = \ActionScheduler::store();
        foreach ([ self::HOOK, self::HOOK . '_b' ] as $hook) {
            foreach ((array) $store->query_actions([ 'hook' => $hook, 'per_page' => 200 ]) as $id) {
                $store->delete_action((int) $id);
            }
        }
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && ! isset($out['error']) ? $out['result'] : $out;
    }

    private function write(string $op, array $args, bool $confirm = false): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if ($confirm) {
            $call['confirm'] = true;
        }
        return (new Plugin_Data_Integration())->handle_write($call);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function code(array $out): string
    {
        return (string) ($out['error']['code'] ?? '');
    }

    /** Queue a single action and return its id. */
    private function queue(int $in = 3600, array $args = [ 'order_id' => 7 ], string $hook = self::HOOK, string $group = self::GROUP): int
    {
        return (int) as_schedule_single_action(time() + $in, $hook, $args, $group);
    }

    // ---------------------------------------------------------------
    // Catalog and availability
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_op_with_its_mode_and_manage_options(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            $this->assertSame('manage_options', $ops[ $name ]['capability'], $name);
        }
        $this->assertTrue($ops['actionscheduler-run-action']['requires_confirm']);
    }

    public function test_while_action_scheduler_is_missing_a_call_answers_plugin_missing_with_the_plugins_link(): void
    {
        add_filter('wpmcp_action_scheduler_active', '__return_false');
        $out = $this->read('actionscheduler-list-actions');
        $this->assertContains($this->code($out), [ 'plugin_missing', 'plugin_inactive' ], (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Action Scheduler', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        $this->assertFalse($ops['actionscheduler-get-action']['dependency_met']);
    }

    public function test_presence_defaults_to_the_plugin_being_loaded(): void
    {
        $this->assertSame(class_exists('ActionScheduler') && function_exists('as_get_scheduled_actions'), Action_Scheduler_Pack::active());
    }

    public function test_every_op_takes_manage_options(): void
    {
        $id = $this->queue();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->code($this->read('actionscheduler-list-actions')));
        $this->assertSame('operation_denied', $this->code($this->read('actionscheduler-get-action', [ 'action_id' => $id ])));
        $this->assertSame('operation_denied', $this->code($this->write('actionscheduler-cancel-action', [ 'action_id' => $id ])));
        $this->assertSame('pending', \ActionScheduler::store()->get_status($id));
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_filters_by_hook_group_and_status_and_pages(): void
    {
        $a = $this->queue(100);
        $b = $this->queue(200, [ 'order_id' => 8 ]);
        $c = $this->queue(300, [ 'order_id' => 9 ], self::HOOK . '_b', 'other-group');
        \ActionScheduler::store()->cancel_action($b);

        $by_hook = $this->read('actionscheduler-list-actions', [ 'hook' => self::HOOK ]);
        $this->assertSame(2, $by_hook['total']);
        $this->assertEqualsCanonicalizing([ $a, $b ], array_column($by_hook['actions'], 'id'));

        $by_group = $this->read('actionscheduler-list-actions', [ 'group' => 'other-group' ]);
        $this->assertSame([ $c ], array_column($by_group['actions'], 'id'));

        $canceled = $this->read('actionscheduler-list-actions', [ 'hook' => self::HOOK, 'status' => 'canceled' ]);
        $this->assertSame([ $b ], array_column($canceled['actions'], 'id'));
        $this->assertSame('canceled', $canceled['actions'][0]['status']);

        $pending = $this->read('actionscheduler-list-actions', [ 'hook' => self::HOOK, 'status' => 'pending' ]);
        $this->assertSame([ $a ], array_column($pending['actions'], 'id'));
        $this->assertSame('pending', $pending['actions'][0]['status']);
        $this->assertSame(self::GROUP, $pending['actions'][0]['group']);
        $this->assertSame('single', $pending['actions'][0]['schedule']['type']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $pending['actions'][0]['scheduled_at']);

        $page1 = $this->read('actionscheduler-list-actions', [ 'hook' => self::HOOK, 'per_page' => 1, 'page' => 1, 'order' => 'asc' ]);
        $page2 = $this->read('actionscheduler-list-actions', [ 'hook' => self::HOOK, 'per_page' => 1, 'page' => 2, 'order' => 'asc' ]);
        $this->assertSame(2, $page1['total']);
        $this->assertSame([ $a ], array_column($page1['actions'], 'id'), 'oldest first with order asc');
        $this->assertSame([ $b ], array_column($page2['actions'], 'id'));
        $newest = $this->read('actionscheduler-list-actions', [ 'hook' => self::HOOK, 'per_page' => 1 ]);
        $this->assertSame([ $b ], array_column($newest['actions'], 'id'), 'newest first by default');
    }

    public function test_list_filters_by_scheduled_date_with_a_comparison(): void
    {
        $soon  = $this->queue(3600);
        $later = $this->queue(86400 * 5);
        $cut   = gmdate('Y-m-d H:i:s', time() + 86400 * 2);

        $before = $this->read('actionscheduler-list-actions', [ 'hook' => self::HOOK, 'date' => $cut ]);
        $this->assertSame([ $soon ], array_column($before['actions'], 'id'), 'date_compare defaults to <=');

        $after = $this->read('actionscheduler-list-actions', [ 'hook' => self::HOOK, 'date' => $cut, 'date_compare' => '>' ]);
        $this->assertSame([ $later ], array_column($after['actions'], 'id'));
        $this->assertSame(1, $after['total']);
    }

    public function test_list_refuses_a_bad_date_and_a_comparison_without_one(): void
    {
        $this->assertSame('invalid_date', $this->code($this->read('actionscheduler-list-actions', [ 'date' => 'next tuesday' ])));
        $this->assertSame('date_required', $this->code($this->read('actionscheduler-list-actions', [ 'date_compare' => '>' ])));
    }

    public function test_list_masks_emails_ips_and_tokens_in_args_and_get_shows_everything_but_secrets(): void
    {
        $id = $this->queue(100, [
            'email'        => 'jane.doe@example.com',
            'note'         => 'Mail jane.doe@example.com from 203.0.113.7',
            'api_token'    => 'abcd1234',
            'nested'       => [ 'password' => 'hunter2', 'label' => 'ok', 'cc' => [ 'sam@example.org' ] ],
            'auth'         => 'Bearer abcdefghijklmnop12345',
            'opaque'       => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8',
            'order_id'     => 42,
        ]);

        $list = $this->read('actionscheduler-list-actions', [ 'hook' => self::HOOK ]);
        $this->assertTrue($list['args_masked']);
        $args = $list['actions'][0]['args'];
        $json = (string) wp_json_encode($args);
        $this->assertSame('j***@example.com', $args['email']);
        $this->assertSame('Mail j***@example.com from 203.0.*.*', $args['note']);
        $this->assertSame('s***@example.org', $args['nested']['cc'][0]);
        $this->assertSame(42, $args['order_id']);
        foreach ([ 'abcd1234', 'hunter2', 'abcdefghijklmnop12345', 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8', 'jane.doe', '203.0.113.7' ] as $secret) {
            $this->assertStringNotContainsString($secret, $json, $secret);
        }
        $this->assertSame('ok', $args['nested']['label']);

        $get  = $this->ok($this->read('actionscheduler-get-action', [ 'action_id' => $id ]))['action'];
        $full = (string) wp_json_encode($get['args']);
        $this->assertSame('jane.doe@example.com', $get['args']['email'], 'the single read shows the args in full');
        $this->assertSame('Mail jane.doe@example.com from 203.0.113.7', $get['args']['note']);
        foreach ([ 'abcd1234', 'hunter2', 'abcdefghijklmnop12345', 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8' ] as $secret) {
            $this->assertStringNotContainsString($secret, $full, 'secrets stay hidden in a single read: ' . $secret);
        }
    }

    public function test_get_returns_the_action_with_its_log_entries(): void
    {
        $id = $this->queue(100, [ 'order_id' => 7 ]);
        \ActionScheduler::logger()->log($id, 'Mail to jane@example.com failed, token=abc123secret');

        $get = $this->ok($this->read('actionscheduler-get-action', [ 'action_id' => $id ]))['action'];
        $this->assertSame($id, $get['id']);
        $this->assertSame(self::HOOK, $get['hook']);
        $this->assertSame('pending', $get['status']);
        $this->assertSame(self::GROUP, $get['group']);
        $this->assertSame([ 'order_id' => 7 ], $get['args']);
        $this->assertIsInt($get['priority']);
        $messages = array_column($get['logs'], 'message');
        $this->assertNotEmpty($messages, 'the store logs the creation, and ours is there');
        $joined = implode("\n", $messages);
        $this->assertStringContainsString('Mail to jane@example.com failed', $joined);
        $this->assertStringNotContainsString('abc123secret', $joined);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string) $get['logs'][0]['date']);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string[]}> */
    public static function leak_forms(): array
    {
        return [
            'key=value text'        => [ [ 'q' => 'client_secret=abc123XYZ&my_api_key=def456UVW&ok=1' ], [ 'abc123XYZ', 'def456UVW' ] ],
            'JSON string'           => [ [ 'payload' => '{"password":"hunter2","api_key":"abcDEF","nested":{"license":"LIC-9999","name":"x"}}' ], [ 'hunter2', 'abcDEF', 'LIC-9999' ] ],
            'JSON in JSON'          => [ [ 'payload' => '{"inner":"{\\"auth_token\\":\\"deepsecret1\\"}"}' ], [ 'deepsecret1' ] ],
            'serialized string'     => [ [ 'payload' => serialize([ 'password' => 'hunter2', 'user' => [ 'nonce' => 'n0nce77', 'name' => 'x' ] ]) ], [ 'hunter2', 'n0nce77' ] ],
            'broken serialized'     => [ [ 'payload' => 's:8:"password";s:7:"hunter2";' ], [ 'hunter2' ] ],
            'nested secret key'     => [ [ 'a' => [ 'b' => [ 'client_secret' => 'zzz-top-secret', 'my_license_key' => 'LK-1234' ] ] ], [ 'zzz-top-secret', 'LK-1234' ] ],
        ];
    }

    /**
     * @dataProvider leak_forms
     * @param array<string, mixed> $args
     * @param string[]             $secrets
     */
    public function test_no_secret_form_leaks_through_list_or_get(array $args, array $secrets): void
    {
        $id   = $this->queue(100, $args);
        $list = (string) wp_json_encode($this->read('actionscheduler-list-actions', [ 'hook' => self::HOOK ]));
        $get  = (string) wp_json_encode($this->ok($this->read('actionscheduler-get-action', [ 'action_id' => $id ])));
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $list, 'list: ' . $secret);
            $this->assertStringNotContainsString($secret, $get, 'get: ' . $secret);
        }
    }

    public function test_masking_keeps_the_shape_of_json_and_serialized_args_and_never_builds_objects(): void
    {
        $this->assertSame('{"user":"jane","password":"[redacted]"}', Action_Scheduler_Pack::hide_secrets([ 'p' => '{"user":"jane","password":"x"}' ])['p']);
        $ser = Action_Scheduler_Pack::hide_secrets([ 'p' => serialize([ 'user' => 'jane', 'token' => 'x' ]) ])['p'];
        $this->assertSame([ 'user' => 'jane', 'token' => '[redacted]' ], unserialize($ser));
        $this->assertSame('j***@example.com', json_decode(Action_Scheduler_Pack::mask_args([ 'p' => '{"e":"jane@example.com"}' ])['p'], true)['e']);

        $object = 'O:8:"stdClass":1:{s:8:"password";s:7:"hunter2";}';
        $out    = Action_Scheduler_Pack::hide_secrets([ 'p' => $object ])['p'];
        $this->assertStringNotContainsString('hunter2', $out);
        $this->assertSame('O:8:"stdClass"', substr($out, 0, 14), 'an object payload is masked as text, not opened and rewritten');
    }

    public function test_list_args_are_cut_at_2kb_with_a_flag_and_get_returns_them_whole(): void
    {
        $big = str_repeat('payload-', 600);
        $id  = $this->queue(100, [ 'blob' => $big, 'order_id' => 1 ]);
        $this->queue(200, [ 'order_id' => 2 ]);

        $rows = array_column($this->read('actionscheduler-list-actions', [ 'hook' => self::HOOK ])['actions'], null, 'id');
        $this->assertTrue($rows[ $id ]['args_truncated']);
        $this->assertLessThanOrEqual(Action_Scheduler_Pack::MAX_LIST_ARGS_BYTES, strlen($rows[ $id ]['args']['preview']));
        $this->assertGreaterThan(Action_Scheduler_Pack::MAX_LIST_ARGS_BYTES, $rows[ $id ]['args_bytes']);
        $small = array_values(array_filter($rows, static fn (array $r): bool => $r['id'] !== $id))[0];
        $this->assertFalse($small['args_truncated']);
        $this->assertSame($big, $this->read('actionscheduler-get-action', [ 'action_id' => $id ])['action']['args']['blob']);
    }

    public function test_page_and_per_page_have_maximums(): void
    {
        $out = $this->read('actionscheduler-list-actions', [ 'per_page' => 101 ]);
        $this->assertArrayHasKey('error', $out);
        $out = $this->read('actionscheduler-list-actions', [ 'page' => Action_Scheduler_Pack::MAX_PAGE + 1 ]);
        $this->assertArrayHasKey('error', $out);
    }

    public function test_get_refuses_an_unknown_action(): void
    {
        $this->assertSame('action_not_found', $this->code($this->read('actionscheduler-get-action', [ 'action_id' => 999999 ])));
    }

    public function test_get_reports_recurring_schedules(): void
    {
        $interval = (int) as_schedule_recurring_action(time() + 100, 900, self::HOOK, [ 'n' => 1 ], self::GROUP);
        $cron     = (int) as_schedule_cron_action(time() + 100, '*/15 * * * *', self::HOOK, [ 'n' => 2 ], self::GROUP);

        $i = $this->read('actionscheduler-get-action', [ 'action_id' => $interval ])['action'];
        $c = $this->read('actionscheduler-get-action', [ 'action_id' => $cron ])['action'];
        $this->assertSame([ 'type' => 'interval', 'interval_seconds' => 900 ], $i['schedule']);
        $this->assertSame([ 'type' => 'cron', 'cron' => '*/15 * * * *' ], $c['schedule']);
    }

    // ---------------------------------------------------------------
    // Writes: off by default
    // ---------------------------------------------------------------

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $id = $this->queue();
        remove_all_filters('wpmcp_enable_action_scheduler_write');
        foreach ([ 'actionscheduler-cancel-action', 'actionscheduler-run-action' ] as $op) {
            $out = $this->write($op, [ 'action_id' => $id ], true);
            $this->assertSame('opt_in_required', $this->code($out), $op);
            $this->assertStringContainsString('wpmcp_enable_action_scheduler_write', $out['error']['message']);
        }
        $this->assertSame('pending', \ActionScheduler::store()->get_status($id));
    }

    // ---------------------------------------------------------------
    // Cancel
    // ---------------------------------------------------------------

    public function test_cancel_cancels_a_pending_action_and_rollback_queues_it_again_on_its_schedule(): void
    {
        $due = time() + 7200;
        $id  = (int) as_schedule_single_action($due, self::HOOK, [ 'order_id' => 7, 'who' => 'jane@example.com' ], self::GROUP, false, 3);

        $out = $this->ok($this->write('actionscheduler-cancel-action', [ 'action_id' => $id ]));
        $this->assertTrue($out['recoverable']);
        $this->assertNotEmpty($out['operation_id']);
        $this->assertSame('canceled', $out['result']['status']);
        $this->assertSame('canceled', \ActionScheduler::store()->get_status($id));
        $snapshot = Snapshot_Store::get_by_operation($out['operation_id'])['snapshot'];
        $this->assertSame('action_scheduler', $snapshot['object_type']);
        $this->assertSame((string) $id, $snapshot['object_id']);
        $this->assertSame('pending', $snapshot['data']['before']['status']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $pending = (array) \ActionScheduler::store()->query_actions([ 'hook' => self::HOOK, 'status' => 'pending' ]);
        $this->assertCount(1, $pending, 'exactly one pending action is back');
        $new = (int) $pending[0];
        $this->assertNotSame($id, $new, 'the store cannot reopen a canceled action, so it is queued again');
        $action = \ActionScheduler::store()->fetch_action($new);
        $this->assertSame([ 'order_id' => 7, 'who' => 'jane@example.com' ], $action->get_args());
        $this->assertSame(self::GROUP, $action->get_group());
        $this->assertSame(3, $action->get_priority());
        $this->assertSame($due, \ActionScheduler::store()->get_date($new)->getTimestamp());
        $this->assertSame('canceled', \ActionScheduler::store()->get_status($id), 'the canceled record stays');
    }

    public function test_the_undo_of_a_recurring_and_a_cron_action_keeps_the_interval_and_the_expression(): void
    {
        $interval = (int) as_schedule_recurring_action(time() + 500, 900, self::HOOK, [ 'n' => 1 ], self::GROUP);
        $cron     = (int) as_schedule_cron_action(time() + 500, '*/15 * * * *', self::HOOK . '_b', [ 'n' => 2 ], self::GROUP);

        $a = $this->ok($this->write('actionscheduler-cancel-action', [ 'action_id' => $interval ]));
        $b = $this->ok($this->write('actionscheduler-cancel-action', [ 'action_id' => $cron ]));
        $this->assertSame([], (array) \ActionScheduler::store()->query_actions([ 'hook' => self::HOOK, 'status' => 'pending' ]));

        $this->assertTrue(Rollback_Service::restore_operation($a['operation_id']));
        $this->assertTrue(Rollback_Service::restore_operation($b['operation_id']));

        $i = (int) ((array) \ActionScheduler::store()->query_actions([ 'hook' => self::HOOK, 'status' => 'pending' ]))[0];
        $c = (int) ((array) \ActionScheduler::store()->query_actions([ 'hook' => self::HOOK . '_b', 'status' => 'pending' ]))[0];
        $this->assertSame(900, (int) \ActionScheduler::store()->fetch_action($i)->get_schedule()->get_recurrence());
        $this->assertSame('*/15 * * * *', \ActionScheduler::store()->fetch_action($c)->get_schedule()->get_recurrence());
    }

    public function test_the_undo_does_not_queue_a_duplicate_when_an_identical_action_is_pending(): void
    {
        $id  = $this->queue(100, [ 'order_id' => 7 ]);
        $out = $this->ok($this->write('actionscheduler-cancel-action', [ 'action_id' => $id ]));
        $twin = $this->queue(200, [ 'order_id' => 7 ]);

        Rollback_Service::restore_operation($out['operation_id']);

        $pending = (array) \ActionScheduler::store()->query_actions([ 'hook' => self::HOOK, 'status' => 'pending' ]);
        $this->assertSame([ $twin ], array_map('intval', $pending), 'no duplicate');
    }

    /** Issue #954: undoing the cancel of one of two identical pending actions leaves two pending. */
    public function test_the_undo_of_one_of_two_identical_pending_actions_queues_it_again(): void
    {
        $due   = time() + 3600;
        $first = (int) as_schedule_single_action($due, self::HOOK, [ 'order_id' => 7 ], self::GROUP);
        $twin  = (int) as_schedule_single_action($due, self::HOOK, [ 'order_id' => 7 ], self::GROUP);
        $this->assertNotSame($first, $twin);

        $out = $this->ok($this->write('actionscheduler-cancel-action', [ 'action_id' => $first ]));
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $pending = array_map('intval', (array) \ActionScheduler::store()->query_actions([ 'hook' => self::HOOK, 'status' => 'pending' ]));
        $this->assertCount(2, $pending, 'the pending count is back to what it was before the cancel');
        $this->assertContains($twin, $pending);
        $new = (int) current(array_diff($pending, [ $twin ]));
        $this->assertSame($due, \ActionScheduler::store()->get_date($new)->getTimestamp());
        foreach (Rollback_Service::take_warnings() as $warning) {
            $this->assertStringNotContainsString('was not queued again', $warning);
        }
    }

    /** Issue #955: a canceled action reports the date it was scheduled for, never a zero date. */
    public function test_get_and_list_report_the_scheduled_date_of_a_canceled_action(): void
    {
        $due = time() + 5400;
        $id  = (int) as_schedule_single_action($due, self::HOOK, [ 'order_id' => 7 ], self::GROUP);
        $this->ok($this->write('actionscheduler-cancel-action', [ 'action_id' => $id ]));

        $expected = gmdate('Y-m-d\TH:i:s\Z', $due);
        $get      = $this->ok($this->read('actionscheduler-get-action', [ 'action_id' => $id ]))['action'];
        $this->assertSame('canceled', $get['status']);
        $this->assertSame($expected, $get['scheduled_at']);

        $rows = $this->ok($this->read('actionscheduler-list-actions', [ 'hook' => self::HOOK, 'status' => 'canceled' ]))['actions'];
        $this->assertSame([ $expected ], array_column($rows, 'scheduled_at'));
    }

    public function test_cancel_refuses_an_action_that_is_not_pending_or_does_not_exist(): void
    {
        $done = $this->queue();
        \ActionScheduler::store()->mark_complete($done);
        $this->assertSame('action_not_pending', $this->code($this->write('actionscheduler-cancel-action', [ 'action_id' => $done ])));
        $this->assertSame('action_not_found', $this->code($this->write('actionscheduler-cancel-action', [ 'action_id' => 999999 ])));
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_cancel_and_run_refuse_wp_mcp_s_own_hooks(): void
    {
        $own = (string) (Cron_Registry::hooks()[0] ?? '');
        $this->assertNotSame('', $own);
        $id = (int) as_schedule_single_action(time() + 3600, $own, [ 'job' => 1 ], self::GROUP);
        try {
            $this->assertSame('internal_hook', $this->code($this->write('actionscheduler-cancel-action', [ 'action_id' => $id ])));
            $this->assertSame('internal_hook', $this->code($this->write('actionscheduler-run-action', [ 'action_id' => $id ], true)));
            $this->assertSame('pending', \ActionScheduler::store()->get_status($id));
        } finally {
            \ActionScheduler::store()->delete_action($id);
        }
    }

    // ---------------------------------------------------------------
    // Run now
    // ---------------------------------------------------------------

    public function test_run_asks_for_confirmation_with_a_preview_and_runs_nothing(): void
    {
        $ran = 0;
        add_action(self::HOOK, static function () use (&$ran): void {
            ++$ran;
        });
        $id = $this->queue(3600, [ 'email' => 'jane@example.com' ]);

        $out = $this->write('actionscheduler-run-action', [ 'action_id' => $id ]);

        $this->assertSame('confirmation_required', $this->code($out), (string) wp_json_encode($out));
        $this->assertStringContainsString('CANNOT be undone', $out['error']['message']);
        $this->assertSame(self::HOOK, $out['error']['data']['hook']);
        $this->assertSame('j***@example.com', $out['error']['data']['args']['email'], 'the preview masks personal data too');
        $this->assertSame(0, $ran);
        $this->assertSame('pending', \ActionScheduler::store()->get_status($id));
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_run_runs_the_action_now_is_not_recoverable_and_writes_no_snapshot(): void
    {
        $seen = [];
        add_action(self::HOOK, static function (...$args) use (&$seen): void {
            $seen[] = $args;
        }, 10, 2);
        $id = $this->queue(86400 * 3, [ 'order_id' => 7 ]);

        $out = $this->ok($this->write('actionscheduler-run-action', [ 'action_id' => $id ], true));

        $this->assertFalse($out['recoverable']);
        $this->assertArrayNotHasKey('operation_id', $out);
        $this->assertSame([ [ 7 ] ], $seen, 'the hook ran once, with the stored args');
        $this->assertTrue($out['result']['ran']);
        $this->assertSame('complete', $out['result']['status']);
        $this->assertSame('complete', \ActionScheduler::store()->get_status($id));
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_run_reports_a_failing_action_with_its_log(): void
    {
        add_action(self::HOOK, static function (): void {
            throw new \RuntimeException('Gateway said no for jane@example.com');
        });
        $id = $this->queue();

        $out = $this->ok($this->write('actionscheduler-run-action', [ 'action_id' => $id ], true));

        $this->assertSame('failed', $out['result']['status']);
        $this->assertTrue($out['result']['ran']);
        $this->assertStringContainsString('Gateway said no', implode("\n", $out['result']['log']));
        $this->assertSame('failed', \ActionScheduler::store()->get_status($id));
    }

    public function test_run_refuses_an_action_that_is_not_pending(): void
    {
        $id = $this->queue();
        \ActionScheduler::store()->cancel_action($id);
        $out = $this->write('actionscheduler-run-action', [ 'action_id' => $id ], true);
        $this->assertSame('action_not_pending', $this->code($out));
        $this->assertStringContainsString('canceled', $out['error']['message']);
    }
}
