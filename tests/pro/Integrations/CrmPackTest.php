<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\CRM_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\MCP\Elicitation;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Crm_Rows_Snapshot;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tests\Support\Fake_FluentCRM;
use WPMCP\Tests\Support\Fake_MailPoet;

require_once __DIR__ . '/../../support/crm-tables.php';
require_once __DIR__ . '/../../support/crm-doubles.php';

/**
 * FluentCRM and MailPoet contacts, lists, tags, campaigns and automations
 * (issue #477): paid-tier ops on the plugin-data dispatcher pair, so they add
 * no top-level tools.
 *
 * Neither plugin is loaded in the shared test core. Their tables are built
 * from the plugins' own schemas (tests/support/crm-tables.php), presence is
 * driven through wpmcp_fluentcrm_active and wpmcp_mailpoet_active, and the
 * calls into each plugin's API are replaced by doubles that write those
 * tables the way the plugin does (tests/support/crm-doubles.php). The real
 * API calls run in the live CRM group (CrmLiveTest).
 *
 * Every write is snapshotted as a 'crm_rows' image, and rollback-operation
 * restores contacts, memberships, custom field values and lists exactly,
 * except that it never sets a contact who unsubscribed or bounced back to a
 * mailable status, and never overrides a status the person changed since.
 */
class CrmPackTest extends \WP_UnitTestCase
{
    private const OTHER_FILTERS = [ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active', 'wpmcp_buddypress_active', 'wpmcp_tutor_active', 'wpmcp_lifterlms_active' ];

    private const LISTS = 'FluentCrm\\App\\Models\\Lists';

    private const TAGS = 'FluentCrm\\App\\Models\\Tag';

    private const MAILPOET_CAPS = [ 'mailpoet_manage_subscribers', 'mailpoet_manage_segments', 'mailpoet_manage_emails', 'mailpoet_manage_automations' ];

    private int $admin;

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_crm_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_crm_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        CRM_Pack::use_adapters_for_tests([ new Fake_FluentCRM(), new Fake_MailPoet() ]);
        Fake_FluentCRM::$calls        = [];
        Fake_MailPoet::$calls         = [];
        Fake_MailPoet::$confirmation  = true;
        $this->admin                  = self::factory()->user->create([ 'role' => 'administrator' ]);
        $user                         = get_userdata($this->admin);
        foreach (self::MAILPOET_CAPS as $cap) {
            $user->add_cap($cap);
        }
        wp_set_current_user($this->admin);
        add_filter('wpmcp_fluentcrm_active', '__return_true');
        add_filter('wpmcp_mailpoet_active', '__return_true');
        foreach (self::OTHER_FILTERS as $filter) {
            add_filter($filter, '__return_false');
        }
    }

    protected function tearDown(): void
    {
        CRM_Pack::use_adapters_for_tests(null);
        Gate::set_pro_for_tests(null);
        foreach (array_merge(self::OTHER_FILTERS, [ 'wpmcp_fluentcrm_active', 'wpmcp_mailpoet_active', 'wpmcp_crm_direct_subscribe', 'wpmcp_enable_crm_send' ]) as $filter) {
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

    private function write(string $op, array $args, bool $confirm = false, ?string $session = null): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if ($confirm) {
            $call['confirm'] = true;
        }
        if (null !== $session) {
            $call['session_id'] = $session;
        }
        return (new Plugin_Data_Integration())->handle_write($call);
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

    /** Every row of a table, ordered by id. */
    private function rows(string $name): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id ASC', $this->t($name)), ARRAY_A);
    }

    private function insert(string $name, array $row): int
    {
        global $wpdb;
        $this->assertNotFalse($wpdb->insert($this->t($name), $row), $wpdb->last_error);
        return (int) $wpdb->insert_id;
    }

    private function fc_contact(string $email, string $status = 'subscribed', array $extra = []): int
    {
        return $this->insert('fc_subscribers', $extra + [ 'email' => $email, 'status' => $status, 'first_name' => 'First', 'last_name' => 'Last', 'created_at' => '2026-01-01 00:00:00' ]);
    }

    private function fc_group(string $kind, string $title): int
    {
        return $this->insert('list' === $kind ? 'fc_lists' : 'fc_tags', [ 'title' => $title, 'slug' => sanitize_title($title), 'description' => '' ]);
    }

    private function fc_member(int $contact, string $kind, int $group): int
    {
        return $this->insert('fc_subscriber_pivot', [ 'subscriber_id' => $contact, 'object_id' => $group, 'object_type' => 'list' === $kind ? self::LISTS : self::TAGS ]);
    }

    private function fc_custom_fields(): void
    {
        $this->insert('fc_meta', [
            'object_type' => 'option',
            'key'         => 'contact_custom_fields',
            'value'       => maybe_serialize([
                [ 'slug' => 'company_size', 'label' => 'Company size', 'type' => 'select-one', 'options' => [ 'small', 'large' ] ],
                [ 'slug' => 'nickname', 'label' => 'Nickname', 'type' => 'text' ],
            ]),
        ]);
    }

    private function mp_contact(string $email, string $status = 'subscribed', array $extra = []): int
    {
        return $this->insert('mailpoet_subscribers', $extra + [ 'email' => $email, 'status' => $status, 'first_name' => 'First', 'last_name' => 'Last', 'created_at' => '2026-01-01 00:00:00' ]);
    }

    private function mp_list(string $name, string $type = 'default'): int
    {
        return $this->insert('mailpoet_segments', [ 'name' => $name, 'type' => $type, 'description' => '' ]);
    }

    private function mp_tag(string $name): int
    {
        return $this->insert('mailpoet_tags', [ 'name' => $name, 'description' => '' ]);
    }

    private function mp_member(int $contact, int $list, string $status = 'subscribed'): int
    {
        return $this->insert('mailpoet_subscriber_segment', [ 'subscriber_id' => $contact, 'segment_id' => $list, 'status' => $status ]);
    }

    private function editor(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
    }

    // ---------------------------------------------------------------
    // Catalog, presence and tier
    // ---------------------------------------------------------------

    public function test_every_op_is_on_the_pair_for_both_plugins_paid_tier(): void
    {
        $catalog = $this->ok($this->read('list-operations'));
        $names   = array_column($catalog['operations'], 'mode', 'name');
        foreach ([ 'fluentcrm', 'mailpoet' ] as $p) {
            foreach ([ 'summary', 'list-contacts', 'get-contact', 'list-lists', 'list-tags', 'list-custom-fields', 'list-campaigns', 'get-campaign', 'list-automations' ] as $op) {
                $this->assertSame('read', $names[ "{$p}-{$op}" ] ?? null, "{$p}-{$op}");
            }
            foreach ([ 'create-contact', 'update-contact', 'update-contact-lists', 'update-contact-tags', 'create-list', 'update-list', 'create-tag', 'update-tag', 'import-contacts', 'create-campaign', 'update-campaign', 'duplicate-campaign', 'send-test-campaign', 'set-automation-status' ] as $op) {
                $this->assertSame('write', $names[ "{$p}-{$op}" ] ?? null, "{$p}-{$op}");
            }
            foreach ([ 'delete-contact', 'delete-list', 'delete-tag', 'send-campaign' ] as $op) {
                $this->assertSame('destructive', $names[ "{$p}-{$op}" ] ?? null, "{$p}-{$op}");
            }
        }
        $catalog = array_column($catalog['operations'], null, 'name');
        $this->assertFalse($catalog['fluentcrm-send-campaign']['enabled'], 'sending to real recipients is off by default');
        $this->assertTrue($catalog['fluentcrm-send-campaign']['requires_confirm']);
        $this->assertTrue($catalog['mailpoet-send-test-campaign']['enabled'], 'a test goes only to the current user');

        Gate::set_pro_for_tests(false);
        $free = array_column($this->ok($this->read('list-operations'))['operations'], 'name');
        $this->assertSame([], preg_grep('/^(fluentcrm|mailpoet)-/', $free), 'the CRM ops are paid-tier');
        $this->assertFalse(CRM_Pack::active());
    }

    public function test_pair_registers_while_only_a_crm_plugin_is_loaded_and_ops_answer_inactive(): void
    {
        remove_all_filters('wpmcp_mailpoet_active');
        add_filter('wpmcp_mailpoet_active', '__return_false');
        $integration = new Plugin_Data_Integration();
        $this->assertTrue($integration->is_available());
        $this->assertTrue($integration->should_register());

        $out = $this->read('mailpoet-list-contacts');
        $this->assertSame('mailpoet_inactive', $this->code($out));
        $catalog = array_column($this->ok($this->read('list-operations'))['operations'], null, 'name');
        $this->assertFalse($catalog['mailpoet-list-contacts']['dependency_met'], 'an inactive plugin stays documented');
        $this->assertTrue($catalog['fluentcrm-list-contacts']['dependency_met']);

        remove_all_filters('wpmcp_fluentcrm_active');
        add_filter('wpmcp_fluentcrm_active', '__return_false');
        $this->assertFalse($integration->is_available());
        $this->assertFalse($integration->should_register());
    }

    public function test_pair_description_names_the_crm_plugins(): void
    {
        [$read] = (new Plugin_Data_Integration())->abilities();
        $this->assertStringContainsString('FluentCRM and MailPoet', $read->description);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_fluentcrm_reads_contacts_lists_tags_fields_campaigns_and_automations(): void
    {
        $this->fc_custom_fields();
        $news   = $this->fc_group('list', 'Newsletter');
        $vip    = $this->fc_group('tag', 'VIP');
        $ada    = $this->fc_contact('ada@example.com', 'subscribed', [ 'first_name' => 'Ada', 'last_name' => 'Lovelace' ]);
        $bob    = $this->fc_contact('bob@example.com', 'pending', [ 'first_name' => 'Bob' ]);
        $carol  = $this->fc_contact('carol@example.com', 'unsubscribed', [ 'first_name' => 'Carol' ]);
        $this->fc_member($ada, 'list', $news);
        $this->fc_member($ada, 'tag', $vip);
        $this->fc_member($bob, 'list', $news);
        $this->insert('fc_subscriber_meta', [ 'subscriber_id' => $ada, 'created_by' => 0, 'object_type' => 'custom_field', 'key' => 'nickname', 'value' => 'Countess' ]);
        $this->insert('fc_campaigns', [ 'type' => 'campaign', 'title' => 'October', 'slug' => 'october', 'status' => 'scheduled', 'email_subject' => 'News', 'email_body' => 'x', 'recipients_count' => 2, 'scheduled_at' => '2026-10-20 09:00:00' ]);
        $this->insert('fc_campaigns', [ 'type' => 'campaign', 'title' => 'Draft', 'slug' => 'draft', 'status' => 'draft', 'email_body' => '' ]);
        $funnel = $this->insert('fc_funnels', [ 'title' => 'Welcome', 'trigger_name' => 'fluentcrm_contact_added_to_lists', 'status' => 'published' ]);
        $this->insert('fc_funnel_subscribers', [ 'funnel_id' => $funnel, 'subscriber_id' => $ada, 'status' => 'active' ]);
        $this->insert('fc_funnel_subscribers', [ 'funnel_id' => $funnel, 'subscriber_id' => $bob, 'status' => 'completed' ]);

        $all = $this->ok($this->read('fluentcrm-list-contacts'));
        $this->assertSame(3, $all['total']);
        $this->assertSame([ $carol, $bob, $ada ], array_column($all['contacts'], 'id'), 'newest first');
        $this->assertSame([ $ada ], array_column($this->ok($this->read('fluentcrm-list-contacts', [ 'search' => 'lovelace' ]))['contacts'], 'id'));
        $this->assertSame([ $ada ], array_column($this->ok($this->read('fluentcrm-list-contacts', [ 'search' => 'Ada Love' ]))['contacts'], 'id'));
        $this->assertSame([ $bob ], array_column($this->ok($this->read('fluentcrm-list-contacts', [ 'status' => 'pending' ]))['contacts'], 'id'));
        $this->assertSame([ $bob, $ada ], array_column($this->ok($this->read('fluentcrm-list-contacts', [ 'list_id' => $news ]))['contacts'], 'id'));
        $this->assertSame([ $ada ], array_column($this->ok($this->read('fluentcrm-list-contacts', [ 'tag_id' => $vip ]))['contacts'], 'id'));
        $page = $this->ok($this->read('fluentcrm-list-contacts', [ 'page' => 2, 'per_page' => 2 ]));
        $this->assertSame([ $ada ], array_column($page['contacts'], 'id'));
        $this->assertSame(3, $page['total']);

        $one = $this->ok($this->read('fluentcrm-get-contact', [ 'id' => $ada ]));
        $this->assertSame('ada@example.com', $one['email']);
        $this->assertSame([ [ 'id' => $news, 'title' => 'Newsletter' ] ], $one['lists']);
        $this->assertSame([ [ 'id' => $vip, 'title' => 'VIP' ] ], $one['tags']);
        $this->assertSame([ 'nickname' => 'Countess' ], $one['custom_fields']);
        $this->assertSame($bob, $this->ok($this->read('fluentcrm-get-contact', [ 'email' => 'bob@example.com' ]))['id']);
        $this->assertSame('contact_not_found', $this->code($this->read('fluentcrm-get-contact', [ 'email' => 'nobody@example.com' ])));
        $this->assertSame('invalid_args', $this->code($this->read('fluentcrm-get-contact', [])));

        $lists = $this->ok($this->read('fluentcrm-list-lists'))['lists'];
        $this->assertSame([ [ 'id' => $news, 'title' => 'Newsletter', 'slug' => 'newsletter', 'description' => '', 'contacts' => 2 ] ], $lists);
        $this->assertSame(1, $this->ok($this->read('fluentcrm-list-tags'))['tags'][0]['contacts']);

        $fields = $this->ok($this->read('fluentcrm-list-custom-fields'))['fields'];
        $this->assertSame([ 'company_size', 'nickname' ], array_column($fields, 'key'));
        $this->assertSame([ 'small', 'large' ], $fields[0]['options']);

        $campaigns = $this->ok($this->read('fluentcrm-list-campaigns'));
        $this->assertSame(2, $campaigns['total']);
        $this->assertSame([ 'Draft', 'October' ], array_column($campaigns['campaigns'], 'title'));
        $scheduled = $this->ok($this->read('fluentcrm-list-campaigns', [ 'status' => 'scheduled' ]))['campaigns'];
        $this->assertSame('2026-10-20 09:00:00', $scheduled[0]['scheduled_at']);
        $this->assertSame(2, $scheduled[0]['recipients']);
        $this->assertSame('News', $scheduled[0]['subject']);

        $automations = $this->ok($this->read('fluentcrm-list-automations'))['automations'];
        $this->assertSame('Welcome', $automations[0]['title']);
        $this->assertSame(1, $automations[0]['enrolled']);
        $this->assertSame(2, $automations[0]['enrolled_total']);

        $summary = $this->ok($this->read('fluentcrm-summary'));
        $this->assertSame(3, $summary['contacts']['total']);
        $this->assertSame([ 'pending' => 1, 'subscribed' => 1, 'unsubscribed' => 1 ], $summary['contacts']['by_status']);
        $this->assertSame(1, $summary['lists']);
        $this->assertSame(2, $summary['custom_fields']);
        $this->assertSame('pending', $summary['opt_in']['new_contact_status']);
        $this->assertFalse($summary['campaign_send']);
    }

    public function test_mailpoet_reads_subscribers_lists_tags_fields_campaigns_and_automations(): void
    {
        $news   = $this->mp_list('Newsletter');
        $wp     = $this->mp_list('WordPress Users', 'wp_users');
        $tag    = $this->mp_tag('VIP');
        $field  = $this->insert('mailpoet_custom_fields', [ 'name' => 'City', 'type' => 'text', 'params' => serialize([ 'required' => '1' ]) ]);
        $ada    = $this->mp_contact('ada@example.com', 'subscribed', [ 'first_name' => 'Ada' ]);
        $bob    = $this->mp_contact('bob@example.com', 'unconfirmed');
        $gone   = $this->mp_contact('gone@example.com', 'subscribed', [ 'deleted_at' => '2026-02-02 00:00:00' ]);
        $this->mp_member($ada, $news);
        $this->mp_member($bob, $news, 'unsubscribed');
        $this->mp_member($ada, $wp);
        $this->insert('mailpoet_subscriber_tag', [ 'subscriber_id' => $ada, 'tag_id' => $tag ]);
        $this->insert('mailpoet_subscriber_custom_field', [ 'subscriber_id' => $ada, 'custom_field_id' => $field, 'value' => 'Lahore' ]);
        $this->insert('mailpoet_settings', [ 'name' => 'signup_confirmation', 'value' => serialize([ 'enabled' => '1' ]) ]);
        $draft = $this->insert('mailpoet_newsletters', [ 'subject' => 'Draft news', 'type' => 'standard', 'status' => 'draft' ]);
        $this->insert('mailpoet_newsletter_segment', [ 'newsletter_id' => $draft, 'segment_id' => $news ]);
        $sent = $this->insert('mailpoet_newsletters', [ 'subject' => 'Sent news', 'type' => 'standard', 'status' => 'sent' ]);
        $task = $this->insert('mailpoet_scheduled_tasks', [ 'type' => 'sending', 'status' => 'completed', 'scheduled_at' => '2026-09-01 08:00:00' ]);
        $this->insert('mailpoet_sending_queues', [ 'task_id' => $task, 'newsletter_id' => $sent, 'count_total' => 42 ]);
        $this->insert('mailpoet_newsletters', [ 'subject' => 'Welcome', 'type' => 'welcome', 'status' => 'active' ]);
        $auto = $this->insert('mailpoet_automations', [ 'name' => 'Onboarding', 'author' => 1, 'status' => 'active' ]);
        $this->insert('mailpoet_automation_runs', [ 'automation_id' => $auto, 'version_id' => 1, 'trigger_key' => 'x', 'status' => 'running' ]);
        $this->insert('mailpoet_automation_runs', [ 'automation_id' => $auto, 'version_id' => 1, 'trigger_key' => 'x', 'status' => 'complete' ]);

        $all = $this->ok($this->read('mailpoet-list-contacts'));
        $this->assertSame([ $bob, $ada ], array_column($all['contacts'], 'id'), 'trashed subscribers are left out');
        $this->assertSame([ $ada ], array_column($this->ok($this->read('mailpoet-list-contacts', [ 'tag_id' => $tag ]))['contacts'], 'id'));
        $this->assertSame([ $bob, $ada ], array_column($this->ok($this->read('mailpoet-list-contacts', [ 'list_id' => $news ]))['contacts'], 'id'));
        $this->assertSame([ $bob ], array_column($this->ok($this->read('mailpoet-list-contacts', [ 'status' => 'unconfirmed', 'search' => 'bob' ]))['contacts'], 'id'));

        $one = $this->ok($this->read('mailpoet-get-contact', [ 'email' => 'ada@example.com' ]));
        $this->assertSame([ $news, $wp ], array_column($one['lists'], 'id'));
        $this->assertSame('subscribed', $one['lists'][0]['status']);
        $this->assertSame([ [ 'id' => $tag, 'title' => 'VIP' ] ], $one['tags']);
        $this->assertSame([ 'cf_' . $field => 'Lahore' ], $one['custom_fields']);
        $this->assertSame('contact_not_found', $this->code($this->read('mailpoet-get-contact', [ 'id' => $gone ])));

        $lists = $this->ok($this->read('mailpoet-list-lists'))['lists'];
        $this->assertSame([ $news ], array_column($lists, 'id'), 'only MailPoet lists of its own default type');
        $this->assertSame(1, $lists[0]['contacts'], 'subscribed members only');
        $this->assertSame(1, $this->ok($this->read('mailpoet-list-tags'))['tags'][0]['contacts']);
        $fields = $this->ok($this->read('mailpoet-list-custom-fields'))['fields'];
        $this->assertSame('cf_' . $field, $fields[0]['key']);
        $this->assertTrue($fields[0]['required']);

        $campaigns = array_column($this->ok($this->read('mailpoet-list-campaigns'))['campaigns'], null, 'id');
        $this->assertSame([ $sent, $draft ], array_keys($campaigns), 'standard newsletters only');
        $this->assertSame(1, $campaigns[ $draft ]['recipients'], 'subscribed members of its lists');
        $this->assertSame([ $news ], $campaigns[ $draft ]['lists']);
        $this->assertSame(42, $campaigns[ $sent ]['recipients'], 'the queued total once queued');
        $this->assertSame('2026-09-01 08:00:00', $campaigns[ $sent ]['scheduled_at']);
        $this->assertSame([ $draft ], array_column($this->ok($this->read('mailpoet-list-campaigns', [ 'status' => 'draft' ]))['campaigns'], 'id'));

        $automations = $this->ok($this->read('mailpoet-list-automations'))['automations'];
        $this->assertSame([ 1, 2 ], [ $automations[0]['enrolled'], $automations[0]['enrolled_total'] ]);

        $summary = $this->ok($this->read('mailpoet-summary'));
        $this->assertSame(2, $summary['contacts']['total']);
        $this->assertTrue($summary['opt_in']['signup_confirmation']);
        $this->assertSame('unconfirmed', $summary['opt_in']['new_contact_status']);
        $this->assertSame([ 'draft' => 1, 'sent' => 1 ], $summary['campaigns']);
        $this->assertSame([ 'active' => 1 ], $summary['automations']);
    }

    public function test_reads_and_writes_need_the_plugins_own_permission(): void
    {
        $this->editor();
        $this->assertSame('operation_denied', $this->code($this->read('fluentcrm-list-contacts')), 'FluentCRM permission, not just the pair capability');
        $this->assertSame('operation_denied', $this->code($this->read('mailpoet-list-contacts')));
        $this->assertSame('operation_denied', $this->code($this->write('mailpoet-create-contact', [ 'email' => 'x@example.com' ])));
        $this->assertSame('operation_denied', $this->code($this->read('mailpoet-list-campaigns')));

        $editor = get_userdata(get_current_user_id());
        $editor->add_cap('mailpoet_manage_subscribers');
        wp_set_current_user(0);
        wp_set_current_user($editor->ID);
        $this->ok($this->read('mailpoet-list-contacts'));
        $this->assertSame('operation_denied', $this->code($this->write('mailpoet-create-list', [ 'title' => 'X' ])), 'lists take mailpoet_manage_segments');
        $this->assertSame([], Fake_MailPoet::$calls);
    }

    // ---------------------------------------------------------------
    // Contact writes and consent
    // ---------------------------------------------------------------

    public function test_fluentcrm_create_contact_is_pending_with_double_optin_and_rollback_removes_it(): void
    {
        $this->fc_custom_fields();
        $news = $this->fc_group('list', 'Newsletter');
        $vip  = $this->fc_group('tag', 'VIP');
        $out  = $this->write('fluentcrm-create-contact', [ 'email' => 'new@example.com', 'first_name' => 'New', 'lists' => [ $news ], 'tags' => [ $vip ], 'custom_fields' => [ 'nickname' => 'Newbie' ] ]);
        $contact = $this->ok($out)['contact'];
        $this->assertTrue($out['recoverable']);
        $this->assertSame('pending', $contact['status'], 'a new contact confirms by double opt-in');
        $this->assertSame('pending', Fake_FluentCRM::$calls[0][1][5]);
        $this->assertSame([ $news ], array_column($contact['lists'], 'id'));
        $this->assertSame([ $vip ], array_column($contact['tags'], 'id'));
        $this->assertSame([ 'nickname' => 'Newbie' ], $contact['custom_fields']);

        $stored = Snapshot_Store::get_by_operation((string) $out['operation_id'])['snapshot'];
        $this->assertSame(Crm_Rows_Snapshot::key('fluentcrm_contact', $contact['id']), $stored['object_id'], 'a create is re-keyed on the id FluentCRM assigned');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame([], $this->rows('fc_subscribers'));
        $this->assertSame([], $this->rows('fc_subscriber_pivot'));
        $this->assertSame([], $this->rows('fc_subscriber_meta'));
    }

    public function test_create_contact_refuses_direct_subscribe_existing_email_and_unknown_ids(): void
    {
        $this->fc_contact('taken@example.com');
        $this->assertSame('double_optin_required', $this->code($this->write('fluentcrm-create-contact', [ 'email' => 'a@example.com', 'status' => 'subscribed' ])));
        $this->assertSame('contact_exists', $this->code($this->write('fluentcrm-create-contact', [ 'email' => 'taken@example.com' ])));
        $this->assertSame('list_not_found', $this->code($this->write('fluentcrm-create-contact', [ 'email' => 'a@example.com', 'lists' => [ 999 ] ])));
        $this->assertSame('tag_not_found', $this->code($this->write('fluentcrm-create-contact', [ 'email' => 'a@example.com', 'tags' => [ 999 ] ])));
        $this->assertSame('unknown_field', $this->code($this->write('fluentcrm-create-contact', [ 'email' => 'a@example.com', 'custom_fields' => [ 'nope' => 1 ] ])));
        $this->assertSame('invalid_args', $this->code($this->write('fluentcrm-create-contact', [ 'email' => 'not an email' ])));
        $this->assertSame([], Fake_FluentCRM::$calls, 'no refusal reaches FluentCRM');

        add_filter('wpmcp_crm_direct_subscribe', '__return_true');
        $contact = $this->ok($this->write('fluentcrm-create-contact', [ 'email' => 'a@example.com', 'status' => 'subscribed' ]))['contact'];
        $this->assertSame('subscribed', $contact['status'], 'a site may allow direct subscribes');
        $this->assertTrue($this->ok($this->read('fluentcrm-summary'))['opt_in']['direct_subscribe']);
    }

    public function test_update_contact_and_rollback_restore_fields_exactly(): void
    {
        $this->fc_custom_fields();
        $id = $this->fc_contact('ada@example.com');
        $this->insert('fc_subscriber_meta', [ 'subscriber_id' => $id, 'created_by' => 0, 'object_type' => 'custom_field', 'key' => 'nickname', 'value' => 'Old' ]);
        $before_contact = $this->rows('fc_subscribers');
        $before_meta    = $this->rows('fc_subscriber_meta');

        $out     = $this->write('fluentcrm-update-contact', [ 'id' => $id, 'first_name' => 'Augusta', 'custom_fields' => [ 'nickname' => 'New', 'company_size' => 'large' ] ]);
        $contact = $this->ok($out)['contact'];
        $this->assertSame('Augusta', $contact['first_name']);
        $this->assertSame([ 'nickname' => 'New', 'company_size' => 'large' ], $contact['custom_fields']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before_contact, $this->rows('fc_subscribers'));
        $this->assertSame($before_meta, $this->rows('fc_subscriber_meta'), 'the changed value back, the added one gone');

        $this->assertSame('nothing_to_update', $this->code($this->write('fluentcrm-update-contact', [ 'id' => $id ])));
        $this->assertSame('contact_not_found', $this->code($this->write('fluentcrm-update-contact', [ 'id' => 999, 'first_name' => 'X' ])));
        $this->assertSame('invalid_args', $this->code($this->write('fluentcrm-update-contact', [ 'id' => $id, 'status' => 'subscribed' ])), 'unsubscribed is the only status a write sets');
    }

    public function test_an_unsubscribe_is_never_undone_by_a_rollback(): void
    {
        $id  = $this->fc_contact('ada@example.com', 'subscribed');
        $out = $this->write('fluentcrm-update-contact', [ 'id' => $id, 'status' => 'unsubscribed', 'last_name' => 'Byron' ]);
        $this->assertSame('unsubscribed', $this->ok($out)['contact']['status']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $row = $this->rows('fc_subscribers')[0];
        $this->assertSame('unsubscribed', $row['status'], 'an unsubscribed contact is never set back to subscribed');
        $this->assertSame('Last', $row['last_name'], 'the rest of the write is undone');
        $this->assertStringContainsString('status kept as unsubscribed', implode(' ', Rollback_Service::take_warnings()));
    }

    public function test_rollback_keeps_a_status_the_person_changed_since(): void
    {
        $id  = $this->fc_contact('ada@example.com', 'pending');
        $vip = $this->fc_group('tag', 'VIP');
        $out = $this->write('fluentcrm-update-contact-tags', [ 'id' => $id, 'add' => [ $vip ] ]);
        $this->ok($out);

        // The person confirms their opt-in after the write.
        global $wpdb;
        $wpdb->update($this->t('fc_subscribers'), [ 'status' => 'subscribed' ], [ 'id' => $id ]);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('subscribed', $this->rows('fc_subscribers')[0]['status'], 'the confirmation stands');
        $this->assertSame([], $this->rows('fc_subscriber_pivot'), 'the tag is undone');
    }

    public function test_rollback_of_a_create_leaves_a_contact_who_confirmed_since(): void
    {
        $out = $this->write('fluentcrm-create-contact', [ 'email' => 'new@example.com' ]);
        $id  = $this->ok($out)['contact']['id'];
        global $wpdb;
        $wpdb->update($this->t('fc_subscribers'), [ 'status' => 'subscribed' ], [ 'id' => $id ]);

        Rollback_Service::restore_operation((string) $out['operation_id']);
        $this->assertSame([ $id ], array_map('intval', array_column($this->rows('fc_subscribers'), 'id')));
        $this->assertStringContainsString('left in place', implode(' ', Rollback_Service::take_warnings()));
    }

    public function test_membership_writes_and_rollback_restore_lists_and_tags_exactly(): void
    {
        $id   = $this->fc_contact('ada@example.com');
        $a    = $this->fc_group('list', 'A');
        $b    = $this->fc_group('list', 'B');
        $t1   = $this->fc_group('tag', 'T1');
        $t2   = $this->fc_group('tag', 'T2');
        $this->fc_member($id, 'list', $a);
        $this->fc_member($id, 'tag', $t1);
        $before = $this->rows('fc_subscriber_pivot');

        $lists = $this->write('fluentcrm-update-contact-lists', [ 'id' => $id, 'add' => [ $b ], 'remove' => [ $a ] ]);
        $this->assertSame([ $b ], array_column($this->ok($lists)['contact']['lists'], 'id'));
        $tags = $this->write('fluentcrm-update-contact-tags', [ 'id' => $id, 'add' => [ $t2 ], 'remove' => [ $t1 ] ]);
        $this->assertSame([ $t2 ], array_column($this->ok($tags)['contact']['tags'], 'id'));

        $this->assertTrue(Rollback_Service::restore_operation((string) $tags['operation_id']));
        $this->assertTrue(Rollback_Service::restore_operation((string) $lists['operation_id']));
        $this->assertSame($before, $this->rows('fc_subscriber_pivot'), 'the removed rows are back at their ids, the added ones gone');

        $this->assertSame('nothing_to_update', $this->code($this->write('fluentcrm-update-contact-tags', [ 'id' => $id ])));
        $this->assertSame('invalid_args', $this->code($this->write('fluentcrm-update-contact-tags', [ 'id' => $id, 'add' => [ $t1 ], 'remove' => [ $t1 ] ])));
        $this->assertSame('tag_not_found', $this->code($this->write('fluentcrm-update-contact-tags', [ 'id' => $id, 'add' => [ 999 ] ])));
    }

    public function test_a_suppressed_contact_is_never_added_to_a_list(): void
    {
        $news = $this->fc_group('list', 'Newsletter');
        foreach ([ 'unsubscribed', 'bounced', 'complained' ] as $status) {
            $id  = $this->fc_contact($status . '@example.com', $status);
            $out = $this->write('fluentcrm-update-contact-lists', [ 'id' => $id, 'add' => [ $news ] ]);
            $this->assertSame('contact_suppressed', $this->code($out), $status);
            $this->ok($this->write('fluentcrm-update-contact-tags', [ 'id' => $id, 'add' => [ $this->fc_group('tag', 'T ' . $status) ] ]));
        }
        $this->assertSame([], array_filter(Fake_FluentCRM::$calls, static fn (array $call): bool => 'attach' === $call[0] && 'list' === $call[1][1]));

        $mp_list = $this->mp_list('News');
        $mp_gone = $this->mp_contact('left@example.com', 'subscribed');
        $this->mp_member($mp_gone, $mp_list, 'unsubscribed');
        $out = $this->write('mailpoet-update-contact-lists', [ 'id' => $mp_gone, 'add' => [ $mp_list ] ]);
        $this->assertSame('contact_suppressed', $this->code($out), 'MailPoet would resubscribe someone who left the list');
        $this->assertSame([ $mp_list ], $out['error']['data']['lists']);
        $bounced = $this->mp_contact('bounce@example.com', 'bounced');
        $this->assertSame('contact_suppressed', $this->code($this->write('mailpoet-update-contact-lists', [ 'id' => $bounced, 'add' => [ $mp_list ] ])));
        $this->assertSame([], Fake_MailPoet::$calls);
    }

    public function test_delete_contact_needs_confirm_and_rollback_puts_every_row_back(): void
    {
        $id   = $this->fc_contact('ada@example.com');
        $list = $this->fc_group('list', 'Newsletter');
        $this->fc_member($id, 'list', $list);
        $this->insert('fc_subscriber_notes', [ 'subscriber_id' => $id, 'title' => 'Called', 'description' => 'note' ]);
        $this->insert('fc_campaign_emails', [ 'subscriber_id' => $id, 'campaign_id' => 1, 'email_address' => 'ada@example.com', 'status' => 'sent' ]);
        $before = [ $this->rows('fc_subscribers'), $this->rows('fc_subscriber_pivot'), $this->rows('fc_subscriber_notes'), $this->rows('fc_campaign_emails') ];

        $this->assertSame('confirmation_required', $this->code($this->write('fluentcrm-delete-contact', [ 'id' => $id ])));
        $out = $this->write('fluentcrm-delete-contact', [ 'id' => $id ], true);
        $this->assertSame([ 'deleted' => $id ], $this->ok($out));
        $this->assertSame([], $this->rows('fc_subscribers'));
        $this->assertSame([], $this->rows('fc_subscriber_notes'));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, [ $this->rows('fc_subscribers'), $this->rows('fc_subscriber_pivot'), $this->rows('fc_subscriber_notes'), $this->rows('fc_campaign_emails') ]);
    }

    public function test_rollback_of_a_delete_reports_a_contact_whose_email_was_taken_since(): void
    {
        $id  = $this->fc_contact('ada@example.com');
        $out = $this->write('fluentcrm-delete-contact', [ 'id' => $id ], true);
        $this->ok($out);
        $other = $this->fc_contact('ada@example.com');

        Rollback_Service::restore_operation((string) $out['operation_id']);
        $this->assertSame([ $other ], array_map('intval', array_column($this->rows('fc_subscribers'), 'id')));
        $this->assertStringContainsString('could not be re-inserted', implode(' ', Rollback_Service::take_warnings()));
    }

    public function test_mailpoet_contact_writes_follow_its_confirmation_setting_and_roll_back(): void
    {
        $list  = $this->mp_list('Newsletter');
        $tag   = $this->mp_tag('VIP');
        $field = $this->insert('mailpoet_custom_fields', [ 'name' => 'City', 'type' => 'text', 'params' => serialize([]) ]);
        $out   = $this->write('mailpoet-create-contact', [ 'email' => 'new@example.com', 'first_name' => 'New', 'lists' => [ $list ], 'tags' => [ $tag ], 'custom_fields' => [ 'cf_' . $field => 'Lahore' ] ]);
        $new   = $this->ok($out)['contact'];
        $this->assertSame('unconfirmed', $new['status'], 'MailPoet sign-up confirmation decides');
        $this->assertSame([ 'cf_' . $field => 'Lahore' ], $new['custom_fields']);
        $this->assertSame('invalid_args', $this->code($this->write('mailpoet-create-contact', [ 'email' => 'b@example.com', 'status' => 'subscribed' ])), 'MailPoet takes no status');
        $this->assertSame('contact_exists', $this->code($this->write('mailpoet-create-contact', [ 'email' => 'new@example.com' ])));
        $this->assertSame('unknown_field', $this->code($this->write('mailpoet-create-contact', [ 'email' => 'b@example.com', 'custom_fields' => [ 'cf_999' => 'x' ] ])));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame([], $this->rows('mailpoet_subscribers'));
        $this->assertSame([], $this->rows('mailpoet_subscriber_segment'));
        $this->assertSame([], $this->rows('mailpoet_subscriber_tag'));
        $this->assertSame([], $this->rows('mailpoet_subscriber_custom_field'));
    }

    public function test_mailpoet_list_removal_unsubscribe_and_trash_roll_back(): void
    {
        $list = $this->mp_list('Newsletter');
        $id   = $this->mp_contact('ada@example.com', 'subscribed');
        $this->mp_member($id, $list);
        $before_members = $this->rows('mailpoet_subscriber_segment');

        $removed = $this->write('mailpoet-update-contact-lists', [ 'id' => $id, 'remove' => [ $list ] ]);
        $this->assertSame('unsubscribed', $this->ok($removed)['contact']['lists'][0]['status'], 'MailPoet keeps the membership as unsubscribed');
        $this->assertTrue(Rollback_Service::restore_operation((string) $removed['operation_id']));
        $this->assertSame($before_members, $this->rows('mailpoet_subscriber_segment'), 'a removal this write made is undone');

        $unsub = $this->write('mailpoet-update-contact', [ 'id' => $id, 'status' => 'unsubscribed' ]);
        $this->ok($unsub);
        $this->assertTrue(Rollback_Service::restore_operation((string) $unsub['operation_id']));
        $this->assertSame('unsubscribed', $this->rows('mailpoet_subscribers')[0]['status'], 'the subscriber stays unsubscribed');

        $trash = $this->write('mailpoet-delete-contact', [ 'id' => $id ], true);
        $this->ok($trash);
        $this->assertNotNull($this->rows('mailpoet_subscribers')[0]['deleted_at'], 'MailPoet trashes a deleted subscriber');
        $this->assertSame('contact_not_found', $this->code($this->read('mailpoet-get-contact', [ 'id' => $id ])));
        $this->assertTrue(Rollback_Service::restore_operation((string) $trash['operation_id']));
        $this->assertNull($this->rows('mailpoet_subscribers')[0]['deleted_at']);
    }

    // ---------------------------------------------------------------
    // Lists and tags
    // ---------------------------------------------------------------

    public function test_list_and_tag_crud_rolls_back_with_memberships(): void
    {
        $ada = $this->fc_contact('ada@example.com');
        $out = $this->write('fluentcrm-create-list', [ 'title' => 'Customers', 'description' => 'Paying' ]);
        $list = $this->ok($out)['list'];
        $this->assertSame([ 'Customers', 'customers', 0 ], [ $list['title'], $list['slug'], $list['contacts'] ]);
        $this->assertSame('list_exists', $this->code($this->write('fluentcrm-create-list', [ 'title' => 'customers' ])));

        $renamed = $this->write('fluentcrm-update-list', [ 'id' => $list['id'], 'title' => 'Clients' ]);
        $this->assertSame('Clients', $this->ok($renamed)['list']['title']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $renamed['operation_id']));
        $this->assertSame('Customers', $this->rows('fc_lists')[0]['title']);
        $this->assertSame('nothing_to_update', $this->code($this->write('fluentcrm-update-list', [ 'id' => $list['id'] ])));

        $this->fc_member($ada, 'list', $list['id']);
        $before  = [ $this->rows('fc_lists'), $this->rows('fc_subscriber_pivot') ];
        $deleted = $this->write('fluentcrm-delete-list', [ 'id' => $list['id'] ], true);
        $this->ok($deleted);
        $this->assertSame([], $this->rows('fc_subscriber_pivot'));
        $this->assertTrue(Rollback_Service::restore_operation((string) $deleted['operation_id']));
        $this->assertSame($before, [ $this->rows('fc_lists'), $this->rows('fc_subscriber_pivot') ], 'the list and its memberships are back');

        // Undoing the create removes the list only while nobody joined it since.
        Rollback_Service::restore_operation((string) $out['operation_id']);
        $this->assertCount(1, $this->rows('fc_lists'));
        $this->assertStringContainsString('member(s) added since', implode(' ', Rollback_Service::take_warnings()));

        $tag = $this->write('mailpoet-create-tag', [ 'title' => 'Hot' ]);
        $this->assertSame('Hot', $this->ok($tag)['tag']['title']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $tag['operation_id']));
        $this->assertSame([], $this->rows('mailpoet_tags'));
    }

    public function test_mailpoet_list_delete_restores_memberships_and_update_keeps_name(): void
    {
        $list = $this->mp_list('Newsletter');
        $ada  = $this->mp_contact('ada@example.com');
        $this->mp_member($ada, $list);
        $updated = $this->write('mailpoet-update-list', [ 'id' => $list, 'description' => 'Monthly' ]);
        $this->assertSame([ 'Newsletter', 'Monthly' ], [ $this->ok($updated)['list']['title'], $this->ok($updated)['list']['description'] ]);
        $row = $this->rows('mailpoet_segments')[0];
        $this->assertSame([ 'Newsletter', 'Monthly' ], [ $row['name'], $row['description'] ], 'a description-only update keeps the name');

        $before  = $this->rows('mailpoet_subscriber_segment');
        $deleted = $this->write('mailpoet-delete-list', [ 'id' => $list ], true);
        $this->ok($deleted);
        $this->assertTrue(Rollback_Service::restore_operation((string) $deleted['operation_id']));
        $this->assertSame($before, $this->rows('mailpoet_subscriber_segment'));
        $this->assertSame('list_not_found', $this->code($this->write('mailpoet-delete-list', [ 'id' => $this->mp_list('WP', 'wp_users') ], true)), 'MailPoet keeps its user lists');
    }

    public function test_session_rollback_undoes_a_create_then_its_edits(): void
    {
        $session = wp_generate_uuid4();
        $vip     = $this->fc_group('tag', 'VIP');
        $id      = $this->ok($this->write('fluentcrm-create-contact', [ 'email' => 'new@example.com' ], false, $session))['contact']['id'];
        $this->ok($this->write('fluentcrm-update-contact-tags', [ 'id' => $id, 'add' => [ $vip ] ], false, $session));
        $this->ok($this->write('fluentcrm-update-contact', [ 'id' => $id, 'first_name' => 'Later' ], false, $session));

        Rollback_Service::restore_session($session);
        $this->assertSame([], $this->rows('fc_subscribers'));
        $this->assertSame([], $this->rows('fc_subscriber_pivot'));
    }

    // ---------------------------------------------------------------
    // Campaigns
    // ---------------------------------------------------------------

    /** Calls a double recorded, by name. */
    private function calls(array $calls, string $name): array
    {
        return array_values(array_map(static fn (array $call): array => $call[1], array_filter($calls, static fn (array $call): bool => $name === $call[0])));
    }

    private function set_row(string $name, int $id, array $values): void
    {
        global $wpdb;
        $this->assertNotFalse($wpdb->update($this->t($name), $values, [ 'id' => $id ]), $wpdb->last_error);
    }

    /** A FluentCRM list holding a subscribed, a pending and an unsubscribed contact. */
    private function fc_audience(): int
    {
        $list = $this->fc_group('list', 'Newsletter');
        foreach ([ 'ada@example.com' => 'subscribed', 'bob@example.com' => 'pending', 'carol@example.com' => 'unsubscribed' ] as $email => $status) {
            $this->fc_member($this->fc_contact($email, $status), 'list', $list);
        }
        return $list;
    }

    /** A MailPoet list holding a subscribed and an unconfirmed subscriber, and one who left it. */
    private function mp_audience(): int
    {
        $list = $this->mp_list('Newsletter');
        $this->mp_member($this->mp_contact('ada@example.com', 'subscribed'), $list);
        $this->mp_member($this->mp_contact('bob@example.com', 'unconfirmed'), $list);
        $this->mp_member($this->mp_contact('carol@example.com', 'subscribed'), $list, 'unsubscribed');
        return $list;
    }

    public function test_fluentcrm_campaign_drafts_create_update_duplicate_and_roll_back(): void
    {
        $list = $this->fc_audience();
        $out  = $this->write('fluentcrm-create-campaign', [ 'title' => 'October news', 'subject' => 'Hello', 'preheader' => 'Pre', 'body' => '<p>Hi</p>', 'lists' => [ $list ] ]);
        $new  = $this->ok($out)['campaign'];
        $this->assertSame([ 'October news', 'Hello', 'Pre', 'draft', [ $list ], 1 ], [ $new['title'], $new['subject'], $new['preheader'], $new['status'], $new['lists'], $new['recipients'] ], 'only the subscribed contact is a recipient');
        $this->assertSame('<p>Hi</p>', $this->ok($this->read('fluentcrm-get-campaign', [ 'id' => $new['id'] ]))['campaign']['body']);
        $this->assertSame('list_not_found', $this->code($this->write('fluentcrm-create-campaign', [ 'subject' => 'X', 'body' => 'x', 'lists' => [ 999 ] ])));
        $this->assertSame('campaign_exists', $this->code($this->write('fluentcrm-create-campaign', [ 'title' => 'october news', 'subject' => 'X', 'body' => 'x' ])));
        $this->assertSame('campaign_not_found', $this->code($this->read('fluentcrm-get-campaign', [ 'id' => 999 ])));

        $before = $this->rows('fc_campaigns');
        $edit   = $this->write('fluentcrm-update-campaign', [ 'id' => $new['id'], 'subject' => 'Hello again', 'body' => '<p>New</p>' ]);
        $this->assertSame('Hello again', $this->ok($edit)['campaign']['subject']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $edit['operation_id']));
        $this->assertSame($before, $this->rows('fc_campaigns'), 'the draft is back exactly');
        $this->assertSame('nothing_to_update', $this->code($this->write('fluentcrm-update-campaign', [ 'id' => $new['id'] ])));

        $copy = $this->write('fluentcrm-duplicate-campaign', [ 'id' => $new['id'] ]);
        $dup  = $this->ok($copy)['campaign'];
        $this->assertSame('draft', $dup['status']);
        $this->assertNotSame($new['id'], $dup['id']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $copy['operation_id']));
        $this->assertSame($before, $this->rows('fc_campaigns'), 'undoing the duplicate removes the copy');

        $this->set_row('fc_campaigns', $new['id'], [ 'status' => 'scheduled' ]);
        $this->assertSame('campaign_not_draft', $this->code($this->write('fluentcrm-update-campaign', [ 'id' => $new['id'], 'subject' => 'Late' ])));
        $this->set_row('fc_campaigns', $new['id'], [ 'status' => 'draft' ]);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame([], $this->rows('fc_campaigns'));
        $this->assertSame([], array_values(array_filter($this->rows('fc_meta'), static fn (array $row): bool => 'option' !== $row['object_type'])), 'and the meta it created');
    }

    public function test_mailpoet_newsletter_drafts_create_update_duplicate_and_roll_back(): void
    {
        $list  = $this->mp_audience();
        $other = $this->mp_list('Customers');
        $out   = $this->write('mailpoet-create-campaign', [ 'subject' => 'Hello', 'body' => '<p>Hi</p>', 'lists' => [ $list ] ]);
        $new   = $this->ok($out)['campaign'];
        $this->assertSame([ 'Hello', 'draft', [ $list ], 1 ], [ $new['subject'], $new['status'], $new['lists'], $new['recipients'] ], 'only the subscribed member of the list is a recipient');
        $this->assertStringContainsString('<p>Hi</p>', $this->ok($this->read('mailpoet-get-campaign', [ 'id' => $new['id'] ]))['campaign']['body']);

        $before = [ $this->rows('mailpoet_newsletters'), $this->rows('mailpoet_newsletter_segment') ];
        $edit   = $this->write('mailpoet-update-campaign', [ 'id' => $new['id'], 'subject' => 'Changed', 'lists' => [ $other ] ]);
        $this->assertSame([ 'Changed', [ $other ] ], [ $this->ok($edit)['campaign']['subject'], $this->ok($edit)['campaign']['lists'] ]);
        $this->assertTrue(Rollback_Service::restore_operation((string) $edit['operation_id']));
        $this->assertSame($before, [ $this->rows('mailpoet_newsletters'), $this->rows('mailpoet_newsletter_segment') ], 'subject and lists are back exactly');

        $copy = $this->write('mailpoet-duplicate-campaign', [ 'id' => $new['id'] ]);
        $this->assertSame([ 'draft', [ $list ] ], [ $this->ok($copy)['campaign']['status'], $this->ok($copy)['campaign']['lists'] ]);
        $this->assertTrue(Rollback_Service::restore_operation((string) $copy['operation_id']));
        $this->assertSame($before, [ $this->rows('mailpoet_newsletters'), $this->rows('mailpoet_newsletter_segment') ]);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame([], $this->rows('mailpoet_newsletters'));
        $this->assertSame([], $this->rows('mailpoet_newsletter_segment'));
    }

    public function test_a_campaign_edit_is_not_undone_once_the_draft_was_sent(): void
    {
        $list = $this->fc_audience();
        $id   = $this->ok($this->write('fluentcrm-create-campaign', [ 'subject' => 'Hello', 'body' => 'x', 'lists' => [ $list ] ]))['campaign']['id'];
        $edit = $this->write('fluentcrm-update-campaign', [ 'id' => $id, 'subject' => 'Sent subject' ]);
        $this->ok($edit);
        $this->set_row('fc_campaigns', $id, [ 'status' => 'archived' ]);

        $this->assertFalse(Rollback_Service::restore_operation((string) $edit['operation_id']));
        $this->assertStringContainsString('no longer a draft', implode(' ', Rollback_Service::take_warnings()));
        $this->assertSame('Sent subject', $this->rows('fc_campaigns')[0]['email_subject']);
    }

    public function test_a_test_email_goes_to_the_current_user_and_is_never_undone(): void
    {
        $email = get_userdata($this->admin)->user_email;
        foreach ([ 'fluentcrm' => $this->fc_audience(), 'mailpoet' => $this->mp_audience() ] as $p => $list) {
            $id  = $this->ok($this->write("{$p}-create-campaign", [ 'subject' => 'Hello', 'body' => 'x', 'lists' => [ $list ] ]))['campaign']['id'];
            $out = $this->write("{$p}-send-test-campaign", [ 'id' => $id ]);
            $this->assertSame($email, $this->ok($out)['sent_to']);
            $this->assertFalse($out['recoverable'], 'mail cannot be unsent');
            $calls = 'fluentcrm' === $p ? Fake_FluentCRM::$calls : Fake_MailPoet::$calls;
            $this->assertSame([ [ $id, $email ] ], $this->calls($calls, 'send_test'));
            $this->assertSame([], $this->calls($calls, 'send_campaign'), 'nobody else is mailed');

            $this->assertFalse(Rollback_Service::restore_operation((string) $out['operation_id']));
            $this->assertStringContainsString('cannot be unsent', implode(' ', Rollback_Service::take_warnings()));
        }
    }

    public function test_sending_is_off_until_enabled_and_asks_for_confirmation_with_the_recipient_count(): void
    {
        $list = $this->fc_audience();
        $id   = $this->ok($this->write('fluentcrm-create-campaign', [ 'subject' => 'Hello', 'body' => 'x', 'lists' => [ $list ] ]))['campaign']['id'];

        $off = $this->write('fluentcrm-send-campaign', [ 'id' => $id ], true);
        $this->assertSame('operation_disabled', $this->code($off));
        $this->assertStringContainsString('wpmcp_enable_crm_send', $off['error']['message']);

        add_filter('wpmcp_enable_crm_send', '__return_true');
        $ask = $this->write('fluentcrm-send-campaign', [ 'id' => $id ]);
        $this->assertSame('confirmation_required', $this->code($ask));
        $this->assertTrue(Elicitation::is_confirmation_refusal($ask), 'the person is asked through the confirmation path');
        $this->assertSame(1, $ask['error']['data']['recipients']);
        $this->assertStringContainsString('1 subscribed contact', $ask['error']['message']);
        $this->assertSame([], $this->calls(Fake_FluentCRM::$calls, 'send_campaign'), 'nothing is sent before the person confirms');

        $this->assertSame('invalid_args', $this->code($this->write('fluentcrm-send-campaign', [ 'id' => $id, 'send_at' => '2001-01-01 00:00:00' ], true)), 'a send time in the past');
        $at   = wp_date('Y-m-d H:i:s', time() + DAY_IN_SECONDS);
        $sent = $this->write('fluentcrm-send-campaign', [ 'id' => $id, 'send_at' => $at ], true);
        $done = $this->ok($sent);
        $this->assertSame([ 1, 'pending-scheduled', $at ], [ $done['recipients'], $done['campaign']['status'], $done['send_at'] ]);
        $this->assertSame([ [ $id, $at ] ], $this->calls(Fake_FluentCRM::$calls, 'send_campaign'));
        $this->assertFalse($sent['recoverable']);
        $this->assertFalse(Rollback_Service::restore_operation((string) $sent['operation_id']));
        $this->assertStringContainsString('cannot be unsent', implode(' ', Rollback_Service::take_warnings()));
        $this->assertSame('pending-scheduled', $this->rows('fc_campaigns')[0]['status']);
        $this->assertSame('campaign_not_draft', $this->code($this->write('fluentcrm-send-campaign', [ 'id' => $id ], true)));

        $pending = $this->fc_group('list', 'Pending only');
        $this->fc_member((int) $this->rows('fc_subscribers')[1]['id'], 'list', $pending);
        $none = $this->ok($this->write('fluentcrm-create-campaign', [ 'subject' => 'Nobody', 'body' => 'x', 'lists' => [ $pending ] ]))['campaign']['id'];
        $this->assertSame('no_recipients', $this->code($this->write('fluentcrm-send-campaign', [ 'id' => $none ], true)), 'a pending contact is never mailed a campaign');
    }

    public function test_mailpoet_send_now_queues_the_subscribed_members_and_is_never_undone(): void
    {
        add_filter('wpmcp_enable_crm_send', '__return_true');
        $list = $this->mp_audience();
        $id   = $this->ok($this->write('mailpoet-create-campaign', [ 'subject' => 'Hello', 'body' => 'x', 'lists' => [ $list ] ]))['campaign']['id'];
        $ask  = $this->write('mailpoet-send-campaign', [ 'id' => $id ]);
        $this->assertSame([ 'confirmation_required', 1 ], [ $this->code($ask), $ask['error']['data']['recipients'] ]);

        $sent = $this->write('mailpoet-send-campaign', [ 'id' => $id ], true);
        $this->assertSame([ 1, 'sending', null ], [ $this->ok($sent)['recipients'], $this->ok($sent)['campaign']['status'], $this->ok($sent)['send_at'] ]);
        $this->assertSame('1', $this->rows('mailpoet_sending_queues')[0]['count_total']);
        $this->assertFalse(Rollback_Service::restore_operation((string) $sent['operation_id']));
        $this->assertSame('sending', $this->rows('mailpoet_newsletters')[0]['status']);
    }

    public function test_campaign_writes_need_the_plugins_email_permission(): void
    {
        $list = $this->mp_audience();
        $this->editor();
        $this->assertSame('operation_denied', $this->code($this->write('fluentcrm-create-campaign', [ 'subject' => 'X', 'body' => 'x' ])));
        wp_set_current_user($this->admin);
        wp_get_current_user()->remove_cap('mailpoet_manage_emails');
        $this->assertSame('operation_denied', $this->code($this->write('mailpoet-create-campaign', [ 'subject' => 'X', 'body' => 'x', 'lists' => [ $list ] ])));
        $this->assertSame('operation_denied', $this->code($this->read('mailpoet-get-campaign', [ 'id' => 1 ])));
        wp_get_current_user()->remove_cap('mailpoet_manage_automations');
        $this->assertSame('operation_denied', $this->code($this->write('mailpoet-set-automation-status', [ 'id' => 1, 'status' => 'draft' ])));
    }

    // ---------------------------------------------------------------
    // Bulk import
    // ---------------------------------------------------------------

    public function test_fluentcrm_import_never_resubscribes_and_follows_double_optin(): void
    {
        $list    = $this->fc_group('list', 'Leads');
        $known   = $this->fc_contact('known@example.com', 'subscribed', [ 'first_name' => 'Known' ]);
        $gone    = $this->fc_contact('gone@example.com', 'unsubscribed');
        $bounced = $this->fc_contact('bounce@example.com', 'bounced');
        $before  = [ $this->rows('fc_subscribers'), $this->rows('fc_subscriber_pivot') ];

        $out = $this->write('fluentcrm-import-contacts', [
            'contacts' => [
                [ 'email' => 'new@example.com', 'first_name' => 'New' ],
                [ 'email' => 'known@example.com', 'first_name' => 'Changed' ],
                [ 'email' => 'gone@example.com' ],
                [ 'email' => 'bounce@example.com' ],
                [ 'email' => 'NEW@example.com' ],
                [ 'email' => 'not-an-email' ],
            ],
            'lists'    => [ $list ],
        ]);
        $done = $this->ok($out);
        $this->assertSame([ 'new@example.com' ], array_column($done['created'], 'email'));
        $this->assertSame('pending', $this->ok($this->read('fluentcrm-get-contact', [ 'email' => 'new@example.com' ]))['status'], 'a new contact confirms by double opt-in');
        $this->assertSame([], $done['updated']);
        $this->assertSame(
            [ 'known@example.com' => 'contact_exists', 'gone@example.com' => 'contact_suppressed', 'bounce@example.com' => 'contact_suppressed', 'NEW@example.com' => 'duplicate', 'not-an-email' => 'invalid_email' ],
            array_column($done['skipped'], 'reason', 'email')
        );
        $this->assertCount(1, $out['operation_ids']);
        $this->assertSame([], array_filter($this->calls(Fake_FluentCRM::$calls, 'attach'), static fn (array $args): bool => in_array($args[0], [ $gone, $bounced, $known ], true)), 'nobody suppressed or existing is touched');

        $again = $this->write('fluentcrm-import-contacts', [ 'contacts' => [ [ 'email' => 'known@example.com', 'first_name' => 'Changed' ], [ 'email' => 'gone@example.com' ] ], 'lists' => [ $list ], 'update_existing' => true ]);
        $this->assertSame([ $known ], array_column($this->ok($again)['updated'], 'id'));
        $this->assertSame([ 'gone@example.com' => 'contact_suppressed' ], array_column($this->ok($again)['skipped'], 'reason', 'email'), 'update_existing never reaches a suppressed contact');
        $this->assertSame('Changed', $this->ok($this->read('fluentcrm-get-contact', [ 'id' => $known ]))['first_name']);

        Rollback_Service::restore_session((string) $again['session_id']);
        Rollback_Service::restore_session((string) $out['session_id']);
        $this->assertSame($before, [ $this->rows('fc_subscribers'), $this->rows('fc_subscriber_pivot') ], 'rollback-session undoes each import');

        $this->assertSame('double_optin_required', $this->code($this->write('fluentcrm-import-contacts', [ 'contacts' => [ [ 'email' => 'x@example.com' ] ], 'status' => 'subscribed' ])));
        $this->assertSame('unknown_field', $this->code($this->write('fluentcrm-import-contacts', [ 'contacts' => [ [ 'email' => 'x@example.com', 'custom_fields' => [ 'nope' => 1 ] ] ] ])));
    }

    public function test_mailpoet_import_skips_who_left_a_list_and_follows_its_confirmation_setting(): void
    {
        $list = $this->mp_audience();
        $out  = $this->write('mailpoet-import-contacts', [
            'contacts'        => [ [ 'email' => 'new@example.com' ], [ 'email' => 'carol@example.com' ], [ 'email' => 'ada@example.com', 'last_name' => 'L' ] ],
            'lists'           => [ $list ],
            'update_existing' => true,
        ]);
        $done = $this->ok($out);
        $this->assertSame('unconfirmed', $this->ok($this->read('mailpoet-get-contact', [ 'email' => 'new@example.com' ]))['status']);
        $this->assertSame([ 'carol@example.com' => 'contact_suppressed' ], array_column($done['skipped'], 'reason', 'email'), 'who left the list is not put back on it');
        $this->assertSame([ 'ada@example.com' ], array_column($done['updated'], 'email'));
        $this->assertSame('invalid_args', $this->code($this->write('mailpoet-import-contacts', [ 'contacts' => [ [ 'email' => 'x@example.com' ] ], 'status' => 'subscribed' ])));
        Rollback_Service::restore_session((string) $out['session_id']);
        $this->assertSame([ 'ada@example.com', 'bob@example.com', 'carol@example.com' ], array_column($this->rows('mailpoet_subscribers'), 'email'));
        $this->assertSame('Last', $this->rows('mailpoet_subscribers')[0]['last_name']);
    }

    // ---------------------------------------------------------------
    // Automations
    // ---------------------------------------------------------------

    public function test_set_automation_status_and_roll_it_back(): void
    {
        $funnel = $this->insert('fc_funnels', [ 'title' => 'Welcome', 'trigger_name' => 'fluentcrm_contact_added_to_lists', 'status' => 'draft' ]);
        $out    = $this->write('fluentcrm-set-automation-status', [ 'id' => $funnel, 'status' => 'published' ]);
        $this->assertSame('published', $this->ok($out)['automation']['status']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('draft', $this->rows('fc_funnels')[0]['status']);
        $this->assertSame('nothing_to_update', $this->code($this->write('fluentcrm-set-automation-status', [ 'id' => $funnel, 'status' => 'draft' ])));
        $this->assertSame('invalid_args', $this->code($this->write('fluentcrm-set-automation-status', [ 'id' => $funnel, 'status' => 'active' ])));
        $this->assertSame('automation_not_found', $this->code($this->write('fluentcrm-set-automation-status', [ 'id' => 999, 'status' => 'published' ])));

        $auto   = $this->insert('mailpoet_automations', [ 'name' => 'Welcome', 'author' => $this->admin, 'status' => 'active' ]);
        $this->insert('mailpoet_automation_versions', [ 'automation_id' => $auto, 'steps' => '{"root":{}}' ]);
        $before = [ $this->rows('mailpoet_automations'), $this->rows('mailpoet_automation_versions') ];
        $off    = $this->write('mailpoet-set-automation-status', [ 'id' => $auto, 'status' => 'deactivating' ]);
        $this->assertSame('deactivating', $this->ok($off)['automation']['status']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $off['operation_id']));
        $this->assertSame($before, [ $this->rows('mailpoet_automations'), $this->rows('mailpoet_automation_versions') ], 'status and versions are back exactly');
    }

    // ---------------------------------------------------------------
    // The snapshot type
    // ---------------------------------------------------------------

    public function test_crm_rows_is_a_restorable_type_confined_to_the_plugins_tables(): void
    {
        $this->assertContains(Crm_Rows_Snapshot::TYPE, Rollback_Service::restorable_object_types());
        $bogus = Snapshot::capture(Crm_Rows_Snapshot::TYPE, 'users:1');
        $this->assertSame('', $bogus['data']['kind']);
        $this->assertSame([], $bogus['data']['sets']);
        $this->assertNull(Crm_Rows_Snapshot::restore($bogus));
        $this->expectException(\InvalidArgumentException::class);
        Crm_Rows_Snapshot::table('users');
    }

    public function test_restore_takes_the_plugins_permission(): void
    {
        $id  = $this->mp_contact('ada@example.com');
        $out = $this->write('mailpoet-update-contact', [ 'id' => $id, 'first_name' => 'Changed' ]);
        $this->ok($out);
        $this->editor();
        $this->assertFalse(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('Changed', $this->rows('mailpoet_subscribers')[0]['first_name']);

        $fc  = $this->fc_contact('bob@example.com');
        wp_set_current_user($this->admin);
        $out = $this->write('fluentcrm-update-contact', [ 'id' => $fc, 'first_name' => 'Changed' ]);
        $this->editor();
        $this->expectException(\WPMCP\Safety\Mutation_Failed::class);
        Rollback_Service::restore_operation((string) $out['operation_id']);
    }
}
