<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Everest_Forms_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/everest-forms-stubs.php';

/**
 * Everest Forms (issue #676): free-tier ops on the plugin-data dispatcher
 * pair, so they add no top-level tools.
 *
 * Everest Forms is not loaded in the shared test core. Presence is driven
 * through wpmcp_everest_forms_active, and the storage is built and seeded the
 * way Everest Forms 3.6.4 keeps it (tests/support/everest-forms-stubs.php):
 * everest_form posts holding the form JSON, and the {prefix}evf_entries and
 * {prefix}evf_entrymeta tables.
 *
 * Every write is off until the site opts in (wpmcp_enable_everest_forms_write)
 * and is undone in a test.
 */
class EverestFormsPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'everestforms-list-forms'          => 'read',
        'everestforms-get-form'            => 'read',
        'everestforms-list-fields'         => 'read',
        'everestforms-list-notifications'  => 'read',
        'everestforms-list-entries'        => 'read',
        'everestforms-get-entry'           => 'read',
        'everestforms-update-entry-status' => 'write',
        'everestforms-delete-entry'        => 'destructive',
    ];

    private int $form;

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_everest_forms_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_everest_forms_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_everest_forms_active', '__return_true');
        add_filter('wpmcp_enable_everest_forms_write', '__return_true');
        $this->form = wpmcp_test_everest_form();
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_everest_forms_active');
        remove_all_filters('wpmcp_enable_everest_forms_write');
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
        return $out;
    }

    private function submit(string $email, array $row = [], ?int $form = null): int
    {
        return wpmcp_test_everest_forms_entry($form ?? $this->form, [
            'fullname' => 'Ada Lovelace',
            'email'    => $email,
            'subject'  => 'Engines',
            'message'  => 'Hello there',
            'topics'   => [ 'Sales', 'Support' ],
        ], $row);
    }

    private function entry_row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE entry_id = %d', $wpdb->prefix . 'evf_entries', $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private function meta_rows(int $id): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE entry_id = %d ORDER BY meta_id', $wpdb->prefix . 'evf_entrymeta', $id), ARRAY_A);
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
        $this->assertTrue($ops['everestforms-delete-entry']['requires_confirm']);
    }

    public function test_presence_defaults_to_the_evf_version_constant(): void
    {
        remove_all_filters('wpmcp_everest_forms_active');
        $this->assertSame(defined('EVF_VERSION'), Everest_Forms_Pack::active());
    }

    public function test_alone_it_makes_the_plugin_data_pair_available(): void
    {
        $filters = [ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active', 'wpmcp_buddypress_active', 'wpmcp_bbpress_active', 'wpmcp_code_snippets_active', 'wpmcp_ws_form_active', 'wpmcp_elementor_pro_active' ];
        foreach ($filters as $filter) {
            add_filter($filter, '__return_false');
        }
        $available = (new Plugin_Data_Integration())->is_available();
        foreach ($filters as $filter) {
            remove_all_filters($filter);
        }
        $this->assertTrue($available);
    }

    public function test_while_everest_forms_is_missing_a_call_answers_plugin_missing_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_everest_forms_active');
        add_filter('wpmcp_everest_forms_active', '__return_false');
        $out = $this->read('everestforms-list-forms');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Everest Forms', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        $this->assertFalse($ops['everestforms-get-form']['dependency_met']);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $id = $this->submit('ada@example.org');
        remove_all_filters('wpmcp_enable_everest_forms_write');
        $out = $this->write('everestforms-update-entry-status', [ 'entry_id' => $id, 'status' => 'spam' ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertStringContainsString('wpmcp_enable_everest_forms_write', $out['error']['message']);
        $out = $this->write('everestforms-delete-entry', [ 'entry_id' => $id ], true);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertNotNull($this->entry_row($id));
    }

    public function test_every_op_takes_manage_options(): void
    {
        $id = $this->submit('ada@example.org');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $reads = [
            'everestforms-list-forms'         => [],
            'everestforms-get-form'           => [ 'form_id' => $this->form ],
            'everestforms-list-fields'        => [ 'form_id' => $this->form ],
            'everestforms-list-notifications' => [ 'form_id' => $this->form ],
            'everestforms-list-entries'       => [ 'form_id' => $this->form ],
            'everestforms-get-entry'          => [ 'entry_id' => $id ],
        ];
        foreach ($reads as $op => $args) {
            $this->assertSame('operation_denied', $this->read($op, $args)['error']['code'] ?? null, $op);
        }
        $this->assertSame('operation_denied', $this->write('everestforms-update-entry-status', [ 'entry_id' => $id, 'starred' => true ])['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // Forms
    // ---------------------------------------------------------------

    public function test_list_forms_counts_entries_and_leaves_trash_out(): void
    {
        $this->submit('ada@example.org');
        $this->submit('bob@example.org', [ 'viewed' => 1 ]);
        $this->submit('eve@example.org', [ 'status' => 'spam' ]);
        $this->submit('mal@example.org', [ 'status' => 'trash' ]);
        $trashed = wpmcp_test_everest_form('Old form', 'trash');
        $other   = self::factory()->post->create([ 'post_title' => 'Not a form' ]);

        $forms = array_column($this->ok($this->read('everestforms-list-forms'))['result']['forms'], null, 'id');
        $this->assertArrayNotHasKey($trashed, $forms);
        $this->assertArrayNotHasKey($other, $forms);
        $this->assertSame('Contact Form', $forms[ $this->form ]['title']);
        $this->assertSame('publish', $forms[ $this->form ]['status']);
        $this->assertSame(sprintf('[everest_form id="%d"]', $this->form), $forms[ $this->form ]['shortcode']);
        $this->assertSame([ 'total' => 2, 'unread' => 1, 'spam' => 1, 'trash' => 1 ], $forms[ $this->form ]['entries']);

        $only = $this->ok($this->read('everestforms-list-forms', [ 'status' => 'trash' ]))['result'];
        $this->assertSame([ $trashed ], array_column($only['forms'], 'id'));
    }

    public function test_get_form_returns_fields_and_settings(): void
    {
        $form = $this->ok($this->read('everestforms-get-form', [ 'form_id' => $this->form ]))['result']['form'];
        $this->assertSame($this->form, $form['id']);
        $this->assertSame([ 'Name', 'Email', 'Subject', 'Message', 'Topics' ], array_column($form['fields'], 'label'));
        $this->assertSame('Thanks for contacting us! We will be in touch with you shortly.', $form['settings']['success_message']);
        $this->assertSame('Submit', $form['settings']['submit_button_text']);
        $this->assertTrue($form['settings']['entries_stored']);

        $this->assertSame('form_not_found', $this->read('everestforms-get-form', [ 'form_id' => 999999 ])['error']['code'] ?? null);
        $post = self::factory()->post->create();
        $this->assertSame('form_not_found', $this->read('everestforms-get-form', [ 'form_id' => $post ])['error']['code'] ?? null, 'another post type is not a form');
    }

    public function test_list_fields_follows_the_layout_with_meta_keys_required_and_choices(): void
    {
        $reordered = wpmcp_test_everest_form('Reordered', 'publish', static function (array $content): array {
            $content['structure'] = [ 'row_1' => [ 'grid_1' => [ 'email', 'fullname' ], 'grid_2' => [ 'topics' ] ], 'row_2' => [ 'grid_1' => [ 'message', 'subject' ] ] ];
            return $content;
        });
        $fields = $this->ok($this->read('everestforms-list-fields', [ 'form_id' => $reordered ]))['result']['fields'];
        $this->assertSame([ 'email', 'fullname', 'topics', 'message', 'subject' ], array_column($fields, 'id'));
        $by = array_column($fields, null, 'id');
        $this->assertSame('email', $by['email']['key']);
        $this->assertTrue($by['email']['required']);
        $this->assertFalse($by['message']['required']);
        $this->assertSame([ 'Sales', 'Support' ], $by['topics']['choices']);
        $this->assertArrayNotHasKey('choices', $by['email']);
    }

    public function test_list_notifications_returns_each_email_connection(): void
    {
        $notes = $this->ok($this->read('everestforms-list-notifications', [ 'form_id' => $this->form ]))['result']['notifications'];
        $this->assertCount(1, $notes);
        $this->assertSame('connection_1', $notes[0]['id']);
        $this->assertSame('Admin Notification', $notes[0]['name']);
        $this->assertTrue($notes[0]['active']);
        $this->assertSame('{admin_email}', $notes[0]['to']);
        $this->assertSame('{field_id="email"}', $notes[0]['reply_to']);
        $this->assertSame('{all_fields}', $notes[0]['message']);

        $off = wpmcp_test_everest_form('Quiet', 'publish', static function (array $content): array {
            $content['settings']['email']['connection_1']['enable_email_notification'] = '0';
            return $content;
        });
        $this->assertFalse($this->ok($this->read('everestforms-list-notifications', [ 'form_id' => $off ]))['result']['notifications'][0]['active']);
    }

    // ---------------------------------------------------------------
    // Entries
    // ---------------------------------------------------------------

    public function test_list_entries_masks_every_value_and_leaves_the_ip_out(): void
    {
        $id  = $this->submit('ada@example.org');
        $out = $this->ok($this->read('everestforms-list-entries', [ 'form_id' => $this->form ]))['result'];
        $this->assertTrue($out['masked']);
        $this->assertSame(1, $out['total']);
        $entry = $out['entries'][0];
        $this->assertSame($id, $entry['id']);
        $this->assertSame('publish', $entry['status']);
        $values = array_column($entry['fields'], 'value', 'label');
        $this->assertSame('A***', $values['Name']);
        $this->assertSame('a***@example.org', $values['Email']);
        $this->assertSame([ 'S***', 'S***' ], $values['Topics']);
        $json = (string) wp_json_encode($out);
        $this->assertStringNotContainsString('203.0.113.7', $json);
        $this->assertStringNotContainsString('Lovelace', $json);
        $this->assertStringNotContainsString('Chrome', $json);
    }

    public function test_list_entries_filters_by_status_and_pages_newest_first(): void
    {
        $a = $this->submit('a@example.org', [ 'date_created' => '2026-01-01 10:00:00' ]);
        $b = $this->submit('b@example.org', [ 'date_created' => '2026-02-01 10:00:00' ]);
        $c = $this->submit('c@example.org', [ 'date_created' => '2026-03-01 10:00:00', 'status' => 'spam' ]);
        $d = $this->submit('d@example.org', [ 'date_created' => '2026-04-01 10:00:00', 'status' => 'trash' ]);
        $e = $this->submit('e@example.org', [ 'date_created' => '2026-05-01 10:00:00', 'status' => 'draft' ]);

        $ids = fn (array $args): array => array_column($this->ok($this->read('everestforms-list-entries', [ 'form_id' => $this->form ] + $args))['result']['entries'], 'id');
        $this->assertSame([ $b, $a ], $ids([]), 'spam, trash and drafts left out, newest first');
        $this->assertSame([ $c ], $ids([ 'status' => 'spam' ]));
        $this->assertSame([ $d ], $ids([ 'status' => 'trash' ]));
        $this->assertSame([ $e ], $ids([ 'status' => 'draft' ]));
        $this->assertSame([ $b ], $ids([ 'page_size' => 1 ]));
        $this->assertSame([ $a ], $ids([ 'page_size' => 1, 'offset' => 1 ]));
        $page = $this->ok($this->read('everestforms-list-entries', [ 'form_id' => $this->form, 'page_size' => 1 ]))['result'];
        $this->assertSame(1, $page['next_offset']);
    }

    public function test_get_entry_returns_every_value_and_the_visit_data_in_full(): void
    {
        $id    = $this->submit('ada@example.org');
        $entry = $this->ok($this->read('everestforms-get-entry', [ 'entry_id' => $id ]))['result']['entry'];
        $this->assertSame($id, $entry['id']);
        $this->assertSame($this->form, $entry['form_id']);
        $values = array_column($entry['fields'], 'value', 'label');
        $this->assertSame('ada@example.org', $values['Email']);
        $this->assertSame('Ada Lovelace', $values['Name']);
        $this->assertSame([ 'Sales', 'Support' ], $values['Topics'], 'a checkbox comes back as its chosen labels');
        $by = array_column($entry['fields'], null, 'id');
        $this->assertSame('email', $by['email']['type']);
        $this->assertSame('email', $by['email']['key']);
        $this->assertSame('203.0.113.7', $entry['ip_address']);
        $this->assertSame('Chrome/Mac/Desktop', $entry['user_agent']);
        $this->assertSame('https://example.org/contact/', $entry['referer']);
        $this->assertFalse($entry['viewed'], 'reading through MCP does not mark the entry read');
        $this->assertSame('0', (string) $this->entry_row($id)['viewed']);

        $this->assertSame('entry_not_found', $this->read('everestforms-get-entry', [ 'entry_id' => 999999 ])['error']['code'] ?? null);
    }

    public function test_get_entry_falls_back_to_the_meta_rows_when_the_fields_column_is_empty(): void
    {
        global $wpdb;
        $id = $this->submit('ada@example.org');
        $wpdb->update($wpdb->prefix . 'evf_entries', [ 'fields' => '{}' ], [ 'entry_id' => $id ]);
        $wpdb->insert($wpdb->prefix . 'evf_entrymeta', [ 'entry_id' => $id, 'meta_key' => '_evf_trash_entry_status', 'meta_value' => 'publish' ]);

        $entry  = $this->ok($this->read('everestforms-get-entry', [ 'entry_id' => $id ]))['result']['entry'];
        $values = array_column($entry['fields'], 'value', 'label');
        $this->assertSame('ada@example.org', $values['Email']);
        $this->assertSame([ 'Sales', 'Support' ], $values['Topics']);
        $this->assertNotContains('_evf_trash_entry_status', array_column($entry['fields'], 'key'));
    }

    // ---------------------------------------------------------------
    // Writes, each undone
    // ---------------------------------------------------------------

    public function test_update_status_marks_spam_stars_and_reads_and_rolls_back(): void
    {
        $id     = $this->submit('ada@example.org');
        $before = $this->entry_row($id);

        $out = $this->ok($this->write('everestforms-update-entry-status', [ 'entry_id' => $id, 'status' => 'spam', 'starred' => true, 'viewed' => true ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('spam', $out['result']['entry']['status']);
        $this->assertTrue($out['result']['entry']['starred']);
        $this->assertTrue($out['result']['entry']['viewed']);
        $this->assertSame([ 'status' => 'publish', 'starred' => false, 'viewed' => false ], $out['result']['previous']);
        $this->assertSame([], array_filter($this->meta_rows($id), static fn (array $m): bool => '_evf_trash_entry_status' === $m['meta_key']), 'no trash bookkeeping for spam');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, $this->entry_row($id));
    }

    public function test_marking_a_spam_entry_publish_is_not_spam(): void
    {
        $id  = $this->submit('ada@example.org', [ 'status' => 'spam' ]);
        $out = $this->ok($this->write('everestforms-update-entry-status', [ 'entry_id' => $id, 'status' => 'publish' ]));
        $this->assertSame('publish', $out['result']['entry']['status']);
    }

    public function test_update_status_refuses_a_trashed_entry_a_missing_one_an_empty_change_and_trash(): void
    {
        $trashed = $this->submit('t@example.org', [ 'status' => 'trash' ]);
        $out     = $this->write('everestforms-update-entry-status', [ 'entry_id' => $trashed, 'status' => 'publish' ]);
        $this->assertSame('entry_status_locked', $out['error']['code'] ?? null);
        $this->assertSame('trash', $this->entry_row($trashed)['status']);
        $this->ok($this->write('everestforms-update-entry-status', [ 'entry_id' => $trashed, 'starred' => true ]));

        $this->assertSame('entry_not_found', $this->write('everestforms-update-entry-status', [ 'entry_id' => 999999, 'status' => 'spam' ])['error']['code'] ?? null);
        $this->assertSame('invalid_args', $this->write('everestforms-update-entry-status', [ 'entry_id' => $this->submit('a@example.org') ])['error']['code'] ?? null);
        $this->assertSame('invalid_args', $this->write('everestforms-update-entry-status', [ 'entry_id' => 1, 'status' => 'trash' ])['error']['code'] ?? null);
    }

    public function test_delete_asks_for_confirmation_with_a_preview_and_touches_nothing(): void
    {
        $id  = $this->submit('ada@example.org');
        $out = $this->write('everestforms-delete-entry', [ 'entry_id' => $id ]);
        $this->assertSame('confirmation_required', $out['error']['code'] ?? null);
        $this->assertStringContainsString('"Contact Form"', $out['error']['message']);
        $this->assertStringContainsString('5 stored values', $out['error']['message']);
        $this->assertNotNull($this->entry_row($id));
        $this->assertCount(5, $this->meta_rows($id));
    }

    public function test_delete_removes_the_entry_and_its_values_and_rollback_session_puts_them_back(): void
    {
        $id     = $this->submit('ada@example.org');
        $other  = $this->submit('bob@example.org');
        $before = [ $this->entry_row($id), $this->meta_rows($id) ];

        $out = $this->ok($this->write('everestforms-delete-entry', [ 'entry_id' => $id ], true));
        $this->assertTrue($out['recoverable']);
        $this->assertCount(2, $out['operation_ids']);
        $this->assertSame($id, $out['result']['deleted']['id']);
        $this->assertNull($this->entry_row($id));
        $this->assertSame([], $this->meta_rows($id));
        $this->assertNotNull($this->entry_row($other), 'another entry is untouched');
        $this->assertCount(5, $this->meta_rows($other));

        $this->assertSame(2, Rollback_Service::restore_session($out['session_id']));
        $this->assertSame($before, [ $this->entry_row($id), $this->meta_rows($id) ]);
    }
}
