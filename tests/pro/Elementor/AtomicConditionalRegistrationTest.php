<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\MCP\Registrar;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\Tools\Elementor\Add_Atomic_Widget;
use WPMCP\Tools\Elementor\Add_Div_Block;
use WPMCP\Tools\Elementor\Add_Flexbox;
use WPMCP\Tools\Elementor\Atomic_Element;
use WPMCP\Tools\Elementor\Atomic_Styles;
use WPMCP\Tools\Elementor\Detect_Elementor_Version;
use WPMCP\Tools\Elementor\Update_Atomic_Widget;

/**
 * Issue #62: next-generation (atomic/v4) builder support.
 *
 * The atomic write tools (add-flexbox, add-div-block, add-atomic-widget,
 * update-atomic-widget) must register only when the active builder ships the
 * atomic-widgets module, so the registration itself is exercised here through
 * the very method Plugin::register_abilities_into() calls, not just the
 * predicate behind it: deleting the gate has to fail a test.
 *
 * The shared style-class builder turns flat CSS-ish params into the local
 * style class shape the v4 editor stores on an element, reusing the
 * Global_Class_Schema dialect so create-global-class and the atomic tools
 * accept the same keys and fail the same way.
 */
class AtomicConditionalRegistrationTest extends Structural_Harness
{
    private const ATOMIC_TOOLS = [
        'wpmcp/add-flexbox',
        'wpmcp/add-div-block',
        'wpmcp/add-atomic-widget',
        'wpmcp/update-atomic-widget',
    ];

    protected function tearDown(): void
    {
        Atomic_Element::set_supported_for_tests(null);
        parent::tearDown();
    }

    /**
     * The atomic ability names a full registration run kept. Driven through
     * Plugin::register_abilities_into(), the same entry point production uses,
     * so the gate is exercised where it actually lives rather than through a
     * bespoke public seam on the singleton.
     *
     * @return array<int,string>
     */
    private function registered_atomic_names(): array
    {
        Gate::set_pro_for_tests(true);

        $registrar = new Registrar();
        Plugin::instance()->register_abilities_into($registrar);

        $names = array_map(static fn($ability) => $ability->name, $registrar->all());

        return array_values(array_intersect(self::ATOMIC_TOOLS, $names));
    }

    /** A page carrying one atomic container, for the write-path guard tests. */
    private function atomic_page_id(): int
    {
        $post_id = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($post_id, '_elementor_data', wp_json_encode([[
            'id'       => 'flex001',
            'elType'   => 'e-flexbox',
            'settings' => ['classes' => ['$$type' => 'classes', 'value' => []]],
            'elements' => [],
        ]]));
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');

        return $post_id;
    }

    // ---- conditional registration -------------------------------------------

    public function test_atomic_write_tools_register_when_the_builder_is_supported(): void
    {
        if (! Atomic_Element::is_supported()) {
            $this->markTestSkipped('This install has no atomic builder to register against.');
        }

        $names = $this->registered_atomic_names();

        foreach (self::ATOMIC_TOOLS as $tool) {
            $this->assertContains($tool, $names, $tool . ' must register on an atomic-capable builder.');
        }
    }

    public function test_detect_elementor_version_stays_available_and_reports_registration(): void
    {
        Atomic_Element::set_supported_for_tests(false);

        $out = (new Detect_Elementor_Version())->handle([]);

        $this->assertArrayHasKey('supports_atomic', $out);
        $this->assertFalse($out['supports_atomic']);
        $this->assertFalse(
            $out['atomic_tools_registered'],
            'The discoverability path must report what registration actually decided.'
        );
    }

    // ---- version gate --------------------------------------------------------

    public function test_gate_closes_when_the_builder_cannot_render_atomic_elements(): void
    {
        Atomic_Element::set_supported_for_tests(false);

        $this->assertFalse(Atomic_Element::is_supported());
        $this->assertWPError(Atomic_Element::require_supported());
    }

    // ---- shared style-class builder -----------------------------------------

    public function test_style_builder_emits_local_class_with_typed_props(): void
    {
        $built = Atomic_Styles::build('abc123', [
            'color'            => '#222222',
            'background_color' => '#f5f5f5',
            'font_size'        => 18,
            'padding'          => '12px',
            'text_align'       => 'center',
        ]);

        $this->assertNotWPError($built);
        $this->assertSame([], $built['warnings']);
        $this->assertArrayHasKey($built['class_id'], $built['styles']);

        $class = $built['styles'][$built['class_id']];
        $this->assertSame('class', $class['type']);

        $props = $class['variants'][0]['props'];
        $this->assertSame('color', $props['color']['$$type']);
        $this->assertSame('#222222', $props['color']['value']);
        $this->assertSame('background', $props['background']['$$type']);
        $this->assertSame(12.0, $props['padding']['value']['block-start']['value']['size']);
        $this->assertSame('center', $props['text-align']['value']);
        $this->assertSame(18.0, $props['font-size']['value']['size']);
        $this->assertSame('px', $props['font-size']['value']['unit']);
    }

    public function test_style_builder_accepts_the_size_object_shape_and_css_lengths(): void
    {
        $built = Atomic_Styles::build('abc123', [
            'font_size' => ['size' => 1.5, 'unit' => 'rem'],
            'width'     => '50%',
        ]);

        $this->assertNotWPError($built);

        $props = $built['styles'][$built['class_id']]['variants'][0]['props'];
        $this->assertSame(['size' => 1.5, 'unit' => 'rem'], $props['font-size']['value']);
        $this->assertSame(['size' => 50.0, 'unit' => '%'], $props['width']['value']);
    }

    public function test_style_builder_rejects_an_unknown_param_instead_of_warning(): void
    {
        $built = Atomic_Styles::build('abc123', ['bogus_prop' => 1, 'width' => '50%']);

        $this->assertWPError($built);
        $this->assertSame('unknown_style_key', $built->get_error_code());
        $this->assertStringContainsString('bogus_prop', $built->get_error_message());
    }

    public function test_style_builder_rejects_an_unsanitizable_color(): void
    {
        $built = Atomic_Styles::build('abc123', ['color' => '#fff;background:url(https://evil.example/?c=)']);

        $this->assertWPError($built);
        $this->assertSame('invalid_color', $built->get_error_code());
    }

    public function test_style_builder_rejects_a_value_that_is_not_a_length(): void
    {
        $built = Atomic_Styles::build('abc123', ['width' => 'auto']);

        $this->assertWPError($built);
        $this->assertSame('invalid_style_value', $built->get_error_code());
        $this->assertStringContainsString('width', $built->get_error_message());
    }

    public function test_style_builder_rejects_a_non_object_style_argument(): void
    {
        $built = Atomic_Styles::build('abc123', 'color:red');

        $this->assertWPError($built);
        $this->assertSame('invalid_style', $built->get_error_code());
    }

    public function test_padding_object_maps_sides_and_refuses_a_positional_list(): void
    {
        $built = Atomic_Styles::build('abc123', ['padding' => ['top' => 10, 'inline-start' => '2rem']]);

        $this->assertNotWPError($built);
        $sides = $built['styles'][$built['class_id']]['variants'][0]['props']['padding']['value'];
        $this->assertSame(10.0, $sides['block-start']['value']['size']);
        $this->assertSame(['size' => 2.0, 'unit' => 'rem'], $sides['inline-start']['value']);
        $this->assertArrayNotHasKey('block-end', $sides, 'An unset side stays unset rather than defaulting.');

        $listed = Atomic_Styles::build('abc123', ['padding' => [10, 20, 30, 40]]);

        $this->assertWPError($listed, 'A positional list is not a side map and must not be silently discarded.');
        $this->assertSame('unknown_style_side', $listed->get_error_code());
    }

    public function test_attach_links_the_class_and_survives_a_non_list_classes_value(): void
    {
        $built = Atomic_Styles::build('abc123', ['width' => '50%']);
        $this->assertNotWPError($built);

        $element = Atomic_Element::widget('e-vendor-thing', ['classes' => 'hero']);
        $element = Atomic_Styles::attach($element, $built);

        $this->assertArrayHasKey($built['class_id'], $element['styles']);
        $this->assertContains($built['class_id'], $element['settings']['classes']['value']);
        $this->assertContains('hero', $element['settings']['classes']['value']);
    }

    public function test_attach_replaces_a_previously_generated_class_for_the_same_element(): void
    {
        $element = Atomic_Element::widget('e-heading', []);

        $first  = Atomic_Styles::build($element['id'], ['color' => '#111111']);
        $second = Atomic_Styles::build($element['id'], ['color' => '#222222']);
        $this->assertNotWPError($first);
        $this->assertNotWPError($second);

        $element = Atomic_Styles::attach($element, $first);
        $element = Atomic_Styles::attach($element, $second);

        $this->assertSame([$second['class_id']], array_keys($element['styles']));
        $this->assertSame([$second['class_id']], $element['settings']['classes']['value']);
    }

    // ---- reviewer regressions (issue #62) ------------------------------------

    /**
     * The v4 editor names an element's own local class `e-<element-id>-<hash>`,
     * which is the exact shape this builder generates. Ownership therefore
     * cannot be decided by id prefix: a human-authored local class (with its
     * tablet/hover variants) must survive a tool-driven style write.
     */
    public function test_attach_preserves_an_editor_authored_local_class(): void
    {
        $element = Atomic_Element::widget('e-heading', []);
        $editor_class_id = 'e-' . $element['id'] . '-deadbee';

        $element['styles'] = [
            $editor_class_id => [
                'id'       => $editor_class_id,
                'type'     => 'class',
                'label'    => 'local',
                'variants' => [
                    ['meta' => ['breakpoint' => 'desktop', 'state' => null], 'props' => ['color' => ['$$type' => 'color', 'value' => '#aaaaaa']]],
                    ['meta' => ['breakpoint' => 'tablet', 'state' => 'hover'], 'props' => ['color' => ['$$type' => 'color', 'value' => '#bbbbbb']]],
                ],
            ],
        ];
        $element['settings']['classes'] = ['$$type' => 'classes', 'value' => [$editor_class_id]];

        $built = Atomic_Styles::build($element['id'], ['color' => '#222222']);
        $this->assertNotWPError($built);

        $element = Atomic_Styles::attach($element, $built);

        $this->assertArrayHasKey($editor_class_id, $element['styles'], 'An editor-authored local class must not be deleted by a tool style write.');
        $this->assertCount(2, $element['styles'][$editor_class_id]['variants'], 'Its breakpoint/state variants must survive intact.');
        $this->assertContains($editor_class_id, $element['settings']['classes']['value']);
        $this->assertContains($built['class_id'], $element['settings']['classes']['value']);
    }

    /** Only the class this builder generated is replaced on a repeat write. */
    public function test_attach_replaces_only_the_class_this_builder_owns(): void
    {
        $element = Atomic_Element::widget('e-heading', []);
        $foreign = 'e-' . $element['id'] . '-cafe123';
        $element['styles'] = [$foreign => ['id' => $foreign, 'type' => 'class', 'label' => 'local', 'variants' => []]];

        $first  = Atomic_Styles::build($element['id'], ['color' => '#111111']);
        $second = Atomic_Styles::build($element['id'], ['color' => '#222222']);

        $element = Atomic_Styles::attach($element, $first);
        $element = Atomic_Styles::attach($element, $second);

        $this->assertSame([$foreign, $second['class_id']], array_keys($element['styles']));
    }

    /** The generated class carries an explicit ownership marker. */
    public function test_generated_class_is_labelled_as_tool_owned(): void
    {
        $built = Atomic_Styles::build('abc123', ['color' => '#222222']);
        $this->assertNotWPError($built);
        $this->assertSame(
            Atomic_Styles::OWNED_LABEL,
            $built['styles'][$built['class_id']]['label'],
            'Ownership is a label on the class, not a guess from its id.'
        );
    }

    /**
     * A caller-supplied `<key>_unit` must win regardless of where it sits in
     * the JSON object, since object key order carries no meaning.
     */
    public function test_unit_companion_is_order_independent(): void
    {
        $after  = Atomic_Styles::build('abc123', ['font_size' => 18, 'font_size_unit' => 'rem']);
        $before = Atomic_Styles::build('abc123', ['font_size_unit' => 'rem', 'font_size' => 18]);

        $this->assertNotWPError($after);
        $this->assertNotWPError($before);

        $unit = static fn($built) => $built['styles'][$built['class_id']]['variants'][0]['props']['font-size']['value']['unit'];

        $this->assertSame('rem', $unit($after));
        $this->assertSame('rem', $unit($before), 'Key order must not decide the unit.');
    }

    /** A padding object mixing the size shorthand with side names is ambiguous. */
    public function test_padding_mixing_shorthand_and_sides_is_refused(): void
    {
        $built = Atomic_Styles::build('abc123', ['padding' => ['size' => 5, 'top' => 10]]);

        $this->assertWPError($built, 'A silently discarded side is a style the caller believes it applied.');
        $this->assertSame('unknown_style_side', $built->get_error_code());
    }

    /** The invalid-length message names the key and quotes the value once. */
    public function test_invalid_length_message_reads_correctly(): void
    {
        $built = Atomic_Styles::build('abc123', ['width' => 'auto']);

        $this->assertWPError($built);
        $this->assertStringNotContainsString('""', $built->get_error_message());
        $this->assertStringContainsString('"width"', $built->get_error_message());
    }

    /** A junk unit must not reach the generated CSS verbatim. */
    public function test_a_bogus_unit_is_refused(): void
    {
        $built = Atomic_Styles::build('abc123', ['font_size' => ['size' => 1, 'unit' => 'px;color:red']]);

        $this->assertWPError($built);
        $this->assertSame('invalid_style_value', $built->get_error_code());
    }

    /** The raw props escape hatch create-global-class exposes works here too. */
    public function test_style_accepts_the_raw_props_escape_hatch(): void
    {
        $built = Atomic_Styles::build('abc123', [
            'color' => '#222222',
            'props' => ['width' => ['$$type' => 'size', 'value' => ['size' => 100, 'unit' => '%']]],
        ]);

        $this->assertNotWPError($built);
        $props = $built['styles'][$built['class_id']]['variants'][0]['props'];
        $this->assertSame('%', $props['width']['value']['unit']);
        $this->assertSame('#222222', $props['color']['value']);
    }

    // ---- the write-path guard, driven with the capability forced closed -------

    public function test_every_atomic_handler_refuses_when_the_builder_cannot_render(): void
    {
        $post_id = $this->atomic_page_id();
        $before  = get_post_meta($post_id, '_elementor_data', true);

        Atomic_Element::set_supported_for_tests(false);

        $calls = [
            'add-flexbox'           => [new Add_Flexbox(), ['post_id' => $post_id, 'expected_hash' => 'x']],
            'add-div-block'         => [new Add_Div_Block(), ['post_id' => $post_id, 'expected_hash' => 'x']],
            'add-atomic-widget'     => [new Add_Atomic_Widget(), ['post_id' => $post_id, 'expected_hash' => 'x', 'widget_type' => 'e-heading']],
            'update-atomic-widget'  => [new Update_Atomic_Widget(), ['post_id' => $post_id, 'expected_hash' => 'x', 'element_id' => 'flex001', 'params' => ['title' => 'x']]],
        ];

        foreach ($calls as $tool => [$handler, $args]) {
            $out = $handler->handle($args);
            $this->assertWPError($out, $tool . ' must refuse a builder that cannot render atomic elements.');
            $this->assertSame('atomic_unsupported', $out->get_error_code(), $tool . ' must report atomic_unsupported.');
        }

        $this->assertSame($before, get_post_meta($post_id, '_elementor_data', true), 'A refused atomic call writes nothing.');
    }

    /**
     * A classic (v3) widget found by id is not styleable by these tools: typed
     * atomic props and a `styles` blob mean nothing to the classic renderer, so
     * writing them would report success for styling that never appears.
     */
    public function test_update_atomic_widget_refuses_a_classic_element(): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($post_id, '_elementor_data', wp_json_encode([[
            'id'         => 'classic1',
            'elType'     => 'widget',
            'widgetType' => 'heading',
            'settings'   => ['title' => 'Classic'],
            'elements'   => [],
        ]]));
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');

        $before = get_post_meta($post_id, '_elementor_data', true);

        $out = (new Update_Atomic_Widget())->handle([
            'post_id'       => $post_id,
            'expected_hash' => \WPMCP\Tools\Elementor\Element_Tree::data_hash($post_id),
            'element_id'    => 'classic1',
            'style'         => ['color' => '#112233'],
        ]);

        $this->assertWPError($out);
        $this->assertSame('not_an_atomic_element', $out->get_error_code());
        $this->assertStringContainsString('heading', $out->get_error_message(), 'The error must name what the caller pointed at.');
        $this->assertSame($before, get_post_meta($post_id, '_elementor_data', true), 'A refused call writes nothing.');
    }

    /** Atomic containers and `e-` widgets are atomic; classic elements are not. */
    public function test_is_atomic_node_recognizes_the_v4_element_types(): void
    {
        $this->assertTrue(Atomic_Element::is_atomic_node(['elType' => 'e-flexbox']));
        $this->assertTrue(Atomic_Element::is_atomic_node(['elType' => 'e-div-block']));
        $this->assertTrue(Atomic_Element::is_atomic_node(['elType' => 'widget', 'widgetType' => 'e-heading']));
        $this->assertFalse(Atomic_Element::is_atomic_node(['elType' => 'widget', 'widgetType' => 'heading']));
        $this->assertFalse(Atomic_Element::is_atomic_node(['elType' => 'container']));
        $this->assertFalse(Atomic_Element::is_atomic_node(['elType' => 'section']));
    }

    public function test_atomic_tools_do_not_register_when_the_builder_cannot_render(): void
    {
        Atomic_Element::set_supported_for_tests(false);

        $this->assertSame([], $this->registered_atomic_names(), 'A legacy builder must register none of the atomic write tools.');
    }
}
