<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Fluent_Forms_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;

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

    // ---- #675: delete-entry ----------------------------------------------

    private function rows(string $table, string $column, int $id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE %i = %d', $wpdb->prefix . $table, $column, $id));
    }

    private function seed_children(int $entry): void
    {
        \FF_Test_DB::seed('fluentform_submission_meta', [ 'response_id' => $entry, 'form_id' => $this->form_id, 'meta_key' => 'is_favourite', 'value' => '1' ]); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a column of Fluent Forms' own table.
        \FF_Test_DB::seed('fluentform_logs', [ 'source_id' => $entry, 'source_type' => 'submission_item', 'message' => 'Email sent' ]);
        \FF_Test_DB::seed('fluentform_logs', [ 'source_id' => $entry, 'source_type' => 'forms', 'message' => 'Not this entry\'s log' ]);
        \FF_Test_DB::seed('fluentform_entry_details', [ 'form_id' => $this->form_id, 'submission_id' => $entry, 'field_name' => 'email', 'field_value' => 'ada@example.test' ]);
        \FF_Test_DB::seed('fluentform_entry_details', [ 'form_id' => $this->form_id, 'submission_id' => $entry, 'field_name' => 'name', 'field_value' => 'Ada' ]);
    }

    public function test_delete_entry_is_off_until_opted_in_then_needs_confirm_and_previews_without_values(): void
    {
        $this->seed_children($this->unread);
        $this->assertSame('opt_in_required', $this->integration->handle_write([ 'operation' => 'delete-entry', 'args' => [ 'entry_id' => $this->unread ], 'confirm' => true ])['error']['code']);

        add_filter('wpmcp_enable_forms_write', '__return_true');
        $preview = $this->integration->handle_write([ 'operation' => 'delete-entry', 'args' => [ 'entry_id' => $this->unread ] ]);
        $this->assertSame('confirmation_required', $preview['error']['code']);
        $this->assertSame(2, $preview['error']['data']['values']);
        $this->assertStringNotContainsString('ada@example.test', wp_json_encode($preview));
        $this->assertSame(1, $this->rows('fluentform_submissions', 'id', $this->unread));
        remove_all_filters('wpmcp_enable_forms_write');
    }

    public function test_delete_entry_removes_every_row_of_the_entry_and_one_session_restores_them(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $this->seed_children($this->unread);
        $out = $this->integration->handle_write([ 'operation' => 'delete-entry', 'args' => [ 'entry_id' => $this->unread ], 'confirm' => true ]);

        $this->assertTrue($out['result']['deleted']);
        $this->assertTrue($out['recoverable']);
        $this->assertSame(0, $this->rows('fluentform_submissions', 'id', $this->unread));
        $this->assertSame(0, $this->rows('fluentform_submission_meta', 'response_id', $this->unread));
        $this->assertSame(0, $this->rows('fluentform_entry_details', 'submission_id', $this->unread));
        $this->assertSame(1, $this->rows('fluentform_logs', 'source_id', $this->unread), 'Only the submission_item log goes, a form log with the same id stays');
        $this->assertSame(1, $this->rows('fluentform_submissions', 'id', $this->trashed), 'Another entry stays');

        ( new Rollback_Session() )->handle([ 'session_id' => $out['session_id'] ]);
        $this->assertSame(1, $this->rows('fluentform_submissions', 'id', $this->unread));
        $this->assertSame(1, $this->rows('fluentform_submission_meta', 'response_id', $this->unread));
        $this->assertSame(2, $this->rows('fluentform_entry_details', 'submission_id', $this->unread));
        $this->assertSame(2, $this->rows('fluentform_logs', 'source_id', $this->unread));
        remove_all_filters('wpmcp_enable_forms_write');
    }

    public function test_delete_entry_refuses_an_unknown_entry_and_one_with_payments(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $this->assertSame('entry_not_found', $this->integration->handle_write([ 'operation' => 'delete-entry', 'args' => [ 'entry_id' => 999 ], 'confirm' => true ])['error']['code']);

        \FF_Test_DB::seed('fluentform_transactions', [ 'submission_id' => $this->unread ]);
        $out = $this->integration->handle_write([ 'operation' => 'delete-entry', 'args' => [ 'entry_id' => $this->unread ], 'confirm' => true ]);
        $this->assertSame('entry_has_payments', $out['error']['code']);
        $this->assertSame(1, $this->rows('fluentform_submissions', 'id', $this->unread));
        remove_all_filters('wpmcp_enable_forms_write');
    }

    public function test_a_failure_part_way_through_a_delete_puts_back_what_was_already_deleted(): void
    {
        global $wpdb;
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $this->seed_children($this->unread);
        // The entry row is the last table deleted: make that one DELETE fail.
        $break = static fn (string $query): string => str_starts_with($query, 'DELETE FROM `' . $wpdb->prefix . 'fluentform_submissions`') ? 'DELETE BROKEN' : $query;
        add_filter('query', $break);
        $wpdb->suppress_errors(true);

        $out = $this->integration->handle_write([ 'operation' => 'delete-entry', 'args' => [ 'entry_id' => $this->unread ], 'confirm' => true ]);

        $wpdb->suppress_errors(false);
        remove_filter('query', $break);
        $this->assertSame('delete_failed', $out['error']['code'], wp_json_encode($out));
        $this->assertSame(3, count($out['error']['data']['restored']), 'Every table already deleted was put back');
        $this->assertSame([], $out['error']['data']['not_restored']);
        $this->assertSame(1, $this->rows('fluentform_submissions', 'id', $this->unread));
        $this->assertSame(1, $this->rows('fluentform_submission_meta', 'response_id', $this->unread));
        $this->assertSame(2, $this->rows('fluentform_logs', 'source_id', $this->unread));
        $this->assertSame(2, $this->rows('fluentform_entry_details', 'submission_id', $this->unread));
        remove_all_filters('wpmcp_enable_forms_write');
    }

    public function test_an_entry_with_more_rows_than_a_snapshot_holds_is_refused_and_nothing_is_deleted(): void
    {
        global $wpdb;
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $this->seed_children($this->unread);
        $values = [];
        for ($i = 0; $i < 600; $i++) {
            $values[] = $wpdb->prepare('(%d, %d, %s, %s)', $this->form_id, $this->unread, 'f' . $i, 'v');
        }
        $wpdb->query('INSERT INTO ' . $wpdb->prefix . 'fluentform_entry_details (form_id, submission_id, field_name, field_value) VALUES ' . implode(',', $values)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- bulk fixture rows, every value prepared above.

        $out = $this->integration->handle_write([ 'operation' => 'delete-entry', 'args' => [ 'entry_id' => $this->unread ], 'confirm' => true ]);

        $this->assertSame('entry_too_large', $out['error']['code'], wp_json_encode($out));
        $this->assertSame(1, $this->rows('fluentform_submissions', 'id', $this->unread));
        $this->assertSame(1, $this->rows('fluentform_submission_meta', 'response_id', $this->unread));
        $this->assertSame(602, $this->rows('fluentform_entry_details', 'submission_id', $this->unread));
        remove_all_filters('wpmcp_enable_forms_write');
    }
}
