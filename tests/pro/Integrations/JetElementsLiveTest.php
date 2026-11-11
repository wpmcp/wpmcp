<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Elementor\Get_Elementor_Data;

/**
 * Issue #653: the JetElements addon pack ops against a real JetElements.
 *
 * It runs only in the local gate's live JetElements leg
 * (bin/test-local.sh --live-jet-elements), which sets
 * WPMCP_LIVE_JET_ELEMENTS=1, copies JetElements from WPMCP_JET_ELEMENTS_SRC
 * onto a separate WordPress install beside the pinned free Elementor
 * (JetElements is commercial, not on wordpress.org) and loads it in
 * tests/bootstrap.php. No license key is set.
 *
 * The module catalog comes from JetElements' own settings singleton, the
 * widgets from Elementor's widgets manager as JetElements registered them,
 * and what a toggle leaves is read back through JetElements' own
 * jet_elements_settings()->get(), so the ops agree with the suite on both
 * sides.
 *
 * Everywhere else JetElements is absent and the test is skipped; the live
 * leg runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group jet-elements-live
 */
class JetElementsLiveTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('jet_elements') || ! function_exists('jet_elements_settings') || ! wpmcp_elementor_active()) {
            $this->markTestSkipped('Needs the real JetElements (bin/test-local.sh --live-jet-elements, WPMCP_JET_ELEMENTS_SRC).');
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        delete_option('jet-elements-settings');
        jet_elements_settings()->settings = null;
    }

    protected function tearDown(): void
    {
        delete_option('jet-elements-settings');
        if (function_exists('jet_elements_settings')) {
            jet_elements_settings()->settings = null;
        }
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    /** JetElements' own answer for one widget slug, as its register_addons() reads it. */
    private function jet_widget_on(string $slug): bool
    {
        jet_elements_settings()->settings = null;
        $map = jet_elements_settings()->get('avaliable_widgets');
        if (! $map) {
            return true;
        }
        return filter_var($map[ $slug ] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    public function test_the_real_suite_is_detected_and_its_catalog_read(): void
    {
        $names = array_column((new Theme_Integration())->catalog()['operations'], 'dependency_met', 'name');
        $this->assertTrue($names['get-jet-elements-modules']);
        $this->assertTrue($names['list-jet-elements-widgets']);

        $result = $this->read('get-jet-elements-modules')['result'];
        $slugs  = array_keys(jet_elements_settings()->avaliable_widgets);
        $this->assertGreaterThanOrEqual(30, count($slugs), 'JetElements lists its widget files');
        $expected = array_fill_keys(array_merge($slugs, [ 'section_parallax' ]), true);
        ksort($expected);
        $this->assertSame($expected, $result['modules'], 'Every widget and the parallax extension, all on while nothing is saved');
    }

    public function test_widgets_are_the_ones_jet_elements_registered(): void
    {
        $out = $this->read('list-jet-elements-widgets');
        $this->assertArrayNotHasKey('error', $out);
        $names = array_column($out['result']['widgets'], 'name');
        $this->assertContains('jet-banner', $names);
        $this->assertContains('jet-button', $names);
        foreach (\Elementor\Plugin::instance()->widgets_manager->get_widget_types() as $name => $widget) {
            $ours = 0 === strpos(get_class($widget), 'Elementor\\Jet_Elements_');
            $this->assertSame($ours, in_array($name, $names, true), "{$name} is listed exactly when it is a JetElements widget");
        }

        // JetElements' own Weather widget declares set_api_key twice while no
        // weather API key is saved, which Elementor reports as incorrect usage
        // the first time that widget's controls are built.
        $this->setExpectedIncorrectUsage('Elementor\\Controls_Manager::add_control_to_stack');
        $plain = strlen((string) wp_json_encode($out['result']));
        $named = strlen((string) wp_json_encode($this->read('list-jet-elements-widgets', [ 'widgets' => [ 'jet-banner', 'jet-button' ] ])['result']));
        $this->assertLessThan($named, $plain, 'Controls only for named widgets');
    }

    /**
     * Control payloads as a real MCP request sees them. Elementor strips the
     * editor keys (label, options, selectors) and sets the style controls
     * aside on a front-end request, which a PHPUnit run is; on a REST
     * request it keeps them all. So the stacks are rebuilt in REST mode for
     * this test, and the sizes are printed for the record.
     */
    public function test_controls_come_for_named_widgets_as_a_summary_and_full_for_one(): void
    {
        $this->setExpectedIncorrectUsage('Elementor\\Controls_Manager::add_control_to_stack');
        $frontend = new \ReflectionProperty(\Elementor\Core\Frontend\Performance::class, 'is_frontend');
        $stacks   = new \ReflectionProperty(\Elementor\Controls_Manager::class, 'stacks');
        $was     = $frontend->getValue();
        $manager = \Elementor\Plugin::instance()->controls_manager;
        $frontend->setValue(null, false);
        $stacks->setValue($manager, []);

        try {
            $size   = static fn (array $out): int => strlen((string) wp_json_encode($out['result'] ?? $out));
            $plain  = $this->read('list-jet-elements-widgets');
            $others = array_values(array_diff(array_column($plain['result']['widgets'], 'name'), [ 'jet-banner', 'jet-button' ]));
            $five   = array_merge([ 'jet-banner', 'jet-button' ], array_slice($others, 0, 3));
            $list   = $this->read('list-jet-elements-widgets', [ 'widgets' => $five ]);
            $all    = $this->read('list-jet-elements-widgets', [ 'include_controls' => true ]);
            $six    = $this->read('list-jet-elements-widgets', [ 'widget' => $others[3], 'widgets' => $five ]);
            $one    = $this->read('list-jet-elements-widgets', [ 'widget' => 'jet-banner' ]);
            $full   = $this->read('list-jet-elements-widgets', [ 'widget' => 'jet-banner', 'detail' => 'full' ]);
            $refuse = $this->read('list-jet-elements-widgets', [ 'detail' => 'full' ]);

            // The controls as the read returned them before the summary
            // (Widget_View::controls), in place of what it returns now.
            $old = static function (array $result): int {
                foreach ($result['widgets'] as $i => $row) {
                    $widget = \Elementor\Plugin::instance()->widgets_manager->get_widget_types($row['name']);
                    $result['widgets'][ $i ]['controls'] = \WPMCP\Tools\Elementor\Widget_View::controls($widget);
                }
                unset($result['detail']);
                return strlen((string) wp_json_encode($result));
            };

            $this->assertSame('summary', $list['result']['detail']);
            $sorted = $five;
            sort($sorted);
            $this->assertSame($sorted, array_column($list['result']['widgets'], 'name'), 'Exactly the five named widgets');
            $this->assertArrayNotHasKey('controls', $plain['result']['widgets'][0], 'The plain list carries no controls');
            $this->assertSame('controls_need_widget', $all['error']['code'], 'No controls for the whole list');
            $this->assertSame('too_many_widgets', $six['error']['code']);
            $controls = $one['result']['widgets'][0]['controls'];
            $this->assertNotEmpty($controls);
            $selects = array_filter($controls, static fn (array $c): bool => 'select' === $c['type']);
            $this->assertNotEmpty($selects, 'The banner has select controls');
            foreach ($selects as $name => $control) {
                $this->assertArrayHasKey('options', $control, "{$name} lists its option keys");
            }
            foreach ($controls as $name => $control) {
                $this->assertNotContains($control['type'], [ 'section', 'tab', 'tabs', 'heading', 'raw_html', 'divider', 'alert' ], "{$name}: no layout controls in the summary");
                $this->assertSame([], array_diff(array_keys($control), [ 'type', 'label', 'default', 'options' ]), "{$name}: summary keys only");
            }
            $this->assertArrayHasKey('selectors', array_merge(...array_values(array_map(static fn ($c): array => (array) $c, $full['result']['widgets'][0]['controls']))), 'Full detail keeps the CSS selectors');
            $this->assertSame('full_detail_needs_widget', $refuse['error']['code']);
            $this->assertLessThan($size($full), $size($one));

            fwrite(STDERR, sprintf(
                "\n[jet-elements controls, REST mode] plain list of %d widgets: %d bytes. jet-banner: before %d, summary %d, full %d bytes. 5 named widgets (%s): before %d, summary %d bytes. Controls for the whole list and full detail for more than one widget refused.\n",
                $plain['result']['count'],
                $size($plain),
                $old($one['result']),
                $size($one),
                $size($full),
                implode(', ', $five),
                $old($list['result']),
                $size($list)
            ));
        } finally {
            $frontend->setValue(null, $was);
            $stacks->setValue($manager, []);
        }
    }

    public function test_a_toggle_reads_back_through_jet_elements_and_rolls_back(): void
    {
        $stored = [ 'api_key' => 'live-key', 'widgets_load_level' => 50 ];
        update_option('jet-elements-settings', $stored);
        $this->assertTrue($this->jet_widget_on('jet-elements-button'));

        $out = $this->write('set-jet-elements-modules', [ 'modules' => [ 'jet-elements-button' => false, 'section_parallax' => false ] ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);

        $this->assertFalse($this->jet_widget_on('jet-elements-button'), 'JetElements now skips the widget');
        $this->assertTrue($this->jet_widget_on('jet-elements-banner'), 'and still registers every other one');
        $extensions = jet_elements_settings()->get('avaliable_extensions', jet_elements_settings()->default_avaliable_extensions);
        $this->assertFalse(filter_var($extensions['section_parallax'], FILTER_VALIDATE_BOOLEAN), 'The parallax extension is off the way the suite reads it');
        $this->assertSame('live-key', jet_elements_settings()->get('api_key'), 'The other settings are untouched');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($stored, get_option('jet-elements-settings'));
        $this->assertTrue($this->jet_widget_on('jet-elements-button'));
    }

    public function test_a_page_read_names_the_jet_elements_widget_type(): void
    {
        $page = self::factory()->post->create([ 'post_type' => 'page' ]);
        update_post_meta($page, '_elementor_edit_mode', 'builder');
        update_post_meta($page, '_elementor_data', wp_json_encode([
            [
                'id'       => 'c0ffee1',
                'elType'   => 'container',
                'settings' => [],
                'elements' => [
                    [
                        'id'         => 'a1b2c3d',
                        'elType'     => 'widget',
                        'widgetType' => 'jet-banner',
                        'settings'   => [ 'banner_title' => 'Spring sale' ],
                        'elements'   => [],
                    ],
                ],
            ],
        ]));

        $out = (new Get_Elementor_Data())->handle([ 'post_id' => $page ]);
        $this->assertSame('jet-banner', $out['elements'][0]['elements'][0]['widgetType']);
        $this->assertSame('Spring sale', $out['elements'][0]['elements'][0]['settings']['banner_title']);
    }
}
