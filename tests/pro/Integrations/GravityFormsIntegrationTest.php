<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Gravity_Forms_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;

require_once __DIR__ . '/../../support/gfapi-stub.php';
require_once __DIR__ . '/../../support/forms-delete-preview.php';

/**
 * Gravity Forms dispatcher (forms adapter pack, pro tier). Exercised against a faithful GFAPI double
 * (see tests/support/gfapi-stub.php) reproducing Gravity Forms 2.9's public
 * API contracts, since Gravity Forms is paid and cannot install from wp.org.
 */
class GravityFormsIntegrationTest extends \WP_UnitTestCase
{
    use \WPMCP\Tests\Support\Forms_Delete_Preview_Assertions;

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
        foreach ([ 'list-forms', 'get-form', 'list-fields', 'list-notifications', 'list-entries', 'get-entry', 'get-notes', 'update-entry-status', 'delete-entry' ] as $op) {
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
        $this->assertSame('S***', $spam['result']['entries'][0]['1']);

        $paged = $this->integration->handle_read([ 'operation' => 'list-entries', 'args' => [ 'form_id' => 1, 'page_size' => 1, 'offset' => 1 ] ]);
        $this->assertCount(1, $paged['result']['entries']);
        $this->assertSame(2, $paged['result']['total']);
    }

    public function test_list_entries_masks_field_values_and_personal_meta_but_keeps_ids_dates_status_and_payment(): void
    {
        $id = \GFAPI::seed_entry(1, [
            '1'              => 'Grace',
            '2'              => 'grace@example.com',
            'ip'             => '203.0.113.7',
            'user_agent'     => 'Mozilla/5.0',
            'source_url'     => 'https://example.com/contact?email=grace@example.com',
            'created_by'     => '42',
            'payment_status' => 'Paid',
            'payment_amount' => '19.00',
        ], 'active', '2026-03-03 10:00:00');

        $out = $this->integration->handle_read([ 'operation' => 'list-entries', 'args' => [ 'form_id' => 1 ] ])['result'];
        $this->assertTrue($out['masked']);
        $row = array_values(array_filter($out['entries'], static fn ($e) => (int) $e['id'] === $id))[0];
        $this->assertSame('G***', $row['1']);
        $this->assertSame('g***@example.com', $row['2']);
        $this->assertSame('2***', $row['ip']);
        $this->assertSame('M***', $row['user_agent']);
        $this->assertSame('h***@example.com', $row['source_url']);
        $this->assertSame('4***', $row['created_by']);
        $this->assertSame('1', $row['form_id']);
        $this->assertSame('active', $row['status']);
        $this->assertSame('2026-03-03 10:00:00', $row['date_created']);
        $this->assertSame('Paid', $row['payment_status']);
        $this->assertSame('19.00', $row['payment_amount']);
        $json = wp_json_encode($out);
        foreach ([ 'grace@example.com', 'Grace', '203.0.113.7', 'Mozilla' ] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }

        $one = $this->integration->handle_read([ 'operation' => 'get-entry', 'args' => [ 'entry_id' => $id ] ])['result'];
        $this->assertArrayNotHasKey('masked', $one);
        $this->assertSame('grace@example.com', $one['entry']['2']);
        $this->assertSame('203.0.113.7', $one['entry']['ip']);
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

    // ---- #675: delete-entry ----------------------------------------------

    private function rows(string $table, string $column, int $id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE %i = %d', $wpdb->prefix . $table, $column, $id));
    }

    private function seed_children(int $entry): void
    {
        \GFAPI::seed_row('gf_entry_meta', [ 'form_id' => 1, 'entry_id' => $entry, 'meta_key' => '1', 'meta_value' => 'Ada' ]); // phpcs:ignore WordPress.DB.SlowDBQuery -- a column of Gravity Forms' own table.
        \GFAPI::seed_row('gf_entry_meta', [ 'form_id' => 1, 'entry_id' => $entry, 'meta_key' => '2', 'meta_value' => 'ada@example.com' ]); // phpcs:ignore WordPress.DB.SlowDBQuery -- a column of Gravity Forms' own table.
        \GFAPI::seed_row('gf_entry_notes', [ 'entry_id' => $entry, 'user_name' => 'admin', 'value' => 'Followed up by phone', 'note_type' => 'user' ]);
        \GFAPI::seed_row('gf_entry_meta', [ 'form_id' => 1, 'entry_id' => $this->spammer, 'meta_key' => '1', 'meta_value' => 'Spammer' ]); // phpcs:ignore WordPress.DB.SlowDBQuery -- a column of Gravity Forms' own table.
    }

    public function test_delete_entry_is_off_until_opted_in_then_needs_confirm_and_previews_without_values(): void
    {
        $this->seed_children($this->ada);
        $this->assertSame('opt_in_required', $this->integration->handle_write([ 'operation' => 'delete-entry', 'args' => [ 'entry_id' => $this->ada ], 'confirm' => true ])['error']['code']);

        add_filter('wpmcp_enable_forms_write', '__return_true');
        $preview = $this->integration->handle_write([ 'operation' => 'delete-entry', 'args' => [ 'entry_id' => $this->ada ] ]);
        $this->assert_delete_entry_preview($preview, sprintf('Gravity Forms entry %d of form 1', $this->ada), $this->ada, 2);
        $this->assertSame(1, $preview['error']['data']['notes']);
        $this->assertStringNotContainsString('ada@example.com', (string) wp_json_encode($preview));
        $this->assertSame(1, $this->rows('gf_entry', 'id', $this->ada));
        remove_all_filters('wpmcp_enable_forms_write');
    }

    public function test_delete_entry_removes_the_entry_its_values_and_notes_and_one_session_restores_them(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $this->seed_children($this->ada);
        $out = $this->integration->handle_write([ 'operation' => 'delete-entry', 'args' => [ 'entry_id' => $this->ada ], 'confirm' => true ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['result']['deleted']);
        $this->assertTrue($out['recoverable']);
        $this->assertSame(0, $this->rows('gf_entry', 'id', $this->ada));
        $this->assertSame(0, $this->rows('gf_entry_meta', 'entry_id', $this->ada));
        $this->assertSame(0, $this->rows('gf_entry_notes', 'entry_id', $this->ada));
        $this->assertSame(1, $this->rows('gf_entry', 'id', $this->spammer), 'Another entry stays');
        $this->assertSame(1, $this->rows('gf_entry_meta', 'entry_id', $this->spammer), 'Another entry keeps its values');

        ( new Rollback_Session() )->handle([ 'session_id' => $out['session_id'] ]);
        $this->assertSame(1, $this->rows('gf_entry', 'id', $this->ada));
        $this->assertSame(2, $this->rows('gf_entry_meta', 'entry_id', $this->ada));
        $this->assertSame(1, $this->rows('gf_entry_notes', 'entry_id', $this->ada));
        $this->assertSame('Ada', \GFAPI::get_entry($this->ada)['1']);
        remove_all_filters('wpmcp_enable_forms_write');
    }

    public function test_delete_entry_refuses_an_unknown_entry_and_one_with_payments(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $this->assertSame('entry_not_found', $this->integration->handle_write([ 'operation' => 'delete-entry', 'args' => [ 'entry_id' => 404 ], 'confirm' => true ])['error']['code']);

        \GFAPI::seed_row('gf_addon_payment_transaction', [ 'lead_id' => $this->ada, 'transaction_id' => 'ch_1', 'amount' => '10.00' ]);
        $out = $this->integration->handle_write([ 'operation' => 'delete-entry', 'args' => [ 'entry_id' => $this->ada ], 'confirm' => true ]);
        $this->assertSame('entry_has_payments', $out['error']['code']);
        $this->assertSame(1, $this->rows('gf_entry', 'id', $this->ada));

        // A payment recorded only on the entry itself (payment_status / transaction_id) also blocks it.
        $paid = \GFAPI::seed_entry(1, [ '1' => 'Payer', 'payment_status' => 'Paid', 'transaction_id' => 'ch_2' ]);
        $out  = $this->integration->handle_write([ 'operation' => 'delete-entry', 'args' => [ 'entry_id' => $paid ], 'confirm' => true ]);
        $this->assertSame('entry_has_payments', $out['error']['code']);
        $this->assertSame(1, $this->rows('gf_entry', 'id', $paid));
        remove_all_filters('wpmcp_enable_forms_write');
    }

    public function test_delete_entry_is_refused_below_manage_options(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $out = $this->integration->handle_write([ 'operation' => 'delete-entry', 'args' => [ 'entry_id' => $this->ada ], 'confirm' => true ]);
        $this->assertSame('operation_denied', $out['error']['code']);
        $this->assertSame(1, $this->rows('gf_entry', 'id', $this->ada));
        remove_all_filters('wpmcp_enable_forms_write');
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
