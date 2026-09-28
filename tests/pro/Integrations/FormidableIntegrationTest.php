<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Formidable_Integration;

require_once __DIR__ . '/../../support/forms-stubs.php';

/**
 * The Formidable dispatcher (forms adapter pack, pro tier), exercised against
 * doubles of FrmForm, FrmField, FrmFormAction and FrmEntry
 * (tests/support/forms-stubs.php). Live Formidable stays production-verified.
 */
class FormidableIntegrationTest extends \WP_UnitTestCase
{
    private Formidable_Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        \FrmForm::$forms   = [ 5 => (object) [ 'id' => 5, 'name' => 'Booking', 'form_key' => 'booking' ] ];
        \FrmField::$fields = [ 5 => [
            (object) [ 'id' => 50, 'field_key' => 'name', 'name' => 'Name', 'type' => 'text', 'required' => '1' ],
            (object) [ 'id' => 51, 'field_key' => 'room', 'name' => 'Room', 'type' => 'select', 'required' => '0', 'options' => [ 'Single', 'Double' ] ],
        ] ];
        \FrmFormAction::$actions = [ 5 => [
            (object) [ 'ID' => 70, 'post_title' => 'Admin email', 'post_status' => 'publish', 'post_excerpt' => 'email', 'post_content' => [ 'email_to' => '[admin_email]', 'email_subject' => 'New booking' ] ],
            (object) [ 'ID' => 71, 'post_title' => 'Paused', 'post_status' => 'draft', 'post_excerpt' => 'email', 'post_content' => [] ],
        ] ];
        \FrmEntry::$entries = [
            50 => (object) [ 'id' => 50, 'form_id' => 5, 'item_key' => 'e50', 'created_at' => '2026-03-01 10:00:00', 'metas' => [ 50 => 'Ada' ], 'ip' => '203.0.113.1' ],
            52 => (object) [ 'id' => 52, 'form_id' => 5, 'item_key' => 'e52', 'created_at' => '2026-03-02 10:00:00', 'is_draft' => 1 ],
            51 => (object) [ 'id' => 51, 'form_id' => 9, 'item_key' => 'other', 'created_at' => '2026-03-03 10:00:00' ],
        ];
        $this->integration = new Formidable_Integration();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    public function test_lists_and_reads_forms(): void
    {
        $this->assertTrue($this->integration->is_available());
        $this->assertSame('pro', $this->integration->tier());

        $forms = $this->integration->handle_read([ 'operation' => 'list-forms' ]);
        $this->assertSame(1, $forms['result']['total']);
        $this->assertSame('Booking', $forms['result']['forms'][0]['name']);
        $this->assertSame('booking', $forms['result']['forms'][0]['key']);

        $form = $this->integration->handle_read([ 'operation' => 'get-form', 'args' => [ 'form_id' => 5 ] ]);
        $this->assertSame('Booking', $form['result']['form']->name);
        $this->assertNull($this->integration->handle_read([ 'operation' => 'get-form', 'args' => [ 'form_id' => 999 ] ])['result']['form']);
    }

    public function test_fields_and_email_actions(): void
    {
        $fields = $this->integration->handle_read([ 'operation' => 'list-fields', 'args' => [ 'form_id' => 5 ] ])['result']['fields'];
        $this->assertTrue($fields[0]['required']);
        $this->assertFalse($fields[1]['required']);
        $this->assertSame([ 'Single', 'Double' ], $fields[1]['options']);

        $notes = $this->integration->handle_read([ 'operation' => 'list-notifications', 'args' => [ 'form_id' => 5 ] ])['result']['notifications'];
        $this->assertSame('[admin_email]', $notes[0]['to']);
        $this->assertTrue($notes[0]['active']);
        $this->assertFalse($notes[1]['active'], 'A draft form action is switched off');
    }

    public function test_entries_page_newest_first_and_read_with_values(): void
    {
        $page = $this->integration->handle_read([ 'operation' => 'list-entries', 'args' => [ 'form_id' => 5, 'page_size' => 1 ] ])['result'];
        $this->assertSame(2, $page['total']);
        $this->assertSame(52, $page['entries'][0]['id']);
        $this->assertTrue($page['entries'][0]['is_draft']);

        $entry = $this->integration->handle_read([ 'operation' => 'get-entry', 'args' => [ 'entry_id' => 50 ] ])['result']['entry'];
        $this->assertSame([ 50 => 'Ada' ], $entry['values']);
        $this->assertNull($this->integration->handle_read([ 'operation' => 'get-entry', 'args' => [ 'entry_id' => 999 ] ])['result']['entry']);
    }

    public function test_there_is_no_status_write_because_formidable_models_no_entry_status(): void
    {
        $names = array_column($this->integration->catalog()['operations'], 'name');
        $this->assertNotContains('update-entry-status', $names);
        $this->assertNotContains('delete-entry', $names);
    }
}
