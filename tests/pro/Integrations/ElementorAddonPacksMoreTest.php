<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/elementor-addon-more-stubs.php';

/**
 * The second #653 slice of the Elementor addon suite packs: Happy Addons,
 * Royal Elementor Addons and PowerPack Lite, as the same three paid-tier ops
 * per suite on the theme dispatcher pair (list-{suite}-widgets,
 * get-{suite}-modules, set-{suite}-modules).
 *
 * Each suite keeps its module switches in its own storage, read here exactly
 * as the suite reads it and written in the suite's own format:
 *
 *  - Happy Addons: three lists of the keys that are OFF.
 *  - Royal Elementor Addons: one option per module, 'on' or not.
 *  - PowerPack Lite: one list of the widgets that are ON, or 'disabled'.
 *
 * Presence is driven through the wpmcp_{suite}_active filters, so no suite
 * has to be installed into the shared test core.
 */
class ElementorAddonPacksMoreTest extends \WP_UnitTestCase
{
    private const SUITES = [ 'happy-addons', 'royal-addons', 'powerpack' ];

    private const HAPPY_OPTIONS = [ 'happyaddons_inactive_widgets', 'happyaddons_inactive_features', 'happyaddons_inactive_extensions' ];

    private const ROYAL_SLUGS = [ 'grid', 'button', 'promo-box', 'post-title', 'post-media', 'toggle-all' ];

    /** @var string[] widget names registered by a test, unregistered in tearDown */
    private array $widgets = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $this->reset_catalogs();
    }

    protected function tearDown(): void
    {
        foreach ([ 'happy_addons', 'royal_addons', 'powerpack' ] as $suite) {
            remove_all_filters("wpmcp_{$suite}_active");
        }
        if ([] !== $this->widgets && class_exists('\\Elementor\\Plugin')) {
            foreach ($this->widgets as $name) {
                \Elementor\Plugin::instance()->widgets_manager->unregister($name);
            }
        }
        $this->widgets = [];
        foreach (self::HAPPY_OPTIONS as $option) {
            delete_option($option);
        }
        foreach (self::ROYAL_SLUGS as $slug) {
            delete_option('wpr-element-' . $slug);
        }
        delete_option('pp_elementor_modules');
        $this->reset_catalogs();
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function reset_catalogs(): void
    {
        \Happy_Addons\Elementor\Classes\Widgets_Manager::$map           = [];
        \Happy_Addons\Elementor\Classes\Extensions_Manager::$features   = [];
        \Happy_Addons\Elementor\Classes\Extensions_Manager::$extensions = [];
        \WprAddons\Classes\Utilities::$modules                          = [];
        \WprAddons\Classes\Utilities::$theme                            = [];
        \WprAddons\Classes\Utilities::$woo                              = [];
        unset($GLOBALS['wpmcp_test_powerpack']);
    }

    private function activate(string ...$suites): void
    {
        foreach ($suites as $suite) {
            add_filter('wpmcp_' . str_replace('-', '_', $suite) . '_active', '__return_true');
        }
    }

    private function read(string $op, array $args = []): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function seed_happy(): void
    {
        \Happy_Addons\Elementor\Classes\Widgets_Manager::$map = [
            'infobox'  => [ 'cat' => 'general', 'is_active' => true, 'title' => 'Info Box' ],
            'card'     => [ 'cat' => 'creative', 'is_active' => false, 'title' => 'Card' ],
            'calendly' => [ 'cat' => 'social-media', 'is_active' => true, 'title' => 'Calendly' ],
        ];
        \Happy_Addons\Elementor\Classes\Extensions_Manager::$features = [
            'foreground-overlay' => [ 'title' => 'Foreground Overlay', 'is_pro' => false ],
            'live-copy'          => [ 'title' => 'Live Copy', 'is_pro' => false, 'always_on' => true ],
        ];
        \Happy_Addons\Elementor\Classes\Extensions_Manager::$extensions = [
            'foreground-overlay' => [ 'title' => 'Foreground Overlay', 'is_pro' => false ],
            'text-stroke'        => [ 'title' => 'Text Stroke', 'is_pro' => false ],
        ];
    }

    private function seed_royal(): void
    {
        \WprAddons\Classes\Utilities::$modules = [
            'Post Grid/Slider/Carousel' => [ 'grid', 'https://example.com/grid', '', '' ],
            'Button'                    => [ 'button', '', '', '' ],
            'Promo Box'                 => [ 'promo-box', '', '', '' ],
        ];
        \WprAddons\Classes\Utilities::$theme = [
            'Post Title' => [ 'post-title', '', '', '' ],
            'Post Media' => [ 'post-media', '', '', '' ],
        ];
    }

    private function seed_powerpack(): void
    {
        $GLOBALS['wpmcp_test_powerpack'] = [
            'modules'     => [
                'pp-buttons'        => 'Buttons',
                'pp-image-hotspots' => 'Image Hotspots',
                'pp-info-box'       => 'Info Box',
                'pp-twitter-tweet'  => 'Twitter Tweet',
            ],
            'default_off' => [ 'pp-twitter-tweet' ],
        ];
    }

    private function skip_on_multisite(): void
    {
        if (is_multisite()) {
            $this->markTestSkipped('PowerPack reads its network copy on multisite; the pack refuses there.');
        }
    }

    public function test_every_new_suite_registers_three_paid_ops_and_is_skipped_while_inactive(): void
    {
        $catalog = [];
        foreach ((new Theme_Integration())->catalog()['operations'] as $row) {
            $catalog[ $row['name'] ] = $row;
        }
        foreach (self::SUITES as $suite) {
            foreach ([ "list-{$suite}-widgets", "get-{$suite}-modules", "set-{$suite}-modules" ] as $op) {
                $this->assertArrayHasKey($op, $catalog, $op);
                $this->assertFalse($catalog[ $op ]['dependency_met'], "{$suite} is not active");
            }
            $this->assertSame('addon_suite_inactive', $this->read("get-{$suite}-modules")['error']['code']);
            $this->assertSame('addon_suite_inactive', $this->read("list-{$suite}-widgets")['error']['code']);
        }

        $before = Snapshot_Store::row_count();
        $this->assertSame('addon_suite_inactive', $this->write('set-happy-addons-modules', [ 'modules' => [ 'infobox' => false ] ])['error']['code']);
        $this->assertFalse(get_option('happyaddons_inactive_widgets'), 'Nothing was written');
        $this->assertSame($before, Snapshot_Store::row_count(), 'No snapshot was taken');

        Gate::set_pro_for_tests(false);
        $names = array_column((new Theme_Integration())->catalog()['operations'], 'name');
        foreach (self::SUITES as $suite) {
            $this->assertNotContains("set-{$suite}-modules", $names, 'Paid tier');
        }
    }

    public function test_widget_catalogs_go_by_each_suites_class_namespace(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }
        require_once __DIR__ . '/../../support/elementor-addon-widgets.php';
        require_once __DIR__ . '/../../support/elementor-addon-more-widgets.php';
        $manager = \Elementor\Plugin::instance()->widgets_manager;
        $manager->get_widget_types();
        foreach (
            [
                \Happy_Addons\Elementor\Widget\Fixture_Infobox::class,
                \WprAddons\Modules\FixturePromo\Widgets\Wpr_Fixture_Promo::class,
                \PowerpackElementsLite\Modules\FixtureBox\Widgets\Fixture_Box::class,
            ] as $class
        ) {
            $widget = new $class();
            $manager->register($widget);
            $this->widgets[] = $widget->get_name();
        }
        $this->activate(...self::SUITES);

        $expected = [ 'happy-addons' => 'ha-fixture-infobox', 'royal-addons' => 'wpr-fixture-promo', 'powerpack' => 'pp-fixture-box' ];
        foreach ($expected as $suite => $name) {
            $result = $this->read("list-{$suite}-widgets")['result'];
            $this->assertSame($suite, $result['suite']);
            $this->assertSame([ $name ], array_column($result['widgets'], 'name'), "Only {$suite}'s own widget, never core or another suite");
            $this->assertArrayNotHasKey('controls', $result['widgets'][0]);

            $one = $this->read("list-{$suite}-widgets", [ 'widget' => $name ])['result'];
            $this->assertArrayHasKey('fixture_title', $one['widgets'][0]['controls'], 'A named widget comes with its controls');
        }
        $this->assertSame('unknown_widget', $this->read('list-happy-addons-widgets', [ 'widget' => 'wpr-fixture-promo' ])['error']['code']);
    }

    public function test_happy_addons_reads_its_three_inactive_lists(): void
    {
        $this->activate('happy-addons');
        $this->seed_happy();

        $read = $this->read('get-happy-addons-modules')['result'];
        $this->assertSame(
            [
                'calendly'                     => true,
                'card'                         => true,
                'extension:foreground-overlay' => true,
                'extension:text-stroke'        => true,
                'feature:foreground-overlay'   => true,
                'infobox'                      => true,
            ],
            $read['modules'],
            'Nothing saved: Happy registers every widget, and the always-on feature is not a switch at all'
        );

        update_option('happyaddons_inactive_widgets', [ 'card', 'retired-widget' ]);
        update_option('happyaddons_inactive_extensions', [ 'text-stroke' ]);
        update_option('happyaddons_inactive_features', [ 'live-copy' ]);
        $read = $this->read('get-happy-addons-modules')['result']['modules'];
        $this->assertFalse($read['card']);
        $this->assertFalse($read['retired-widget'], 'A saved key the suite no longer lists is still shown');
        $this->assertTrue($read['infobox']);
        $this->assertFalse($read['extension:text-stroke']);
        $this->assertTrue($read['feature:foreground-overlay']);
        $this->assertArrayNotHasKey('feature:live-copy', $read, 'Always on, whatever the list says');
    }

    public function test_happy_addons_toggle_writes_only_the_touched_lists_and_rolls_back_exactly(): void
    {
        $this->activate('happy-addons');
        $this->seed_happy();
        $stored = [ 'card', 'calendly' ];
        update_option('happyaddons_inactive_widgets', $stored);

        $out = $this->write('set-happy-addons-modules', [ 'modules' => [ 'infobox' => false, 'card' => true, 'extension:text-stroke' => false, 'calendly' => false ] ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);
        $this->assertSame([ 'infobox' => false, 'card' => true, 'extension:text-stroke' => false ], $out['result']['changed']);
        $this->assertSame([ 'calendly', 'infobox' ], get_option('happyaddons_inactive_widgets'), 'Order kept, a key switched back on leaves the list');
        $this->assertSame([ 'text-stroke' ], get_option('happyaddons_inactive_extensions'));
        $this->assertFalse(get_option('happyaddons_inactive_features'), 'An untouched list is not written');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($stored, get_option('happyaddons_inactive_widgets'), 'Byte for byte');
        $this->assertFalse(get_option('happyaddons_inactive_extensions'), 'A list the toggle created is gone again');

        $refused = $this->write('set-happy-addons-modules', [ 'modules' => [ 'feature:live-copy' => false ] ]);
        $this->assertSame('unknown_module', $refused['error']['code'], 'An always-on feature cannot be switched off');
    }

    public function test_royal_addons_reads_one_option_per_module(): void
    {
        $this->activate('royal-addons');
        $this->seed_royal();

        $read = $this->read('get-royal-addons-modules')['result'];
        $this->assertSame([ 'button' => true, 'grid' => true, 'post-media' => true, 'post-title' => true, 'promo-box' => true ], $read['modules'], 'An unsaved module is on');

        update_option('wpr-element-grid', '');
        update_option('wpr-element-button', 'off');
        $modules = $this->read('get-royal-addons-modules')['result']['modules'];
        $this->assertFalse($modules['grid'], 'An unchecked box saves an empty value');
        $this->assertFalse($modules['button'], 'Anything but on is off');
        $this->assertTrue($modules['promo-box']);

        update_option('wpr-element-post-title', '');
        update_option('wpr-element-post-media', '');
        $modules = $this->read('get-royal-addons-modules')['result']['modules'];
        $this->assertTrue($modules['post-title'], 'Every theme builder module off with no toggle-all saved: Royal loads the whole group');
        $this->assertTrue($modules['post-media']);

        update_option('wpr-element-toggle-all', '');
        $this->assertFalse($this->read('get-royal-addons-modules')['result']['modules']['post-title'], 'With toggle-all saved, off is off');
    }

    public function test_royal_addons_toggle_writes_its_own_values_and_rolls_back_exactly(): void
    {
        $this->activate('royal-addons');
        $this->seed_royal();
        update_option('wpr-element-button', '');

        $out = $this->write('set-royal-addons-modules', [ 'modules' => [ 'grid' => false, 'button' => true, 'promo-box' => true ] ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);
        $this->assertSame([ 'grid' => false, 'button' => true ], $out['result']['changed']);
        $this->assertSame('', get_option('wpr-element-grid'), 'Off the way the settings form saves it');
        $this->assertSame('on', get_option('wpr-element-button'));
        $this->assertFalse(get_option('wpr-element-promo-box'), 'An unchanged module is not written');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertFalse(get_option('wpr-element-grid'), 'An option the toggle created is gone again');
        $this->assertSame('', get_option('wpr-element-button'));
    }

    public function test_royal_addons_switching_a_whole_group_off_keeps_it_off(): void
    {
        $this->activate('royal-addons');
        $this->seed_royal();

        $out = $this->write('set-royal-addons-modules', [ 'modules' => [ 'post-title' => false, 'post-media' => false ] ]);
        $this->assertArrayNotHasKey('error', $out);
        $modules = $this->read('get-royal-addons-modules')['result']['modules'];
        $this->assertFalse($modules['post-title'], 'Royal would load an all-off group again unless toggle-all is saved');
        $this->assertFalse($modules['post-media']);
        $this->assertSame('', get_option('wpr-element-toggle-all'));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertFalse(get_option('wpr-element-toggle-all'));
        $this->assertFalse(get_option('wpr-element-post-title'));
    }

    public function test_royal_addons_a_toggle_in_a_fallback_group_keeps_the_rest_on(): void
    {
        $this->activate('royal-addons');
        $this->seed_royal();
        update_option('wpr-element-post-title', '');
        update_option('wpr-element-post-media', '');

        $out = $this->write('set-royal-addons-modules', [ 'modules' => [ 'post-title' => false ] ]);
        $this->assertSame([ 'post-title' => false ], $out['result']['changed']);
        $modules = $out['result']['modules'];
        $this->assertFalse($modules['post-title']);
        $this->assertTrue($modules['post-media'], 'The module Royal was loading through its all-off fallback stays on');
        $this->assertSame('on', get_option('wpr-element-post-media'));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('', get_option('wpr-element-post-media'));
    }

    public function test_powerpack_reads_its_enabled_list(): void
    {
        $this->skip_on_multisite();
        $this->activate('powerpack');
        $this->seed_powerpack();

        $read = $this->read('get-powerpack-modules')['result'];
        $this->assertSame('pp_elementor_modules', $read['option']);
        $this->assertSame(
            [ 'pp-buttons' => true, 'pp-image-hotspots' => true, 'pp-info-box' => true, 'pp-twitter-tweet' => false ],
            $read['modules'],
            'Nothing saved: every widget but the ones that ship off'
        );

        update_option('pp_elementor_modules', [ 'pp-buttons', 'pp-hotspots' ]);
        $this->assertSame(
            [ 'pp-buttons' => true, 'pp-image-hotspots' => true, 'pp-info-box' => false, 'pp-twitter-tweet' => false ],
            $this->read('get-powerpack-modules')['result']['modules'],
            'A legacy name reads as its current name'
        );

        update_option('pp_elementor_modules', 'disabled');
        $this->assertSame([], array_filter($this->read('get-powerpack-modules')['result']['modules']), 'The disabled sentinel means every widget off');
    }

    public function test_powerpack_toggle_keeps_its_list_shape_and_rolls_back_exactly(): void
    {
        $this->skip_on_multisite();
        $this->activate('powerpack');
        $this->seed_powerpack();

        $first = $this->write('set-powerpack-modules', [ 'modules' => [ 'pp-info-box' => false ] ]);
        $this->assertArrayNotHasKey('error', $first);
        $this->assertSame([ 'pp-buttons', 'pp-image-hotspots' ], get_option('pp_elementor_modules'), 'The first save writes out the defaults that were live');

        update_option('pp_elementor_modules', [ 'pp-hotspots', 'pp-buttons', 'custom-kept' ]);
        $stored = get_option('pp_elementor_modules');
        $out    = $this->write('set-powerpack-modules', [ 'modules' => [ 'pp-buttons' => false, 'pp-twitter-tweet' => true ] ]);
        $this->assertSame([ 'pp-buttons' => false, 'pp-twitter-tweet' => true ], $out['result']['changed']);
        $this->assertSame([ 'pp-image-hotspots', 'custom-kept', 'pp-twitter-tweet' ], get_option('pp_elementor_modules'), 'Legacy names saved as their current name, unknown names kept');

        $off = $this->write('set-powerpack-modules', [ 'modules' => [ 'pp-image-hotspots' => false, 'custom-kept' => false, 'pp-twitter-tweet' => false ] ]);
        $this->assertSame('disabled', get_option('pp_elementor_modules'), 'Nothing on is the string disabled, never an empty list');

        $this->assertTrue(Rollback_Service::restore_operation((string) $off['operation_id']));
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($stored, get_option('pp_elementor_modules'), 'Byte for byte');
        $this->assertTrue(Rollback_Service::restore_operation((string) $first['operation_id']));
        $this->assertFalse(get_option('pp_elementor_modules'), 'Back to no saved option at all');
    }

    public function test_powerpack_refuses_on_multisite(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Multisite only');
        }
        $this->activate('powerpack');
        $this->seed_powerpack();

        $this->assertSame('addon_suite_multisite', $this->read('get-powerpack-modules')['error']['code']);
        $this->assertSame('addon_suite_multisite', $this->write('set-powerpack-modules', [ 'modules' => [ 'pp-buttons' => false ] ])['error']['code']);
        $this->assertFalse(get_option('pp_elementor_modules'));
    }

    public function test_unknown_modules_and_no_ops_write_nothing(): void
    {
        $this->activate('happy-addons', 'royal-addons');
        $this->seed_happy();
        $this->seed_royal();
        $before = Snapshot_Store::row_count();

        $bad = $this->write('set-royal-addons-modules', [ 'modules' => [ 'grid' => false, 'no-such-module' => true ] ]);
        $this->assertSame('unknown_module', $bad['error']['code']);
        $this->assertFalse(get_option('wpr-element-grid'));

        $noop = $this->write('set-happy-addons-modules', [ 'modules' => [ 'infobox' => true ] ]);
        $this->assertArrayNotHasKey('error', $noop);
        $this->assertFalse($noop['recoverable']);
        $this->assertSame([], $noop['result']['changed']);
        $this->assertFalse(get_option('happyaddons_inactive_widgets'));
        $this->assertSame($before, Snapshot_Store::row_count(), 'No undo point for a refusal or a no-op');
    }
}
