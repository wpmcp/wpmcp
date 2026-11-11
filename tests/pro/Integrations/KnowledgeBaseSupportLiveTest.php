<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Helpdesk_Adapter;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #708: the BetterDocs, Fluent Support and Awesome Support ops against
 * the real plugins.
 *
 * It runs only in the local gate's live helpdesk leg (bin/test-local.sh, or
 * bin/test-local.sh --live-helpdesk alone), which sets WPMCP_LIVE_HELPDESK=1,
 * installs the three plugins from wordpress.org on a separate WordPress
 * install, loads them in tests/bootstrap.php and builds Fluent Support's
 * tables and Awesome Support's capabilities with their own routines.
 *
 * Every write runs through the plugin's own services and functions, so
 * their hooks fire, and rollback-operation must then put the plugin's own
 * state back. Everywhere else the plugins are absent and the test is
 * skipped; the live leg runs with --fail-on-skipped so it cannot pass
 * vacuously.
 *
 * @group helpdesk-live
 */
class KnowledgeBaseSupportLiveTest extends \WP_UnitTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('BETTERDOCS_VERSION') || ! defined('FLUENT_SUPPORT_VERSION') || ! defined('WPAS_VERSION')) {
            $this->markTestSkipped('Needs the real BetterDocs, Fluent Support and Awesome Support (bin/test-local.sh --live-helpdesk, WPMCP_LIVE_HELPDESK=1).');
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        $user        = get_userdata($this->admin);
        foreach ([ 'edit_docs', 'edit_others_docs', 'publish_docs', 'read_private_docs', 'edit_published_docs', 'delete_docs', 'delete_published_docs', 'delete_others_docs' ] as $cap) {
            $user->add_cap($cap);
        }
        wp_set_current_user($this->admin);
        add_filter(Helpdesk_Adapter::ENABLE_FILTER, '__return_true');
        reset_phpmailer_instance();
    }

    protected function tearDown(): void
    {
        remove_all_filters(Helpdesk_Adapter::ENABLE_FILTER);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
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
        return $out['result'];
    }

    // ---------------------------------------------------------------- BetterDocs

    public function test_betterdocs_create_files_the_doc_in_the_category_order_and_rollback_trashes_it(): void
    {
        $this->assertTrue(post_type_exists('docs'));
        $cat = (int) wp_insert_term('Getting started', 'doc_category')['term_id'];
        $out = $this->write('betterdocs-create-doc', [ 'title' => 'Install', 'content' => 'Steps', 'status' => 'publish', 'categories' => [ $cat ], 'tags' => [ 'setup' ] ]);
        $doc = $this->ok($out)['doc'];
        $this->assertSame('publish', $doc['status']);
        $this->assertContains((string) $doc['id'], explode(',', (string) get_term_meta($cat, '_docs_order', true)), 'BetterDocs put the doc into its category order');

        $listed = $this->ok($this->read('betterdocs-list-docs', [ 'category' => $cat ]))['docs'];
        $this->assertSame([ $doc['id'] ], array_column($listed, 'id'));

        $this->assertGreaterThan(0, Rollback_Service::restore_session((string) $out['session_id']), implode(' ', Rollback_Service::take_warnings()));
        $this->assertSame('trash', get_post_status($doc['id']));
    }

    public function test_betterdocs_update_and_rollback(): void
    {
        $doc = self::factory()->post->create([ 'post_type' => 'docs', 'post_title' => 'Old title', 'post_status' => 'publish' ]);
        $out = $this->write('betterdocs-update-doc', [ 'id' => $doc, 'title' => 'New title', 'content' => 'New body' ]);
        $this->assertSame('New title', $this->ok($out)['doc']['title']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('Old title', get_post($doc)->post_title);
    }

    // ---------------------------------------------------------------- Fluent Support

    private function fs_ticket(): int
    {
        global $wpdb;
        $p = $wpdb->prefix . 'fs_';
        $wpdb->insert("{$p}persons", [ 'first_name' => 'Ada', 'last_name' => 'Agent', 'email' => 'agent' . $this->admin . '@example.com', 'person_type' => 'agent', 'user_id' => $this->admin, 'hash' => md5((string) wp_rand()) ]);
        $wpdb->insert("{$p}persons", [ 'first_name' => 'Cleo', 'last_name' => 'Customer', 'email' => 'cleo' . wp_rand() . '@example.com', 'person_type' => 'customer', 'hash' => md5((string) wp_rand()) ]);
        $customer = (int) $wpdb->insert_id;
        $mailbox  = (int) $wpdb->get_var("SELECT id FROM {$p}mail_boxes ORDER BY id ASC LIMIT 1"); // phpcs:ignore WordPress.DB
        $now      = current_time('mysql');
        $wpdb->insert("{$p}tickets", [ 'customer_id' => $customer, 'mailbox_id' => $mailbox ?: null, 'title' => 'Cannot log in', 'content' => 'Help', 'status' => 'new', 'hash' => md5((string) wp_rand()), 'serial_number' => wp_rand(1, 1000000), 'created_at' => $now, 'updated_at' => $now, 'last_customer_response' => $now ]);
        return (int) $wpdb->insert_id;
    }

    private function fs_row(int $id): array
    {
        global $wpdb;
        return (array) $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}fs_tickets WHERE id = %d", $id), ARRAY_A);
    }

    private function fs_thread(int $id): array
    {
        global $wpdb;
        return array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fs_conversations WHERE ticket_id = %d ORDER BY id", $id)));
    }

    public function test_fluent_support_reply_note_and_close_through_its_services_and_rollback(): void
    {
        $ticket = $this->fs_ticket();
        $list   = $this->ok($this->read('fluent-support-list-tickets', [ 'status' => 'open' ]));
        $this->assertContains($ticket, array_column($list['tickets'], 'id'));
        $this->assertStringContainsString('***@example.com', (string) $list['tickets'][0]['customer']['email']);

        $before = $this->fs_row($ticket);
        $replied = did_action('fluent_support/response_added_by_agent');
        $out    = $this->write('fluent-support-reply-to-ticket', [ 'ticket_id' => $ticket, 'content' => 'Please reset your password.' ], true);
        $this->ok($out);
        $this->assertSame($replied + 1, did_action('fluent_support/response_added_by_agent'), 'the reply ran Fluent Support\'s own hooks');
        $this->assertSame('active', $this->fs_row($ticket)['status']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame([], $this->fs_thread($ticket));
        $this->assertSame($before['status'], $this->fs_row($ticket)['status']);
        $this->assertSame($before['response_count'], $this->fs_row($ticket)['response_count']);

        $note = $this->write('fluent-support-add-ticket-note', [ 'ticket_id' => $ticket, 'content' => 'Internal only' ]);
        $this->ok($note);
        $this->assertCount(1, $this->fs_thread($ticket));

        $closed = did_action('fluent_support/ticket_closed');
        $close  = $this->write('fluent-support-set-ticket-status', [ 'ticket_id' => $ticket, 'status' => 'closed' ]);
        $this->assertSame('closed', $this->ok($close)['status']);
        $this->assertSame($closed, did_action('fluent_support/ticket_closed'), 'the close is silent: no closing email, no attachment purge');
        $this->assertTrue(Rollback_Service::restore_operation((string) $close['operation_id']));
        $this->assertSame('new', $this->fs_row($ticket)['status']);
        $this->assertNull($this->fs_row($ticket)['resolved_at']);
    }

    // ---------------------------------------------------------------- Awesome Support

    private function as_ticket(): array
    {
        $customer = self::factory()->user->create([ 'role' => 'subscriber', 'user_email' => 'sam' . wp_rand() . '@example.org' ]);
        $ticket   = wp_insert_post([ 'post_type' => 'ticket', 'post_title' => 'Order missing', 'post_content' => 'Where?', 'post_status' => 'queued', 'post_author' => $customer ]);
        update_post_meta($ticket, '_wpas_status', 'open');
        update_post_meta($ticket, '_wpas_assignee', $this->admin);
        update_post_meta($ticket, '_wpas_last_reply_date', '2026-11-01 09:00:00');
        update_post_meta($ticket, '_wpas_is_waiting_client_reply', true);
        return [ (int) $ticket, $customer ];
    }

    public function test_awesome_support_reply_and_close_through_its_functions_and_rollback(): void
    {
        [$ticket] = $this->as_ticket();
        $meta     = get_post_meta($ticket);
        $got      = $this->ok($this->read('awesome-support-get-ticket', [ 'ticket_id' => $ticket ]))['ticket'];
        $this->assertSame('open', $got['status']);

        $replied = did_action('wpas_add_reply_complete');
        $out     = $this->write('awesome-support-reply-to-ticket', [ 'ticket_id' => $ticket, 'content' => 'Shipped today.' ], true);
        $reply   = (int) $this->ok($out)['reply_id'];
        $this->assertSame($replied + 1, did_action('wpas_add_reply_complete'), 'the reply ran Awesome Support\'s own hooks');
        $this->assertSame('ticket_reply', get_post_type($reply));
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertNull(get_post($reply));
        $this->assertEquals($meta, get_post_meta($ticket));

        $close = $this->write('awesome-support-set-ticket-status', [ 'ticket_id' => $ticket, 'status' => 'closed' ]);
        $this->assertSame('closed', $this->ok($close)['status']);
        $this->assertSame('closed', get_post_meta($ticket, '_wpas_status', true));
        $this->assertTrue(Rollback_Service::restore_operation((string) $close['operation_id']));
        $this->assertSame('open', get_post_meta($ticket, '_wpas_status', true));
        $this->assertSame([], get_posts([ 'post_type' => 'ticket_history', 'post_parent' => $ticket, 'post_status' => 'any', 'fields' => 'ids' ]), 'the history lines the write logged are gone');

        $hold = $this->write('awesome-support-set-ticket-status', [ 'ticket_id' => $ticket, 'status' => 'hold' ]);
        $this->assertSame('hold', $this->ok($hold)['status']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $hold['operation_id']));
        clean_post_cache($ticket);
        $this->assertSame('queued', get_post_status($ticket));
    }
}
