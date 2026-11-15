<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Forminator_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Session;

/**
 * Issue #675 against the real Forminator (bin/test-local.sh --live-forms,
 * WPMCP_LIVE_FORMS=1): Forminator's own installer creates its tables, the
 * entry is written by its own entry model, and delete-entry removes and
 * restores the real rows. Skipped elsewhere; the live leg runs with
 * --fail-on-skipped so it cannot pass vacuously.
 *
 * @group forms-live
 */
class ForminatorLiveTest extends \WP_UnitTestCase
{
    private int $form_id;
    private int $entry;

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        if (class_exists('Forminator_Database_Tables')) {
            $offline = static fn () => new \WP_Error('offline', 'Offline test run.');
            add_filter('pre_http_request', $offline);
            \Forminator_Database_Tables::install_database_tables(); // DDL (dbDelta), outside any test transaction
            remove_filter('pre_http_request', $offline);
        }
    }

    public static function tear_down_after_class(): void
    {
        global $wpdb;
        if (class_exists('Forminator_Database_Tables')) {
            foreach ((array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'frmt_') . '%')) as $table) {
                $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
            }
        }
        parent::tear_down_after_class();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists('Forminator_Database_Tables') || ! class_exists('Forminator_Form_Entry_Model')) {
            $this->markTestSkipped('Needs the real Forminator (bin/test-local.sh --live-forms, WPMCP_LIVE_FORMS=1).');
        }
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_forms_write', '__return_true');

        $this->form_id = (int) wp_insert_post([ 'post_type' => 'forminator_forms', 'post_status' => 'publish', 'post_title' => 'Live Forminator' ]);
        $this->entry   = $this->make_entry($this->form_id, [ 'email-1' => 'ada@example.test', 'name-1' => 'Ada' ]);
        $this->assertGreaterThan(0, $this->entry, 'Forminator stored the entry');
    }

    private function make_entry(int $form_id, array $values): int
    {
        $entry             = new \Forminator_Form_Entry_Model();
        $entry->entry_type = 'custom-forms';
        $entry->form_id    = $form_id;
        $entry->is_spam    = 0;
        $entry->save();
        $meta = [];
        foreach ($values as $name => $value) {
            $meta[] = [ 'name' => $name, 'value' => $value ];
        }
        $entry->set_fields($meta);
        return (int) $entry->entry_id;
    }

    private function rows(string $table, string $column, int $id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE %i = %d', $wpdb->prefix . $table, $column, $id));
    }

    public function test_delete_entry_removes_the_real_rows_and_one_session_restores_them(): void
    {
        $integration = new Forminator_Integration();
        $metas       = $this->rows('frmt_form_entry_meta', 'entry_id', $this->entry);
        $this->assertGreaterThan(0, $metas);

        $preview = $integration->handle_write([ 'operation' => 'delete-entry', 'args' => [ 'form_id' => $this->form_id, 'entry_id' => $this->entry ] ]);
        $this->assertSame('confirmation_required', $preview['error']['code'], wp_json_encode($preview));
        $this->assertStringNotContainsString('ada@example.test', wp_json_encode($preview));

        $wrong = $integration->handle_write([ 'operation' => 'delete-entry', 'confirm' => true, 'args' => [ 'form_id' => $this->form_id + 999, 'entry_id' => $this->entry ] ]);
        $this->assertSame('entry_not_found', $wrong['error']['code'], 'An entry of another form is not found');

        $out = $integration->handle_write([ 'operation' => 'delete-entry', 'confirm' => true, 'args' => [ 'form_id' => $this->form_id, 'entry_id' => $this->entry ] ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertTrue($out['result']['deleted']);
        $this->assertSame(0, $this->rows('frmt_form_entry', 'entry_id', $this->entry));
        $this->assertSame(0, $this->rows('frmt_form_entry_meta', 'entry_id', $this->entry));
        $this->assertEmpty((new \Forminator_Form_Entry_Model($this->entry))->entry_id, 'Forminator no longer finds the entry');

        $undone = ( new Rollback_Session() )->handle([ 'session_id' => $out['session_id'] ]);
        $this->assertNotEmpty($undone, wp_json_encode($undone));
        $this->assertSame(1, $this->rows('frmt_form_entry', 'entry_id', $this->entry));
        $this->assertSame($metas, $this->rows('frmt_form_entry_meta', 'entry_id', $this->entry));
        $this->assertSame($this->entry, (int) (new \Forminator_Form_Entry_Model($this->entry))->entry_id, 'Forminator finds the restored entry');
    }
}
