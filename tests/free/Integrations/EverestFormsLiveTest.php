<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #676 against the real Everest Forms (bin/test-local.sh --live-forms,
 * WPMCP_LIVE_FORMS=1): Everest Forms' own installer creates its entry
 * tables, the form is its default Contact Form template, and the entry is
 * written by its own EVF_Form_Task::entry_save(). The reads, the status
 * change and the delete run on those rows and are undone, and Everest
 * Forms' own evf_get_entry() reads the result back. Skipped elsewhere; the
 * live leg runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group forms-live
 */
class EverestFormsLiveTest extends \WP_UnitTestCase
{
    private int $form_id;

    private int $entry;

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        if (class_exists('EVF_Install')) {
            // DDL (dbDelta), outside any test transaction.
            $create = new \ReflectionMethod('EVF_Install', 'create_tables');
            $create->setAccessible(true);
            $create->invoke(null);
        }
    }

    public static function tear_down_after_class(): void
    {
        global $wpdb;
        if (class_exists('EVF_Install')) {
            foreach ([ 'evf_entries', 'evf_entrymeta', 'evf_sessions' ] as $table) {
                $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . $table));
            }
        }
        parent::tear_down_after_class();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('EVF_VERSION') || ! function_exists('evf') || ! function_exists('evf_get_entry')) {
            $this->markTestSkipped('Needs the real Everest Forms (bin/test-local.sh --live-forms, WPMCP_LIVE_FORMS=1).');
        }
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_everest_forms_write', '__return_true');

        $title = 'Live Contact';
        include EVF_ABSPATH . 'includes/templates/contact.php';
        $this->form_id = (int) wp_insert_post([ 'post_title' => $title, 'post_status' => 'publish', 'post_type' => 'everest_form', 'post_content' => '{}' ]);
        $form_data     = array_merge([ 'id' => $this->form_id ], $form_template['contact']);
        wp_update_post([ 'ID' => $this->form_id, 'post_content' => evf_encode($form_data) ]);

        $fields = [];
        foreach ([ 'fullname' => 'Ada Lovelace', 'email' => 'ada@example.test', 'subject' => 'Engines', 'message' => 'Hello there' ] as $id => $value) {
            $def           = $form_data['form_fields'][ $id ];
            $fields[ $id ] = [ 'name' => $def['label'], 'value' => $value, 'id' => $id, 'type' => $def['type'], 'meta_key' => $def['meta-key'] ];
        }
        $this->entry = (int) evf()->task->entry_save($fields, [], $this->form_id, $form_data);
        $this->assertGreaterThan(0, $this->entry, 'Everest Forms stored the entry');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_everest_forms_write');
        parent::tearDown();
    }

    private function read(string $op, array $args): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'];
    }

    private function write(string $op, array $args, bool $confirm = false): array
    {
        $call = [ 'operation' => $op, 'args' => $args ] + ($confirm ? [ 'confirm' => true ] : []);
        return (new Plugin_Data_Integration())->handle_write($call);
    }

    public function test_reads_list_the_template_form_and_mask_the_entry_in_lists_only(): void
    {
        $forms = array_column($this->read('everestforms-list-forms', [])['forms'], null, 'id');
        $this->assertSame(1, $forms[ $this->form_id ]['entries']['total']);

        $fields = $this->read('everestforms-list-fields', [ 'form_id' => $this->form_id ])['fields'];
        $this->assertSame([ 'fullname', 'email', 'subject', 'message' ], array_column($fields, 'id'));
        $notes = $this->read('everestforms-list-notifications', [ 'form_id' => $this->form_id ])['notifications'];
        $this->assertSame('{admin_email}', $notes[0]['to']);

        $list = $this->read('everestforms-list-entries', [ 'form_id' => $this->form_id ]);
        $this->assertSame([ $this->entry ], array_column($list['entries'], 'id'));
        $this->assertStringNotContainsString('ada@example.test', (string) wp_json_encode($list));

        $entry = $this->read('everestforms-get-entry', [ 'entry_id' => $this->entry ])['entry'];
        $this->assertSame('ada@example.test', array_column($entry['fields'], 'value', 'id')['email']);
        $this->assertFalse($entry['viewed']);
    }

    public function test_spam_and_star_change_the_real_row_and_roll_back(): void
    {
        $out = $this->write('everestforms-update-entry-status', [ 'entry_id' => $this->entry, 'status' => 'spam', 'starred' => true ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $seen = evf_get_entry($this->entry, false, [ 'cap' => false ]);
        $this->assertSame('spam', $seen->status, 'Everest Forms reads the new status');
        $this->assertSame('1', (string) $seen->starred);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        wp_cache_delete($this->entry, 'evf-entry');
        $seen = evf_get_entry($this->entry, false, [ 'cap' => false ]);
        $this->assertSame('publish', $seen->status);
        $this->assertSame('0', (string) $seen->starred);
    }

    public function test_delete_removes_the_real_rows_and_one_session_restores_them(): void
    {
        $out = $this->write('everestforms-delete-entry', [ 'entry_id' => $this->entry ], true);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        wp_cache_delete($this->entry, 'evf-entry');
        $this->assertSame([], evf_get_entries_ids($this->form_id), 'Everest Forms no longer finds the entry');

        $this->assertSame(2, Rollback_Service::restore_session($out['session_id']));
        wp_cache_delete($this->form_id, 'evf-entries-ids');
        wp_cache_delete($this->entry, 'evf-entry');
        wp_cache_delete($this->entry, 'evf-entrymeta');
        $this->assertSame([ $this->entry ], evf_get_entries_ids($this->form_id));
        $seen = evf_get_entry($this->entry, false, [ 'cap' => false ]);
        $this->assertSame('ada@example.test', $seen->meta['email']);
    }
}
