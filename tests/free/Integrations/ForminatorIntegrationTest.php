<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Forminator_Integration;

require_once __DIR__ . '/../../support/forminator-stubs.php';

/**
 * The Forminator dispatcher pair, exercised against a faithful double of the
 * public Forminator_API surface it calls (tests/support/forminator-stubs.php).
 * Live Forminator stays production-verified.
 */
class ForminatorIntegrationTest extends \WP_UnitTestCase
{
    private Forminator_Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        \Forminator_API::reset();
        \Forminator_API::$forms = [
            11 => new \Forminator_Test_Form(
                11,
                'contact-slug',
                [ 'formName' => 'Contact Us' ],
                'publish',
                [
                    new \Forminator_Test_Field([ 'element_id' => 'email-1', 'type' => 'email', 'field_label' => 'Email', 'required' => true ]),
                    new \Forminator_Test_Field([ 'element_id' => 'text-1', 'type' => 'text', 'field_label' => 'Name' ]),
                ]
            ),
            12 => new \Forminator_Test_Form(12, 'quote-slug', [], 'draft'),
        ];
        \Forminator_API::$entries = [
            101 => new \Forminator_Test_Entry(101, 11, '2026-02-01 10:00:00', [ 'email-1' => [ 'value' => 'a@example.test' ] ]),
            102 => new \Forminator_Test_Entry(102, 11, '2026-02-02 10:00:00', [ 'email-1' => [ 'value' => 'b@example.test' ] ]),
            103 => new \Forminator_Test_Entry(103, 12, '2026-02-03 10:00:00', [ 'text-1' => 'plain' ]),
        ];
        $this->integration = new Forminator_Integration();

        // Issue #66: delete-entry is default-off for every forms adapter, so
        // the deletion tests below opt in the way a site would. The
        // off-by-default contract itself is asserted in
        // test_delete_entry_is_off_by_default and in the shared conformance
        // suite; WP_UnitTestCase restores hooks between tests.
        add_filter('wpmcp_integration_op_enabled', static fn ($enabled, $integration, $op) => ('forminator' === $integration && 'delete-entry' === $op) ? true : $enabled, 10, 3);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        \Forminator_API::reset();
        parent::tearDown();
    }

    public function test_is_available_when_forminator_api_present(): void
    {
        $this->assertTrue($this->integration->is_available());
    }

    public function test_list_forms_returns_names_status_and_shortcodes(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'list-forms' ]);

        $this->assertSame(2, $out['result']['total']);
        $this->assertSame('Contact Us', $out['result']['forms'][0]['name']);
        $this->assertSame('publish', $out['result']['forms'][0]['status']);
        $this->assertSame('[forminator_form id="11"]', $out['result']['forms'][0]['shortcode']);
        // Falls back to the model slug when settings.formName is absent.
        $this->assertSame('quote-slug', $out['result']['forms'][1]['name']);
    }

    public function test_get_form_returns_fields_with_required_flags(): void
    {
        $out = $this->integration->handle_read([
            'operation' => 'get-form',
            'args'      => [ 'form_id' => 11 ],
        ]);

        $form = $out['result']['form'];
        $this->assertSame('Contact Us', $form['name']);
        $this->assertSame('[forminator_form id="11"]', $form['shortcode']);
        $this->assertSame('email-1', $form['fields'][0]['id']);
        $this->assertSame('email', $form['fields'][0]['type']);
        $this->assertSame('Email', $form['fields'][0]['label']);
        $this->assertTrue($form['fields'][0]['required']);
        $this->assertFalse($form['fields'][1]['required']);
    }

    public function test_get_missing_form_returns_null(): void
    {
        $out = $this->integration->handle_read([
            'operation' => 'get-form',
            'args'      => [ 'form_id' => 999 ],
        ]);

        $this->assertNull($out['result']['form']);
    }

    public function test_list_entries_is_scoped_to_the_form_and_pages(): void
    {
        $out = $this->integration->handle_read([
            'operation' => 'list-entries',
            'args'      => [ 'form_id' => 11 ],
        ]);

        $this->assertSame(2, $out['result']['total']);
        $this->assertSame('a@example.test', $out['result']['entries'][0]['values']['email-1']);
        $this->assertSame('2026-02-01 10:00:00', $out['result']['entries'][0]['created_at']);

        $paged = $this->integration->handle_read([
            'operation' => 'list-entries',
            'args'      => [ 'form_id' => 11, 'page_size' => 1, 'offset' => 1 ],
        ]);
        $this->assertCount(1, $paged['result']['entries']);
        $this->assertSame(102, $paged['result']['entries'][0]['id']);
    }

    public function test_get_entry_requires_matching_form_id(): void
    {
        $ok = $this->integration->handle_read([
            'operation' => 'get-entry',
            'args'      => [ 'form_id' => 11, 'entry_id' => 101 ],
        ]);
        $this->assertSame(101, $ok['result']['entry']['id']);

        $wrong_form = $this->integration->handle_read([
            'operation' => 'get-entry',
            'args'      => [ 'form_id' => 12, 'entry_id' => 101 ],
        ]);
        $this->assertNull($wrong_form['result']['entry']);
    }

    public function test_delete_entry_is_refused_without_confirm(): void
    {
        $out = $this->integration->handle_write([
            'operation' => 'delete-entry',
            'args'      => [ 'form_id' => 11, 'entry_id' => 101 ],
        ]);

        $this->assertSame('confirmation_required', $out['error']['code']);
        $this->assertSame([], \Forminator_API::$deleted);
    }

    public function test_delete_entry_with_confirm_deletes_and_reports_unrecoverable(): void
    {
        $out = $this->integration->handle_write([
            'operation' => 'delete-entry',
            'args'      => [ 'form_id' => 11, 'entry_id' => 101 ],
            'confirm'   => true,
        ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['result']['deleted']);
        $this->assertFalse($out['recoverable']);
        $this->assertArrayNotHasKey('operation_id', $out);
        $this->assertSame([ 101 ], \Forminator_API::$deleted);
    }

    public function test_delete_entry_reports_a_failed_api_call(): void
    {
        \Forminator_API::$delete_fails = true;

        $out = $this->integration->handle_write([
            'operation' => 'delete-entry',
            'args'      => [ 'form_id' => 11, 'entry_id' => 101 ],
            'confirm'   => true,
        ]);

        $this->assertFalse($out['result']['deleted']);
    }

    public function test_delete_entry_requires_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));

        $out = $this->integration->handle_write([
            'operation' => 'delete-entry',
            'args'      => [ 'form_id' => 11, 'entry_id' => 101 ],
            'confirm'   => true,
        ]);

        $this->assertSame('operation_denied', $out['error']['code']);
        $this->assertSame('capability', $out['error']['data']['reason']);
        $this->assertSame([], \Forminator_API::$deleted);
    }

    public function test_bad_args_are_rejected_before_the_handler_runs(): void
    {
        $out = $this->integration->handle_write([
            'operation' => 'delete-entry',
            'args'      => [ 'form_id' => 11 ],
            'confirm'   => true,
        ]);

        $this->assertSame('invalid_args', $out['error']['code']);
        $this->assertSame([], \Forminator_API::$deleted);
    }

    public function test_catalog_marks_delete_entry_destructive(): void
    {
        $catalog = $this->integration->catalog();
        $ops     = array_column($catalog['operations'], null, 'name');

        $this->assertSame('forminator', $catalog['integration']);
        $this->assertTrue($catalog['available']);
        $this->assertSame(
            [ 'list-forms', 'get-form', 'list-entries', 'get-entry', 'delete-entry' ],
            array_keys($ops)
        );
        $this->assertTrue($ops['delete-entry']['requires_confirm']);
        $this->assertSame('manage_options', $ops['delete-entry']['capability']);
        $this->assertFalse($ops['list-forms']['requires_confirm']);
    }

    public function test_write_half_advertises_destructive_hint(): void
    {
        [ $read, $write ] = $this->integration->abilities();

        $this->assertSame('wpmcp/forminator-read', $read->name);
        $this->assertSame('wpmcp/forminator-write', $write->name);
        $this->assertSame('free', $write->tier);
        $this->assertTrue($write->destructive_hint);
        $this->assertTrue($read->read_only_hint);
    }

    public function test_delete_entry_is_off_by_default(): void
    {
        remove_all_filters('wpmcp_integration_op_enabled');

        $ops = array_column($this->integration->catalog()['operations'], null, 'name');
        $this->assertFalse($ops['delete-entry']['enabled'], 'Issue #66: entry deletion ships off');

        $out = $this->integration->handle_write([
            'operation' => 'delete-entry',
            'args'      => $this->delete_entry_args(),
            'confirm'   => true,
        ]);

        $this->assertSame('operation_disabled', $out['error']['code']);
    }

    /** @return array<string, int> the args a valid delete-entry call takes here. */
    private function delete_entry_args(): array
    {
        return [ 'form_id' => 11, 'entry_id' => 101 ];
    }
}
