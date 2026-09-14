<?php

namespace WPMCP\Tests\Free\Integrations;

use SRFM\Inc\Database\Tables\Entries;
use WPMCP\Integrations\SureForms_Integration;

require_once __DIR__ . '/../../support/sureforms-stubs.php';

/**
 * The SureForms dispatcher pair. Forms run against a real sureforms_form CPT
 * with real srfm/* block content, so parse_blocks() field extraction is
 * genuinely exercised; entries run against a faithful double of SureForms' own
 * Entries table accessor (tests/support/sureforms-stubs.php), which the
 * harness cannot install. Live SureForms stays production-verified.
 */
class SureFormsIntegrationTest extends \WP_UnitTestCase
{
    private const BLOCKS = '<!-- wp:srfm/input {"slug":"name","label":"Your name","required":true} /-->'
        . '<!-- wp:srfm/group -->'
        . '<!-- wp:srfm/email {"slug":"email","label":"Email"} /-->'
        . '<!-- /wp:srfm/group -->'
        . '<!-- wp:paragraph --><p>not a field</p><!-- /wp:paragraph -->';

    private SureForms_Integration $integration;
    private int $form_id;

    protected function setUp(): void
    {
        parent::setUp();
        register_post_type('sureforms_form', [ 'public' => true, 'label' => 'Forms' ]);
        Entries::reset();
        $this->integration = new SureForms_Integration();

        // Issue #66: delete-entry is default-off for every forms adapter, so
        // the deletion tests below opt in the way a site would. The
        // off-by-default contract itself is asserted in
        // test_delete_entry_is_off_by_default and in the shared conformance
        // suite; WP_UnitTestCase restores hooks between tests.
        add_filter('wpmcp_integration_op_enabled', static fn ($enabled, $integration, $op) => ('sureforms' === $integration && 'delete-entry' === $op) ? true : $enabled, 10, 3);
        $this->form_id     = self::factory()->post->create([
            'post_type'    => 'sureforms_form',
            'post_title'   => 'Contact',
            'post_content' => self::BLOCKS,
        ]);
        Entries::seed(201, $this->form_id, 'unread', [ 'name' => 'Ada', 'email' => 'ada@example.test' ]);
        Entries::seed(202, $this->form_id, 'read', [ 'name' => 'Grace' ]);
        Entries::seed(203, $this->form_id + 1000, 'unread', [ 'name' => 'Other form' ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        Entries::reset();
        unregister_post_type('sureforms_form');
        parent::tearDown();
    }

    public function test_available_when_cpt_and_entries_accessor_present(): void
    {
        $this->assertTrue($this->integration->is_available());
    }

    public function test_unavailable_without_the_form_cpt(): void
    {
        unregister_post_type('sureforms_form');

        $this->assertFalse($this->integration->is_available());
        $out = $this->integration->handle_read([ 'operation' => 'list-forms' ]);
        $this->assertSame('integration_unavailable', $out['error']['code']);

        register_post_type('sureforms_form', [ 'public' => true, 'label' => 'Forms' ]);
    }

    public function test_list_forms_returns_entry_counts_and_shortcodes(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'list-forms' ]);

        $this->assertSame(1, $out['result']['total']);
        $form = $out['result']['forms'][0];
        $this->assertSame('Contact', $form['title']);
        $this->assertSame(2, $form['entry_count']);
        $this->assertSame(sprintf('[sureforms id="%d"]', $this->form_id), $form['shortcode']);
    }

    public function test_get_form_parses_nested_srfm_field_blocks(): void
    {
        $out = $this->integration->handle_read([
            'operation' => 'get-form',
            'args'      => [ 'form_id' => $this->form_id ],
        ]);

        $fields = $out['result']['form']['fields'];
        $this->assertCount(2, $fields, 'Only srfm/* blocks carrying a slug are fields');
        $this->assertSame('name', $fields[0]['name']);
        $this->assertSame('input', $fields[0]['type']);
        $this->assertSame('Your name', $fields[0]['label']);
        $this->assertTrue($fields[0]['required']);
        $this->assertSame('email', $fields[1]['name'], 'Fields nested inside layout blocks are collected');
        $this->assertFalse($fields[1]['required']);
    }

    public function test_get_form_rejects_a_post_of_another_type(): void
    {
        $other = self::factory()->post->create([ 'post_type' => 'post' ]);

        $out = $this->integration->handle_read([
            'operation' => 'get-form',
            'args'      => [ 'form_id' => $other ],
        ]);

        $this->assertNull($out['result']['form']);
    }

    public function test_list_entries_is_scoped_to_the_form_and_decodes_values(): void
    {
        $out = $this->integration->handle_read([
            'operation' => 'list-entries',
            'args'      => [ 'form_id' => $this->form_id ],
        ]);

        $this->assertSame(2, $out['result']['total']);
        $this->assertSame(201, $out['result']['entries'][0]['id']);
        $this->assertSame('unread', $out['result']['entries'][0]['status']);
        $this->assertSame('ada@example.test', $out['result']['entries'][0]['values']['email']);

        $paged = $this->integration->handle_read([
            'operation' => 'list-entries',
            'args'      => [ 'form_id' => $this->form_id, 'page_size' => 1, 'offset' => 1 ],
        ]);
        $this->assertCount(1, $paged['result']['entries']);
        $this->assertSame(202, $paged['result']['entries'][0]['id']);
    }

    public function test_get_entry_and_missing_entry(): void
    {
        $out = $this->integration->handle_read([
            'operation' => 'get-entry',
            'args'      => [ 'entry_id' => 201 ],
        ]);
        $this->assertSame($this->form_id, $out['result']['entry']['form_id']);
        $this->assertSame('Ada', $out['result']['entry']['values']['name']);

        $missing = $this->integration->handle_read([
            'operation' => 'get-entry',
            'args'      => [ 'entry_id' => 9999 ],
        ]);
        $this->assertNull($missing['result']['entry']);
    }

    public function test_delete_entry_is_refused_without_confirm(): void
    {
        $out = $this->integration->handle_write([
            'operation' => 'delete-entry',
            'args'      => [ 'entry_id' => 201 ],
        ]);

        $this->assertSame('confirmation_required', $out['error']['code']);
        $this->assertSame([], Entries::$deleted);
    }

    public function test_delete_entry_with_confirm_deletes_and_reports_unrecoverable(): void
    {
        $out = $this->integration->handle_write([
            'operation' => 'delete-entry',
            'args'      => [ 'entry_id' => 201 ],
            'confirm'   => true,
        ]);

        $this->assertTrue($out['result']['deleted']);
        $this->assertFalse($out['recoverable']);
        $this->assertArrayNotHasKey('operation_id', $out);
        $this->assertSame([ 201 ], Entries::$deleted);
    }

    public function test_delete_entry_reports_not_found_without_calling_delete(): void
    {
        $out = $this->integration->handle_write([
            'operation' => 'delete-entry',
            'args'      => [ 'entry_id' => 9999 ],
            'confirm'   => true,
        ]);

        $this->assertFalse($out['result']['deleted']);
        $this->assertSame('not_found', $out['result']['reason']);
        $this->assertSame([], Entries::$deleted);
    }

    public function test_delete_entry_requires_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));

        $out = $this->integration->handle_write([
            'operation' => 'delete-entry',
            'args'      => [ 'entry_id' => 201 ],
            'confirm'   => true,
        ]);

        $this->assertSame('operation_denied', $out['error']['code']);
        $this->assertSame([], Entries::$deleted);
    }

    public function test_catalog_lists_every_operation(): void
    {
        $catalog = $this->integration->catalog();
        $ops     = array_column($catalog['operations'], null, 'name');

        $this->assertSame('sureforms', $catalog['integration']);
        $this->assertSame(
            [ 'list-forms', 'get-form', 'list-entries', 'get-entry', 'delete-entry' ],
            array_keys($ops)
        );
        $this->assertTrue($ops['delete-entry']['requires_confirm']);
        $this->assertSame('manage_options', $ops['delete-entry']['capability']);
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
        return [ 'entry_id' => 201 ];
    }
}
