<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Builders\Elementor_Cache;
use WPMCP\Tools\Elementor\Create_Global_Variable;
use WPMCP\Tools\Elementor\Delete_Global_Variable;
use WPMCP\Tools\Elementor\Global_Variable_Schema;
use WPMCP\Tools\Elementor\Global_Variable_Usage;
use WPMCP\Tools\Elementor\Global_Variables_Store;
use WPMCP\Tools\Elementor\List_Global_Variables;
use WPMCP\Tools\Elementor\Update_Global_Variable;

/**
 * Elementor v4 global variables (design tokens: colors, fonts, sizes).
 *
 * Runs against Elementor's real Variables_Service, so the label rules, the
 * soft delete and the watermark bump are Elementor's own. What this suite
 * pins is the wpmcp contract on top: the optimistic lock, strict type and
 * value validation, the usage report before a delete, a byte-exact rollback
 * of the kit's variables meta, and the generated-CSS cache being cleared.
 */
class GlobalVariablesWriteTest extends Structural_Harness
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Global_Variables_Store::is_supported()) {
            $this->markTestSkipped('Elementor v4 global variables are not available');
        }
    }

    protected function tearDown(): void
    {
        Elementor_Cache::set_available_for_tests(null);
        parent::tearDown();
    }

    // ---- helpers ------------------------------------------------------------

    private function kit_id(): int
    {
        return (int) \Elementor\Plugin::instance()->kits_manager->get_active_id();
    }

    private function state_hash(): string
    {
        $list = (new List_Global_Variables())->handle([]);
        $this->assertIsArray($list);

        return $list['state_hash'];
    }

    /** @return array<string,array> active variables keyed by id. */
    private function stored(): array
    {
        $list = (new List_Global_Variables())->handle([]);
        $this->assertIsArray($list);

        return array_column($list['variables'], null, 'id');
    }

    private function raw_meta()
    {
        clean_post_cache($this->kit_id());

        return get_post_meta($this->kit_id(), Global_Variables_Store::META_KEY, true);
    }

    private function create(string $label, string $type = 'color', string $value = '#112233'): string
    {
        $out = (new Create_Global_Variable())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => $label,
            'type'          => $type,
            'value'         => $value,
        ]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');

        return $out['id'];
    }

    private function page_using(string $variable_id): array
    {
        return [[
            'id'       => 'cont001',
            'elType'   => 'container',
            'settings' => [],
            'elements' => [[
                'id'         => 'atomic0',
                'elType'     => 'widget',
                'widgetType' => 'e-heading',
                'settings'   => [],
                'styles'     => [
                    's-1' => [
                        'variants' => [[
                            'meta'  => ['breakpoint' => 'desktop', 'state' => null],
                            'props' => ['color' => ['$$type' => 'global-color-variable', 'value' => $variable_id]],
                        ]],
                    ],
                ],
                'elements'   => [],
            ]],
            'isInner'  => false,
        ]];
    }

    // ---- list ---------------------------------------------------------------

    public function test_list_is_empty_with_a_state_hash_on_a_fresh_kit(): void
    {
        delete_post_meta($this->kit_id(), Global_Variables_Store::META_KEY);

        $out = (new List_Global_Variables())->handle([]);

        $this->assertIsArray($out);
        $this->assertSame([], $out['variables']);
        $this->assertSame($this->kit_id(), $out['kit_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $out['state_hash']);
    }

    public function test_list_returns_variables_in_order_with_friendly_types(): void
    {
        $a = $this->create('brand-primary', 'color', '#ff0000');
        $b = $this->create('body-font', 'font', 'Inter');

        $out = (new List_Global_Variables())->handle([]);

        $this->assertSame([$a, $b], array_column($out['variables'], 'id'));
        $this->assertSame('color', $out['variables'][0]['type']);
        $this->assertSame('global-color-variable', $out['variables'][0]['elementor_type']);
        $this->assertSame('#ff0000', $out['variables'][0]['value']);
        $this->assertSame('font', $out['variables'][1]['type']);
        $this->assertSame('Inter', $out['variables'][1]['value']);
    }

    // ---- create -------------------------------------------------------------

    public function test_create_stores_a_color_variable_through_elementor(): void
    {
        $out = (new Create_Global_Variable())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => 'brand-primary',
            'type'          => 'color',
            'value'         => '#1A2B3C',
        ]);

        $this->assertIsArray($out);
        $this->assertMatchesRegularExpression('/^e-gv-[0-9a-f]+$/', $out['id']);
        $this->assertSame('brand-primary', $out['label']);
        $this->assertArrayHasKey('operation_id', $out);
        $this->assertArrayHasKey('state_hash', $out);

        // Elementor's own storage shape: a v2 record with typed values.
        $record = json_decode((string) $this->raw_meta(), true);
        $this->assertSame('global-color-variable', $record['data'][$out['id']]['type']);
        $this->assertSame(['$$type' => 'color', 'value' => '#1A2B3C'], $record['data'][$out['id']]['value']);

        $stored = $this->stored()[$out['id']];
        $this->assertSame('#1A2B3C', $stored['value']);
    }

    public function test_create_accepts_a_font_variable(): void
    {
        $id = $this->create('heading-font', 'font', 'Playfair Display');

        $this->assertSame('Playfair Display', $this->stored()[$id]['value']);
        $this->assertSame('font', $this->stored()[$id]['type']);
    }

    public function test_create_accepts_rgba_and_hsl_colors(): void
    {
        $a = $this->create('overlay', 'color', 'rgba(0, 0, 0, 0.5)');
        $b = $this->create('accent-hsl', 'color', 'hsl(210, 50%, 40%)');

        $this->assertSame('rgba(0, 0, 0, 0.5)', $this->stored()[$a]['value']);
        $this->assertSame('hsl(210, 50%, 40%)', $this->stored()[$b]['value']);
    }

    public function test_create_refuses_an_unknown_type_instead_of_coercing_it(): void
    {
        $out = (new Create_Global_Variable())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => 'shadow-lg',
            'type'          => 'shadow',
            'value'         => '0 0 4px #000',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_type', $out->get_error_code());
        $this->assertSame([], $this->stored());
    }

    public function test_create_requires_a_type(): void
    {
        $out = (new Create_Global_Variable())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => 'no-type',
            'value'         => '#000000',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_type', $out->get_error_code());
    }

    public function test_create_refuses_a_value_that_is_not_a_color(): void
    {
        $out = (new Create_Global_Variable())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => 'broken',
            'type'          => 'color',
            'value'         => 'not a color',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_value', $out->get_error_code());
    }

    public function test_create_refuses_an_empty_or_unsafe_font_value(): void
    {
        foreach (['', '   ', 'Inter; } body { color: red'] as $value) {
            $out = (new Create_Global_Variable())->handle([
                'expected_hash' => $this->state_hash(),
                'label'         => 'font-x',
                'type'          => 'font',
                'value'         => $value,
            ]);

            $this->assertInstanceOf(\WP_Error::class, $out, 'Font value: ' . $value);
            $this->assertSame('invalid_value', $out->get_error_code());
        }
    }

    public function test_create_refuses_a_label_elementor_cannot_use_as_a_css_variable(): void
    {
        foreach (['has space', str_repeat('a', 51), '', 'semi;colon'] as $label) {
            $out = (new Create_Global_Variable())->handle([
                'expected_hash' => $this->state_hash(),
                'label'         => $label,
                'type'          => 'color',
                'value'         => '#000000',
            ]);

            $this->assertInstanceOf(\WP_Error::class, $out, 'Label: ' . $label);
            $this->assertSame('invalid_label', $out->get_error_code());
        }
    }

    public function test_create_refuses_a_duplicate_label_case_insensitively(): void
    {
        $this->create('Brand');

        $out = (new Create_Global_Variable())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => 'brand',
            'type'          => 'color',
            'value'         => '#000000',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('duplicate_label', $out->get_error_code());
    }

    public function test_size_variables_follow_elementor_pro_availability(): void
    {
        $out = (new Create_Global_Variable())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => 'space-md',
            'type'          => 'size',
            'value'         => '16px',
        ]);

        if (! Global_Variables_Store::sizes_supported()) {
            // Elementor hides size variables from the editor without Pro, so
            // creating one would store a token nobody can see or use.
            $this->assertInstanceOf(\WP_Error::class, $out);
            $this->assertSame('requires_elementor_pro', $out->get_error_code());
            return;
        }

        $this->assertIsArray($out);
        $this->assertSame('16px', $this->stored()[$out['id']]['value']);
    }

    public function test_size_values_are_validated_against_elementor_units(): void
    {
        // Values are checked before the Pro gate, so this holds either way.
        $out = (new Create_Global_Variable())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => 'space-bad',
            'type'          => 'size',
            'value'         => '16parsecs',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_value', $out->get_error_code());
    }

    public function test_size_values_are_canonicalized_the_way_elementor_reads_them_back(): void
    {
        $this->assertSame('1.5rem', Global_Variable_Schema::value('size', ' 1.50REM '));
        $this->assertSame('-4px', Global_Variable_Schema::value('size', '-4px'));
        $this->assertSame('auto', Global_Variable_Schema::value('size', 'AUTO'));
        $this->assertSame('clamp(1rem, 2vw, 2rem)', Global_Variable_Schema::value('custom-size', 'clamp(1rem, 2vw, 2rem)'));
        $this->assertInstanceOf(\WP_Error::class, Global_Variable_Schema::value('size', 'clamp(1rem, 2vw, 2rem)'));
        $this->assertInstanceOf(\WP_Error::class, Global_Variable_Schema::value('custom-size', 'x; color: red'));
        $this->assertSame('size', Global_Variable_Schema::type('global-size-variable'));
        $this->assertSame('custom-size', Global_Variable_Schema::type('global-custom-size-variable'));
    }

    public function test_create_refuses_a_stale_expected_hash(): void
    {
        $stale = $this->state_hash();
        $this->create('first');

        $out = (new Create_Global_Variable())->handle([
            'expected_hash' => $stale,
            'label'         => 'second',
            'type'          => 'color',
            'value'         => '#000000',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('stale_expected_hash', $out->get_error_code());
        $this->assertCount(1, $this->stored());
    }

    public function test_create_requires_an_expected_hash(): void
    {
        $out = (new Create_Global_Variable())->handle([
            'label' => 'x',
            'type'  => 'color',
            'value' => '#000000',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_expected_hash', $out->get_error_code());
    }

    public function test_write_tools_refuse_a_user_without_manage_options(): void
    {
        $hash = $this->state_hash();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $out = (new Create_Global_Variable())->handle([
            'expected_hash' => $hash,
            'label'         => 'x',
            'type'          => 'color',
            'value'         => '#000000',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('forbidden', $out->get_error_code());
    }

    // ---- update -------------------------------------------------------------

    public function test_update_changes_label_and_value(): void
    {
        $id = $this->create('brand', 'color', '#111111');

        $out = (new Update_Global_Variable())->handle([
            'expected_hash' => $this->state_hash(),
            'id'            => $id,
            'label'         => 'brand-main',
            'value'         => '#222222',
        ]);

        $this->assertIsArray($out);
        $stored = $this->stored()[$id];
        $this->assertSame('brand-main', $stored['label']);
        $this->assertSame('#222222', $stored['value']);
        $this->assertSame('color', $stored['type']);
    }

    public function test_update_validates_the_value_against_the_existing_type(): void
    {
        $id = $this->create('brand', 'color', '#111111');

        $out = (new Update_Global_Variable())->handle([
            'expected_hash' => $this->state_hash(),
            'id'            => $id,
            'value'         => 'Inter',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_value', $out->get_error_code());
        $this->assertSame('#111111', $this->stored()[$id]['value']);
    }

    public function test_update_refuses_a_type_change_elementor_forbids(): void
    {
        $id = $this->create('brand', 'color', '#111111');

        $out = (new Update_Global_Variable())->handle([
            'expected_hash' => $this->state_hash(),
            'id'            => $id,
            'type'          => 'font',
            'value'         => 'Inter',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('type_change_forbidden', $out->get_error_code());
    }

    public function test_update_rejects_an_unknown_variable(): void
    {
        $out = (new Update_Global_Variable())->handle([
            'expected_hash' => $this->state_hash(),
            'id'            => 'e-gv-missing',
            'value'         => '#000000',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('variable_not_found', $out->get_error_code());
    }

    public function test_update_requires_something_to_change(): void
    {
        $id = $this->create('brand');

        $out = (new Update_Global_Variable())->handle([
            'expected_hash' => $this->state_hash(),
            'id'            => $id,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('nothing_to_update', $out->get_error_code());
    }

    public function test_update_refuses_a_label_taken_by_another_variable(): void
    {
        $this->create('taken');
        $id = $this->create('mine');

        $out = (new Update_Global_Variable())->handle([
            'expected_hash' => $this->state_hash(),
            'id'            => $id,
            'label'         => 'TAKEN',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('duplicate_label', $out->get_error_code());
    }

    // ---- delete -------------------------------------------------------------

    public function test_delete_without_confirm_reports_usage_and_writes_nothing(): void
    {
        $id   = $this->create('used-color');
        $page = $this->make_page($this->page_using($id));
        $raw  = $this->raw_meta();

        $out = (new Delete_Global_Variable())->handle([
            'expected_hash' => $this->state_hash(),
            'id'            => $id,
        ]);

        $this->assertIsArray($out);
        $this->assertFalse($out['deleted']);
        $this->assertTrue($out['confirm_required']);
        $this->assertSame(1, $out['usage']['total']);
        $this->assertSame($page, $out['usage']['posts'][0]['post_id']);
        $this->assertSame($raw, $this->raw_meta(), 'The dry run must not write.');
    }

    public function test_delete_with_confirm_removes_the_variable(): void
    {
        $id = $this->create('doomed');

        $out = (new Delete_Global_Variable())->handle([
            'expected_hash' => $this->state_hash(),
            'id'            => $id,
            'confirm'       => true,
        ]);

        $this->assertIsArray($out);
        $this->assertTrue($out['deleted']);
        $this->assertArrayNotHasKey($id, $this->stored());
    }

    public function test_a_deleted_label_can_be_reused(): void
    {
        $id = $this->create('recycled');
        (new Delete_Global_Variable())->handle([
            'expected_hash' => $this->state_hash(),
            'id'            => $id,
            'confirm'       => true,
        ]);

        $again = $this->create('recycled');

        $this->assertNotSame($id, $again);
    }

    public function test_delete_rejects_an_unknown_or_already_deleted_variable(): void
    {
        $id = $this->create('gone');
        (new Delete_Global_Variable())->handle([
            'expected_hash' => $this->state_hash(),
            'id'            => $id,
            'confirm'       => true,
        ]);

        foreach (['e-gv-missing', $id] as $target) {
            $out = (new Delete_Global_Variable())->handle([
                'expected_hash' => $this->state_hash(),
                'id'            => $target,
                'confirm'       => true,
            ]);

            $this->assertInstanceOf(\WP_Error::class, $out);
            $this->assertSame('variable_not_found', $out->get_error_code());
        }
    }

    public function test_usage_scan_does_not_match_an_id_prefix(): void
    {
        $this->make_page($this->page_using('e-gv-1a2b3c4'));

        $this->assertSame(0, Global_Variable_Usage::scan('e-gv-1a2')['total']);
        $this->assertSame(1, Global_Variable_Usage::scan('e-gv-1a2b3c4')['total']);
    }

    // ---- rollback -----------------------------------------------------------

    public function test_rollback_of_a_first_create_removes_the_meta_entirely(): void
    {
        delete_post_meta($this->kit_id(), Global_Variables_Store::META_KEY);

        $out = (new Create_Global_Variable())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => 'temp',
            'type'          => 'color',
            'value'         => '#000000',
        ]);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        clean_post_cache($this->kit_id());
        $this->assertFalse(metadata_exists('post', $this->kit_id(), Global_Variables_Store::META_KEY));
        $this->assertSame([], $this->stored());
    }

    public function test_rollback_restores_the_variables_meta_byte_for_byte(): void
    {
        $keep = $this->create('keep', 'color', '#abcdef');
        $this->create('font-a', 'font', 'Inter');
        $before = $this->raw_meta();

        $update = (new Update_Global_Variable())->handle([
            'expected_hash' => $this->state_hash(),
            'id'            => $keep,
            'value'         => '#000000',
        ]);
        $this->assertNotSame($before, $this->raw_meta());
        Rollback_Service::restore_operation($update['operation_id']);
        $this->assertSame($before, $this->raw_meta(), 'Rollback of an update must restore the exact record, watermark included.');

        $delete = (new Delete_Global_Variable())->handle([
            'expected_hash' => $this->state_hash(),
            'id'            => $keep,
            'confirm'       => true,
        ]);
        $this->assertArrayNotHasKey($keep, $this->stored());
        Rollback_Service::restore_operation($delete['operation_id']);
        $this->assertSame($before, $this->raw_meta(), 'Rollback of a delete must restore the exact record.');
        $this->assertSame('#abcdef', $this->stored()[$keep]['value']);
    }

    public function test_the_write_is_recorded_as_a_global_variables_operation(): void
    {
        $out = (new Create_Global_Variable())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => 'audited',
            'type'          => 'color',
            'value'         => '#000000',
            'session_id'    => 'sess-vars',
        ]);

        $row = Snapshot_Store::get_by_operation($out['operation_id']);

        $this->assertNotNull($row);
        $this->assertSame(Global_Variables_Store::SNAPSHOT_TYPE, $row['object_type']);
        $this->assertSame('create-global-variable', $row['tool_name']);
        $this->assertSame('sess-vars', $row['session_id']);
        $this->assertSame($this->kit_id(), (int) $row['object_id']);
        $this->assertContains(Global_Variables_Store::SNAPSHOT_TYPE, Rollback_Service::restorable_object_types());
    }

    // ---- cache --------------------------------------------------------------

    public function test_every_write_and_rollback_clears_the_generated_css_cache(): void
    {
        $kit = $this->kit_id();

        update_post_meta($kit, '_elementor_css', ['status' => 'stale-probe']);
        $out = (new Create_Global_Variable())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => 'cached',
            'type'          => 'color',
            'value'         => '#000000',
        ]);
        $this->assertIsArray($out);
        $this->assertSame('', get_post_meta($kit, '_elementor_css', true), 'A write must clear the generated CSS.');

        update_post_meta($kit, '_elementor_css', ['status' => 'stale-probe']);
        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame('', get_post_meta($kit, '_elementor_css', true), 'A rollback must clear the generated CSS.');
    }

    /**
     * The write and the rollback both purge through Elementor_Cache, the one
     * place wpmcp invalidates Elementor's derived caches. With the helper
     * reporting Elementor unavailable, it is a no-op, so the probe survives
     * only when nothing clears the CSS on its own.
     */
    public function test_write_and_rollback_clear_the_css_through_the_shared_helper(): void
    {
        $kit = $this->kit_id();
        Elementor_Cache::set_available_for_tests(false);

        update_post_meta($kit, '_elementor_css', ['status' => 'stale-probe']);
        $out = (new Create_Global_Variable())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => 'via-helper',
            'type'          => 'color',
            'value'         => '#000000',
        ]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame(
            ['status' => 'stale-probe'],
            get_post_meta($kit, '_elementor_css', true),
            'The write must purge through Elementor_Cache::clear_all(), not inline.'
        );

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame(
            ['status' => 'stale-probe'],
            get_post_meta($kit, '_elementor_css', true),
            'The rollback must purge through Elementor_Cache::clear_all(), not inline.'
        );
    }
}
