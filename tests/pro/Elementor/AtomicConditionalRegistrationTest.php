<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\MCP\Registrar;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\Tools\Elementor\Atomic_Element;
use WPMCP\Tools\Elementor\Atomic_Styles;
use WPMCP\Tools\Elementor\Detect_Elementor_Version;

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
        remove_filter('wpmcp_elementor_atomic_supported', '__return_false');
        remove_filter('wpmcp_elementor_atomic_supported', '__return_true');
        parent::tearDown();
    }

    /** @return array<int,string> The ability names a fresh Registrar kept. */
    private function registered_atomic_names(): array
    {
        Gate::set_pro_for_tests(true);

        $registrar = new Registrar();
        Plugin::instance()->register_atomic_elementor_abilities($registrar);

        return array_map(static fn($ability) => $ability->name, $registrar->all());
    }

    // ---- conditional registration -------------------------------------------

    public function test_atomic_write_tools_are_absent_when_the_builder_is_unsupported(): void
    {
        add_filter('wpmcp_elementor_atomic_supported', '__return_false');

        $names = $this->registered_atomic_names();

        $this->assertSame([], $names, 'A legacy builder must register none of the atomic write tools.');
        foreach (self::ATOMIC_TOOLS as $tool) {
            $this->assertNotContains($tool, $names, $tool . ' must not register on an unsupported builder.');
        }
    }

    public function test_atomic_write_tools_register_when_the_builder_is_supported(): void
    {
        add_filter('wpmcp_elementor_atomic_supported', '__return_true');

        $names = $this->registered_atomic_names();

        foreach (self::ATOMIC_TOOLS as $tool) {
            $this->assertContains($tool, $names, $tool . ' must register on an atomic-capable builder.');
        }
    }

    public function test_detect_elementor_version_stays_available_and_reports_the_gate(): void
    {
        add_filter('wpmcp_elementor_atomic_supported', '__return_false');

        $out = (new Detect_Elementor_Version())->handle([]);

        $this->assertArrayHasKey('supports_atomic', $out);
        $this->assertFalse(
            $out['atomic_tools_registered'],
            'The discoverability path must report the predicate that actually gated registration.'
        );
    }

    // ---- version gate --------------------------------------------------------

    public function test_gate_mirrors_builder_support_by_default(): void
    {
        $this->assertSame(
            Atomic_Element::is_supported(),
            Atomic_Element::registration_supported(),
            'Unfiltered, the registration gate is exactly the builder-version check.'
        );
    }

    public function test_gate_closes_when_builder_version_unsupported(): void
    {
        add_filter('wpmcp_elementor_atomic_supported', '__return_false');

        $this->assertFalse(
            Atomic_Element::registration_supported(),
            'A legacy builder must keep the atomic write tools unregistered.'
        );
    }

    public function test_write_path_refuses_even_when_the_gate_is_forced_open(): void
    {
        if (Atomic_Element::is_supported()) {
            $this->assertNull(
                Atomic_Element::require_supported(),
                'An atomic-capable builder must not block atomic writes.'
            );
            return;
        }

        add_filter('wpmcp_elementor_atomic_supported', '__return_true');

        $this->assertWPError(
            Atomic_Element::require_supported(),
            'A forced-open gate must not let atomic writes reach a builder that cannot render them.'
        );
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
}
