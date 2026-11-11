<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Helpdesk_Adapter;
use WPMCP\Integrations\Helpdesk_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Helpdesk_Ticket_Snapshot;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tests\Support\Fake_Awesome_Support;
use WPMCP\Tests\Support\Fake_Fluent_Support;

require_once __DIR__ . '/../../support/helpdesk-doubles.php';

/**
 * Knowledge base and support desk (issue #708): BetterDocs docs and Fluent
 * Support and Awesome Support tickets as paid-tier ops on the plugin-data
 * pair, so they add no top-level tools.
 *
 * None of the three plugins is loaded in the shared test core. BetterDocs's
 * docs post type and taxonomies, Awesome Support's ticket types and
 * Fluent Support's tables are built from their own definitions
 * (tests/support/helpdesk-doubles.php), presence is driven through each
 * plugin's filter, and the calls into the support plugins' APIs are doubles
 * that write what the plugin writes. The real calls run in the live group
 * (KnowledgeBaseSupportLiveTest).
 *
 * Docs are content: their writes are on by default and undone like posts.
 * Tickets carry personal data: lists mask the customer email, every ticket
 * write is off until wpmcp_enable_helpdesk_write, a reply (which the plugin
 * emails) asks for confirmation first, and every write's undo takes back
 * only what it did.
 */
class KnowledgeBaseSupportPackTest extends \WP_UnitTestCase
{
    private const PRESENCE = [ 'wpmcp_betterdocs_active', 'wpmcp_fluent_support_active', 'wpmcp_awesome_support_active' ];

    private int $admin;

    private Fake_Fluent_Support $fluent;

    private Fake_Awesome_Support $awesome;

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_fluent_support_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_fluent_support_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wpmcp_test_register_betterdocs_types();
        wpmcp_test_register_awesome_support_types();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        $user        = get_userdata($this->admin);
        wpmcp_test_grant_doc_caps($user);
        wpmcp_test_grant_ticket_caps($user);
        wp_set_current_user($this->admin);
        foreach (self::PRESENCE as $filter) {
            add_filter($filter, '__return_true');
        }
        $this->fluent  = new Fake_Fluent_Support();
        $this->awesome = new Fake_Awesome_Support();
        Helpdesk_Pack::use_adapters_for_tests([ $this->fluent, $this->awesome ]);
    }

    protected function tearDown(): void
    {
        Helpdesk_Pack::use_adapters_for_tests(null);
        foreach (self::PRESENCE as $filter) {
            remove_all_filters($filter);
        }
        remove_all_filters(Helpdesk_Adapter::ENABLE_FILTER);
        wpmcp_test_unregister_betterdocs_types();
        wpmcp_test_unregister_awesome_support_types();
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

    private function enable_ticket_writes(): void
    {
        add_filter(Helpdesk_Adapter::ENABLE_FILTER, '__return_true');
    }

    // ---------------------------------------------------------------
    // Catalog and gating
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_op_with_its_mode_on_the_plugin_data_pair(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        $expected = [
            'betterdocs-list-docs'               => 'read',
            'betterdocs-get-doc'                 => 'read',
            'betterdocs-list-categories'         => 'read',
            'betterdocs-create-doc'              => 'write',
            'betterdocs-update-doc'              => 'write',
            'fluent-support-list-tickets'        => 'read',
            'fluent-support-get-ticket'          => 'read',
            'fluent-support-reply-to-ticket'     => 'destructive',
            'fluent-support-add-ticket-note'     => 'write',
            'fluent-support-set-ticket-status'   => 'write',
            'awesome-support-list-tickets'       => 'read',
            'awesome-support-get-ticket'         => 'read',
            'awesome-support-reply-to-ticket'    => 'destructive',
            'awesome-support-set-ticket-status'  => 'write',
        ];
        foreach ($expected as $op => $mode) {
            $this->assertArrayHasKey($op, $ops, $op);
            $this->assertSame($mode, $ops[ $op ]['mode'], $op);
        }
        $this->assertArrayNotHasKey('awesome-support-add-ticket-note', $ops, 'the free Awesome Support has no internal notes');
    }

    public function test_without_the_paid_tier_no_op_is_offered(): void
    {
        Gate::set_pro_for_tests(false);
        $names = array_column((new Plugin_Data_Integration())->catalog()['operations'], 'name');
        $this->assertSame([], preg_grep('/^(betterdocs|fluent-support|awesome-support)-/', $names));
    }

    public function test_an_inactive_plugin_answers_with_its_own_name(): void
    {
        remove_all_filters('wpmcp_awesome_support_active');
        add_filter('wpmcp_awesome_support_active', '__return_false');
        $out = $this->read('awesome-support-list-tickets');
        $this->assertSame('awesome_support_inactive', $out['error']['code'] ?? null);
        $this->assertStringContainsString('Awesome Support', $out['error']['message']);
    }

    public function test_ticket_writes_are_off_until_the_site_opts_in(): void
    {
        $ticket = $this->fs_ticket();
        $out    = $this->write('fluent-support-set-ticket-status', [ 'ticket_id' => $ticket, 'status' => 'closed' ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertStringContainsString(Helpdesk_Adapter::ENABLE_FILTER, $out['error']['message']);
        $this->assertSame('new', $this->fs_row($ticket)['status']);
    }

    public function test_mask_email_keeps_the_first_letter_and_the_domain(): void
    {
        $this->assertSame('j***@example.com', Helpdesk_Adapter::mask_email('jane.doe@example.com'));
        $this->assertSame('***', Helpdesk_Adapter::mask_email('not-an-email'));
        $this->assertSame('', Helpdesk_Adapter::mask_email(''));
    }

    // ---------------------------------------------------------------
    // BetterDocs
    // ---------------------------------------------------------------

    public function test_list_docs_filters_by_category_and_hides_drafts_the_caller_may_not_read(): void
    {
        $cat     = self::factory()->term->create([ 'taxonomy' => 'doc_category', 'name' => 'Setup' ]);
        $other   = self::factory()->user->create([ 'role' => 'author' ]);
        $in      = self::factory()->post->create([ 'post_type' => 'docs', 'post_title' => 'Install', 'post_status' => 'publish' ]);
        $out     = self::factory()->post->create([ 'post_type' => 'docs', 'post_title' => 'Elsewhere', 'post_status' => 'publish' ]);
        $draft   = self::factory()->post->create([ 'post_type' => 'docs', 'post_title' => 'Secret draft', 'post_status' => 'draft', 'post_author' => $other ]);
        wp_set_object_terms($in, [ $cat ], 'doc_category');
        wp_set_object_terms($draft, [ $cat ], 'doc_category');

        $docs = $this->ok($this->read('betterdocs-list-docs', [ 'category' => $cat ]))['docs'];
        $this->assertEqualsCanonicalizing([ $in, $draft ], array_column($docs, 'id'), 'an administrator reads every doc in the category');
        $this->assertSame('Setup', $docs[0]['categories'][0]['name']);
        $this->assertNotContains($out, array_column($docs, 'id'));

        $reader = self::factory()->user->create([ 'role' => 'contributor' ]);
        wp_set_current_user($reader);
        get_userdata($reader)->add_cap('edit_docs');
        $ids = array_column($this->ok($this->read('betterdocs-list-docs', [ 'category' => $cat ]))['docs'], 'id');
        $this->assertSame([ $in ], $ids, 'another author\'s draft stays hidden');
    }

    public function test_get_doc_returns_content_and_refuses_a_post_that_is_not_a_doc(): void
    {
        $doc = self::factory()->post->create([ 'post_type' => 'docs', 'post_title' => 'FAQ', 'post_content' => 'Answers', 'post_status' => 'publish' ]);
        update_post_meta($doc, '_betterdocs_meta_views', 7);
        $got = $this->ok($this->read('betterdocs-get-doc', [ 'id' => $doc ]))['doc'];
        $this->assertSame('Answers', $got['content']);
        $this->assertSame(7, $got['views']);

        $post = self::factory()->post->create();
        $this->assertSame('doc_not_found', $this->read('betterdocs-get-doc', [ 'id' => $post ])['error']['code'] ?? null);
    }

    public function test_list_categories_orders_by_betterdocs_order(): void
    {
        $b = self::factory()->term->create([ 'taxonomy' => 'doc_category', 'name' => 'B' ]);
        $a = self::factory()->term->create([ 'taxonomy' => 'doc_category', 'name' => 'A' ]);
        update_term_meta($b, 'doc_category_order', 1);
        update_term_meta($a, 'doc_category_order', 2);
        $this->assertSame([ $b, $a ], array_column($this->ok($this->read('betterdocs-list-categories'))['categories'], 'id'));
    }

    public function test_create_doc_files_it_and_rollback_trashes_it(): void
    {
        $cat = self::factory()->term->create([ 'taxonomy' => 'doc_category', 'name' => 'Billing' ]);
        $out = $this->write('betterdocs-create-doc', [ 'title' => 'Refunds', 'content' => 'How refunds work', 'categories' => [ $cat ], 'tags' => [ 'money' ] ]);
        $doc = $this->ok($out)['doc'];
        $this->assertSame('draft', $doc['status']);
        $this->assertSame([ $cat ], array_column($doc['categories'], 'id'));
        $this->assertSame([ 'money' ], array_column($doc['tags'], 'name'));

        $this->assertTrue(Rollback_Service::restore_session((string) $out['session_id']) > 0);
        $this->assertSame('trash', get_post_status($doc['id']));
    }

    public function test_create_doc_refuses_an_unknown_category_and_publishing_without_publish_docs(): void
    {
        $this->assertSame('unknown_category', $this->write('betterdocs-create-doc', [ 'title' => 'X', 'categories' => [ 999999 ] ])['error']['code'] ?? null);

        $writer = self::factory()->user->create([ 'role' => 'contributor' ]);
        get_userdata($writer)->add_cap('edit_docs');
        wp_set_current_user($writer);
        $this->assertSame('operation_denied', $this->write('betterdocs-create-doc', [ 'title' => 'X', 'status' => 'publish' ])['error']['code'] ?? null);
        $this->ok($this->write('betterdocs-create-doc', [ 'title' => 'X' ]));
    }

    public function test_update_doc_and_rollback_puts_fields_and_categories_back(): void
    {
        $old = self::factory()->term->create([ 'taxonomy' => 'doc_category', 'name' => 'Old' ]);
        $new = self::factory()->term->create([ 'taxonomy' => 'doc_category', 'name' => 'New' ]);
        $doc = self::factory()->post->create([ 'post_type' => 'docs', 'post_title' => 'Before', 'post_status' => 'publish' ]);
        wp_set_object_terms($doc, [ $old ], 'doc_category');

        $out = $this->write('betterdocs-update-doc', [ 'id' => $doc, 'title' => 'After', 'categories' => [ $new ] ]);
        $this->assertSame('After', $this->ok($out)['doc']['title']);
        $this->assertSame([ $new ], wp_get_object_terms($doc, 'doc_category', [ 'fields' => 'ids' ]));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('Before', get_post($doc)->post_title);
        $this->assertSame([ $old ], wp_get_object_terms($doc, 'doc_category', [ 'fields' => 'ids' ]));
    }

    // ---------------------------------------------------------------
    // Fluent Support
    // ---------------------------------------------------------------

    private function fs_person(string $type, string $email, int $user_id = 0): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'fs_persons', [ 'first_name' => 'Pat', 'last_name' => ucfirst($type), 'email' => $email, 'person_type' => $type, 'user_id' => $user_id ?: null ]);
        return (int) $wpdb->insert_id;
    }

    private function fs_agent(): int
    {
        global $wpdb;
        $found = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fs_persons WHERE user_id = %d AND person_type = 'agent'", $this->admin));
        return $found ?: $this->fs_person('agent', 'agent@example.com', $this->admin);
    }

    private function fs_ticket(string $status = 'new', string $title = 'Cannot log in'): int
    {
        global $wpdb;
        $this->fs_agent();
        $customer = $this->fs_person('customer', 'jane.doe@example.com');
        $wpdb->insert($wpdb->prefix . 'fs_tickets', [ 'customer_id' => $customer, 'status' => $status, 'title' => $title, 'content' => 'Help please', 'hash' => 'secret-hash', 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00' ]);
        $id = (int) $wpdb->insert_id;
        $wpdb->insert($wpdb->prefix . 'fs_conversations', [ 'ticket_id' => $id, 'person_id' => $customer, 'conversation_type' => 'response', 'content' => 'Still stuck', 'created_at' => '2026-11-01 11:00:00' ]);
        return $id;
    }

    private function fs_row(int $id): array
    {
        global $wpdb;
        return (array) $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}fs_tickets WHERE id = %d", $id), ARRAY_A);
    }

    /** @return int[] */
    private function fs_thread(int $id): array
    {
        global $wpdb;
        return array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fs_conversations WHERE ticket_id = %d ORDER BY id", $id)));
    }

    public function test_fluent_list_tickets_masks_customer_emails_and_get_ticket_shows_the_thread(): void
    {
        $open   = $this->fs_ticket('active', 'Open one');
        $closed = $this->fs_ticket('closed', 'Closed one');

        $list = $this->ok($this->read('fluent-support-list-tickets', [ 'status' => 'open' ]));
        $this->assertSame([ $open ], array_column($list['tickets'], 'id'));
        $this->assertSame('j***@example.com', $list['tickets'][0]['customer']['email']);
        $this->assertTrue($list['emails_masked']);
        $this->assertStringNotContainsString('jane.doe', (string) wp_json_encode($list));
        $this->assertSame([ $closed ], array_column($this->ok($this->read('fluent-support-list-tickets', [ 'status' => 'closed' ]))['tickets'], 'id'));

        $ticket = $this->ok($this->read('fluent-support-get-ticket', [ 'ticket_id' => $open ]))['ticket'];
        $this->assertSame('jane.doe@example.com', $ticket['customer']['email']);
        $this->assertSame('Still stuck', $ticket['thread'][0]['content']);
        $this->assertStringNotContainsString('secret-hash', (string) wp_json_encode($ticket), 'the public ticket hash is never returned');
    }

    public function test_fluent_reads_refuse_a_user_who_is_not_an_agent(): void
    {
        $ticket = $this->fs_ticket();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->read('fluent-support-list-tickets')['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->read('fluent-support-get-ticket', [ 'ticket_id' => $ticket ])['error']['code'] ?? null);
    }

    public function test_fluent_reply_asks_for_confirmation_naming_the_masked_recipient(): void
    {
        $this->enable_ticket_writes();
        $ticket = $this->fs_ticket();
        $out    = $this->write('fluent-support-reply-to-ticket', [ 'ticket_id' => $ticket, 'content' => 'Try resetting your password.' ]);
        $this->assertSame('confirmation_required', $out['error']['code'] ?? null);
        $this->assertStringContainsString('j***@example.com', $out['error']['message']);
        $this->assertSame(1, count($this->fs_thread($ticket)), 'nothing is posted before the confirmation');
        $this->assertSame([], $this->fluent->mailed);
    }

    public function test_fluent_reply_and_rollback_removes_the_reply_and_puts_the_ticket_back(): void
    {
        $this->enable_ticket_writes();
        $ticket = $this->fs_ticket();
        $before = $this->fs_row($ticket);
        $thread = $this->fs_thread($ticket);

        $out    = $this->write('fluent-support-reply-to-ticket', [ 'ticket_id' => $ticket, 'content' => 'Try resetting your password.' ], true);
        $result = $this->ok($out);
        $this->assertSame('active', $result['status']);
        $this->assertSame('j***@example.com', $result['notified']);
        $this->assertCount(2, $this->fs_thread($ticket));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($thread, $this->fs_thread($ticket));
        $after = $this->fs_row($ticket);
        foreach ([ 'status', 'agent_id', 'response_count', 'last_agent_response', 'waiting_since', 'first_response_time' ] as $column) {
            $this->assertSame($before[ $column ], $after[ $column ], $column);
        }
        $this->assertStringContainsString('cannot be unsent', implode(' ', Rollback_Service::take_warnings()));
    }

    public function test_fluent_rollback_keeps_a_customer_reply_posted_since(): void
    {
        global $wpdb;
        $this->enable_ticket_writes();
        $ticket = $this->fs_ticket();
        $out    = $this->write('fluent-support-reply-to-ticket', [ 'ticket_id' => $ticket, 'content' => 'Try this.' ], true);
        $reply  = (int) $this->ok($out)['reply_id'];
        $wpdb->insert($wpdb->prefix . 'fs_conversations', [ 'ticket_id' => $ticket, 'person_id' => 1, 'conversation_type' => 'response', 'content' => 'That worked, thanks' ]);
        $later = (int) $wpdb->insert_id;
        $wpdb->update($wpdb->prefix . 'fs_tickets', [ 'response_count' => 5 ], [ 'id' => $ticket ]);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $thread = $this->fs_thread($ticket);
        $this->assertNotContains($reply, $thread);
        $this->assertContains($later, $thread);
        $this->assertSame('5', $this->fs_row($ticket)['response_count'], 'a column changed since is left alone');
        $this->assertStringContainsString('response_count was changed since', implode(' ', Rollback_Service::take_warnings()));
    }

    public function test_fluent_note_and_status_changes_are_undone(): void
    {
        $this->enable_ticket_writes();
        $ticket = $this->fs_ticket('active');
        $note   = $this->write('fluent-support-add-ticket-note', [ 'ticket_id' => $ticket, 'content' => 'Escalate to billing' ]);
        $this->ok($note);
        $this->assertSame([], $this->fluent->mailed, 'a note is not mailed');

        $before = $this->fs_row($ticket);
        $close  = $this->write('fluent-support-set-ticket-status', [ 'ticket_id' => $ticket, 'status' => 'closed' ]);
        $this->assertSame([ 'ticket_id' => $ticket, 'from' => 'active', 'status' => 'closed' ], $this->ok($close));
        $this->assertSame('already_done', $this->write('fluent-support-set-ticket-status', [ 'ticket_id' => $ticket, 'status' => 'closed' ])['error']['code'] ?? null);

        $this->assertTrue(Rollback_Service::restore_operation((string) $close['operation_id']));
        $after = $this->fs_row($ticket);
        foreach ([ 'status', 'resolved_at', 'closed_by', 'total_close_time' ] as $column) {
            $this->assertSame($before[ $column ], $after[ $column ], $column);
        }
        $count = count($this->fs_thread($ticket));
        $this->assertTrue(Rollback_Service::restore_operation((string) $note['operation_id']));
        $this->assertCount($count - 1, $this->fs_thread($ticket));
    }

    public function test_a_snapshot_records_the_ticket_kind_and_capability(): void
    {
        $this->assertSame('edit_ticket', Helpdesk_Ticket_Snapshot::capability('awesome_support'));
        $this->assertSame([ 'kind' => 'fluent_support', 'id' => 12 ], Helpdesk_Ticket_Snapshot::parse(Helpdesk_Ticket_Snapshot::key('fluent_support', 12)));
        $this->assertSame([ 'kind' => '', 'id' => 3 ], Helpdesk_Ticket_Snapshot::parse('wp_users:3'));
    }

    // ---------------------------------------------------------------
    // Awesome Support
    // ---------------------------------------------------------------

    private function as_ticket(int $agent = 0, string $state = 'queued'): int
    {
        static $n = 0;
        $n++;
        $customer = self::factory()->user->create([ 'role' => 'subscriber', 'user_email' => "sam.smith{$n}@example.org", 'display_name' => 'Sam' ]);
        $ticket   = self::factory()->post->create([ 'post_type' => 'ticket', 'post_status' => $state, 'post_title' => 'Order missing', 'post_content' => 'Where is it?', 'post_author' => $customer ]);
        update_post_meta($ticket, '_wpas_status', 'open');
        update_post_meta($ticket, '_wpas_assignee', $agent ?: $this->admin);
        update_post_meta($ticket, '_wpas_ttl_replies', 1);
        update_post_meta($ticket, '_wpas_is_waiting_client_reply', true);
        update_post_meta($ticket, '_wpas_last_reply_date', '2026-11-01 09:00:00');
        self::factory()->post->create([ 'post_type' => 'ticket_reply', 'post_parent' => $ticket, 'post_status' => 'unread', 'post_content' => 'Any news?', 'post_author' => $customer ]);
        return $ticket;
    }

    public function test_awesome_list_masks_emails_and_filters_closed(): void
    {
        $open   = $this->as_ticket();
        $closed = $this->as_ticket();
        update_post_meta($closed, '_wpas_status', 'closed');

        $list = $this->ok($this->read('awesome-support-list-tickets', [ 'status' => 'open' ]));
        $this->assertSame([ $open ], array_column($list['tickets'], 'id'));
        $this->assertSame('s***@example.org', $list['tickets'][0]['customer']['email']);
        $this->assertSame([ $closed ], array_column($this->ok($this->read('awesome-support-list-tickets', [ 'status' => 'closed' ]))['tickets'], 'id'));

        $ticket = $this->ok($this->read('awesome-support-get-ticket', [ 'ticket_id' => $open ]))['ticket'];
        $this->assertMatchesRegularExpression('/^sam\.smith\d+@example\.org$/', $ticket['customer']['email']);
        $this->assertSame('Any news?', $ticket['thread'][0]['content']);
        $this->assertSame('customer', $ticket['thread'][0]['by']['type']);
    }

    public function test_an_awesome_agent_who_may_not_see_all_sees_only_assigned_tickets(): void
    {
        $agent = self::factory()->user->create([ 'role' => 'editor' ]);
        foreach ([ 'view_ticket', 'edit_ticket', 'reply_ticket' ] as $cap) {
            get_userdata($agent)->add_cap($cap);
        }
        $mine   = $this->as_ticket($agent);
        $theirs = $this->as_ticket();
        wp_set_current_user($agent);
        $this->assertSame([ $mine ], array_column($this->ok($this->read('awesome-support-list-tickets'))['tickets'], 'id'));
        $this->assertSame('operation_denied', $this->read('awesome-support-get-ticket', [ 'ticket_id' => $theirs ])['error']['code'] ?? null);
    }

    public function test_awesome_reply_and_rollback_restores_meta_and_read_state(): void
    {
        $this->enable_ticket_writes();
        $ticket   = $this->as_ticket();
        $customer = get_posts([ 'post_type' => 'ticket_reply', 'post_parent' => $ticket, 'post_status' => 'unread', 'fields' => 'ids' ])[0];
        $meta     = get_post_meta($ticket);

        $out   = $this->write('awesome-support-reply-to-ticket', [ 'ticket_id' => $ticket, 'content' => 'Shipped today.' ], true);
        $reply = (int) $this->ok($out)['reply_id'];
        $this->assertSame([ $ticket ], $this->awesome->mailed);
        $this->assertSame('read', get_post_status($customer));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertNull(get_post($reply));
        $this->assertSame('unread', get_post_status($customer));
        $this->assertEquals($meta, get_post_meta($ticket));
    }

    public function test_awesome_close_and_rollback_deletes_the_history_and_restores_the_counter(): void
    {
        $this->enable_ticket_writes();
        update_user_option($this->admin, 'wpas_open_tickets', 3);
        $ticket = $this->as_ticket();

        $out = $this->write('awesome-support-set-ticket-status', [ 'ticket_id' => $ticket, 'status' => 'closed' ]);
        $this->assertSame('closed', $this->ok($out)['status']);
        $this->assertSame(2, (int) get_user_option('wpas_open_tickets', $this->admin));
        $this->assertCount(1, get_posts([ 'post_type' => 'ticket_history', 'post_parent' => $ticket, 'post_status' => 'any' ]));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('open', get_post_meta($ticket, '_wpas_status', true));
        $this->assertSame('', get_post_meta($ticket, '_ticket_closed_on', true));
        $this->assertSame(3, (int) get_user_option('wpas_open_tickets', $this->admin));
        $this->assertCount(0, get_posts([ 'post_type' => 'ticket_history', 'post_parent' => $ticket, 'post_status' => 'any' ]));
    }

    public function test_awesome_state_change_and_rollback(): void
    {
        $this->enable_ticket_writes();
        $ticket = $this->as_ticket();
        $out    = $this->write('awesome-support-set-ticket-status', [ 'ticket_id' => $ticket, 'status' => 'hold' ]);
        $this->assertSame([ 'ticket_id' => $ticket, 'from' => 'queued', 'status' => 'hold' ], $this->ok($out));
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        clean_post_cache($ticket);
        $this->assertSame('queued', get_post_status($ticket));
    }

    public function test_restoring_an_awesome_ticket_takes_edit_ticket(): void
    {
        $this->enable_ticket_writes();
        $ticket = $this->as_ticket();
        $out    = $this->write('awesome-support-set-ticket-status', [ 'ticket_id' => $ticket, 'status' => 'hold' ]);
        $this->ok($out);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertFalse(Rollback_Service::restore_operation((string) $out['operation_id']));
        clean_post_cache($ticket);
        $this->assertSame('hold', get_post_status($ticket));
    }
}
