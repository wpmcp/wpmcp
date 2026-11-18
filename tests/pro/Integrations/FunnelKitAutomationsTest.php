<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\CRM_FunnelKit;
use WPMCP\Integrations\CRM_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\FunnelKit_Automations_Snapshot;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tests\Support\Fake_FunnelKit;

use function WPMCP\Tests\Support\fka_create_tables;
use function WPMCP\Tests\Support\fka_drop_tables;

require_once __DIR__ . '/../../support/funnelkit-automations.php';

/**
 * FunnelKit Automations automations, contacts, lists and tags (issue #831):
 * paid-tier ops on the plugin-data pair, beside FluentCRM and MailPoet, so
 * they add no top-level tool.
 *
 * The plugin is not loaded in the shared test core: its tables are built
 * from its own schemas (tests/support/funnelkit-automations.php), presence
 * is driven through wpmcp_funnelkit_automations_active, and the calls into
 * FunnelKit's API are replaced by a double that writes those tables the way
 * FunnelKit does.
 *
 * Contact lists are masked. Writes are off until the site opts in
 * (wpmcp_enable_funnelkit_automations_write), each is snapshotted as a
 * 'funnelkit_automations' image, and rollback-operation takes back exactly
 * what the write changed: a tag or list someone added or removed since
 * stays, and an automation whose status was changed since keeps it.
 */
class FunnelKitAutomationsTest extends \WP_UnitTestCase
{
    private const OTHER_FILTERS = [ 'wpmcp_fluentcrm_active', 'wpmcp_mailpoet_active', 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active', 'wpmcp_buddypress_active', 'wpmcp_tutor_active', 'wpmcp_lifterlms_active', 'wpmcp_action_scheduler_active' ];

    private int $admin;

    public static function wpSetUpBeforeClass(): void
    {
        fka_create_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        fka_drop_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        CRM_Pack::use_adapters_for_tests([ new Fake_FunnelKit() ]);
        Fake_FunnelKit::$calls         = [];
        Fake_FunnelKit::$hook_adds_tag = null;
        $this->admin                   = self::factory()->user->create([ 'role' => 'administrator' ]);
        wp_set_current_user($this->admin);
        add_filter('wpmcp_funnelkit_automations_active', '__return_true');
        add_filter('wpmcp_enable_funnelkit_automations_write', '__return_true');
        foreach (self::OTHER_FILTERS as $filter) {
            add_filter($filter, '__return_false');
        }
    }

    protected function tearDown(): void
    {
        CRM_Pack::use_adapters_for_tests(null);
        Gate::set_pro_for_tests(null);
        foreach (array_merge(self::OTHER_FILTERS, [ 'wpmcp_funnelkit_automations_active', 'wpmcp_enable_funnelkit_automations_write' ]) as $filter) {
            remove_all_filters($filter);
        }
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'];
    }

    private function code(array $out): string
    {
        return (string) ($out['error']['code'] ?? '');
    }

    private function t(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    private function term(string $name, int $type): int
    {
        global $wpdb;
        $wpdb->insert($this->t('bwfan_terms'), [ 'name' => $name, 'type' => $type, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00' ]);
        return (int) $wpdb->insert_id;
    }

    private function contact(string $email, array $tags = [], array $lists = [], int $status = 1, string $first = 'Ada', string $last = 'Lovelace'): int
    {
        global $wpdb;
        $wpdb->insert($this->t('bwf_contact'), [
            'wpid'          => 0,
            'uid'           => md5($email),
            'email'         => $email,
            'f_name'        => $first,
            'l_name'        => $last,
            'tags'          => wp_json_encode(array_map('strval', $tags)),
            'lists'         => wp_json_encode(array_map('strval', $lists)),
            'last_modified' => '2026-01-01 00:00:00',
            'creation_date' => '2026-01-01 00:00:00',
            'status'        => $status,
        ]);
        return (int) $wpdb->insert_id;
    }

    private function automation(string $title, int $status): int
    {
        global $wpdb;
        $wpdb->insert($this->t('bwfan_automations'), [ 'source' => 'wp', 'event' => 'wp_user_creation', 'status' => $status, 'priority' => 0, 'start' => 1700000000, 'v' => 2, 'title' => $title ]);
        return (int) $wpdb->insert_id;
    }

    /** @return string[] */
    private function ids(int $contact, string $column): array
    {
        global $wpdb;
        $raw = json_decode((string) $wpdb->get_var($wpdb->prepare('SELECT %i FROM %i WHERE id = %d', $column, $this->t('bwf_contact'), $contact)), true);
        $raw = is_array($raw) ? array_map('strval', $raw) : [];
        sort($raw);
        return $raw;
    }

    private function automation_status(int $id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT status FROM %i WHERE ID = %d', $this->t('bwfan_automations'), $id));
    }

    // ---------------------------------------------------------------
    // Catalog and availability
    // ---------------------------------------------------------------

    public function test_ops_are_paid_tier_on_the_plugin_data_pair(): void
    {
        $ops = ( new CRM_FunnelKit() )->operations();
        foreach ([ 'fka-list-automations', 'fka-get-automation', 'fka-set-automation-status', 'fka-list-contacts', 'fka-get-contact', 'fka-list-lists', 'fka-list-tags', 'fka-update-contact-tags', 'fka-update-contact-lists' ] as $op) {
            $this->assertArrayHasKey($op, $ops);
            $this->assertSame('pro', $ops[ $op ]['tier'], $op);
        }
        foreach ([ 'fka-set-automation-status', 'fka-update-contact-tags', 'fka-update-contact-lists' ] as $op) {
            $this->assertSame('write', $ops[ $op ]['mode'], $op);
            $this->assertSame('wpmcp_enable_funnelkit_automations_write', $ops[ $op ]['opt_in_filter'], $op);
        }
        $this->assertArrayHasKey('fka-list-contacts', CRM_Pack::operations());
    }

    public function test_an_op_names_the_plugin_while_it_is_inactive(): void
    {
        remove_all_filters('wpmcp_funnelkit_automations_active');
        add_filter('wpmcp_funnelkit_automations_active', '__return_false');
        $out = $this->read('fka-list-tags');
        $this->assertSame('funnelkit_automations_inactive', $this->code($out), (string) wp_json_encode($out));
        $this->assertStringContainsString('FunnelKit Automations', (string) $out['error']['message']);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters('wpmcp_enable_funnelkit_automations_write');
        $id  = $this->automation('Welcome', 1);
        $out = $this->write('fka-set-automation-status', [ 'id' => $id, 'status' => 'inactive' ]);
        $this->assertSame('opt_in_required', $this->code($out));
        $this->assertSame(1, $this->automation_status($id));
        $this->assertSame([], Fake_FunnelKit::$calls);
    }

    public function test_a_user_without_funnelkit_access_is_refused(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $out = $this->read('fka-list-automations');
        $this->assertNotSame('', $this->code($out));
        $this->assertArrayNotHasKey('result', $out);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_and_get_automations_with_enrolled_counts(): void
    {
        global $wpdb;
        $on  = $this->automation('Welcome series', 1);
        $off = $this->automation('Win back', 2);
        $wpdb->insert($this->t('bwfan_automation_contact'), [ 'cid' => 1, 'aid' => $on, 'event' => 'x', 'e_time' => 0, 'last_time' => 0, 'trail' => 't1' ]);
        $wpdb->insert($this->t('bwfan_automation_contact'), [ 'cid' => 2, 'aid' => $on, 'event' => 'x', 'e_time' => 0, 'last_time' => 0, 'trail' => 't2' ]);
        $wpdb->insert($this->t('bwfan_automation_complete_contact'), [ 'cid' => 3, 'aid' => $on, 'event' => 'x', 'trail' => 't3' ]);
        $wpdb->insert($this->t('bwfan_automation_step'), [ 'aid' => $on, 'type' => 2, 'action' => 'wp_sendemail', 'status' => 1 ]);

        $all = $this->ok($this->read('fka-list-automations'));
        $this->assertSame(2, $all['total']);
        $byid = array_column($all['automations'], null, 'id');
        $this->assertSame('active', $byid[ $on ]['status']);
        $this->assertSame('inactive', $byid[ $off ]['status']);
        $this->assertSame(2, $byid[ $on ]['active_contacts']);
        $this->assertSame(1, $byid[ $on ]['completed_contacts']);

        $active = $this->ok($this->read('fka-list-automations', [ 'status' => 'inactive' ]));
        $this->assertSame([ $off ], array_column($active['automations'], 'id'));

        $one = $this->ok($this->read('fka-get-automation', [ 'id' => $on ]))['automation'];
        $this->assertSame('Welcome series', $one['title']);
        $this->assertSame('wp_user_creation', $one['event']);
        $this->assertSame(1, $one['steps']);

        $this->assertSame('automation_not_found', $this->code($this->read('fka-get-automation', [ 'id' => 999999 ])));
    }

    public function test_list_contacts_is_masked_and_filters_by_tag_list_and_status(): void
    {
        global $wpdb;
        $vip   = $this->term('VIP', 1);
        $news  = $this->term('Newsletter', 2);
        $a     = $this->contact('ada@example.com', [ $vip ], [ $news ]);
        $b     = $this->contact('bob@example.com', [], [ $news ], 1, 'Bob', 'Byte');
        $c     = $this->contact('cy@example.com', [], [], 2, 'Cy', 'Bounce');
        $wpdb->insert($this->t('bwfan_message_unsubscribe'), [ 'recipient' => 'bob@example.com', 'mode' => 1, 'c_date' => '2026-01-02 00:00:00', 'c_type' => 3 ]);

        $out = $this->ok($this->read('fka-list-contacts'));
        $this->assertTrue($out['masked']);
        $this->assertSame(3, $out['total']);
        $flat = (string) wp_json_encode($out);
        $this->assertStringNotContainsString('ada@example.com', $flat);
        $this->assertStringNotContainsString('Lovelace', $flat);

        $this->assertSame([ $a ], array_column($this->ok($this->read('fka-list-contacts', [ 'tag_id' => $vip ]))['contacts'], 'id'));
        $lists = array_column($this->ok($this->read('fka-list-contacts', [ 'list_id' => $news ]))['contacts'], 'id');
        sort($lists);
        $this->assertSame([ $a, $b ], $lists);
        $this->assertSame([ $b ], array_column($this->ok($this->read('fka-list-contacts', [ 'status' => 'unsubscribed' ]))['contacts'], 'id'));
        $this->assertSame([ $c ], array_column($this->ok($this->read('fka-list-contacts', [ 'status' => 'bounced' ]))['contacts'], 'id'));
        $this->assertSame([ $a ], array_column($this->ok($this->read('fka-list-contacts', [ 'status' => 'subscribed' ]))['contacts'], 'id'));
        $this->assertSame([ $b ], array_column($this->ok($this->read('fka-list-contacts', [ 'search' => 'bob' ]))['contacts'], 'id'));
    }

    public function test_get_contact_by_id_or_email_with_tag_and_list_names(): void
    {
        $vip  = $this->term('VIP', 1);
        $news = $this->term('Newsletter', 2);
        $id   = $this->contact('ada@example.com', [ $vip ], [ $news ]);

        $one = $this->ok($this->read('fka-get-contact', [ 'id' => $id ]))['contact'];
        $this->assertSame('ada@example.com', $one['email']);
        $this->assertSame('Ada', $one['first_name']);
        $this->assertSame('subscribed', $one['status']);
        $this->assertSame([ [ 'id' => $vip, 'name' => 'VIP' ] ], $one['tags']);
        $this->assertSame([ [ 'id' => $news, 'name' => 'Newsletter' ] ], $one['lists']);

        $this->assertSame($id, $this->ok($this->read('fka-get-contact', [ 'email' => 'ada@example.com' ]))['contact']['id']);
        $this->assertSame('contact_not_found', $this->code($this->read('fka-get-contact', [ 'email' => 'nobody@example.com' ])));
        $this->assertSame('invalid_args', $this->code($this->read('fka-get-contact', [])));
    }

    public function test_list_lists_and_tags_with_member_counts(): void
    {
        $vip  = $this->term('VIP', 1);
        $news = $this->term('Newsletter', 2);
        $this->contact('ada@example.com', [ $vip ], [ $news ]);
        $this->contact('bob@example.com', [], [ $news ]);

        $tags = $this->ok($this->read('fka-list-tags'))['tags'];
        $this->assertSame([ [ 'id' => $vip, 'name' => 'VIP', 'contacts' => 1 ] ], array_map(static fn (array $t): array => array_intersect_key($t, array_flip([ 'id', 'name', 'contacts' ])), $tags));
        $lists = $this->ok($this->read('fka-list-lists'))['lists'];
        $this->assertSame(2, $lists[0]['contacts']);
        $this->assertSame($news, $lists[0]['id']);
    }

    // ---------------------------------------------------------------
    // Writes and undo
    // ---------------------------------------------------------------

    public function test_tag_and_untag_a_contact_and_undo_it(): void
    {
        $vip  = $this->term('VIP', 1);
        $lead = $this->term('Lead', 1);
        $id   = $this->contact('ada@example.com', [ $lead ]);

        $out = $this->write('fka-update-contact-tags', [ 'id' => $id, 'add' => [ $vip ], 'remove' => [ $lead ] ]);
        $res = $this->ok($out);
        $this->assertSame([ (string) $vip ], $this->ids($id, 'tags'));
        $this->assertSame([ [ 'id' => $vip, 'name' => 'VIP' ] ], $res['contact']['tags']);
        $this->assertSame(FunnelKit_Automations_Snapshot::TYPE, Snapshot_Store::get_by_operation((string) $out['operation_id'])['snapshot']['object_type']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame([ (string) $lead ], $this->ids($id, 'tags'));
    }

    public function test_undo_keeps_a_tag_added_since_and_removes_one_a_hook_added(): void
    {
        $vip   = $this->term('VIP', 1);
        $hook  = $this->term('Hooked', 1);
        $since = $this->term('Since', 1);
        $id    = $this->contact('ada@example.com');

        Fake_FunnelKit::$hook_adds_tag = $hook;
        $out                           = $this->write('fka-update-contact-tags', [ 'id' => $id, 'add' => [ $vip ] ]);
        $this->ok($out);
        $this->assertSame([ (string) $vip, (string) $hook ], array_values(array_intersect([ (string) $vip, (string) $hook ], $this->ids($id, 'tags'))));

        // Someone else tags the contact after the write.
        global $wpdb;
        $now   = $this->ids($id, 'tags');
        $now[] = (string) $since;
        $wpdb->update($this->t('bwf_contact'), [ 'tags' => wp_json_encode($now) ], [ 'id' => $id ]);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame([ (string) $since ], $this->ids($id, 'tags'));
    }

    public function test_add_and_remove_lists_and_undo(): void
    {
        $news  = $this->term('Newsletter', 2);
        $promo = $this->term('Promo', 2);
        $id    = $this->contact('ada@example.com', [], [ $promo ]);

        $out = $this->write('fka-update-contact-lists', [ 'id' => $id, 'add' => [ $news ], 'remove' => [ $promo ] ]);
        $this->ok($out);
        $this->assertSame([ (string) $news ], $this->ids($id, 'lists'));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame([ (string) $promo ], $this->ids($id, 'lists'));
    }

    public function test_an_unsubscribed_or_bounced_contact_is_never_added_to_a_list(): void
    {
        global $wpdb;
        $news    = $this->term('Newsletter', 2);
        $bounced = $this->contact('cy@example.com', [], [], 2);
        $unsub   = $this->contact('bob@example.com');
        $wpdb->insert($this->t('bwfan_message_unsubscribe'), [ 'recipient' => 'bob@example.com', 'mode' => 1, 'c_date' => '2026-01-02 00:00:00', 'c_type' => 3 ]);

        $this->assertSame('contact_suppressed', $this->code($this->write('fka-update-contact-lists', [ 'id' => $bounced, 'add' => [ $news ] ])));
        $this->assertSame('contact_suppressed', $this->code($this->write('fka-update-contact-lists', [ 'id' => $unsub, 'add' => [ $news ] ])));
        $this->assertSame([], Fake_FunnelKit::$calls);
        // Removing one is always allowed.
        $this->ok($this->write('fka-update-contact-lists', [ 'id' => $unsub, 'remove' => [ $news ] ]));
    }

    public function test_membership_writes_check_their_ids(): void
    {
        $vip  = $this->term('VIP', 1);
        $news = $this->term('Newsletter', 2);
        $id   = $this->contact('ada@example.com');

        $this->assertSame('tag_not_found', $this->code($this->write('fka-update-contact-tags', [ 'id' => $id, 'add' => [ $news ] ])));
        $this->assertSame('list_not_found', $this->code($this->write('fka-update-contact-lists', [ 'id' => $id, 'add' => [ $vip ] ])));
        $this->assertSame('contact_not_found', $this->code($this->write('fka-update-contact-tags', [ 'id' => 999999, 'add' => [ $vip ] ])));
        $this->assertSame('nothing_to_update', $this->code($this->write('fka-update-contact-tags', [ 'id' => $id ])));
        $this->assertSame('invalid_args', $this->code($this->write('fka-update-contact-tags', [ 'id' => $id, 'add' => [ $vip ], 'remove' => [ $vip ] ])));
        $this->assertSame([], Fake_FunnelKit::$calls);
    }

    public function test_set_automation_status_and_undo(): void
    {
        $id  = $this->automation('Welcome', 1);
        $out = $this->write('fka-set-automation-status', [ 'id' => $id, 'status' => 'inactive' ]);
        $this->assertSame('inactive', $this->ok($out)['automation']['status']);
        $this->assertSame(2, $this->automation_status($id));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame(1, $this->automation_status($id));

        $this->assertSame('nothing_to_update', $this->code($this->write('fka-set-automation-status', [ 'id' => $id, 'status' => 'active' ])));
        $this->assertSame('automation_not_found', $this->code($this->write('fka-set-automation-status', [ 'id' => 999999, 'status' => 'active' ])));
    }

    public function test_undo_keeps_an_automation_status_changed_since(): void
    {
        global $wpdb;
        $id  = $this->automation('Welcome', 2);
        $out = $this->write('fka-set-automation-status', [ 'id' => $id, 'status' => 'active' ]);
        $this->ok($out);
        // Someone sets it to a status of their own after the write.
        $wpdb->update($this->t('bwfan_automations'), [ 'status' => 3 ], [ 'ID' => $id ]);

        Rollback_Service::restore_operation((string) $out['operation_id']);
        $this->assertSame(3, $this->automation_status($id));
        $this->assertStringContainsString('changed since', implode(' ', Rollback_Service::take_warnings()));
    }

    public function test_undo_takes_funnelkit_access(): void
    {
        $vip = $this->term('VIP', 1);
        $id  = $this->contact('ada@example.com');
        $out = $this->write('fka-update-contact-tags', [ 'id' => $id, 'add' => [ $vip ] ]);
        $this->ok($out);

        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertFalse(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame([ (string) $vip ], $this->ids($id, 'tags'));
    }
}
