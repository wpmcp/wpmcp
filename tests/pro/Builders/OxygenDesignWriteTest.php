<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Get_Builder_Content, Update_Builder_Content};

/**
 * Issue #805: Oxygen 6 design system writes through update-builder-content
 * scope design_system: selectors (classes), variables and palette colors.
 *
 * Storage follows the Oxygen 6.1 source: the engine's set_global_option()
 * stores each document JSON-encoded under the `oxygen_` prefix (see store()
 * for which layer each saver adds), selectors in
 * `oxygen_oxy_selectors_json_string` (saveSelectors()), variables in
 * `oxygen_variables_json_string` (saveVariables()) and the palette at
 * settings.colors.palette.colors in `oxygen_global_settings_json_string`.
 * Selectors and variables are keyed by id, since elements reference a
 * selector by id (meta.classes) and selectors a variable by {var-<id>}.
 *
 * Oxygen is not installed here: its presence is toggled through
 * wpmcp_oxygen_active, and every write is undone through rollback.
 */
class OxygenDesignWriteTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        add_filter('wpmcp_oxygen_active', '__return_true');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_oxygen_active');
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function write(array $args)
    {
        return (new Update_Builder_Content())->handle($args + ['scope' => 'design_system', 'builder' => 'oxygen']);
    }

    /** @return array<string,string> */
    private function hashes(): array
    {
        $out = (new Get_Builder_Content())->handle(['builder' => 'oxygen', 'scope' => 'design_system']);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertIsArray($out['hashes'] ?? null, 'the Oxygen design_system read returns per-list hashes');

        return $out['hashes'];
    }

    private function assertWrote($out): void
    {
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_code() . ': ' . $out->get_error_message() : '');
        $this->assertNotEmpty($out['operation_id']);
    }

    private function assertRefused(string $code, $out): void
    {
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame($code, $out->get_error_code(), $out->get_error_message());
    }

    /**
     * Store a document the way Oxygen's own savers do: saveSelectors() and
     * saveVariables() hand set_global_option() the decoded array, which it
     * JSON-encodes once; save_global_settings() hands it the JSON string,
     * so that option holds a JSON string literal.
     */
    private static function store(string $field, $document): void
    {
        $json = (string) wp_json_encode($document);
        update_option('oxygen_' . $field, 'global_settings_json_string' === $field ? wp_json_encode($json) : $json, false);
    }

    /** Read a document back the way Oxygen's get_global_option() does. */
    private static function load(string $field)
    {
        $value = json_decode((string) get_option('oxygen_' . $field), true);

        return is_string($value) ? json_decode($value, true) : $value;
    }

    /** @return array<int,array<string,mixed>> */
    private static function selectors(): array
    {
        return [[
            'id'         => '6f1c2a10-0000-4000-8000-000000000001',
            'name'       => 'card',
            'type'       => 'class',
            'properties' => ['breakpoint_base' => ['spacing' => ['spacing' => ['padding' => ['top' => ['number' => 8, 'unit' => 'px']]]]]],
            'children'   => [],
            'collection' => 'Default',
        ]];
    }

    /** @return array<int,array<string,mixed>> */
    private static function variables(): array
    {
        return [[
            'id'              => '6f1c2a10-0000-4000-8000-0000000000a1',
            'cssVariableName' => 'brand',
            'type'            => 'color',
            'label'           => 'Brand',
            'value'           => '#0055ff',
            'collection'      => 'Default',
        ]];
    }

    /** @return array<string,mixed> */
    private static function settings(): array
    {
        return ['settings' => [
            'colors'     => ['palette' => ['colors' => [['cssVariableName' => 'palette-color-1', 'label' => 'Accent', 'value' => '#ff5500']], 'gradients' => []]],
            'typography' => ['global_typography' => ['typography_presets' => []]],
        ]];
    }

    public function test_the_read_returns_a_hash_for_each_writable_list(): void
    {
        self::store('oxy_selectors_json_string', self::selectors());

        $hashes = $this->hashes();

        $this->assertSame(['classes', 'variables', 'palettes'], array_keys($hashes));
        foreach ($hashes as $hash) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        }
    }

    public function test_selector_add_update_remove_keep_oxygens_encoding_and_roll_back(): void
    {
        self::store('oxy_selectors_json_string', self::selectors());
        $raw_before = get_option('oxygen_oxy_selectors_json_string');

        $add = $this->write([
            'operation'     => 'add',
            'to'            => 'classes',
            'element'       => ['name' => 'hero', 'properties' => ['breakpoint_base' => ['typography' => ['color' => '#111']]]],
            'expected_hash' => $this->hashes()['classes'],
        ]);
        $this->assertWrote($add);
        $this->assertSame('oxygen', $add['builder']);

        $this->assertIsArray(json_decode((string) get_option('oxygen_oxy_selectors_json_string'), true), 'one decode yields the list, as getOxySelectors() expects');
        $stored = self::load('oxy_selectors_json_string');
        $this->assertCount(2, $stored);
        $added = $stored[1];
        $this->assertSame('classes/' . $added['id'], $add['path']);
        $this->assertTrue(wp_is_uuid($added['id']), 'a new selector gets a uuid id the way the builder makes one');
        $this->assertSame('hero', $added['name']);
        $this->assertSame('class', $added['type']);
        $this->assertSame([], $added['children']);
        $this->assertSame('Default', $added['collection']);

        $update = $this->write([
            'operation'     => 'update',
            'path'          => 'classes/' . self::selectors()[0]['id'],
            'attrs'         => ['properties' => ['breakpoint_base' => ['background' => ['color' => '#fff']]]],
            'expected_hash' => $add['hash'],
        ]);
        $this->assertWrote($update);
        $this->assertSame(['breakpoint_base' => ['background' => ['color' => '#fff']]], self::load('oxy_selectors_json_string')[0]['properties']);

        $remove = $this->write([
            'operation'     => 'remove',
            'path'          => 'classes/' . $added['id'],
            'expected_hash' => $update['hash'],
        ]);
        $this->assertWrote($remove);
        $this->assertCount(1, self::load('oxy_selectors_json_string'));

        foreach ([$remove, $update, $add] as $step) {
            $this->assertTrue(Rollback_Service::restore_operation($step['operation_id']));
        }
        $this->assertSame($raw_before, get_option('oxygen_oxy_selectors_json_string'));
    }

    public function test_a_custom_selector_and_a_duplicate_name_are_checked(): void
    {
        self::store('oxy_selectors_json_string', self::selectors());

        $custom = $this->write([
            'operation'     => 'add',
            'to'            => 'classes',
            'element'       => ['name' => '.card:hover', 'type' => 'custom'],
            'expected_hash' => $this->hashes()['classes'],
        ]);
        $this->assertWrote($custom);
        // Oxygen's savers turn an empty object into null before decoding, so
        // an empty properties object is stored the same way, never as [].
        $this->assertStringContainsString('"properties":null', (string) get_option('oxygen_oxy_selectors_json_string'));

        $before = get_option('oxygen_oxy_selectors_json_string');
        $this->assertRefused('invalid_design_entry', $this->write([
            'operation'     => 'add',
            'to'            => 'classes',
            'element'       => ['name' => 'card'],
            'expected_hash' => $custom['hash'],
        ]));
        $this->assertRefused('invalid_design_entry', $this->write([
            'operation'     => 'add',
            'to'            => 'classes',
            'element'       => ['name' => 'has space'],
            'expected_hash' => $custom['hash'],
        ]));
        $this->assertRefused('invalid_design_entry', $this->write([
            'operation'     => 'add',
            'to'            => 'classes',
            'element'       => ['name' => 'kids', 'children' => 'nope'],
            'expected_hash' => $custom['hash'],
        ]));
        $this->assertSame($before, get_option('oxygen_oxy_selectors_json_string'));
    }

    public function test_variable_add_names_it_like_the_builder_and_rolls_back(): void
    {
        self::store('variables_json_string', self::variables());
        $raw_before = get_option('oxygen_variables_json_string');

        $add = $this->write([
            'operation'     => 'add',
            'to'            => 'variables',
            'element'       => ['label' => 'Gap Large', 'type' => 'unit', 'value' => ['number' => 48, 'unit' => 'px']],
            'expected_hash' => $this->hashes()['variables'],
        ]);
        $this->assertWrote($add);

        $added = self::load('variables_json_string')[1];
        $this->assertSame('variables/' . $added['id'], $add['path']);
        $this->assertTrue(wp_is_uuid($added['id']));
        $this->assertSame('gap-large', $added['cssVariableName']);
        $this->assertSame('Default', $added['collection']);
        $this->assertSame(['number' => 48, 'unit' => 'px'], $added['value']);

        $update = $this->write([
            'operation'     => 'update',
            'path'          => 'variables/' . self::variables()[0]['id'],
            'attrs'         => ['value' => '#0044cc'],
            'expected_hash' => $add['hash'],
        ]);
        $this->assertWrote($update);
        $this->assertSame('#0044cc', self::load('variables_json_string')[0]['value']);

        Rollback_Service::restore_operation($update['operation_id']);
        Rollback_Service::restore_operation($add['operation_id']);
        $this->assertSame($raw_before, get_option('oxygen_variables_json_string'));
    }

    public function test_variables_refuse_a_taken_css_name_and_an_unknown_collection(): void
    {
        self::store('variables_json_string', self::variables());
        self::store('variables_collections_json_string', ['Default', 'Spacing']);
        $hash   = $this->hashes()['variables'];
        $before = get_option('oxygen_variables_json_string');

        $this->assertRefused('invalid_design_entry', $this->write([
            'operation'     => 'add',
            'to'            => 'variables',
            'element'       => ['label' => 'Brand', 'type' => 'color', 'value' => '#000'],
            'expected_hash' => $hash,
        ]));
        $unknown = $this->write([
            'operation'     => 'add',
            'to'            => 'variables',
            'element'       => ['label' => 'Ink', 'type' => 'color', 'value' => '#000', 'collection' => 'Nowhere'],
            'expected_hash' => $hash,
        ]);
        $this->assertRefused('invalid_design_entry', $unknown);
        $this->assertStringContainsString('Spacing', $unknown->get_error_message());
        $this->assertSame($before, get_option('oxygen_variables_json_string'));

        $known = $this->write([
            'operation'     => 'add',
            'to'            => 'variables',
            'element'       => ['label' => 'Gap', 'type' => 'unit', 'value' => '1rem', 'collection' => 'Spacing'],
            'expected_hash' => $hash,
        ]);
        $this->assertWrote($known);
    }

    public function test_palette_color_writes_touch_only_the_palette(): void
    {
        self::store('global_settings_json_string', self::settings());

        $add = $this->write([
            'operation'     => 'add',
            'to'            => 'palettes',
            'element'       => ['cssVariableName' => 'palette-ink', 'label' => 'Ink', 'value' => '#111111'],
            'expected_hash' => $this->hashes()['palettes'],
        ]);
        $this->assertWrote($add);
        $this->assertSame('palettes/palette-ink', $add['path']);

        $expected = self::settings();
        $expected['settings']['colors']['palette']['colors'][] = ['cssVariableName' => 'palette-ink', 'label' => 'Ink', 'value' => '#111111'];
        $this->assertSame($expected, self::load('global_settings_json_string'));

        Rollback_Service::restore_operation($add['operation_id']);
        $this->assertSame(self::settings(), self::load('global_settings_json_string'));
    }

    public function test_writes_need_oxygen_loaded_and_a_fresh_hash(): void
    {
        self::store('oxy_selectors_json_string', self::selectors());
        $hash = $this->hashes()['classes'];

        remove_all_filters('wpmcp_oxygen_active');
        add_filter('wpmcp_oxygen_active', '__return_false');
        $this->assertRefused('plugin_missing', $this->write(['operation' => 'add', 'to' => 'classes', 'element' => ['name' => 'x'], 'expected_hash' => $hash]));

        remove_all_filters('wpmcp_oxygen_active');
        add_filter('wpmcp_oxygen_active', '__return_true');
        $this->assertRefused('stale_expected_hash', $this->write(['operation' => 'add', 'to' => 'classes', 'element' => ['name' => 'x'], 'expected_hash' => str_repeat('0', 64)]));
        $this->assertSame(self::selectors(), self::load('oxy_selectors_json_string'));
    }

    public function test_an_editor_without_edit_theme_options_is_refused(): void
    {
        self::store('oxy_selectors_json_string', self::selectors());
        $hash = $this->hashes()['classes'];
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $this->assertRefused('forbidden', $this->write(['operation' => 'add', 'to' => 'classes', 'element' => ['name' => 'x'], 'expected_hash' => $hash]));
    }
}
