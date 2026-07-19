<?php

namespace WPMCP\Tests\Free\Elementor;

use WPMCP\Tools\Elementor\Widget_Catalog;

/**
 * Drift guard for the curated widget catalog (issue #59): every catalog
 * entry is validated against the REAL Elementor install at test time, so
 * the catalog cannot silently drift from what Elementor actually registers.
 *
 * For every entry whose requirement is satisfied by the installed builder:
 *  - the widget type must resolve to a real registered widget (Elementor's
 *    Pro-promotion placeholders do not count);
 *  - every curated param must map to a control name that exists on the
 *    widget's own control stack;
 *  - responsive params must have real _tablet and _mobile control variants;
 *  - repeater fields must exist in the real repeater control's fields;
 *  - choice enums must be a subset of the control's options where Elementor
 *    exposes them.
 *
 * Entries requiring Elementor Pro are validated the same way only when the
 * real Pro widget is installed; without Pro they are exercised by the shape
 * tests (WidgetCatalogTest) and by the clean-degrade tool tests only.
 */
class WidgetCatalogDriftTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }

        // get_controls() on nested widgets dereferences the active kit.
        wpmcp_ensure_elementor_kit();
    }

    public function test_every_satisfiable_entry_matches_installed_elementor(): void
    {
        $validated = 0;

        foreach (Widget_Catalog::all() as $type => $entry) {
            $widget = Widget_Catalog::installed_widget($type);

            if ('elementor' === $entry['requires']) {
                $this->assertNotNull(
                    $widget,
                    "Catalog entry '{$type}' requires free Elementor but is not a real registered widget."
                );
            }

            if (null === $widget) {
                // Requires Elementor Pro and Pro is absent: nothing real to
                // validate against in this environment.
                continue;
            }

            $this->assert_entry_matches_widget($type, $entry, $widget);
            $validated++;
        }

        $this->assertGreaterThanOrEqual(
            31,
            $validated,
            'The drift guard must validate at least the full free core widget set.'
        );
    }

    public function test_installed_widget_rejects_pro_promotion_placeholders(): void
    {
        // With Elementor Pro absent, 'form' is registered by free Elementor
        // as a promotion placeholder; the catalog must not treat that as an
        // installed widget.
        $registered = \Elementor\Plugin::instance()->widgets_manager->get_widget_types('form');

        if (null === $registered || 0 === strpos(get_class($registered), 'ElementorPro\\')) {
            $this->markTestSkipped('Environment does not register form as a promotion placeholder.');
        }

        $this->assertNull(Widget_Catalog::installed_widget('form'));
    }

    private function assert_entry_matches_widget(string $type, array $entry, \Elementor\Widget_Base $widget): void
    {
        $controls = $widget->get_controls();

        foreach ($entry['params'] as $name => $spec) {
            $control_name = (string) ($spec['control'] ?? $name);

            $this->assertArrayHasKey(
                $control_name,
                $controls,
                "Catalog drift: {$type}.{$name} curates control '{$control_name}' which the installed widget does not define."
            );

            if (! empty($spec['responsive'])) {
                foreach (['_tablet', '_mobile'] as $suffix) {
                    $this->assertArrayHasKey(
                        $control_name . $suffix,
                        $controls,
                        "Catalog drift: {$type}.{$name} is marked responsive but control '{$control_name}{$suffix}' does not exist."
                    );
                }
            }

            if ('repeater' === $spec['type']) {
                $fields = $controls[ $control_name ]['fields'] ?? null;
                $this->assertIsArray(
                    $fields,
                    "Catalog drift: {$type}.{$name} is a repeater but control '{$control_name}' has no fields."
                );
                foreach (array_keys($spec['fields']) as $field_name) {
                    $field_control = (string) ($spec['fields'][ $field_name ]['control'] ?? $field_name);
                    $this->assertArrayHasKey(
                        $field_control,
                        $fields,
                        "Catalog drift: {$type}.{$name}.{$field_name} curates repeater field '{$field_control}' which does not exist."
                    );
                }
            }

            if ('choice' === $spec['type']) {
                $options = $controls[ $control_name ]['options'] ?? null;
                if (is_array($options) && ! empty($options)) {
                    foreach ($spec['enum'] as $value) {
                        $this->assertArrayHasKey(
                            $value,
                            $options,
                            "Catalog drift: {$type}.{$name} enum value '{$value}' is not an option of control '{$control_name}'."
                        );
                    }
                }
            }
        }
    }
}
