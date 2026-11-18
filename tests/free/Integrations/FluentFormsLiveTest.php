<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Fluent_Forms_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Session;

/**
 * Issue #675 against the real Fluent Forms (bin/test-local.sh --live-forms,
 * WPMCP_LIVE_FORMS=1): Fluent Forms' own migrator creates its tables, the
 * submission and its child rows are written through its query builder, and
 * delete-entry removes and restores them on those real tables. Skipped
 * elsewhere; the live leg runs with --fail-on-skipped so it cannot pass
 * vacuously.
 *
 * @group forms-live
 */
class FluentFormsLiveTest extends \WP_UnitTestCase
{
    private int $form_id;
    private int $entry;
    private int $other;

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        if (class_exists('FluentForm\Database\DBMigrator')) {
            $offline = static fn () => new \WP_Error('offline', 'Offline test run.');
            add_filter('pre_http_request', $offline);
            \FluentForm\Database\DBMigrator::run(); // DDL (dbDelta), outside any test transaction
            remove_filter('pre_http_request', $offline);
        }
    }

    public static function tear_down_after_class(): void
    {
        global $wpdb;
        if (class_exists('FluentForm\Database\DBMigrator')) {
            foreach ((array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'fluentform_') . '%')) as $table) {
                $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
            }
        }
        parent::tear_down_after_class();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists('FluentForm\Database\DBMigrator') || ! function_exists('wpFluent')) {
            $this->markTestSkipped('Needs the real Fluent Forms (bin/test-local.sh --live-forms, WPMCP_LIVE_FORMS=1).');
        }
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_forms_write', '__return_true');

        $now           = current_time('mysql');
        $this->form_id = (int) wpFluent()->table('fluentform_forms')->insertGetId([ 'title' => 'Live Fluent', 'status' => 'published', 'type' => 'form', 'form_fields' => '{"fields":[]}', 'created_at' => $now, 'updated_at' => $now ]);
        $submission    = static fn (int $form, int $n) => wpFluent()->table('fluentform_submissions')->insertGetId([ 'form_id' => $form, 'serial_number' => $n, 'response' => '{"email":"ada@example.test"}', 'status' => 'unread', 'ip' => '203.0.113.9', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql') ]);
        $this->entry   = (int) $submission($this->form_id, 1);
        $this->other   = (int) $submission($this->form_id, 2);
        $this->assertGreaterThan(0, $this->entry);
        wpFluent()->table('fluentform_submission_meta')->insert([ 'response_id' => $this->entry, 'form_id' => $this->form_id, 'meta_key' => 'is_favourite', 'value' => '1', 'created_at' => $now, 'updated_at' => $now ]);
        wpFluent()->table('fluentform_logs')->insert([ 'parent_source_id' => $this->form_id, 'source_type' => 'submission_item', 'source_id' => $this->entry, 'component' => 'Emails', 'status' => 'success', 'title' => 'Email sent', 'description' => 'To ada@example.test', 'created_at' => $now ]);
        wpFluent()->table('fluentform_entry_details')->insert([ 'form_id' => $this->form_id, 'submission_id' => $this->entry, 'field_name' => 'email', 'field_value' => 'ada@example.test' ]);
        wpFluent()->table('fluentform_entry_details')->insert([ 'form_id' => $this->form_id, 'submission_id' => $this->entry, 'field_name' => 'name', 'field_value' => 'Ada' ]);
    }

    private function rows(string $table, string $column, int $id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE %i = %d', $wpdb->prefix . $table, $column, $id));
    }

    public function test_delete_entry_removes_every_real_row_of_the_entry_and_one_session_restores_them(): void
    {
        $integration = new Fluent_Forms_Integration();
        $this->assertTrue($integration->is_available());

        $preview = $integration->handle_write([ 'operation' => 'delete-entry', 'args' => [ 'entry_id' => $this->entry ] ]);
        $this->assertSame('confirmation_required', $preview['error']['code']);
        $this->assertSame(2, $preview['error']['data']['values']);
        $this->assertStringContainsString('This is not an erasure', $preview['error']['message'], 'Issue #946');
        $this->assertStringNotContainsString('ada@example.test', wp_json_encode($preview));

        $out = $integration->handle_write([ 'operation' => 'delete-entry', 'confirm' => true, 'args' => [ 'entry_id' => $this->entry ] ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertTrue($out['result']['deleted']);
        $this->assertTrue($out['recoverable']);
        $this->assertSame(0, $this->rows('fluentform_submissions', 'id', $this->entry));
        $this->assertSame(0, $this->rows('fluentform_submission_meta', 'response_id', $this->entry));
        $this->assertSame(0, $this->rows('fluentform_logs', 'source_id', $this->entry));
        $this->assertSame(0, $this->rows('fluentform_entry_details', 'submission_id', $this->entry));
        $this->assertSame(1, $this->rows('fluentform_submissions', 'id', $this->other), 'Another entry stays');

        $undone = ( new Rollback_Session() )->handle([ 'session_id' => $out['session_id'] ]);
        $this->assertNotEmpty($undone, wp_json_encode($undone));
        $this->assertSame(1, $this->rows('fluentform_submissions', 'id', $this->entry));
        $this->assertSame(1, $this->rows('fluentform_submission_meta', 'response_id', $this->entry));
        $this->assertSame(1, $this->rows('fluentform_logs', 'source_id', $this->entry));
        $this->assertSame(2, $this->rows('fluentform_entry_details', 'submission_id', $this->entry));
        $this->assertSame('{"email":"ada@example.test"}', wpFluent()->table('fluentform_submissions')->where('id', $this->entry)->first()->response, 'The entry comes back with its response');
    }

    /** Issue #945: list-entries reads the real query builder's collection, not its properties. */
    public function test_list_entries_returns_the_real_submissions_columns(): void
    {
        $out = ( new Fluent_Forms_Integration() )->handle_read([ 'operation' => 'list-entries', 'args' => [ 'form_id' => $this->form_id ] ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));

        $this->assertSame(2, $out['result']['total']);
        $this->assertSame([ $this->other, $this->entry ], array_column($out['result']['entries'], 'id'), 'Newest first');
        $this->assertSame([ $this->form_id ], array_values(array_unique(array_column($out['result']['entries'], 'form_id'))));
        $this->assertSame([ 2, 1 ], array_column($out['result']['entries'], 'serial_number'));
        $this->assertSame('unread', $out['result']['entries'][0]['status']);
        $this->assertNotSame('', $out['result']['entries'][0]['created_at']);

        $forms = ( new Fluent_Forms_Integration() )->handle_read([ 'operation' => 'list-forms' ])['result']['forms'];
        $this->assertContains($this->form_id, array_column($forms, 'id'), 'list-forms iterates the collection too');
    }
}
