<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\SureForms_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

/**
 * Issue #675 against the real SureForms (bin/test-local.sh --live-forms,
 * WPMCP_LIVE_FORMS=1): SureForms' own registrar creates its srfm_entries
 * table, the entry is written by its own accessor, and delete-entry
 * snapshots, deletes and restores that real row. Skipped elsewhere; the live
 * leg runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group forms-live
 */
class SureFormsLiveTest extends \WP_UnitTestCase
{
    private int $form_id;
    private int $entry;

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        if (class_exists('SRFM\Inc\Database\Register')) {
            \SRFM\Inc\Database\Register::init(); // DDL (CREATE TABLE), outside any test transaction
        }
    }

    public static function tear_down_after_class(): void
    {
        global $wpdb;
        if (class_exists('SRFM\Inc\Database\Register')) {
            foreach ((array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'srfm_') . '%')) as $table) {
                $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
            }
        }
        parent::tear_down_after_class();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists('SRFM\Inc\Database\Register') || ! class_exists('SRFM\Inc\Database\Tables\Entries')) {
            $this->markTestSkipped('Needs the real SureForms (bin/test-local.sh --live-forms, WPMCP_LIVE_FORMS=1).');
        }
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_forms_write', '__return_true');

        $this->form_id = (int) wp_insert_post([ 'post_type' => 'sureforms_form', 'post_status' => 'publish', 'post_title' => 'Live SureForms' ]);
        $this->entry   = (int) \SRFM\Inc\Database\Tables\Entries::add([ 'form_id' => $this->form_id, 'form_data' => [ 'srfm-input-name' => 'Ada', 'srfm-email' => 'ada@example.test' ] ]);
        $this->assertGreaterThan(0, $this->entry, 'SureForms stored the entry');
    }

    public function test_delete_entry_snapshots_the_real_row_and_rollback_restores_it(): void
    {
        global $wpdb;
        $integration = new SureForms_Integration();
        $this->assertTrue($integration->is_available());

        $preview = $integration->handle_write([ 'operation' => 'delete-entry', 'args' => [ 'entry_id' => $this->entry ] ]);
        $this->assertSame('confirmation_required', $preview['error']['code'], wp_json_encode($preview));
        $this->assertSame(2, $preview['error']['data']['values']);
        $this->assertStringNotContainsString('ada@example.test', wp_json_encode($preview));

        $out = $integration->handle_write([ 'operation' => 'delete-entry', 'confirm' => true, 'args' => [ 'entry_id' => $this->entry ] ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertTrue($out['result']['deleted']);
        $this->assertTrue($out['recoverable']);
        $this->assertEmpty(\SRFM\Inc\Database\Tables\Entries::get($this->entry), 'SureForms no longer finds the entry');
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE ID = %d', $wpdb->prefix . 'srfm_entries', $this->entry)));

        $undone = ( new Rollback_Operation() )->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertTrue($undone['restored'], wp_json_encode($undone));
        // SureForms keeps a per-request query cache on its table object, which
        // this one process shares across the delete and the undo (two requests
        // on a real site), so the restored row is read straight from the table.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE ID = %d', $wpdb->prefix . 'srfm_entries', $this->entry), ARRAY_A);
        $this->assertNotEmpty($row, 'The row is back at its original id');
        $this->assertSame($this->form_id, (int) $row['form_id']);
        $this->assertStringContainsString('ada@example.test', (string) $row['form_data']);
    }
}
