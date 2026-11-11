<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\WS_Form_Pack;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/ws-form-stubs.php';

/**
 * WS Form (issue #676): free-tier ops on the plugin-data dispatcher pair, so
 * they add no top-level tools.
 *
 * WS Form is not loaded in the shared test core. Presence is driven through
 * wpmcp_ws_form_active, and the storage is built and seeded the way WS Form
 * PRO 1.12.6 keeps it (tests/support/ws-form-stubs.php): the {prefix}wsf_*
 * tables, with the contact-us template form and its 'action' meta recorded
 * from the real plugin.
 *
 * Every write is off until the site opts in (wpmcp_enable_ws_form_write) and
 * is undone in a test: a status change is a db_rows before-image of the
 * row, and a delete captures every row of the three tables in one session.
 */
class WSFormPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'wsform-list-forms'          => 'read',
        'wsform-get-form'            => 'read',
        'wsform-list-fields'         => 'read',
        'wsform-list-actions'        => 'read',
        'wsform-list-notifications'  => 'read',
        'wsform-list-entries'        => 'read',
        'wsform-get-entry'           => 'read',
        'wsform-update-entry-status' => 'write',
        'wsform-delete-entry'        => 'destructive',
    ];

    private int $form;

    /** @var int[] */
    private array $fields;

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_ws_form_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_ws_form_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_ws_form_active', '__return_true');
        add_filter('wpmcp_enable_ws_form_write', '__return_true');
        $seeded       = wpmcp_test_ws_form_form();
        $this->form   = $seeded['form_id'];
        $this->fields = $seeded['fields'];
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_ws_form_active');
        remove_all_filters('wpmcp_enable_ws_form_write');
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

    private function submit(string $email, array $row = [], array $other = []): int
    {
        [ $first, $last, $mail, $phone, $inquiry, $gdpr ] = $this->fields;
        return wpmcp_test_ws_form_submission($this->form, [
            $first   => 'Ada',
            $last    => 'Lovelace',
            $mail    => $email,
            $phone   => '+44 20 7946 0000',
            $inquiry => 'Hello there',
            $gdpr    => [ 'I consent' ],
        ], $row, $other + [ 'tracking_remote_ip' => '203.0.113.7' ]);
    }

    /** @return array<string, int> table => row count for one submission. */
    private function rows(int $id): array
    {
        global $wpdb;
        return [
            'submit'      => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE id = %d', $wpdb->prefix . 'wsf_submit', $id)),
            'submit_meta' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE parent_id = %d', $wpdb->prefix . 'wsf_submit_meta', $id)),
            'submit_note' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE submit_id = %d', $wpdb->prefix . 'wsf_submit_note', $id)),
        ];
    }

    private function note(int $id): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'wsf_submit_note', [ 'submit_id' => $id, 'user_id' => 1, 'user_name' => 'admin', 'date_added' => '2026-11-11 09:00:00', 'date_updated' => '2026-11-11 09:00:00', 'content' => 'Called back', 'meta' => '' ]);
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
        $this->assertTrue($ops['wsform-delete-entry']['requires_confirm']);
    }

    public function test_presence_defaults_to_the_ws_form_version_constant(): void
    {
        remove_all_filters('wpmcp_ws_form_active');
        $this->assertSame(defined('WS_FORM_VERSION'), WS_Form_Pack::active());
    }

    public function test_alone_it_makes_the_plugin_data_pair_available(): void
    {
        $filters = [ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active', 'wpmcp_buddypress_active', 'wpmcp_bbpress_active', 'wpmcp_code_snippets_active', 'wpmcp_tutor_active', 'wpmcp_lifterlms_active', 'wpmcp_memberpress_active', 'wpmcp_elementor_pro_active' ];
        foreach ($filters as $filter) {
            add_filter($filter, '__return_false');
        }
        $available = (new Plugin_Data_Integration())->is_available();
        foreach ($filters as $filter) {
            remove_all_filters($filter);
        }
        $this->assertTrue($available);
    }

    public function test_while_ws_form_is_missing_a_call_answers_plugin_missing_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_ws_form_active');
        add_filter('wpmcp_ws_form_active', '__return_false');
        $out = $this->read('wsform-list-forms');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate WS Form', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
        $this->assertSame('wsform-list-forms', $out['error']['data']['operation'] ?? null);
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        $this->assertFalse($ops['wsform-get-form']['dependency_met']);
    }

    public function test_with_the_plugin_files_installed_but_inactive_it_answers_plugin_inactive(): void
    {
        remove_all_filters('wpmcp_ws_form_active');
        add_filter('wpmcp_ws_form_active', '__return_false');
        $dir     = WP_PLUGIN_DIR . '/ws-form';
        $created = ! is_dir($dir);
        if ($created) {
            wp_mkdir_p($dir);
        }
        $file  = $dir . '/ws-form.php';
        $owned = ! file_exists($file);
        if ($owned) {
            file_put_contents($file, "<?php\n// Plugin Name: WS Form LITE\n");
        }
        try {
            $out = $this->read('wsform-get-entry', [ 'entry_id' => 1 ]);
        } finally {
            if ($owned) {
                unlink($file);
            }
            if ($created) {
                rmdir($dir);
            }
        }
        $this->assertSame('plugin_inactive', $out['error']['code'] ?? null);
        $this->assertStringContainsString('installed but not active', $out['error']['message']);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $id = $this->submit('ada@example.org');
        remove_all_filters('wpmcp_enable_ws_form_write');
        $out = $this->write('wsform-update-entry-status', [ 'entry_id' => $id, 'status' => 'trash' ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertStringContainsString('wpmcp_enable_ws_form_write', $out['error']['message']);
        $out = $this->write('wsform-delete-entry', [ 'entry_id' => $id ], true);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertSame(1, $this->rows($id)['submit']);
    }

    public function test_every_op_takes_manage_options(): void
    {
        $id = $this->submit('ada@example.org');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $reads = [
            'wsform-list-forms'         => [],
            'wsform-get-form'           => [ 'form_id' => $this->form ],
            'wsform-list-fields'        => [ 'form_id' => $this->form ],
            'wsform-list-actions'       => [ 'form_id' => $this->form ],
            'wsform-list-notifications' => [ 'form_id' => $this->form ],
            'wsform-list-entries'       => [ 'form_id' => $this->form ],
            'wsform-get-entry'          => [ 'entry_id' => $id ],
        ];
        foreach ($reads as $op => $args) {
            $this->assertSame('operation_denied', $this->read($op, $args)['error']['code'] ?? null, $op);
        }
        $this->assertSame('operation_denied', $this->write('wsform-update-entry-status', [ 'entry_id' => $id, 'starred' => true ])['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // Forms
    // ---------------------------------------------------------------

    public function test_list_forms_counts_submissions_live_and_leaves_trash_out(): void
    {
        $this->submit('ada@example.org');
        $this->submit('bob@example.org', [ 'viewed' => 1 ]);
        $this->submit('eve@example.org', [ 'status' => 'spam', 'spam_level' => 100 ]);
        $this->submit('mal@example.org', [ 'status' => 'trash' ]);
        $live    = wpmcp_test_ws_form_form('Newsletter', 'publish')['form_id'];
        $trashed = wpmcp_test_ws_form_form('Old form', 'trash')['form_id'];

        $forms = array_column($this->ok($this->read('wsform-list-forms'))['result']['forms'], null, 'id');
        $this->assertArrayNotHasKey($trashed, $forms);
        $this->assertSame('Contact Us', $forms[ $this->form ]['label']);
        $this->assertSame('draft', $forms[ $this->form ]['status']);
        $this->assertSame(sprintf('[ws_form id="%d"]', $this->form), $forms[ $this->form ]['shortcode']);
        $this->assertSame([ 'total' => 2, 'unread' => 1, 'spam' => 1, 'trash' => 1 ], $forms[ $this->form ]['submissions']);
        $this->assertFalse($forms[ $live ]['unpublished_changes']);

        $only = $this->ok($this->read('wsform-list-forms', [ 'status' => 'trash' ]))['result'];
        $this->assertSame([ $trashed ], array_column($only['forms'], 'id'));
    }

    public function test_get_form_returns_tabs_sections_and_fields_in_order(): void
    {
        $form = $this->ok($this->read('wsform-get-form', [ 'form_id' => $this->form ]))['result']['form'];
        $this->assertSame($this->form, $form['id']);
        $this->assertSame('Tab', $form['tabs'][0]['label']);
        $this->assertSame('Section', $form['tabs'][0]['sections'][0]['label']);
        $fields = $form['tabs'][0]['sections'][0]['fields'];
        $this->assertSame([ 'First Name', 'Last Name', 'Email', 'Phone', 'Your Inquiry', 'GDPR', 'Submit' ], array_column($fields, 'label'));
        $this->assertSame([ 'text', 'text', 'email', 'tel', 'textarea', 'checkbox', 'submit' ], array_column($fields, 'type'));
        $this->assertSame($this->fields, array_column($fields, 'id'));

        $this->assertSame('form_not_found', $this->read('wsform-get-form', [ 'form_id' => 999999 ])['error']['code'] ?? null);
    }

    public function test_list_fields_reports_keys_required_flags_and_choices(): void
    {
        $fields = $this->ok($this->read('wsform-list-fields', [ 'form_id' => $this->form ]))['result']['fields'];
        $this->assertCount(7, $fields);
        $this->assertSame('field_' . $this->fields[2], $fields[2]['key']);
        $this->assertSame([ true, false, true, false, true, false, false ], array_column($fields, 'required'));
        $this->assertSame([ 'I consent to #blog_name storing my submitted information so they can respond to my inquiry' ], $fields[5]['choices']);
        $this->assertArrayNotHasKey('choices', $fields[0]);
    }

    public function test_list_actions_decodes_the_recorded_action_grid(): void
    {
        $actions = $this->ok($this->read('wsform-list-actions', [ 'form_id' => $this->form ]))['result']['actions'];
        $this->assertSame([ 'database', 'message', 'email' ], array_column($actions, 'type'));
        $this->assertSame([ 'Save to Submissions', 'Show Message', 'Send Email' ], array_column($actions, 'label'));
        $this->assertSame([ 1, 2, 3 ], array_column($actions, 'row_id'));
        $this->assertSame([ true, true, true ], array_column($actions, 'active'));
        $this->assertSame([ 'save', 'submit' ], $actions[0]['events']);
        $this->assertSame('Thank you for your inquiry.', $actions[1]['settings']['action_message_message']);
    }

    public function test_a_disabled_action_reads_as_inactive(): void
    {
        global $wpdb;
        $disabled = str_replace('s:2:"id";i:3;s:7:"default";s:0:"";s:8:"disabled";s:0:"";', 's:2:"id";i:3;s:7:"default";s:0:"";s:8:"disabled";s:2:"on";', WPMCP_TEST_WS_FORM_ACTION_META);
        $this->assertNotSame(WPMCP_TEST_WS_FORM_ACTION_META, $disabled);
        $wpdb->update($wpdb->prefix . 'wsf_form_meta', [ 'meta_value' => $disabled ], [ 'parent_id' => $this->form, 'meta_key' => 'action' ]);

        $notes = $this->ok($this->read('wsform-list-notifications', [ 'form_id' => $this->form ]))['result']['notifications'];
        $this->assertFalse($notes[0]['active']);
    }

    public function test_list_notifications_returns_the_email_action(): void
    {
        $notes = $this->ok($this->read('wsform-list-notifications', [ 'form_id' => $this->form ]))['result']['notifications'];
        $this->assertCount(1, $notes);
        $this->assertSame('Send Email', $notes[0]['label']);
        $this->assertSame(3, $notes[0]['row_id']);
        $this->assertSame([ [ 'email' => '#blog_admin_email', 'name' => '#blog_name' ] ], $notes[0]['to']);
        $this->assertSame([ 'email' => '#blog_admin_email', 'name' => '#blog_name' ], $notes[0]['from']);
        $this->assertSame('#form_label', $notes[0]['subject']);
        $this->assertSame('<h3>#email_subject</h3><p>#email_submission</p>', $notes[0]['message'], 'the text editor message, as the action is set to');
    }

    // ---------------------------------------------------------------
    // Entries
    // ---------------------------------------------------------------

    public function test_list_entries_masks_every_value_and_leaves_tracking_out(): void
    {
        $id  = $this->submit('ada@example.org');
        $out = $this->ok($this->read('wsform-list-entries', [ 'form_id' => $this->form ]))['result'];
        $this->assertTrue($out['masked']);
        $this->assertSame(1, $out['total']);
        $entry = $out['entries'][0];
        $this->assertSame($id, $entry['id']);
        $this->assertSame('publish', $entry['status']);
        $this->assertSame('Submitted', $entry['status_label']);
        $values = array_column($entry['fields'], 'value', 'label');
        $this->assertSame('A***', $values['First Name']);
        $this->assertSame('L***', $values['Last Name']);
        $this->assertSame('a***@example.org', $values['Email']);
        $this->assertSame('+***', $values['Phone']);
        $this->assertSame([ 'I***' ], $values['GDPR']);
        $this->assertArrayNotHasKey('meta', $entry);
        $this->assertStringNotContainsString('203.0.113.7', (string) wp_json_encode($out));
        $this->assertStringNotContainsString('Lovelace', (string) wp_json_encode($out));
    }

    public function test_list_entries_filters_by_status_and_pages_newest_first(): void
    {
        $a = $this->submit('a@example.org', [ 'date_added' => '2026-01-01 10:00:00' ]);
        $b = $this->submit('b@example.org', [ 'date_added' => '2026-02-01 10:00:00', 'status' => 'draft' ]);
        $c = $this->submit('c@example.org', [ 'date_added' => '2026-03-01 10:00:00', 'status' => 'spam' ]);
        $d = $this->submit('d@example.org', [ 'date_added' => '2026-04-01 10:00:00', 'status' => 'trash' ]);

        $ids = fn (array $args): array => array_column($this->ok($this->read('wsform-list-entries', [ 'form_id' => $this->form ] + $args))['result']['entries'], 'id');
        $this->assertSame([ $b, $a ], $ids([]), 'spam and trash left out, newest first');
        $this->assertSame([ $c ], $ids([ 'status' => 'spam' ]));
        $this->assertSame([ $d ], $ids([ 'status' => 'trash' ]));
        $this->assertSame([ $b ], $ids([ 'status' => 'draft' ]));
        $this->assertSame([ $b ], $ids([ 'page_size' => 1 ]));
        $this->assertSame([ $a ], $ids([ 'page_size' => 1, 'offset' => 1 ]));
        $page = $this->ok($this->read('wsform-list-entries', [ 'form_id' => $this->form, 'page_size' => 1 ]))['result'];
        $this->assertSame(1, $page['next_offset']);
    }

    public function test_get_entry_returns_every_value_tracking_and_notes_in_full(): void
    {
        $id = $this->submit('ada@example.org', [ 'actions' => wp_json_encode([ [ 'id' => 'email', 'status' => 'success' ] ]) ]);
        $this->note($id);
        $entry = $this->ok($this->read('wsform-get-entry', [ 'entry_id' => $id ]))['result']['entry'];
        $this->assertSame($id, $entry['id']);
        $this->assertSame($this->form, $entry['form_id']);
        $values = array_column($entry['fields'], 'value', 'label');
        $this->assertSame('ada@example.org', $values['Email']);
        $this->assertSame('Lovelace', $values['Last Name']);
        $this->assertSame([ 'I consent' ], $values['GDPR'], 'a serialized choice comes back as a list');
        $this->assertSame('field_' . $this->fields[2], $entry['fields'][2]['key']);
        $this->assertSame('email', $entry['fields'][2]['type']);
        $this->assertSame('203.0.113.7', $entry['meta']['tracking_remote_ip']);
        $this->assertSame('Called back', $entry['notes'][0]['content']);
        $this->assertSame('email', $entry['actions'][0]['id']);
        $this->assertTrue($entry['decrypted']);

        $this->assertSame('entry_not_found', $this->read('wsform-get-entry', [ 'entry_id' => 999999 ])['error']['code'] ?? null);
    }

    public function test_an_encrypted_entry_without_ws_form_encryption_returns_null_values(): void
    {
        $id    = $this->submit('ciphertext', [ 'encrypted' => 1 ]);
        $entry = $this->ok($this->read('wsform-get-entry', [ 'entry_id' => $id ]))['result']['entry'];
        $this->assertTrue($entry['encrypted']);
        $this->assertFalse($entry['decrypted']);
        $this->assertNull($entry['fields'][2]['value']);
    }

    public function test_mask_keeps_only_the_first_character_or_an_email_domain(): void
    {
        $this->assertSame('j***@example.com', WS_Form_Pack::mask('jane.doe@example.com'));
        $this->assertSame('J***', WS_Form_Pack::mask('Jane'));
        $this->assertSame('4***', WS_Form_Pack::mask(42));
        $this->assertSame('', WS_Form_Pack::mask(''));
        $this->assertNull(WS_Form_Pack::mask(null));
        $this->assertSame([ 'a***', [ 'b***' ] ], WS_Form_Pack::mask([ 'alpha', [ 'beta' ] ]));
        $this->assertSame('É***', WS_Form_Pack::mask('Élodie'));
    }

    // ---------------------------------------------------------------
    // Writes, each undone
    // ---------------------------------------------------------------

    public function test_update_status_marks_spam_stars_and_views_and_rolls_back(): void
    {
        global $wpdb;
        $id     = $this->submit('ada@example.org');
        $before = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'wsf_submit', $id), ARRAY_A);

        $out = $this->ok($this->write('wsform-update-entry-status', [ 'entry_id' => $id, 'status' => 'spam', 'starred' => true, 'viewed' => true ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('spam', $out['result']['entry']['status']);
        $this->assertSame(100, $out['result']['entry']['spam_level'], 'spam sets spam_level 100 as db_set_status() does');
        $this->assertTrue($out['result']['entry']['starred']);
        $this->assertTrue($out['result']['entry']['viewed']);
        $this->assertSame([ 'status' => 'publish', 'starred' => false, 'viewed' => false ], $out['result']['previous']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'wsf_submit', $id), ARRAY_A));
    }

    public function test_publishing_a_spam_entry_clears_its_spam_level_like_not_spam(): void
    {
        $id  = $this->submit('ada@example.org', [ 'status' => 'spam', 'spam_level' => 100 ]);
        $out = $this->ok($this->write('wsform-update-entry-status', [ 'entry_id' => $id, 'status' => 'publish' ]));
        $this->assertSame('publish', $out['result']['entry']['status']);
        $this->assertSame(0, $out['result']['entry']['spam_level']);
    }

    public function test_update_status_refuses_a_missing_entry_an_empty_change_and_an_unknown_status(): void
    {
        $this->assertSame('entry_not_found', $this->write('wsform-update-entry-status', [ 'entry_id' => 999999, 'status' => 'trash' ])['error']['code'] ?? null);
        $this->assertSame('invalid_args', $this->write('wsform-update-entry-status', [ 'entry_id' => $this->submit('a@example.org') ])['error']['code'] ?? null);
        $this->assertSame('invalid_args', $this->write('wsform-update-entry-status', [ 'entry_id' => 1, 'status' => 'error' ])['error']['code'] ?? null);
    }

    public function test_delete_asks_for_confirmation_with_a_preview_and_touches_nothing(): void
    {
        $id = $this->submit('ada@example.org');
        $this->note($id);
        $out = $this->write('wsform-delete-entry', [ 'entry_id' => $id ]);
        $this->assertSame('confirmation_required', $out['error']['code'] ?? null);
        $this->assertStringContainsString('"Contact Us"', $out['error']['message']);
        $this->assertStringContainsString('7 stored values and 1 notes', $out['error']['message']);
        $this->assertSame([ 'submit' => 1, 'submit_meta' => 7, 'submit_note' => 1 ], $this->rows($id));
    }

    public function test_delete_removes_every_row_and_rollback_session_puts_them_back_with_their_ids(): void
    {
        global $wpdb;
        $id    = $this->submit('ada@example.org');
        $other = $this->submit('bob@example.org');
        $this->note($id);
        $dump   = static fn (string $t, string $col, int $sid): array => $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE %i = %d ORDER BY id', $wpdb->prefix . $t, $col, $sid), ARRAY_A);
        $before = [ $dump('wsf_submit', 'id', $id), $dump('wsf_submit_meta', 'parent_id', $id), $dump('wsf_submit_note', 'submit_id', $id) ];

        $out = $this->ok($this->write('wsform-delete-entry', [ 'entry_id' => $id ], true));
        $this->assertTrue($out['recoverable']);
        $this->assertCount(3, $out['operation_ids']);
        $this->assertSame($id, $out['result']['deleted']['id']);
        $this->assertSame([ 'submit' => 0, 'submit_meta' => 0, 'submit_note' => 0 ], $this->rows($id));
        $this->assertSame([ 'submit' => 1, 'submit_meta' => 7, 'submit_note' => 0 ], $this->rows($other), 'another submission is untouched');

        $this->assertSame(3, Rollback_Service::restore_session($out['session_id']));
        $this->assertSame($before, [ $dump('wsf_submit', 'id', $id), $dump('wsf_submit_meta', 'parent_id', $id), $dump('wsf_submit_note', 'submit_id', $id) ]);
        $entry = $this->ok($this->read('wsform-get-entry', [ 'entry_id' => $id ]))['result']['entry'];
        $this->assertSame('ada@example.org', array_column($entry['fields'], 'value', 'label')['Email']);
    }
}
