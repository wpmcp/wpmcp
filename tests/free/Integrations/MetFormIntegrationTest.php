<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\MetForm_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

/**
 * The MetForm dispatcher pair, exercised against real metform-form and
 * metform-entry CPTs carrying MetForm's actual postmeta (_elementor_data,
 * metform_entries__form_id, metform_entries__form_data), so the layout parsing,
 * the entry queries, and above all the snapshot-backed delete are genuinely
 * verified rather than mocked. Live MetForm stays production-verified.
 */
class MetFormIntegrationTest extends \WP_UnitTestCase
{
    private MetForm_Integration $integration;
    private int $form_id;
    private int $entry_id;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        register_post_type('metform-form', [ 'public' => true, 'label' => 'Forms' ]);
        register_post_type('metform-entry', [ 'public' => true, 'label' => 'Entries' ]);
        $this->integration = new MetForm_Integration();

        // Issue #66: delete-entry is default-off for every forms adapter, so
        // the deletion tests below opt in the way a site would. The
        // off-by-default contract itself is asserted in
        // test_delete_entry_is_off_by_default and in the shared conformance
        // suite; WP_UnitTestCase restores hooks between tests.
        add_filter('wpmcp_integration_op_enabled', static fn ($enabled, $integration, $op) => ('metform' === $integration && 'delete-entry' === $op) ? true : $enabled, 10, 3);

        $this->form_id = self::factory()->post->create([
            'post_type'  => 'metform-form',
            'post_title' => 'Signup',
        ]);
        update_post_meta($this->form_id, '_elementor_data', wp_json_encode([
            [
                'elType'   => 'section',
                'elements' => [
                    [
                        'elType'   => 'column',
                        'elements' => [
                            [
                                'widgetType' => 'mf-email',
                                'settings'   => [
                                    'mf_input_name'     => 'email',
                                    'mf_input_label'    => 'Email',
                                    'mf_input_required' => 'yes',
                                ],
                            ],
                            [
                                'widgetType' => 'mf-text',
                                'settings'   => [
                                    'mf_input_name'  => 'name',
                                    'mf_input_label' => 'Name',
                                ],
                            ],
                            [
                                'widgetType' => 'heading',
                                'settings'   => [ 'title' => 'Not a field' ],
                            ],
                        ],
                    ],
                ],
            ],
        ]));

        $this->entry_id = $this->make_entry([ 'email' => 'ada@example.test', 'name' => 'Ada' ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        unregister_post_type('metform-entry');
        unregister_post_type('metform-form');
        parent::tearDown();
    }

    private function make_entry(array $values, ?int $form_id = null): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'metform-entry', 'post_title' => 'Entry' ]);
        update_post_meta($id, 'metform_entries__form_id', $form_id ?? $this->form_id);
        update_post_meta($id, 'metform_entries__form_data', $values);
        return $id;
    }

    public function test_available_when_the_form_cpt_is_registered(): void
    {
        $this->assertTrue($this->integration->is_available());
    }

    public function test_unavailable_without_metform(): void
    {
        unregister_post_type('metform-form');

        $this->assertFalse($this->integration->is_available());
        $out = $this->integration->handle_read([ 'operation' => 'list-forms' ]);
        $this->assertSame('integration_unavailable', $out['error']['code']);

        register_post_type('metform-form', [ 'public' => true, 'label' => 'Forms' ]);
    }

    public function test_list_operations_still_answers_when_unavailable(): void
    {
        unregister_post_type('metform-form');

        $out = $this->integration->handle_read([ 'operation' => 'list-operations' ]);
        $this->assertFalse($out['result']['available']);
        $this->assertNotEmpty($out['result']['operations']);

        register_post_type('metform-form', [ 'public' => true, 'label' => 'Forms' ]);
    }

    public function test_list_forms_counts_entries_and_emits_shortcode(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'list-forms' ]);

        $form = $out['result']['forms'][0];
        $this->assertSame('Signup', $form['title']);
        $this->assertSame(1, $form['entry_count']);
        $this->assertSame(sprintf('[metform form_id="%d"]', $this->form_id), $form['shortcode']);
    }

    public function test_list_forms_prefers_metforms_cached_entry_total(): void
    {
        update_post_meta($this->form_id, 'metform_form__form_total_entries', 17);

        $out = $this->integration->handle_read([ 'operation' => 'list-forms' ]);

        $this->assertSame(17, $out['result']['forms'][0]['entry_count']);
    }

    public function test_get_form_parses_nested_elementor_field_widgets(): void
    {
        $out = $this->integration->handle_read([
            'operation' => 'get-form',
            'args'      => [ 'form_id' => $this->form_id ],
        ]);

        $fields = $out['result']['form']['fields'];
        $this->assertCount(2, $fields, 'Only widgets carrying mf_input_name are fields');
        $this->assertSame('email', $fields[0]['name']);
        $this->assertSame('Email', $fields[0]['label']);
        $this->assertSame('email', $fields[0]['type']);
        $this->assertTrue($fields[0]['required']);
        $this->assertSame('name', $fields[1]['name']);
        $this->assertFalse($fields[1]['required']);
    }

    public function test_get_form_rejects_a_post_of_another_type(): void
    {
        $out = $this->integration->handle_read([
            'operation' => 'get-form',
            'args'      => [ 'form_id' => self::factory()->post->create() ],
        ]);

        $this->assertNull($out['result']['form']);
    }

    public function test_list_entries_is_scoped_to_the_form_and_pages(): void
    {
        $second = $this->make_entry([ 'email' => 'grace@example.test' ]);
        $this->make_entry([ 'email' => 'other@example.test' ], $this->form_id + 1000);

        $out = $this->integration->handle_read([
            'operation' => 'list-entries',
            'args'      => [ 'form_id' => $this->form_id ],
        ]);

        $this->assertSame(2, $out['result']['total']);
        $this->assertSame($second, $out['result']['entries'][0]['id'], 'Newest first');
        $this->assertSame('grace@example.test', $out['result']['entries'][0]['values']['email']);

        $paged = $this->integration->handle_read([
            'operation' => 'list-entries',
            'args'      => [ 'form_id' => $this->form_id, 'page_size' => 1, 'offset' => 1 ],
        ]);
        $this->assertCount(1, $paged['result']['entries']);
        $this->assertSame($this->entry_id, $paged['result']['entries'][0]['id']);
    }

    public function test_get_entry_and_missing_entry(): void
    {
        $out = $this->integration->handle_read([
            'operation' => 'get-entry',
            'args'      => [ 'entry_id' => $this->entry_id ],
        ]);
        $this->assertSame($this->form_id, $out['result']['entry']['form_id']);
        $this->assertSame('Ada', $out['result']['entry']['values']['name']);

        $missing = $this->integration->handle_read([
            'operation' => 'get-entry',
            'args'      => [ 'entry_id' => self::factory()->post->create() ],
        ]);
        $this->assertNull($missing['result']['entry']);
    }

    public function test_delete_entry_is_refused_without_confirm(): void
    {
        $out = $this->integration->handle_write([
            'operation' => 'delete-entry',
            'args'      => [ 'entry_id' => $this->entry_id ],
        ]);

        $this->assertSame('confirmation_required', $out['error']['code']);
        $this->assertNotNull(get_post($this->entry_id));
    }

    public function test_delete_entry_requires_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));

        $out = $this->integration->handle_write([
            'operation' => 'delete-entry',
            'args'      => [ 'entry_id' => $this->entry_id ],
            'confirm'   => true,
        ]);

        $this->assertSame('operation_denied', $out['error']['code']);
        $this->assertNotNull(get_post($this->entry_id));
    }

    public function test_delete_entry_snapshots_before_deleting(): void
    {
        $out = $this->integration->handle_write([
            'operation'  => 'delete-entry',
            'args'       => [ 'entry_id' => $this->entry_id ],
            'confirm'    => true,
            'session_id' => 's-136',
        ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['result']['deleted']);
        $this->assertTrue($out['recoverable']);
        $this->assertNotEmpty($out['operation_id']);
        $this->assertNull(get_post($this->entry_id));

        $row = Snapshot_Store::get_by_operation($out['operation_id']);
        $this->assertNotNull($row);
        $this->assertSame('metform-write', $row['tool_name']);
        $this->assertSame('metform-entry', $row['snapshot']['data']['post']['post_type']);
    }

    public function test_deleted_entry_is_resurrected_by_rollback_operation(): void
    {
        $out = $this->integration->handle_write([
            'operation' => 'delete-entry',
            'args'      => [ 'entry_id' => $this->entry_id ],
            'confirm'   => true,
        ]);
        $this->assertNull(get_post($this->entry_id));

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);

        $restored = get_post($this->entry_id);
        $this->assertNotNull($restored);
        $this->assertSame('metform-entry', $restored->post_type);
        $this->assertSame(
            [ 'email' => 'ada@example.test', 'name' => 'Ada' ],
            get_post_meta($this->entry_id, 'metform_entries__form_data', true)
        );
        $this->assertSame($this->form_id, (int) get_post_meta($this->entry_id, 'metform_entries__form_id', true));
    }

    public function test_delete_entry_rejects_a_post_of_another_type(): void
    {
        $other = self::factory()->post->create();

        $out = $this->integration->handle_write([
            'operation' => 'delete-entry',
            'args'      => [ 'entry_id' => $other ],
            'confirm'   => true,
        ]);

        $this->assertFalse($out['result']['deleted']);
        $this->assertSame('not_found', $out['result']['reason']);
        $this->assertNotNull(get_post($other));
    }

    public function test_catalog_marks_delete_entry_destructive(): void
    {
        $catalog = $this->integration->catalog();
        $ops     = array_column($catalog['operations'], null, 'name');

        $this->assertSame('metform', $catalog['integration']);
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
        return [ 'entry_id' => $this->entry_id ];
    }
}
