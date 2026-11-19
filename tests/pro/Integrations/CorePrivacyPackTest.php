<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Core_Privacy_Pack;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;

/**
 * WordPress core personal data requests (issue #714): paid-tier ops on the
 * theme dispatcher pair, next to the other core settings packs, so the
 * feature adds no top-level tool.
 *
 * Requests are core's user_request posts (Tools > Export Personal Data and
 * Erase Personal Data). Lists mask the requester's email; a single read
 * returns it in full. Every write is undoable: a created request is
 * recorded and rolled back by trashing it, and mark-request-complete is
 * snapshotted on the request post. Nothing here runs an exporter or an
 * eraser: create-erasure-request only files the request, behind
 * confirm:true with the request details in the preview.
 */
class CorePrivacyPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'list-privacy-requests' => 'read',
        'get-privacy-request'   => 'read',
        'create-export-request' => 'write',
        'create-erasure-request' => 'destructive',
        'mark-request-complete' => 'write',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $GLOBALS['wpdb']->query('DELETE FROM ' . Snapshot_Store::table_name());
        Gate::set_pro_for_tests(true);
        $admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        if (is_multisite()) {
            grant_super_admin($admin);
        }
        wp_set_current_user($admin);
        reset_phpmailer_instance();
    }

    protected function tearDown(): void
    {
        remove_all_filters(Core_Privacy_Pack::ENABLE_FILTER);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function enable_writes(): void
    {
        add_filter(Core_Privacy_Pack::ENABLE_FILTER, '__return_true');
    }

    private function catalog(): array
    {
        return array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
    }

    private function read(string $op, array $args = []): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args, bool $confirm = false): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if ($confirm) {
            $call['confirm'] = true;
        }
        return (new Theme_Integration())->handle_write($call);
    }

    private function error_code(array $out): string
    {
        return (string) ($out['error']['code'] ?? '');
    }

    private function request(string $email, string $action = 'export_personal_data', string $status = 'pending'): int
    {
        $id = wp_create_user_request($email, $action, [], $status);
        $this->assertIsInt($id);
        return $id;
    }

    // ---------------------------------------------------------------
    // Catalog, tier and opt-in
    // ---------------------------------------------------------------

    public function test_ops_are_paid_tier_on_the_theme_pair_with_their_modes(): void
    {
        $ops = $this->catalog();
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops, $name);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], "{$name}: WordPress core is always present");
            $this->assertSame('read' === $mode, $ops[ $name ]['enabled'], "{$name}: writes are off until the site opts in");
        }
        $this->assertTrue($ops['create-erasure-request']['requires_confirm']);

        Gate::set_pro_for_tests(false);
        $free = $this->catalog();
        foreach (array_keys(self::OPS) as $name) {
            $this->assertArrayNotHasKey($name, $free, "{$name} is paid-tier");
        }
    }

    public function test_writes_are_refused_until_the_site_opts_in_and_the_refusal_names_the_filter(): void
    {
        $out = $this->write('create-export-request', [ 'email' => 'jane@example.com' ]);

        $this->assertSame('opt_in_required', $this->error_code($out));
        $this->assertStringContainsString(Core_Privacy_Pack::ENABLE_FILTER, wp_json_encode($out));
        $this->assertSame(0, $this->count_requests());
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_masks_emails_and_filters_by_type_and_status(): void
    {
        $export = $this->request('jane@example.com');
        $erase  = $this->request('bob@example.org', 'remove_personal_data', 'confirmed');

        $all = $this->read('list-privacy-requests')['result'];
        $this->assertSame(2, $all['total']);
        $this->assertTrue($all['masked']);
        $json = wp_json_encode($all);
        $this->assertStringNotContainsString('jane@example.com', $json);
        $this->assertStringNotContainsString('bob@example.org', $json);
        $this->assertStringContainsString('j***@example.com', $json);

        $exports = $this->read('list-privacy-requests', [ 'type' => 'export' ])['result'];
        $this->assertSame([ $export ], array_column($exports['requests'], 'id'));
        $this->assertSame('export', $exports['requests'][0]['type']);
        $this->assertSame('pending', $exports['requests'][0]['status']);

        $confirmed = $this->read('list-privacy-requests', [ 'status' => 'confirmed' ])['result'];
        $this->assertSame([ $erase ], array_column($confirmed['requests'], 'id'));
        $this->assertSame('erasure', $confirmed['requests'][0]['type']);
    }

    public function test_get_returns_the_full_email_and_never_the_confirm_key(): void
    {
        $user = self::factory()->user->create([ 'user_email' => 'member@example.com' ]);
        $id   = $this->request('member@example.com');
        wp_generate_user_request_key($id);

        $out = $this->read('get-privacy-request', [ 'request_id' => $id ])['result'];

        $this->assertSame($id, $out['id']);
        $this->assertSame('member@example.com', $out['email']);
        $this->assertSame($user, $out['user']['id']);
        $this->assertSame('export', $out['type']);
        $this->assertArrayNotHasKey('confirm_key', $out);
        $this->assertStringNotContainsString('$P$', wp_json_encode($out));
        $this->assertStringNotContainsString('$wp$', wp_json_encode($out));
    }

    public function test_get_refuses_an_id_that_is_not_a_request(): void
    {
        $post = self::factory()->post->create();

        $this->assertSame('request_not_found', $this->error_code($this->read('get-privacy-request', [ 'request_id' => $post ])));
        $this->assertSame('request_not_found', $this->error_code($this->read('get-privacy-request', [ 'request_id' => 999999 ])));
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_create_export_request_without_email_is_confirmed_and_undone_by_trashing(): void
    {
        $this->enable_writes();

        $out = $this->write('create-export-request', [ 'email' => 'jane@example.com' ]);

        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $id      = (int) $out['result']['request']['id'];
        $request = wp_get_user_request($id);
        $this->assertSame('export_personal_data', $request->action_name);
        $this->assertSame('request-confirmed', $request->status, 'without the confirmation email the admin vouches for the request, as on the core screen');
        $this->assertFalse($out['result']['email_sent']);
        $this->assertEmpty(tests_retrieve_phpmailer_instance()->get_sent(), 'no email unless send_email:true');

        (new Rollback_Session())->handle([ 'session_id' => $out['session_id'] ]);

        $this->assertSame('trash', get_post_status($id));
    }

    public function test_create_export_request_with_send_email_stays_pending_and_mails_the_requester(): void
    {
        $this->enable_writes();

        $out = $this->write('create-export-request', [ 'email' => 'jane@example.com', 'send_email' => true ]);

        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $id = (int) $out['result']['request']['id'];
        $this->assertSame('request-pending', get_post_status($id));
        $this->assertTrue($out['result']['email_sent']);
        $sent = tests_retrieve_phpmailer_instance()->get_sent();
        $this->assertNotEmpty($sent);
        $this->assertSame('jane@example.com', $sent->to[0][0]);
    }

    public function test_create_refuses_a_bad_email_and_a_duplicate_without_writing(): void
    {
        $this->enable_writes();
        $this->request('jane@example.com');

        $this->assertSame('invalid_args', $this->error_code($this->write('create-export-request', [ 'email' => 'not-an-email' ])));
        $this->assertSame('duplicate_request', $this->error_code($this->write('create-export-request', [ 'email' => 'jane@example.com' ])));
        $this->assertSame(1, $this->count_requests());
        $this->assertSame(0, (int) $GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM ' . Snapshot_Store::table_name()));
    }

    public function test_create_erasure_request_needs_confirm_in_arguments_with_the_details_and_erases_nothing(): void
    {
        $this->enable_writes();
        $user = self::factory()->user->create([ 'user_email' => 'bob@example.org', 'role' => 'subscriber' ]);

        $preview = $this->write('create-erasure-request', [ 'email' => 'bob@example.org' ]);
        $this->assertSame('confirmation_required', $this->error_code($preview));
        $this->assertSame($user, $preview['error']['data']['user']['id']);
        $this->assertSame('bob@example.org', $preview['error']['data']['email']);
        $this->assertArrayHasKey('erasers', $preview['error']['data']);
        $this->assertSame(0, $this->count_requests(), 'the preview writes nothing');

        $out = $this->write('create-erasure-request', [ 'email' => 'bob@example.org' ], true);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $id = (int) $out['result']['request']['id'];
        $this->assertSame('remove_personal_data', wp_get_user_request($id)->action_name);
        $this->assertSame('bob@example.org', get_userdata($user)->user_email, 'filing the request erases nothing');

        (new Rollback_Session())->handle([ 'session_id' => $out['session_id'] ]);
        $this->assertSame('trash', get_post_status($id));
    }

    public function test_mark_request_complete_is_snapshotted_and_undone(): void
    {
        $this->enable_writes();
        $id = $this->request('jane@example.com', 'export_personal_data', 'confirmed');

        $out = $this->write('mark-request-complete', [ 'request_id' => $id ]);

        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertSame('request-completed', get_post_status($id));
        $this->assertNotEmpty(get_post_meta($id, '_wp_user_request_completed_timestamp', true));
        $this->assertSame('completed', $out['result']['request']['status']);

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        clean_post_cache($id);

        $this->assertSame('request-confirmed', get_post_status($id));
        $this->assertEmpty(get_post_meta($id, '_wp_user_request_completed_timestamp', true));
    }

    public function test_mark_request_complete_refuses_a_completed_request_and_a_non_request(): void
    {
        $this->enable_writes();
        $id = $this->request('jane@example.com', 'export_personal_data', 'confirmed');
        $this->write('mark-request-complete', [ 'request_id' => $id ]);

        $this->assertSame('no_change', $this->error_code($this->write('mark-request-complete', [ 'request_id' => $id ])));
        $this->assertSame('request_not_found', $this->error_code($this->write('mark-request-complete', [ 'request_id' => self::factory()->post->create() ])));
    }

    public function test_an_editor_cannot_reach_the_requests(): void
    {
        $this->enable_writes();
        $id = $this->request('jane@example.com');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));

        $this->assertNotSame('', $this->error_code($this->read('get-privacy-request', [ 'request_id' => $id ])));
        $this->assertNotSame('', $this->error_code($this->read('list-privacy-requests')));
        $this->assertNotSame('', $this->error_code($this->write('mark-request-complete', [ 'request_id' => $id ])));
    }

    private function count_requests(): int
    {
        return (int) $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type = 'user_request'");
    }
}
