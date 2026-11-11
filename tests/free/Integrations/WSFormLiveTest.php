<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\WS_Form_Pack;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #676: the WS Form ops against a real WS Form PRO.
 *
 * It runs only in the local gate's live WS Form leg (bin/test-local.sh
 * --live-ws-form), which sets WPMCP_LIVE_WS_FORM=1, copies WS Form PRO from
 * WPMCP_WS_FORM_SRC onto a separate WordPress install (WS Form PRO is
 * commercial, not on wordpress.org), loads it in tests/bootstrap.php and
 * runs its own WS_Form_Activator::activate() for the tables and
 * capabilities. No license key is set.
 *
 * The form is built from WS Form's own contact-us template
 * (WS_Form_Form::db_create_from_template()), submissions are stored through
 * WS_Form_Submit::db_create() and db_update(), and what the writes leave is
 * read back through WS_Form_Submit::db_read() and WS_Form_Form's counts, so
 * the ops agree with WS Form on both sides.
 *
 * Everywhere else WS Form is absent and the test is skipped; the live leg
 * runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group ws-form-live
 */
class WSFormLiveTest extends \WP_UnitTestCase
{
    private int $form;

    /** @var array<string, int> field label => id */
    private array $fields = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('WS_FORM_VERSION') || ! class_exists('WS_Form_Submit')) {
            $this->markTestSkipped('Needs the real WS Form (bin/test-local.sh --live-ws-form, WPMCP_WS_FORM_SRC).');
        }
        Snapshot_Store::install();
        add_filter('wpmcp_enable_ws_form_write', '__return_true');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));

        $form       = new \WS_Form_Form();
        $this->form = (int) $form->db_create_from_template('contact-us');
        $object     = $form->db_read(true, true, false, false, true);
        foreach ($object->groups as $group) {
            foreach ($group->sections as $section) {
                foreach ($section->fields as $field) {
                    $this->fields[ (string) $field->label ] = (int) $field->id;
                }
            }
        }
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_ws_form_write');
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'];
    }

    private function write(string $op, array $args, bool $confirm = false): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if ($confirm) {
            $call['confirm'] = true;
        }
        $out = (new Plugin_Data_Integration())->handle_write($call);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    /** A submission stored through WS Form's own submit model. */
    private function submit(string $email): int
    {
        $submit          = new \WS_Form_Submit();
        $submit->form_id = $this->form;
        $submit->status  = 'publish';
        $submit->db_create();
        $submit->meta = [
            'field_' . $this->fields['First Name']   => [ 'id' => $this->fields['First Name'], 'value' => 'Ada' ],
            'field_' . $this->fields['Last Name']    => [ 'id' => $this->fields['Last Name'], 'value' => 'Lovelace' ],
            'field_' . $this->fields['Email']        => [ 'id' => $this->fields['Email'], 'value' => $email ],
            'field_' . $this->fields['Your Inquiry'] => [ 'id' => $this->fields['Your Inquiry'], 'value' => 'Hello there' ],
            'field_' . $this->fields['GDPR']         => [ 'id' => $this->fields['GDPR'], 'value' => [ 'I consent' ] ],
            'tracking_remote_ip'                     => '203.0.113.7',
        ];
        $submit->db_update();
        return (int) $submit->id;
    }

    /** The submission as WS Form itself reads it. */
    private static function ws_read(int $id): object
    {
        $submit     = new \WS_Form_Submit();
        $submit->id = $id;
        return $submit->db_read(true, false, true);
    }

    public function test_presence_follows_ws_form(): void
    {
        $this->assertTrue(WS_Form_Pack::active());
        $this->assertTrue((new Plugin_Data_Integration())->is_available());
    }

    public function test_the_template_form_reads_back_with_its_fields_actions_and_email(): void
    {
        $forms = array_column($this->read('wsform-list-forms')['forms'], null, 'id');
        $this->assertSame('Contact Us', $forms[ $this->form ]['label']);

        $fields = $this->read('wsform-list-fields', [ 'form_id' => $this->form ])['fields'];
        $this->assertSame(array_keys($this->fields), array_column($fields, 'label'));
        $this->assertTrue(array_column($fields, 'required', 'label')['Email']);
        $this->assertNotEmpty(array_column($fields, null, 'label')['GDPR']['choices']);

        $form = $this->read('wsform-get-form', [ 'form_id' => $this->form ])['form'];
        $this->assertSame(array_values($this->fields), array_column($form['tabs'][0]['sections'][0]['fields'], 'id'));

        $types = array_column($this->read('wsform-list-actions', [ 'form_id' => $this->form ])['actions'], 'type');
        $this->assertContains('database', $types);
        $this->assertContains('email', $types);

        $notes = $this->read('wsform-list-notifications', [ 'form_id' => $this->form ])['notifications'];
        $this->assertCount(1, $notes);
        $this->assertNotSame('', $notes[0]['subject']);
        $this->assertNotSame('', $notes[0]['message']);
    }

    public function test_entries_are_masked_in_the_list_and_whole_one_at_a_time(): void
    {
        $id = $this->submit('ada@example.org');

        $list = $this->read('wsform-list-entries', [ 'form_id' => $this->form ]);
        $this->assertSame([ $id ], array_column($list['entries'], 'id'));
        $masked = array_column($list['entries'][0]['fields'], 'value', 'label');
        $this->assertSame('a***@example.org', $masked['Email']);
        $this->assertSame('L***', $masked['Last Name']);
        $this->assertStringNotContainsString('203.0.113.7', (string) wp_json_encode($list));

        $entry  = $this->read('wsform-get-entry', [ 'entry_id' => $id ])['entry'];
        $values = array_column($entry['fields'], 'value', 'label');
        $this->assertSame('ada@example.org', $values['Email']);
        $this->assertSame([ 'I consent' ], $values['GDPR']);
        $this->assertSame('203.0.113.7', $entry['meta']['tracking_remote_ip']);

        // The same values WS Form's own reader returns.
        $ws = self::ws_read($id);
        $this->assertSame($ws->meta[ 'field_' . $this->fields['Email'] ]['value'], $values['Email']);
    }

    public function test_status_writes_land_as_ws_form_reads_them_and_roll_back(): void
    {
        $id = $this->submit('ada@example.org');

        $out = $this->write('wsform-update-entry-status', [ 'entry_id' => $id, 'status' => 'spam', 'starred' => true ]);
        $ws  = self::ws_read($id);
        $this->assertSame('spam', $ws->status);
        $this->assertSame(100, (int) $ws->spam_level);
        $this->assertSame(1, (int) $ws->starred);
        $forms = array_column($this->read('wsform-list-forms')['forms'], null, 'id');
        $this->assertSame(1, $forms[ $this->form ]['submissions']['spam']);
        $this->assertSame(0, $forms[ $this->form ]['submissions']['total']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $ws = self::ws_read($id);
        $this->assertSame('publish', $ws->status);
        $this->assertNull($ws->spam_level);
        $this->assertSame(0, (int) $ws->starred);
    }

    public function test_delete_removes_the_submission_and_rollback_session_brings_it_back_for_ws_form(): void
    {
        $id   = $this->submit('ada@example.org');
        $keep = $this->submit('bob@example.org');

        $out = $this->write('wsform-delete-entry', [ 'entry_id' => $id ], true);
        $this->assertTrue($out['recoverable']);
        $this->assertSame([ $keep ], array_column($this->read('wsform-list-entries', [ 'form_id' => $this->form ])['entries'], 'id'));
        try {
            self::ws_read($id);
            $this->fail('WS Form still reads the deleted submission.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Unable to read submission', $e->getMessage());
        }

        $this->assertGreaterThanOrEqual(2, Rollback_Service::restore_session($out['session_id']));
        $ws = self::ws_read($id);
        $this->assertSame('ada@example.org', $ws->meta[ 'field_' . $this->fields['Email'] ]['value']);
        $this->assertSame('203.0.113.7', $ws->meta['tracking_remote_ip']);
    }
}
