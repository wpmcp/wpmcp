<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/elementor-addon-stubs.php';
require_once __DIR__ . '/../../support/jet-elements-stubs.php';

/**
 * Elementor addon suite packs (issue #286): Essential Addons, Premium Addons
 * and Ultimate Addons, plus JetElements (issue #653), as paid-tier ops on the theme dispatcher pair. Each
 * suite gets a widget catalog read (its registered widgets with their
 * controls, from Elementor's widgets manager) and a module toggle pair that
 * reads and writes the suite's own settings option in the suite's own
 * format, snapshotted for an exact rollback.
 *
 * Presence is driven through the wpmcp_{suite}_active filters, so no suite
 * has to be installed into the shared test core.
 */
class ElementorAddonPacksTest extends \WP_UnitTestCase
{
    private const SUITES = [ 'essential-addons', 'premium-addons', 'ultimate-addons', 'jet-elements' ];

    /** @var string[] widget names registered by a test, unregistered in tearDown */
    private array $widgets = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        \PremiumAddons\Admin\Includes\Admin_Helper::$keys = [];
        \HFE\WidgetsManager\Base\HFE_Helper::$list        = [];
        unset($GLOBALS['eael_config']);
        $this->reset_jet_settings();
    }

    protected function tearDown(): void
    {
        foreach ([ 'essential_addons', 'premium_addons', 'ultimate_addons', 'jet_elements' ] as $suite) {
            remove_all_filters("wpmcp_{$suite}_active");
        }
        if ([] !== $this->widgets && class_exists('\\Elementor\\Plugin')) {
            foreach ($this->widgets as $name) {
                \Elementor\Plugin::instance()->widgets_manager->unregister($name);
            }
        }
        $this->widgets = [];
        unset($GLOBALS['eael_config']);
        delete_option('eael_save_settings');
        delete_option('pa_save_settings');
        delete_option('_hfe_widgets');
        delete_option('jet-elements-settings');
        $this->reset_jet_settings();
        wp_cache_delete('pa_elements', 'premium_addons');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function activate(string ...$suites): void
    {
        foreach ($suites as $suite) {
            add_filter('wpmcp_' . str_replace('-', '_', $suite) . '_active', '__return_true');
        }
    }

    private function names(): array
    {
        return array_column((new Theme_Integration())->catalog()['operations'], 'name');
    }

    private function read(string $op, array $args = []): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function register_widgets(string ...$classes): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }
        require_once __DIR__ . '/../../support/elementor-addon-widgets.php';
        $manager = \Elementor\Plugin::instance()->widgets_manager;
        $manager->get_widget_types(); // Let Elementor register its own widgets first.
        foreach ($classes as $class) {
            $widget = new $class();
            $manager->register($widget);
            $this->widgets[] = $widget->get_name();
        }
    }

    private function seed_essential_config(): void
    {
        $GLOBALS['eael_config'] = [
            'elements'   => [
                'post-grid'     => [ 'class' => 'Post_Grid' ],
                'adv-accordion' => [ 'class' => 'Adv_Accordion' ],
            ],
            'extensions' => [
                'section-particles' => [ 'class' => 'Particles' ],
            ],
        ];
    }

    private function seed_premium_keys(): void
    {
        \PremiumAddons\Admin\Includes\Admin_Helper::$keys = [
            [ 'key' => 'premium-ai-abilities' ],
            [ 'key' => 'premium-banner' ],
            [ 'key' => 'premium-site-logo', 'draw_svg' => true ],
        ];
    }

    private function seed_ultimate_list(): void
    {
        \HFE\WidgetsManager\Base\HFE_Helper::$list = [
            'Retina'    => [ 'slug' => 'retina', 'default' => true ],
            'Copyright' => [ 'slug' => 'copyright', 'default' => true ],
            'Cart'      => [ 'slug' => 'cart', 'default' => false ],
        ];
    }

    private function reset_jet_settings(): void
    {
        $settings                               = \Jet_Elements_Settings::get_instance();
        $settings->avaliable_widgets            = [];
        $settings->default_avaliable_extensions = [ 'section_parallax' => 'true' ];
        $settings->settings                     = null;
    }

    /** The JetElements catalog: widget file slug => Name header, as its settings init() builds it. */
    private function seed_jet_widgets(): void
    {
        \Jet_Elements_Settings::get_instance()->avaliable_widgets = [
            'jet-elements-banner'   => 'Banner',
            'jet-elements-button'   => 'Button',
            'jet-elements-timeline' => 'Timeline',
        ];
    }

    public function test_every_suite_registers_three_paid_ops_on_the_theme_pair(): void
    {
        $names = $this->names();
        foreach (self::SUITES as $suite) {
            $this->assertContains("list-{$suite}-widgets", $names);
            $this->assertContains("get-{$suite}-modules", $names);
            $this->assertContains("set-{$suite}-modules", $names);
        }

        Gate::set_pro_for_tests(false);
        $names = $this->names();
        foreach (self::SUITES as $suite) {
            $this->assertNotContains("list-{$suite}-widgets", $names);
            $this->assertNotContains("set-{$suite}-modules", $names);
        }
        $this->activate('essential-addons');
        $this->assertSame('unknown_operation', $this->write('set-essential-addons-modules', [ 'modules' => [ 'post-grid' => false ] ])['error']['code']);
    }

    public function test_an_inactive_suite_is_skipped_cleanly(): void
    {
        update_option('eael_save_settings', [ 'post-grid' => true ]);
        $before = Snapshot_Store::row_count();

        $catalog = [];
        foreach ((new Theme_Integration())->catalog()['operations'] as $row) {
            $catalog[ $row['name'] ] = $row;
        }
        foreach (self::SUITES as $suite) {
            $this->assertFalse($catalog["get-{$suite}-modules"]['dependency_met'], "{$suite} is not active");
            $this->assertFalse($catalog["list-{$suite}-widgets"]['dependency_met']);
        }

        foreach (self::SUITES as $suite) {
            $this->assertSame('addon_suite_inactive', $this->read("get-{$suite}-modules")['error']['code']);
            $this->assertSame('addon_suite_inactive', $this->read("list-{$suite}-widgets")['error']['code']);
        }
        $out = $this->write('set-essential-addons-modules', [ 'modules' => [ 'post-grid' => false ] ]);
        $this->assertSame('addon_suite_inactive', $out['error']['code']);
        $this->assertSame([ 'post-grid' => true ], get_option('eael_save_settings'), 'Nothing was written');
        $this->assertSame($before, Snapshot_Store::row_count(), 'No snapshot was taken');

        $this->activate('essential-addons');
        $this->assertArrayNotHasKey('error', $this->read('get-essential-addons-modules'));
        $this->assertSame('addon_suite_inactive', $this->read('get-premium-addons-modules')['error']['code'], 'Only the active suite answers');
    }

    public function test_widget_catalog_lists_only_the_suites_registered_widgets(): void
    {
        $this->register_widgets(
            \WPMCP\Tests\Support\ElementorAddons\Essential_Card::class,
            \WPMCP\Tests\Support\ElementorAddons\Premium_Banner::class,
            \WPMCP\Tests\Support\ElementorAddons\Ultimate_Retina::class,
            \Essential_Addons_Elementor\Elements\Fixture_Namespaced::class
        );
        $this->activate(...self::SUITES);

        $out = $this->read('list-essential-addons-widgets');
        $this->assertArrayNotHasKey('error', $out);
        $result = $out['result'];
        $this->assertSame('essential-addons', $result['suite']);
        $names = array_column($result['widgets'], 'name');
        sort($names);
        $this->assertSame([ 'eael-fixture-card', 'eael-fixture-namespaced' ], $names, 'Category or namespace; never core or another suite');
        $this->assertSame(2, $result['count']);
        $row = $result['widgets'][ array_search('eael-fixture-card', array_column($result['widgets'], 'name'), true) ];
        $this->assertSame('Fixture eael-fixture-card', $row['title']);
        $this->assertSame([ 'essential-addons-elementor' ], $row['categories']);
        $this->assertArrayNotHasKey('controls', $row, 'Controls only on request, so a large suite stays a small payload');
        $this->assertGreaterThanOrEqual(1, $row['control_count']);

        $with = $this->read('list-essential-addons-widgets', [ 'include_controls' => true ])['result']['widgets'];
        $this->assertSame('text', $with[0]['controls']['fixture_title']['type']);
        $this->assertSame('Hello', $with[0]['controls']['fixture_title']['default']);

        $one = $this->read('list-premium-addons-widgets', [ 'widget' => 'premium-addon-fixture-banner' ])['result'];
        $this->assertSame([ 'premium-addon-fixture-banner' ], array_column($one['widgets'], 'name'));
        $this->assertArrayHasKey('fixture_title', $one['widgets'][0]['controls'], 'A named widget comes with its controls');

        $this->assertSame([ 'fixture-retina' ], array_column($this->read('list-ultimate-addons-widgets')['result']['widgets'], 'name'));

        $miss = $this->read('list-premium-addons-widgets', [ 'widget' => 'eael-fixture-card' ]);
        $this->assertSame('unknown_widget', $miss['error']['code'], 'Another suite\'s widget is not this suite\'s');
    }

    public function test_essential_addons_modules_read_in_the_suites_own_format(): void
    {
        $this->activate('essential-addons');
        $this->seed_essential_config();
        update_option('eael_save_settings', [ 'post-grid' => 1, 'adv-accordion' => false ]);

        $result = $this->read('get-essential-addons-modules')['result'];
        $this->assertSame('eael_save_settings', $result['option']);
        $this->assertSame(
            [ 'adv-accordion' => false, 'post-grid' => true, 'section-particles' => true ],
            $result['modules'],
            'An unsaved module is on, exactly as the suite\'s own merge with its defaults reads it'
        );
    }

    public function test_essential_addons_toggle_writes_the_option_and_rolls_back_exactly(): void
    {
        $this->activate('essential-addons');
        $this->seed_essential_config();
        $stored = [ 'post-grid' => 1, 'adv-accordion' => '', 'legacy-key' => 1 ];
        update_option('eael_save_settings', $stored);

        $out = $this->write('set-essential-addons-modules', [ 'modules' => [ 'post-grid' => false, 'adv-accordion' => true ] ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);
        $this->assertSame([ 'post-grid' => false, 'adv-accordion' => true ], $out['result']['changed']);
        $this->assertSame(
            [ 'post-grid' => false, 'adv-accordion' => true, 'legacy-key' => 1 ],
            get_option('eael_save_settings'),
            'Only the toggled keys change, as booleans the way the suite saves them'
        );

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($stored, get_option('eael_save_settings'), 'Byte for byte');
    }

    public function test_rollback_removes_an_option_the_toggle_created(): void
    {
        $this->activate('essential-addons');
        $this->seed_essential_config();
        $this->assertFalse(get_option('eael_save_settings'));

        $out = $this->write('set-essential-addons-modules', [ 'modules' => [ 'section-particles' => false ] ]);
        $this->assertSame([ 'section-particles' => false ], get_option('eael_save_settings'));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertFalse(get_option('eael_save_settings'), 'The option is absent again, not an empty array');
    }

    public function test_premium_addons_keeps_its_enabled_only_shape_and_seeds_defaults(): void
    {
        $this->activate('premium-addons');
        $this->seed_premium_keys();

        $read = $this->read('get-premium-addons-modules')['result'];
        $this->assertSame('pa_save_settings', $read['option']);
        $this->assertSame(
            [ 'premium-ai-abilities' => false, 'premium-banner' => true, 'premium-site-logo' => true, 'svg_premium-site-logo' => true ],
            $read['modules'],
            'With no saved option every module is on except the opt-in AI abilities'
        );

        wp_cache_set('pa_elements', [ 'stale' => true ], 'premium_addons');
        $out = $this->write('set-premium-addons-modules', [ 'modules' => [ 'premium-banner' => false ] ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame(
            [ 'premium-site-logo' => true, 'svg_premium-site-logo' => true ],
            get_option('pa_save_settings'),
            'Only enabled keys, each => true; the other defaults stay on'
        );
        $this->assertFalse(wp_cache_get('pa_elements', 'premium_addons'), 'The suite\'s cached module map is dropped');

        $second = $this->write('set-premium-addons-modules', [ 'modules' => [ 'premium-ai-abilities' => true ] ]);
        $this->assertSame(
            [ 'premium-site-logo' => true, 'svg_premium-site-logo' => true, 'premium-ai-abilities' => true ],
            get_option('pa_save_settings')
        );

        wp_cache_set('pa_elements', [ 'stale' => true ], 'premium_addons');
        $this->assertTrue(Rollback_Service::restore_operation((string) $second['operation_id']));
        $this->assertSame([ 'premium-site-logo' => true, 'svg_premium-site-logo' => true ], get_option('pa_save_settings'));
        $this->assertFalse(wp_cache_get('pa_elements', 'premium_addons'), 'A rollback drops the cached module map too');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertFalse(get_option('pa_save_settings'), 'Back to no saved option at all');
    }

    public function test_premium_addons_reads_a_saved_option_as_absent_means_off(): void
    {
        $this->activate('premium-addons');
        $this->seed_premium_keys();
        update_option('pa_save_settings', [ 'premium-banner' => true ]);

        $this->assertSame(
            [ 'premium-ai-abilities' => false, 'premium-banner' => true, 'premium-site-logo' => false, 'svg_premium-site-logo' => false ],
            $this->read('get-premium-addons-modules')['result']['modules']
        );
    }

    public function test_ultimate_addons_uses_slug_or_disabled_values(): void
    {
        $this->activate('ultimate-addons');
        $this->seed_ultimate_list();
        $stored = [ 'Retina' => 'retina' ];
        update_option('_hfe_widgets', $stored);

        $read = $this->read('get-ultimate-addons-modules')['result'];
        $this->assertSame('_hfe_widgets', $read['option']);
        $this->assertSame([ 'Cart' => false, 'Copyright' => true, 'Retina' => true ], $read['modules']);

        $out = $this->write('set-ultimate-addons-modules', [ 'modules' => [ 'Retina' => false, 'Cart' => true ] ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame([ 'Retina' => 'disabled', 'Cart' => 'Cart' ], get_option('_hfe_widgets'));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($stored, get_option('_hfe_widgets'));
    }

    public function test_an_unknown_module_refuses_the_whole_batch_before_any_write(): void
    {
        $this->activate('essential-addons');
        $this->seed_essential_config();
        update_option('eael_save_settings', [ 'post-grid' => true ]);
        $before = Snapshot_Store::row_count();

        $out = $this->write('set-essential-addons-modules', [ 'modules' => [ 'post-grid' => false, 'no-such-module' => true ] ]);
        $this->assertSame('unknown_module', $out['error']['code']);
        $this->assertSame('no-such-module', $out['error']['data']['module']);
        $this->assertSame([ 'post-grid' => true ], get_option('eael_save_settings'));
        $this->assertSame($before, Snapshot_Store::row_count());

        $bad = $this->write('set-essential-addons-modules', [ 'modules' => [ 'post-grid' => 'off' ] ]);
        $this->assertSame('invalid_args', $bad['error']['code'], 'A toggle is a boolean');
    }

    public function test_a_batch_that_changes_nothing_writes_nothing(): void
    {
        $this->activate('ultimate-addons');
        $this->seed_ultimate_list();
        update_option('_hfe_widgets', [ 'Retina' => 'retina' ]);
        $before = Snapshot_Store::row_count();

        $out = $this->write('set-ultimate-addons-modules', [ 'modules' => [ 'Retina' => true ] ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertFalse($out['recoverable']);
        $this->assertSame([], $out['result']['changed']);
        $this->assertSame([ 'Retina' => 'retina' ], get_option('_hfe_widgets'));
        $this->assertSame($before, Snapshot_Store::row_count(), 'No undo point for a no-op');
    }

    public function test_module_toggles_require_manage_options(): void
    {
        $this->activate('essential-addons');
        $this->seed_essential_config();
        $user = self::factory()->user->create([ 'role' => 'editor' ]);
        get_userdata($user)->add_cap('edit_theme_options');
        wp_set_current_user($user);

        $out = $this->write('set-essential-addons-modules', [ 'modules' => [ 'post-grid' => false ] ]);
        $this->assertSame('operation_denied', $out['error']['code']);
        $this->assertFalse(get_option('eael_save_settings'));
    }

    public function test_jet_elements_widget_catalog_goes_by_class_name_not_the_shared_category(): void
    {
        $this->register_widgets(
            \Elementor\Jet_Elements_Fixture_Banner::class,
            \Elementor\Jet_Fixture_Unfold::class,
            \WPMCP\Tests\Support\ElementorAddons\Essential_Card::class
        );
        $this->activate('jet-elements');

        $result = $this->read('list-jet-elements-widgets')['result'];
        $this->assertSame('jet-elements', $result['suite']);
        $this->assertSame([ 'jet-fixture-banner' ], array_column($result['widgets'], 'name'), 'Another Crocoblock plugin in the cherry category is not JetElements');
        $this->assertSame([ 'cherry' ], $result['widgets'][0]['categories']);
        $this->assertArrayNotHasKey('controls', $result['widgets'][0]);

        $one = $this->read('list-jet-elements-widgets', [ 'widget' => 'jet-fixture-banner' ])['result'];
        $this->assertArrayHasKey('fixture_title', $one['widgets'][0]['controls']);
        $this->assertSame('unknown_widget', $this->read('list-jet-elements-widgets', [ 'widget' => 'jet-fixture-unfold' ])['error']['code']);
    }

    public function test_jet_elements_modules_are_all_on_while_nothing_is_saved(): void
    {
        $this->activate('jet-elements');
        $this->seed_jet_widgets();

        $result = $this->read('get-jet-elements-modules')['result'];
        $this->assertSame('jet-elements-settings', $result['option']);
        $this->assertSame(
            [ 'jet-elements-banner' => true, 'jet-elements-button' => true, 'jet-elements-timeline' => true, 'section_parallax' => true ],
            $result['modules'],
            'With no saved widget map JetElements registers every widget, and the parallax extension defaults on'
        );

        update_option('jet-elements-settings', [ 'api_key' => 'secret', 'avaliable_widgets' => [] ]);
        \Jet_Elements_Settings::get_instance()->settings = null;
        $this->assertTrue($this->read('get-jet-elements-modules')['result']['modules']['jet-elements-button'], 'An empty saved map still means every widget');
    }

    public function test_jet_elements_reads_a_saved_map_the_way_jet_elements_does(): void
    {
        $this->activate('jet-elements');
        $this->seed_jet_widgets();
        update_option('jet-elements-settings', [
            'api_key'              => 'secret',
            'avaliable_widgets'    => [ 'jet-elements-banner' => 'true', 'jet-elements-button' => 'false', 'jet-elements-legacy' => 'true' ],
            'avaliable_extensions' => [],
        ]);

        $result = $this->read('get-jet-elements-modules')['result'];
        $this->assertSame(
            [
                'jet-elements-banner'   => true,
                'jet-elements-button'   => false,
                'jet-elements-legacy'   => true,
                'jet-elements-timeline' => false,
                'section_parallax'      => false,
            ],
            $result['modules'],
            'Once a widget map is saved a missing widget is off; a saved extension map without the key is off'
        );
        $this->assertStringNotContainsString('secret', (string) wp_json_encode($result), 'The other settings in the option, API keys among them, are never read out');
    }

    public function test_jet_elements_toggle_writes_only_the_module_maps_and_rolls_back_exactly(): void
    {
        $this->activate('jet-elements');
        $this->seed_jet_widgets();
        $stored = [
            'api_key'            => 'secret',
            'widgets_load_level' => 100,
            'avaliable_widgets'  => [ 'jet-elements-banner' => 'true', 'jet-elements-button' => 'true', 'jet-elements-timeline' => 'true' ],
        ];
        update_option('jet-elements-settings', $stored);
        $settings = \Jet_Elements_Settings::get_instance();
        $this->assertSame('true', $settings->get('avaliable_widgets')['jet-elements-button'], 'JetElements cached its settings for the request');

        $out = $this->write('set-jet-elements-modules', [ 'modules' => [ 'jet-elements-button' => false, 'section_parallax' => false, 'jet-elements-banner' => true ] ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);
        $this->assertSame([ 'jet-elements-button' => false, 'section_parallax' => false ], $out['result']['changed']);
        $this->assertSame(
            [
                'api_key'              => 'secret',
                'widgets_load_level'   => 100,
                'avaliable_widgets'    => [ 'jet-elements-banner' => 'true', 'jet-elements-button' => 'false', 'jet-elements-timeline' => 'true' ],
                'avaliable_extensions' => [ 'section_parallax' => 'false' ],
            ],
            get_option('jet-elements-settings'),
            'Every other setting is kept; toggles are stored as the strings JetElements saves'
        );
        $this->assertSame('false', $settings->get('avaliable_widgets')['jet-elements-button'], 'The JetElements settings cache is dropped after the write');
        $this->assertFalse($out['result']['modules']['jet-elements-button']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($stored, get_option('jet-elements-settings'), 'Byte for byte');
        $this->assertSame('true', $settings->get('avaliable_widgets')['jet-elements-button'], 'A rollback drops the settings cache too');
    }

    public function test_jet_elements_first_toggle_saves_the_full_widget_map(): void
    {
        $this->activate('jet-elements');
        $this->seed_jet_widgets();
        $this->assertFalse(get_option('jet-elements-settings'));

        $out = $this->write('set-jet-elements-modules', [ 'modules' => [ 'jet-elements-timeline' => false ] ]);
        $this->assertSame(
            [ 'avaliable_widgets' => [ 'jet-elements-banner' => 'true', 'jet-elements-button' => 'true', 'jet-elements-timeline' => 'false' ] ],
            get_option('jet-elements-settings'),
            'Saving only the toggled widget would switch every other widget off, so the defaults are written out'
        );
        $this->assertTrue($this->read('get-jet-elements-modules')['result']['modules']['section_parallax'], 'The untouched extension keeps its default');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertFalse(get_option('jet-elements-settings'), 'The option is absent again');
    }

    public function test_jet_elements_refuses_an_unknown_module_and_skips_a_no_op(): void
    {
        $this->activate('jet-elements');
        $this->seed_jet_widgets();
        $before = Snapshot_Store::row_count();

        $bad = $this->write('set-jet-elements-modules', [ 'modules' => [ 'jet-elements-banner' => false, 'jet-banner' => false ] ]);
        $this->assertSame('unknown_module', $bad['error']['code'], 'Modules go by file slug, not the Elementor widget name');
        $this->assertFalse(get_option('jet-elements-settings'));

        $noop = $this->write('set-jet-elements-modules', [ 'modules' => [ 'jet-elements-banner' => true ] ]);
        $this->assertSame([], $noop['result']['changed']);
        $this->assertFalse(get_option('jet-elements-settings'));
        $this->assertSame($before, Snapshot_Store::row_count());
    }

    /**
     * Widget reads default to a control summary, the same in every suite;
     * complete definitions come only for one named widget (detail:"full").
     */
    public function test_widget_controls_are_summarized_unless_one_widget_asks_for_full_detail(): void
    {
        $this->register_widgets(
            \WPMCP\Tests\Support\ElementorAddons\Essential_Card::class,
            \WPMCP\Tests\Support\ElementorAddons\Premium_Banner::class,
            \WPMCP\Tests\Support\ElementorAddons\Ultimate_Retina::class,
            \Elementor\Jet_Elements_Fixture_Banner::class
        );
        $this->activate(...self::SUITES);
        $fixtures = [
            'essential-addons' => 'eael-fixture-card',
            'premium-addons'   => 'premium-addon-fixture-banner',
            'ultimate-addons'  => 'fixture-retina',
            'jet-elements'     => 'jet-fixture-banner',
        ];
        $expected = [
            'fixture_title'         => [ 'type' => 'text', 'label' => 'Title', 'default' => 'Hello' ],
            'fixture_link'          => [ 'type' => 'text', 'label' => 'Link' ],
            'fixture_tag'           => [ 'type' => 'select', 'label' => 'Title HTML Tag', 'default' => 'h5', 'options' => [ 'h1', 'h2', 'h5', 'div' ] ],
            'fixture_height'        => [ 'type' => 'slider', 'label' => 'Height', 'default' => [ 'unit' => 'px', 'size' => 300 ] ],
            'fixture_height_tablet' => [ 'type' => 'slider', 'label' => 'Height' ],
            'fixture_items'         => [ 'type' => 'repeater', 'label' => 'Items' ],
        ];

        foreach ($fixtures as $suite => $widget) {
            $op = "list-{$suite}-widgets";

            $list = $this->read($op, [ 'include_controls' => true ])['result'];
            $this->assertSame('summary', $list['detail'], $suite);
            $this->assertSame($expected, $list['widgets'][0]['controls'], "{$suite}: type, label, default, option keys; no layout controls, empty values or editor internals");
            $this->assertSame(8, $list['widgets'][0]['control_count'], "{$suite}: the count is still every control");

            $one = $this->read($op, [ 'widget' => $widget ])['result'];
            $this->assertSame('summary', $one['detail'], "{$suite}: a named widget defaults to the summary");
            $this->assertSame($expected, $one['widgets'][0]['controls']);

            $full = $this->read($op, [ 'widget' => $widget, 'detail' => 'full' ])['result'];
            $this->assertSame('full', $full['detail']);
            $controls = $full['widgets'][0]['controls'];
            $this->assertSame(array_keys((new \ReflectionClass(\WPMCP\Tests\Support\ElementorAddons\Essential_Card::class))->newInstanceWithoutConstructor()->get_controls()), array_keys($controls), "{$suite}: full detail keeps every control, layout ones too");
            $this->assertSame([ 'fixture_title!' => '' ], $controls['fixture_tag']['condition']);
            $this->assertSame([ 'h1' => 'H1', 'h2' => 'H2', 'h5' => 'H5', 'div' => 'div' ], $controls['fixture_tag']['options']);
            $this->assertSame('fixture_height', $controls['fixture_height_tablet']['parent']);
            $this->assertArrayHasKey('item_text', $controls['fixture_items']['fields']);

            $refused = $this->read($op, [ 'detail' => 'full', 'include_controls' => true ]);
            $this->assertSame('full_detail_needs_widget', $refused['error']['code'], "{$suite}: no full detail for the whole list");
            $this->assertStringContainsString('"widget"', $refused['error']['message'], 'The refusal says how to ask for one widget');

            $this->assertArrayNotHasKey('detail', $this->read($op)['result'], 'The bare list carries no controls, so no detail');
            $this->assertArrayHasKey('error', $this->read($op, [ 'widget' => $widget, 'detail' => 'everything' ]), 'An unknown detail is refused');
        }
    }
}
