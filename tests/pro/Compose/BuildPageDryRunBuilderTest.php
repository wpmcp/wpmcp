<?php

namespace WPMCP\Tests\Pro\Compose;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Compose\Build_Page;
use WPMCP\Tools\Elementor\Atomic_Prop_Schema;
use WPMCP\Tools\Elementor\Widget_Catalog;

/**
 * build-page dry_run for the builder (Elementor) dialect (issue #137).
 *
 * The builder dialect is where a dry run earns its keep: unknown widget types
 * and atomic v4 prop shapes are exactly the two things an agent gets wrong,
 * and both are cheap to report and expensive to discover after the write. The
 * composition runs through the SAME Elementor_Composer the real build uses, so
 * the report describes what would actually be stored.
 */
class BuildPageDryRunBuilderTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();

        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }
    }

    protected function tearDown(): void
    {
        Atomic_Prop_Schema::set_for_tests(null);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function page_count(): int
    {
        return count(get_posts(['post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1]));
    }

    private function spec(array $content): array
    {
        return ['title' => 'Builder Dry Run', 'dialect' => 'elementor', 'content' => $content];
    }

    private function classic_content(): array
    {
        return [
            ['type' => 'container', 'children' => [
                ['type' => 'widget', 'settings' => ['widget' => 'heading', 'widget_settings' => ['title' => 'Welcome']]],
                ['type' => 'widget', 'settings' => ['widget' => 'button', 'widget_settings' => ['text' => 'Go']]],
            ]],
        ];
    }

    public function test_dry_run_counts_elements_per_widget_type_without_writing(): void
    {
        $pages = $this->page_count();

        $out = (new Build_Page())->handle(['spec' => $this->spec($this->classic_content()), 'dry_run' => true]);

        $this->assertTrue($out['dry_run']);
        $this->assertSame('elementor', $out['dialect']);
        $this->assertSame(3, $out['created_elements']);
        $this->assertSame(
            ['container' => 1, 'widget:button' => 1, 'widget:heading' => 1],
            $out['element_counts']
        );
        $this->assertSame(0, $out['markup_bytes'], 'The builder dialect stores an element tree, not post markup.');
        $this->assertSame($pages, $this->page_count());
    }

    public function test_dry_run_lists_unknown_widget_types_instead_of_throwing(): void
    {
        $content = $this->classic_content();
        $content[0]['children'][0]['settings']['widget'] = 'not-a-widget';

        $out = (new Build_Page())->handle(['spec' => $this->spec($content), 'dry_run' => true]);

        $this->assertFalse($out['valid']);
        $this->assertSame(['not-a-widget'], $out['unknown_widgets']);
        $this->assertStringContainsString('content[0].children[0]', $out['warnings'][0]);
    }

    public function test_dry_run_explains_a_cataloged_widget_whose_plugin_is_inactive(): void
    {
        $absent = null;
        foreach (Widget_Catalog::all() as $type => $entry) {
            if (null === \Elementor\Plugin::instance()->widgets_manager->get_widget_types((string) $type)) {
                $absent = (string) $type;
                break;
            }
        }

        if (null === $absent) {
            $this->markTestSkipped('Every cataloged widget is registered in this environment.');
        }

        $content = $this->classic_content();
        $content[0]['children'][0]['settings']['widget'] = $absent;

        $out = (new Build_Page())->handle(['spec' => $this->spec($content), 'dry_run' => true]);

        $this->assertFalse($out['valid']);
        $this->assertSame([$absent], $out['unknown_widgets']);
        $this->assertStringContainsString('wpmcp catalog', $out['warnings'][0]);
        $this->assertStringContainsString('not registered on this site', $out['warnings'][0]);
    }

    public function test_dry_run_reports_atomic_prop_coercions_from_the_shared_mapper(): void
    {
        Atomic_Prop_Schema::set_for_tests(require dirname(__DIR__, 2) . '/support/elementor-atomic-prop-fixtures.php');

        $out = (new Build_Page())->handle([
            'spec'    => $this->spec([
                ['type' => 'container', 'children' => [
                    ['type' => 'widget', 'settings' => [
                        'widget'          => 'e-heading',
                        'widget_settings' => ['text' => 'Aliased', 'tag' => 'H1', 'bogus' => 'x'],
                    ]],
                ]],
            ]),
            'dry_run' => true,
        ]);

        $coerced = implode(' | ', $out['coerced']);
        $this->assertStringContainsString('Renamed "text" to "title"', $coerced);
        $this->assertStringContainsString('Normalized "tag"', $coerced);
        $this->assertStringContainsString('content[0].children[0]', $coerced, 'Coercions are node-path addressed.');
        $this->assertStringContainsString('Dropped "bogus"', implode(' | ', $out['warnings']));
    }

    public function test_the_real_build_stores_the_props_the_dry_run_promised(): void
    {
        Atomic_Prop_Schema::set_for_tests(require dirname(__DIR__, 2) . '/support/elementor-atomic-prop-fixtures.php');

        $spec = $this->spec([
            ['type' => 'container', 'children' => [
                ['type' => 'widget', 'settings' => [
                    'widget'          => 'e-heading',
                    'widget_settings' => ['text' => 'Aliased', 'tag' => 'H1'],
                ]],
            ]],
        ]);

        $dry  = (new Build_Page())->handle(['spec' => $spec, 'dry_run' => true]);
        $real = (new Build_Page())->handle(['spec' => $spec]);

        $this->assertSame($dry['created_elements'], $real['created_elements']);
        $this->assertSame($dry['coerced'], $real['coerced']);

        $elements = json_decode(get_post_meta($real['post_id'], '_elementor_data', true), true);
        $settings = $elements[0]['elements'][0]['settings'];
        $this->assertSame(['$$type' => 'escaped-html', 'value' => 'Aliased'], $settings['title']);
        $this->assertSame('h1', $settings['tag']['value']);
    }

    public function test_dry_run_of_the_builder_dialect_is_still_pro_gated(): void
    {
        Gate::set_pro_for_tests(false);

        $this->expectException(\RuntimeException::class);

        (new Build_Page())->handle(['spec' => $this->spec($this->classic_content()), 'dry_run' => true]);
    }
}
