<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Fluent_Forms_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

require_once __DIR__ . '/../../support/fluentforms-stubs.php';

/**
 * The Fluent Forms dispatcher (forms adapter pack, pro tier), exercised against
 * a faithful double of the wpFluent() query builder running on real tables
 * with Fluent Forms' own names (tests/support/fluentforms-stubs.php). Live
 * Fluent Forms stays production-verified.
 */
class FluentFormsIntegrationTest extends \WP_UnitTestCase
{
    private Fluent_Forms_Integration $integration;
    private int $form_id;
    private int $unread;
    private int $trashed;

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        \FF_Test_DB::install(); // DDL, outside any test transaction
    }

    public static function tear_down_after_class(): void
    {
        \FF_Test_DB::uninstall();
        parent::tear_down_after_class();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $this->form_id = \FF_Test_DB::seed('fluentform_forms', [
            'title'       => 'Signup',
            'form_fields' => wp_json_encode([
                'fields' => [
                    [ 'element' => 'input_email', 'attributes' => [ 'name' => 'email' ], 'settings' => [ 'label' => 'Email', 'validation_rules' => [ 'required' => [ 'value' => true ] ] ] ],
                    [ 'element' => 'input_text', 'attributes' => [ 'name' => 'name' ], 'settings' => [ 'label' => 'Name' ] ],
                ],
            ]),
        ]);
        \FF_Test_DB::seed('fluentform_form_meta', [
            'form_id'  => $this->form_id,
            'meta_key' => 'notifications', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a column of Fluent Forms' own table, not a postmeta query.
            'value'    => wp_json_encode([ 'name' => 'Admin Notification Email', 'enabled' => false, 'sendTo' => [ 'type' => 'email', 'email' => '{wp.admin_email}' ], 'subject' => 'New entry', 'message' => '{all_data}' ]),
        ]);
        $this->unread  = \FF_Test_DB::seed('fluentform_submissions', [ 'form_id' => $this->form_id, 'serial_number' => 1, 'status' => 'unread', 'response' => wp_json_encode([ 'email' => 'ada@example.test' ]), 'ip' => '203.0.113.9' ]);
        $this->trashed = \FF_Test_DB::seed('fluentform_submissions', [ 'form_id' => $this->form_id, 'serial_number' => 2, 'status' => 'trashed' ]);
        $this->integration = new Fluent_Forms_Integration();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    public function test_is_available_and_pro(): void
    {
        $this->assertTrue($this->integration->is_available());
        $this->assertSame('pro', $this->integration->tier());
    }

    public function test_list_forms(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'list-forms' ]);
        $this->assertSame(1, $out['result']['total']);
        $this->assertSame('Signup', $out['result']['forms'][0]['title']);
        $this->assertSame($this->form_id, $out['result']['forms'][0]['id']);
    }

    public function test_get_form_and_list_fields_decode_fields(): void
    {
        $form = $this->integration->handle_read([ 'operation' => 'get-form', 'args' => [ 'form_id' => $this->form_id ] ])['result']['form'];
        $this->assertSame('Signup', $form['title']);
        $this->assertSame('input_email', $form['fields'][0]['element']);
        $this->assertSame('email', $form['fields'][0]['name']);
        $this->assertSame('Email', $form['fields'][0]['label']);

        $fields = $this->integration->handle_read([ 'operation' => 'list-fields', 'args' => [ 'form_id' => $this->form_id ] ])['result']['fields'];
        $this->assertTrue($fields[0]['required']);
        $this->assertFalse($fields[1]['required']);
    }

    public function test_get_missing_form_returns_null(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'get-form', 'args' => [ 'form_id' => 999 ] ]);
        $this->assertNull($out['result']['form']);
    }

    public function test_list_notifications_reads_the_notifications_form_meta(): void
    {
        $notes = $this->integration->handle_read([ 'operation' => 'list-notifications', 'args' => [ 'form_id' => $this->form_id ] ])['result']['notifications'];

        $this->assertCount(1, $notes);
        $this->assertSame('Admin Notification Email', $notes[0]['name']);
        $this->assertFalse($notes[0]['enabled']);
        $this->assertSame('{wp.admin_email}', $notes[0]['send_to']['email']);
    }

    public function test_list_entries_leaves_trashed_out_unless_asked(): void
    {
        $default = $this->integration->handle_read([ 'operation' => 'list-entries', 'args' => [ 'form_id' => $this->form_id ] ])['result'];
        $this->assertSame([ $this->unread ], array_column($default['entries'], 'id'));
        $this->assertSame(1, $default['total']);
        $this->assertArrayNotHasKey('response', $default['entries'][0], 'The listing leaves submitted values to get-entry');

        $trashed = $this->integration->handle_read([ 'operation' => 'list-entries', 'args' => [ 'form_id' => $this->form_id, 'status' => 'trashed' ] ])['result'];
        $this->assertSame([ $this->trashed ], array_column($trashed['entries'], 'id'));
    }

    public function test_get_entry_decodes_the_response(): void
    {
        $entry = $this->integration->handle_read([ 'operation' => 'get-entry', 'args' => [ 'entry_id' => $this->unread ] ])['result']['entry'];

        $this->assertSame([ 'email' => 'ada@example.test' ], $entry['response']);
        $this->assertSame('203.0.113.9', $entry['ip']);
        $this->assertNull($this->integration->handle_read([ 'operation' => 'get-entry', 'args' => [ 'entry_id' => 999 ] ])['result']['entry']);
    }

    public function test_update_entry_status_fires_fluents_own_action_and_rolls_back(): void
    {
        $heard = [];
        add_action('fluentform/after_submission_status_update', static function ($id, $status) use (&$heard) {
            $heard[] = [ (int) $id, $status ];
        }, 10, 2);

        $out = $this->integration->handle_write([ 'operation' => 'update-entry-status', 'args' => [ 'entry_id' => $this->unread, 'status' => 'spam' ] ]);

        $this->assertTrue($out['recoverable']);
        $this->assertSame([ [ $this->unread, 'spam' ] ], $heard);
        $this->assertSame('spam', wpFluent()->table('fluentform_submissions')->where('id', $this->unread)->first()->status);

        ( new Rollback_Operation() )->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame('unread', wpFluent()->table('fluentform_submissions')->where('id', $this->unread)->first()->status);
    }

    public function test_update_entry_status_refuses_a_missing_entry(): void
    {
        $out = $this->integration->handle_write([ 'operation' => 'update-entry-status', 'args' => [ 'entry_id' => 999, 'status' => 'read' ] ]);
        $this->assertSame('entry_not_found', $out['error']['code']);
    }
}
