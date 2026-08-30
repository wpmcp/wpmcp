<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Tools\Elementor\Atomic_Element;
use WPMCP\Tools\Elementor\Atomic_Styles;

/**
 * Issue #62: next-generation (atomic/v4) builder support.
 *
 * The atomic write tools (add-flexbox, add-div-block, add-atomic-widget,
 * update-atomic-widget) must register only when the active builder ships the
 * atomic-widgets module; the version gate is a filterable predicate so both
 * tests and site code can force either side. The shared style-class builder
 * turns flat CSS-ish params into the local style class shape the v4 editor
 * stores on an element.
 */
class AtomicConditionalRegistrationTest extends Structural_Harness
{
    // ---- version gate --------------------------------------------------------

    public function test_gate_open_in_atomic_capable_env(): void
    {
        $this->assertTrue(
            Atomic_Element::registration_supported(),
            'The 4.x test env ships the atomic-widgets module, so the gate is open.'
        );
    }

    public function test_gate_closes_when_builder_version_unsupported(): void
    {
        add_filter('wpmcp_elementor_atomic_supported', '__return_false');
        try {
            $this->assertFalse(
                Atomic_Element::registration_supported(),
                'A legacy builder must keep the atomic write tools unregistered.'
            );
        } finally {
            remove_filter('wpmcp_elementor_atomic_supported', '__return_false');
        }
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

        $this->assertSame([], $built['warnings']);
        $this->assertArrayHasKey($built['class_id'], $built['styles']);

        $class = $built['styles'][$built['class_id']];
        $this->assertSame('class', $class['type']);

        $props = $class['variants'][0]['props'];
        $this->assertSame(['$$type' => 'color', 'value' => '#222222'], $props['color']);
        $this->assertSame('background', $props['background']['$$type']);
        $this->assertSame(['size' => 18, 'unit' => 'px'], $props['font-size']['value']);
        $this->assertSame('dimensions', $props['padding']['$$type']);
        $this->assertSame('center', $props['text-align']['value']);
    }

    public function test_style_builder_warns_on_unknown_prop_and_attach_links_class(): void
    {
        $built = Atomic_Styles::build('abc123', ['bogus_prop' => 1, 'width' => '50%']);

        $this->assertCount(1, $built['warnings']);
        $this->assertSame(['size' => 50, 'unit' => '%'], $built['styles'][$built['class_id']]['variants'][0]['props']['width']['value']);

        $element = Atomic_Styles::attach(Atomic_Element::widget('e-heading', []), $built);

        $this->assertArrayHasKey($built['class_id'], $element['styles']);
        $this->assertContains($built['class_id'], $element['settings']['classes']['value']);
    }
}
