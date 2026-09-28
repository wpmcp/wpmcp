<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Gravity_Forms_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

require_once __DIR__ . '/../../support/gfapi-stub.php';

/**
 * Gravity Forms dispatcher (forms adapter pack, pro tier). Exercised against a faithful GFAPI double
 * (see tests/support/gfapi-stub.php) reproducing Gravity Forms 2.9's public
 * API contracts, since Gravity Forms is paid and cannot install from wp.org.
 */
class GravityFormsIntegrationTest extends \WP_UnitTestCase
{
    private Gravity_Forms_Integration $integration;
    private int $ada;
    private int $spammer;

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        \GFAPI::install_table(); // DDL, outside any test transaction
    }

    public static function tear_down_after_class(): void
    {
        \GFAPI::uninstall_table();
        parent::tear_down_after_class();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        \GFAPI::reset();
        \GFAPI::$forms = [
            1 => [
                'id'            => 1,
                'title'         => 'Contact',
                'is_active'     => true,
                'date_created'  => '2026-01-01 00:00:00',
                'fields'        => [
                    [ 'id' => 1, 'type' => 'text', 'label' => 'Name', 'isRequired' => true ],
                    [ 'id' => 2, 'type' => 'email', 'label' => 'Email' ],
                    [ 'id' => 3, 'type' => 'select', 'label' => 'Topic', 'choices' => [ [ 'text' => 'Sales', 'value' => 'sales' ] ] ],
                ],
                'notifications' => [
                    'a1' => [ 'id' => 'a1', 'name' => 'Admin Notification', 'event' => 'form_submission', 'to' => '{admin_email}', 'subject' => 'New submission', 'message' => '{all_fields}' ],
                    'b2' => [ 'id' => 'b2', 'name' => 'Paused', 'isActive' => false ],
                ],
            ],
        ];
        $this->ada     = \GFAPI::seed_entry(1, [ '1' => 'Ada', '2' => 'ada@example.com' ]);
        $this->spammer = \GFAPI::seed_entry(1, [ '1' => 'Spammer', '2' => 'x@spam.test' ], 'spam');
        \GFAPI::$notes = [
            [ 'id' => 100, 'entry_id' => $this->ada, 'value' => 'Followed up by phone' ],
        ];
        $this->integration = new Gravity_Forms_Integration();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        \GFAPI::reset();
        parent::tearDown();
    }

    public function test_reports_available_and_lists_operations(): void
    {
        $this->assertTrue($this->integration->is_available());
        $this->assertSame('pro', $this->integration->tier());

        $out = $this->integration->handle_read([ 'operation' => 'list-operations' ]);
        $this->assertArrayNotHasKey('error', $out);
        $names = array_column($out['result']['operations'], 'name');
        foreach ([ 'list-forms', 'get-form', 'list-fields', 'list-notifications', 'list-entries', 'get-entry', 'get-notes', 'update-entry-status' ] as $op) {
            $this->assertContains($op, $names, "catalog should advertise {$op}");
        }
    }

    public function test_list_forms_returns_summary_with_counts(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'list-forms' ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame(1, $out['result']['total']);
        $form = $out['result']['forms'][0];
        $this->assertSame(1, $form['id']);
        $this->assertSame('Contact', $form['title']);
        $this->assertTrue($form['is_active']);
        $this->assertSame(3, $form['field_count']);
        $this->assertSame(2, $form['entry_count']);
    }

    public function test_get_form_returns_full_form_and_null_when_missing(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'get-form', 'args' => [ 'form_id' => 1 ] ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame('Contact', $out['result']['form']['title']);
        $this->assertCount(3, $out['result']['form']['fields']);

        $missing = $this->integration->handle_read([ 'operation' => 'get-form', 'args' => [ 'form_id' => 999 ] ]);
        $this->assertNull($missing['result']['form']);
    }

    public function test_list_fields_shapes_required_flags_and_choices(): void
    {
        $out    = $this->integration->handle_read([ 'operation' => 'list-fields', 'args' => [ 'form_id' => 1 ] ]);
        $fields = $out['result']['fields'];

        $this->assertSame([ 'id' => '1', 'type' => 'text', 'label' => 'Name', 'required' => true, 'choices' => [] ], $fields[0]);
        $this->assertFalse($fields[1]['required']);
        $this->assertSame([ 'sales' ], $fields[2]['choices']);
    }

    public function test_list_notifications_treats_a_missing_active_flag_as_on(): void
    {
        $out   = $this->integration->handle_read([ 'operation' => 'list-notifications', 'args' => [ 'form_id' => 1 ] ]);
        $notes = $out['result']['notifications'];

        $this->assertCount(2, $notes);
        $this->assertSame('Admin Notification', $notes[0]['name']);
        $this->assertTrue($notes[0]['active']);
        $this->assertSame('{admin_email}', $notes[0]['to']);
        $this->assertFalse($notes[1]['active']);
    }

    public function test_list_entries_pages_and_filters_by_status(): void
    {
        $all = $this->integration->handle_read([ 'operation' => 'list-entries', 'args' => [ 'form_id' => 1 ] ]);
        $this->assertSame(2, $all['result']['total']);
        $this->assertCount(2, $all['result']['entries']);

        $spam = $this->integration->handle_read([ 'operation' => 'list-entries', 'args' => [ 'form_id' => 1, 'status' => 'spam' ] ]);
        $this->assertSame(1, $spam['result']['total']);
        $this->assertSame('Spammer', $spam['result']['entries'][0]['1']);

        $paged = $this->integration->handle_read([ 'operation' => 'list-entries', 'args' => [ 'form_id' => 1, 'page_size' => 1, 'offset' => 1 ] ]);
        $this->assertCount(1, $paged['result']['entries']);
        $this->assertSame(2, $paged['result']['total']);
    }

    public function test_get_entry_and_notes(): void
    {
        $entry = $this->integration->handle_read([ 'operation' => 'get-entry', 'args' => [ 'entry_id' => $this->ada ] ]);
        $this->assertSame('ada@example.com', $entry['result']['entry']['2']);

        $missing = $this->integration->handle_read([ 'operation' => 'get-entry', 'args' => [ 'entry_id' => 404 ] ]);
        $this->assertNull($missing['result']['entry']);

        $notes = $this->integration->handle_read([ 'operation' => 'get-notes', 'args' => [ 'entry_id' => $this->ada ] ]);
        $this->assertCount(1, $notes['result']['notes']);
        $this->assertSame('Followed up by phone', $notes['result']['notes'][0]['value']);
    }

    public function test_entry_reads_are_refused_below_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));

        foreach ([ [ 'list-entries', [ 'form_id' => 1 ] ], [ 'get-entry', [ 'entry_id' => $this->ada ] ], [ 'get-notes', [ 'entry_id' => $this->ada ] ] ] as [ $op, $args ]) {
            $out = $this->integration->handle_read([ 'operation' => $op, 'args' => $args ]);
            $this->assertSame('operation_denied', $out['error']['code'], $op);
            $this->assertSame('capability', $out['error']['data']['reason'], $op);
        }

        // Forms and their definitions are not PII and stay at the pair's own capability.
        $this->assertArrayNotHasKey('error', $this->integration->handle_read([ 'operation' => 'list-fields', 'args' => [ 'form_id' => 1 ] ]));
    }

    public function test_update_entry_status_is_snapshotted_through_update_entry_property(): void
    {
        $out = $this->integration->handle_write([ 'operation' => 'update-entry-status', 'args' => [ 'entry_id' => $this->ada, 'status' => 'trash' ] ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);
        $this->assertSame([ 'entry_id' => $this->ada, 'status' => 'trash', 'previous_status' => 'active', 'changed' => true ], $out['result']);
        $this->assertSame('trash', \GFAPI::get_entry($this->ada)['status']);

        $snapshot = Snapshot_Store::get_by_operation($out['operation_id']);
        $this->assertSame('db_rows', $snapshot['object_type']);

        ( new Rollback_Operation() )->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame('active', \GFAPI::get_entry($this->ada)['status']);
    }

    public function test_update_entry_status_to_the_current_status_changes_nothing(): void
    {
        $out = $this->integration->handle_write([ 'operation' => 'update-entry-status', 'args' => [ 'entry_id' => $this->spammer, 'status' => 'spam' ] ]);

        $this->assertFalse($out['result']['changed']);
        $this->assertSame('spam', \GFAPI::get_entry($this->spammer)['status']);
    }

    public function test_update_entry_status_refuses_a_missing_entry_without_a_snapshot(): void
    {
        global $wpdb;
        $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpmcp_snapshots");

        $out = $this->integration->handle_write([ 'operation' => 'update-entry-status', 'args' => [ 'entry_id' => 404, 'status' => 'spam' ] ]);

        $this->assertSame('entry_not_found', $out['error']['code']);
        $this->assertSame($before, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpmcp_snapshots"));
    }

    public function test_update_entry_status_rejects_an_unknown_status_and_non_admins(): void
    {
        $bad = $this->integration->handle_write([ 'operation' => 'update-entry-status', 'args' => [ 'entry_id' => $this->ada, 'status' => 'deleted' ] ]);
        $this->assertSame('invalid_args', $bad['error']['code']);

        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $denied = $this->integration->handle_write([ 'operation' => 'update-entry-status', 'args' => [ 'entry_id' => $this->ada, 'status' => 'spam' ] ]);
        $this->assertSame('operation_denied', $denied['error']['code']);
        $this->assertSame('active', \GFAPI::get_entry($this->ada)['status']);
    }

    public function test_rejects_unknown_operation(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'nope' ]);
        $this->assertArrayHasKey('error', $out);
    }

    public function test_missing_required_arg_is_rejected_before_dispatch(): void
    {
        // get-form requires form_id; omit it.
        $out = $this->integration->handle_read([ 'operation' => 'get-form' ]);
        $this->assertArrayHasKey('error', $out);
    }
}
