<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Tests\Free\Platform\RegisteredAbilities;
use WPMCP\Tools\Builders\Get_Builder_Content;
use WPMCP\Tools\Builders\Update_Builder_Content;

/**
 * update-builder-content's design_system scope (issue #391, phase two):
 * add, update and remove global classes, variables and palette entries,
 * each call one undoable write of the whole option it changes, refused
 * when the option changed since it was read (expected_hash) and when the
 * entry is malformed.
 *
 * Storage follows each builder's own source: Bricks 2.3 keeps the lists as
 * serialized arrays in `bricks_global_classes`, `bricks_global_variables`
 * and `bricks_color_palette`; Breakdance keeps its selectors and global
 * settings as JSON strings that its option API encodes once more before
 * storing (set_global_option()), so the stored option is a JSON string
 * literal holding the JSON document.
 */
class BuilderDesignWriteTest extends \WP_UnitTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function write(array $args)
    {
        return (new Update_Builder_Content())->handle($args + ['scope' => 'design_system']);
    }

    /** @return array<string,string> the per-list hashes a design_system read returns */
    private function hashes(string $builder): array
    {
        $out = (new Get_Builder_Content())->handle(['builder' => $builder, 'scope' => 'design_system']);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertIsArray($out['hashes'] ?? null);

        return $out['hashes'];
    }

    private function assertWrote($out): void
    {
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_code() . ': ' . $out->get_error_message() : '');
        $this->assertNotEmpty($out['operation_id']);
    }

    /** @return array<int,array<string,mixed>> */
    private static function bricks_classes(): array
    {
        return [
            ['id' => 'kxqzcr', 'name' => 'btn-primary', 'settings' => ['_padding' => ['top' => '8']], 'modified' => 1717000000, 'user_id' => 1],
            ['id' => 'abcdef', 'name' => 'card', 'settings' => []],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function bricks_palettes(): array
    {
        return [
            ['id' => 'p1', 'name' => 'Brand', 'colors' => [
                ['id' => 'c01', 'raw' => 'var(--primary)', 'light' => '#1e40af', 'dark' => '#93c5fd'],
                ['id' => 'c02', 'light' => '#ffffff'],
            ]],
        ];
    }

    // ------------------------------------------------------------ Bricks

    public function test_bricks_add_class_appends_one_entry_and_rolls_back(): void
    {
        update_option('bricks_global_classes', self::bricks_classes());

        $out = $this->write([
            'builder'       => 'bricks',
            'operation'     => 'add',
            'to'            => 'classes',
            'element'       => ['name' => 'hero-title', 'settings' => ['_typography' => ['font-size' => '3rem']]],
            'expected_hash' => $this->hashes('bricks')['classes'],
        ]);

        $this->assertWrote($out);
        $stored = get_option('bricks_global_classes');
        $this->assertCount(3, $stored);
        $this->assertSame(self::bricks_classes()[0], $stored[0]);
        $added = $stored[2];
        $this->assertSame('hero-title', $added['name']);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{6}$/', $added['id']);
        $this->assertSame('classes/' . $added['id'], $out['path']);
        $this->assertSame($this->hashes('bricks')['classes'], $out['hash']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame(self::bricks_classes(), get_option('bricks_global_classes'));
    }

    public function test_bricks_first_write_on_a_fresh_site_rolls_back_to_no_option(): void
    {
        $out = $this->write([
            'builder'       => 'bricks',
            'operation'     => 'add',
            'to'            => 'variables',
            'element'       => ['id' => 'v1a2b3', 'name' => 'space-m', 'value' => 'clamp(1rem, 2vw, 2rem)'],
            'expected_hash' => $this->hashes('bricks')['variables'],
        ]);

        $this->assertWrote($out);
        $this->assertSame([['id' => 'v1a2b3', 'name' => 'space-m', 'value' => 'clamp(1rem, 2vw, 2rem)']], get_option('bricks_global_variables'));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertFalse(get_option('bricks_global_variables'));
    }

    public function test_bricks_update_merges_attrs_and_null_removes_a_key(): void
    {
        update_option('bricks_global_variables', [
            ['id' => 'v1', 'name' => 'space-m', 'value' => '1rem', 'category' => 'c1'],
            ['id' => 'v2', 'name' => 'space-l', 'value' => '2rem'],
        ]);

        $out = $this->write([
            'builder'       => 'bricks',
            'operation'     => 'update',
            'path'          => 'variables/v1',
            'attrs'         => ['value' => '1.25rem', 'category' => null],
            'expected_hash' => $this->hashes('bricks')['variables'],
        ]);

        $this->assertWrote($out);
        $this->assertSame([
            ['id' => 'v1', 'name' => 'space-m', 'value' => '1.25rem'],
            ['id' => 'v2', 'name' => 'space-l', 'value' => '2rem'],
        ], get_option('bricks_global_variables'));
    }

    public function test_bricks_palette_colors_are_addressed_inside_their_palette(): void
    {
        update_option('bricks_color_palette', self::bricks_palettes());
        $hash = $this->hashes('bricks')['palettes'];

        $add = $this->write([
            'builder'       => 'bricks',
            'operation'     => 'add',
            'to'            => 'palettes/p1',
            'element'       => ['id' => 'c03', 'raw' => 'var(--accent)', 'light' => '#f97316'],
            'expected_hash' => $hash,
        ]);
        $this->assertWrote($add);
        $this->assertSame('palettes/p1/c03', $add['path']);

        $remove = $this->write([
            'builder'       => 'bricks',
            'operation'     => 'remove',
            'path'          => 'palettes/p1/c02',
            'expected_hash' => $add['hash'],
        ]);
        $this->assertWrote($remove);

        $colors = get_option('bricks_color_palette')[0]['colors'];
        $this->assertSame(['c01', 'c03'], array_column($colors, 'id'));

        Rollback_Service::restore_operation($remove['operation_id']);
        $this->assertSame(['c01', 'c02', 'c03'], array_column(get_option('bricks_color_palette')[0]['colors'], 'id'));
        Rollback_Service::restore_operation($add['operation_id']);
        $this->assertSame(self::bricks_palettes(), get_option('bricks_color_palette'));
    }

    public function test_bricks_remove_a_whole_palette(): void
    {
        update_option('bricks_color_palette', self::bricks_palettes());

        $out = $this->write([
            'builder'       => 'bricks',
            'operation'     => 'remove',
            'path'          => 'palettes/p1',
            'expected_hash' => $this->hashes('bricks')['palettes'],
        ]);

        $this->assertWrote($out);
        $this->assertSame([], get_option('bricks_color_palette'));
    }

    public function test_a_stale_hash_is_refused_and_nothing_is_written(): void
    {
        update_option('bricks_global_classes', self::bricks_classes());
        $hash = $this->hashes('bricks')['classes'];

        // Someone else saves in the builder after the read.
        $changed = self::bricks_classes();
        $changed[1]['settings'] = ['_margin' => ['top' => '4']];
        update_option('bricks_global_classes', $changed);

        $out = $this->write([
            'builder'       => 'bricks',
            'operation'     => 'remove',
            'path'          => 'classes/kxqzcr',
            'expected_hash' => $hash,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('stale_expected_hash', $out->get_error_code());
        $this->assertSame($changed, get_option('bricks_global_classes'));
    }

    public function test_a_missing_hash_is_refused(): void
    {
        $out = $this->write([
            'builder'   => 'bricks',
            'operation' => 'add',
            'to'        => 'classes',
            'element'   => ['name' => 'x'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_expected_hash', $out->get_error_code());
        $this->assertFalse(get_option('bricks_global_classes'));
    }

    public function test_the_hash_of_one_list_does_not_guard_another(): void
    {
        $out = $this->write([
            'builder'       => 'bricks',
            'operation'     => 'add',
            'to'            => 'classes',
            'element'       => ['name' => 'x'],
            'expected_hash' => 'not-the-classes-hash',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('stale_expected_hash', $out->get_error_code());
    }

    /** @return array<string,array{0:string,1:string,2:array<string,mixed>}> */
    public function malformed_bricks_entries(): array
    {
        return [
            'class without a name'         => ['classes', 'classes', ['settings' => []]],
            'class name with a space'      => ['classes', 'classes', ['name' => 'two words']],
            'class name already taken'     => ['classes', 'classes', ['name' => 'card']],
            'class id already taken'       => ['classes', 'classes', ['id' => 'abcdef', 'name' => 'other']],
            'class settings not an object' => ['classes', 'classes', ['name' => 'ok', 'settings' => 'color:red']],
            'variable value not a string'  => ['variables', 'variables', ['name' => 'gap', 'value' => ['a']]],
            'variable without a value'     => ['variables', 'variables', ['name' => 'gap']],
            'palette without a name'       => ['palettes', 'palettes', ['colors' => []]],
            'palette colors not a list'    => ['palettes', 'palettes', ['name' => 'P', 'colors' => 'red']],
            'color without any value'      => ['palettes', 'palettes/p1', ['id' => 'c9']],
            'color value not a string'     => ['palettes', 'palettes/p1', ['light' => 12]],
            'a list, not an entry'         => ['classes', 'classes', [['name' => 'a']]],
        ];
    }

    /** @dataProvider malformed_bricks_entries */
    public function test_malformed_bricks_entries_are_refused(string $list, string $to, array $element): void
    {
        update_option('bricks_global_classes', self::bricks_classes());
        update_option('bricks_color_palette', self::bricks_palettes());
        $before = [get_option('bricks_global_classes'), get_option('bricks_global_variables'), get_option('bricks_color_palette')];

        $out = $this->write([
            'builder'       => 'bricks',
            'operation'     => 'add',
            'to'            => $to,
            'element'       => $element,
            'expected_hash' => $this->hashes('bricks')[$list],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_design_entry', $out->get_error_code());
        $this->assertSame($before, [get_option('bricks_global_classes'), get_option('bricks_global_variables'), get_option('bricks_color_palette')]);
    }

    public function test_an_update_that_breaks_an_entry_is_refused(): void
    {
        update_option('bricks_global_classes', self::bricks_classes());

        $out = $this->write([
            'builder'       => 'bricks',
            'operation'     => 'update',
            'path'          => 'classes/abcdef',
            'attrs'         => ['name' => null],
            'expected_hash' => $this->hashes('bricks')['classes'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_design_entry', $out->get_error_code());
        $this->assertSame(self::bricks_classes(), get_option('bricks_global_classes'));
    }

    public function test_an_unknown_entry_or_list_is_refused(): void
    {
        update_option('bricks_global_classes', self::bricks_classes());
        $hash = $this->hashes('bricks')['classes'];

        $missing = $this->write(['builder' => 'bricks', 'operation' => 'remove', 'path' => 'classes/nope00', 'expected_hash' => $hash]);
        $this->assertInstanceOf(\WP_Error::class, $missing);
        $this->assertSame('design_entry_not_found', $missing->get_error_code());

        $list = $this->write(['builder' => 'bricks', 'operation' => 'remove', 'path' => 'fonts/x', 'expected_hash' => $hash]);
        $this->assertInstanceOf(\WP_Error::class, $list);
        $this->assertSame('invalid_design_path', $list->get_error_code());

        $op = $this->write(['builder' => 'bricks', 'operation' => 'move', 'path' => 'classes/abcdef', 'expected_hash' => $hash]);
        $this->assertInstanceOf(\WP_Error::class, $op);
        $this->assertSame('invalid_design_request', $op->get_error_code());
    }

    public function test_design_writes_need_edit_theme_options_and_so_does_their_undo(): void
    {
        update_option('bricks_global_classes', self::bricks_classes());
        $hash = $this->hashes('bricks')['classes'];

        $editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor);
        $refused = $this->write(['builder' => 'bricks', 'operation' => 'remove', 'path' => 'classes/abcdef', 'expected_hash' => $hash]);
        $this->assertInstanceOf(\WP_Error::class, $refused);
        $this->assertSame('forbidden', $refused->get_error_code());

        wp_set_current_user($this->admin);
        $out = $this->write(['builder' => 'bricks', 'operation' => 'remove', 'path' => 'classes/abcdef', 'expected_hash' => $hash]);
        $this->assertWrote($out);

        wp_set_current_user($editor);
        $this->assertFalse(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertCount(1, get_option('bricks_global_classes'));
    }

    // ------------------------------------------------------- Breakdance

    /** Store a document the way Breakdance's set_global_option() does. */
    private static function breakdance_store(string $field, $document): void
    {
        update_option('breakdance_' . $field, wp_json_encode((string) wp_json_encode($document)), false);
    }

    /** Read a document back the way Breakdance's get_global_option() and its callers do. */
    private static function breakdance_load(string $field)
    {
        $outer = json_decode((string) get_option('breakdance_' . $field), true);

        return is_string($outer) ? json_decode($outer, true) : null;
    }

    /** @return array<string,mixed> */
    private static function breakdance_settings(): array
    {
        return ['settings' => [
            'colors'     => ['brand' => '#0055ff', 'palette' => ['colors' => [['cssVariableName' => 'palette-color-1', 'label' => 'Accent', 'value' => '#ff5500']], 'gradients' => [['cssVariableName' => 'g1', 'label' => 'Fade', 'value' => ['value' => 'linear-gradient(#fff,#000)']]]]],
            'typography' => ['global_typography' => ['typography_presets' => []]],
        ]];
    }

    public function test_the_reader_decodes_breakdances_stored_encoding(): void
    {
        $selectors = [['name' => 'card', 'type' => 'class', 'properties' => []]];
        self::breakdance_store('breakdance_classes_json_string', $selectors);
        self::breakdance_store('global_settings_json_string', self::breakdance_settings());

        $out = (new Get_Builder_Content())->handle(['builder' => 'breakdance', 'scope' => 'design_system']);

        $this->assertSame($selectors, $out['classes']);
        $this->assertSame([self::breakdance_settings()['settings']['colors']['palette']], $out['palettes']);
    }

    public function test_breakdance_class_writes_keep_breakdances_encoding_and_roll_back(): void
    {
        self::breakdance_store('breakdance_classes_json_string', [['name' => 'card', 'type' => 'class', 'properties' => []]]);
        $raw_before = get_option('breakdance_breakdance_classes_json_string');

        $out = $this->write([
            'builder'       => 'breakdance',
            'operation'     => 'add',
            'to'            => 'classes',
            'element'       => ['name' => 'hero', 'properties' => ['breakpoint_base' => ['typography' => ['color' => '#111']]]],
            'expected_hash' => $this->hashes('breakdance')['classes'],
        ]);

        $this->assertWrote($out);
        $this->assertSame('classes/hero', $out['path']);
        $this->assertSame([
            ['name' => 'card', 'type' => 'class', 'properties' => []],
            ['name' => 'hero', 'properties' => ['breakpoint_base' => ['typography' => ['color' => '#111']]], 'type' => 'class'],
        ], self::breakdance_load('breakdance_classes_json_string'));

        $update = $this->write([
            'builder'       => 'breakdance',
            'operation'     => 'update',
            'path'          => 'classes/card',
            'attrs'         => ['properties' => ['breakpoint_base' => ['background' => '#fff']]],
            'expected_hash' => $out['hash'],
        ]);
        $this->assertWrote($update);
        $this->assertSame(['breakpoint_base' => ['background' => '#fff']], self::breakdance_load('breakdance_classes_json_string')[0]['properties']);

        Rollback_Service::restore_operation($update['operation_id']);
        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($raw_before, get_option('breakdance_breakdance_classes_json_string'));
    }

    public function test_breakdance_palette_color_writes_touch_only_the_palette(): void
    {
        self::breakdance_store('global_settings_json_string', self::breakdance_settings());

        $add = $this->write([
            'builder'       => 'breakdance',
            'operation'     => 'add',
            'to'            => 'palettes',
            'element'       => ['cssVariableName' => 'palette-ink', 'label' => 'Ink', 'value' => '#111111'],
            'expected_hash' => $this->hashes('breakdance')['palettes'],
        ]);
        $this->assertWrote($add);
        $this->assertSame('palettes/palette-ink', $add['path']);

        $update = $this->write([
            'builder'       => 'breakdance',
            'operation'     => 'update',
            'path'          => 'palettes/palette-color-1',
            'attrs'         => ['value' => '#ee4400'],
            'expected_hash' => $add['hash'],
        ]);
        $this->assertWrote($update);

        $settings = self::breakdance_load('global_settings_json_string');
        $expected = self::breakdance_settings();
        $expected['settings']['colors']['palette']['colors'] = [
            ['cssVariableName' => 'palette-color-1', 'label' => 'Accent', 'value' => '#ee4400'],
            ['cssVariableName' => 'palette-ink', 'label' => 'Ink', 'value' => '#111111'],
        ];
        $this->assertSame($expected, $settings);

        $remove = $this->write([
            'builder'       => 'breakdance',
            'operation'     => 'remove',
            'path'          => 'palettes/palette-ink',
            'expected_hash' => $update['hash'],
        ]);
        $this->assertWrote($remove);
        $this->assertCount(1, self::breakdance_load('global_settings_json_string')['settings']['colors']['palette']['colors']);

        foreach ([$remove, $update, $add] as $step) {
            Rollback_Service::restore_operation($step['operation_id']);
        }
        $this->assertSame(self::breakdance_settings(), self::breakdance_load('global_settings_json_string'));
    }

    public function test_breakdance_palette_color_without_a_value_is_refused(): void
    {
        self::breakdance_store('global_settings_json_string', self::breakdance_settings());
        $before = get_option('breakdance_global_settings_json_string');

        $out = $this->write([
            'builder'       => 'breakdance',
            'operation'     => 'add',
            'to'            => 'palettes',
            'element'       => ['cssVariableName' => 'x', 'label' => 'X'],
            'expected_hash' => $this->hashes('breakdance')['palettes'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_design_entry', $out->get_error_code());
        $this->assertSame($before, get_option('breakdance_global_settings_json_string'));
    }

    public function test_breakdance_refuses_a_settings_option_it_cannot_decode(): void
    {
        update_option('breakdance_global_settings_json_string', '{not json');

        $out = $this->write([
            'builder'       => 'breakdance',
            'operation'     => 'add',
            'to'            => 'palettes',
            'element'       => ['cssVariableName' => 'x', 'label' => 'X', 'value' => '#000'],
            'expected_hash' => $this->hashes('breakdance')['palettes'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_design_storage', $out->get_error_code());
        $this->assertSame('{not json', get_option('breakdance_global_settings_json_string'));
    }

    public function test_breakdance_variables_and_oxygen_design_writes_are_not_supported(): void
    {
        $variables = $this->write(['builder' => 'breakdance', 'operation' => 'add', 'to' => 'variables', 'element' => ['label' => 'x'], 'expected_hash' => 'h']);
        $this->assertInstanceOf(\WP_Error::class, $variables);
        $this->assertSame('invalid_design_path', $variables->get_error_code());

        $oxygen = $this->write(['builder' => 'oxygen', 'operation' => 'add', 'to' => 'classes', 'element' => ['name' => 'x'], 'expected_hash' => 'h']);
        $this->assertInstanceOf(\WP_Error::class, $oxygen);
        $this->assertSame('unsupported_builder', $oxygen->get_error_code());
    }

    // ------------------------------------------------------------ surface

    public function test_without_scope_a_post_id_is_still_required(): void
    {
        $out = (new Update_Builder_Content())->handle(['builder' => 'bricks', 'content' => '[]']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_post_id', $out->get_error_code());
    }

    public function test_registered_schema_advertises_the_design_scope(): void
    {
        $ability = null;
        foreach (RegisteredAbilities::all() as $candidate) {
            if ('wpmcp/update-builder-content' === $candidate->name) {
                $ability = $candidate;
            }
        }

        $this->assertNotNull($ability);
        $props = $ability->input_schema['properties'];
        $this->assertArrayHasKey('scope', $props);
        $this->assertArrayHasKey('expected_hash', $props);
        $this->assertNotContains('post_id', $ability->input_schema['required'] ?? []);
        $this->assertStringContainsString('design_system', $ability->description);
    }
}
