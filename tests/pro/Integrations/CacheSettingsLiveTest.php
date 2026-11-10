<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * The cache settings ops (issue #692) through the real WP Rocket,
 * Perfmatters and FlyingPress, each loaded by tests/bootstrap.php from its
 * local copy under WPMCP_LIVE_CACHE_SETTINGS=1 (bin/test-local.sh
 * --live-cache-settings). Each plugin has its own group, run only when its
 * copy is present, so --fail-on-skipped holds.
 *
 * What only the real plugin shows: WP Rocket reads the written values back
 * through get_rocket_option() and its preload reset runs through its own
 * subscriber; Perfmatters stores what its form would; FlyingPress's Config
 * and its config listeners see the write and the rollback.
 */
class CacheSettingsLiveTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('WPMCP_LIVE_CACHE_SETTINGS')) {
            $this->markTestSkipped('Runs in the live cache settings job (bin/test-local.sh --live-cache-settings).');
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_cache_settings_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_cache_settings_write');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function call(string $half, string $op, array $args = []): array
    {
        $theme = new Theme_Integration();
        $out   = 'read' === $half
            ? $theme->handle_read([ 'operation' => $op, 'args' => $args ])
            : $theme->handle_write([ 'operation' => $op, 'args' => $args ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    /**
     * @group cache-wp-rocket-live
     */
    public function test_wp_rocket_settings_are_read_written_and_rolled_back_through_wp_rocket(): void
    {
        $this->assertTrue(defined('WP_ROCKET_VERSION'), 'WP Rocket is loaded');
        update_option('wp_rocket_settings', array_merge((array) get_option('wp_rocket_settings', []), [
            'consumer_key'       => 'live-license-0123456789',
            'cloudflare_api_key' => 'live-cf-key-0123456789',
            'delay_js'           => 0,
            'lazyload'           => 0,
            'manual_preload'     => 1,
            'cdn'                => 0,
            'cdn_cnames'         => [],
            'cdn_zone'           => [],
            'remove_unused_css'  => 0,
            'async_css'          => 0,
            // WP Rocket's save listener fills this default in on any save
            // that lacks it (a rollback too), as a real install already has it.
            'cdn_state'          => 'nothing',
        ]));
        $before = get_option('wp_rocket_settings');

        $read = $this->call('read', 'get-wp-rocket-settings')['result'];
        $this->assertSame(WP_ROCKET_VERSION, $read['version']);
        $this->assertFalse($read['summary']['delay_js']);
        $json = (string) wp_json_encode($read);
        $this->assertStringNotContainsString('live-license-0123456789', $json);
        $this->assertStringNotContainsString('live-cf-key-0123456789', $json);

        $out = $this->call('write', 'set-wp-rocket-settings', [ 'values' => [
            'delay_js'            => true,
            'lazyload'            => true,
            'delay_js_exclusions' => [ 'jquery' ],
            'cdn'                 => true,
            'cdn_cnames'          => [ 'cdn.example.org' ],
        ] ]);
        $this->assertTrue($out['recoverable']);
        $this->assertSame(1, (int) get_rocket_option('delay_js'), 'WP Rocket reads the write back');
        $this->assertSame([ 'jquery' ], get_rocket_option('delay_js_exclusions'));
        $this->assertSame([ 'all' ], get_rocket_option('cdn_zone'));
        $this->assertSame('live-license-0123456789', get_option('wp_rocket_settings')['consumer_key'], 'the stored license stays');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, get_option('wp_rocket_settings'));
        $this->assertSame(0, (int) get_rocket_option('delay_js'));

        $resets = did_action('rocket_reset_preload');
        $preload = $this->call('write', 'preload-wp-rocket-cache');
        $this->assertFalse($preload['recoverable']);
        $this->assertSame($resets + 1, did_action('rocket_reset_preload'));
        $this->assertNotFalse(has_action('rocket_reset_preload'), 'WP Rocket listens for its preload reset');
    }

    /**
     * @group cache-perfmatters-live
     */
    public function test_perfmatters_settings_are_written_the_way_its_form_saves_and_rolled_back(): void
    {
        $this->assertTrue(defined('PERFMATTERS_VERSION'), 'Perfmatters is loaded');
        update_option('perfmatters_options', function_exists('perfmatters_default_options') ? perfmatters_default_options() : []);
        $before = get_option('perfmatters_options');

        $read = $this->call('read', 'get-perfmatters-settings')['result'];
        $this->assertSame(PERFMATTERS_VERSION, $read['version']);
        $this->assertSame('wp-content,wp-includes', $read['settings']['cdn.cdn_directories'], 'Perfmatters\' own defaults');

        $out = $this->call('write', 'set-perfmatters-settings', [ 'values' => [
            'lazyload.lazy_loading'       => true,
            'assets.delay_js'             => true,
            'assets.delay_js_inclusions'  => [ 'gtag', 'fbevents.js' ],
            'cdn.enable_cdn'              => true,
            'cdn.cdn_url'                 => 'https://cdn.example.org',
        ] ]);
        $stored = get_option('perfmatters_options');
        $this->assertSame('1', $stored['lazyload']['lazy_loading']);
        $this->assertSame([ 'gtag', 'fbevents.js' ], $stored['assets']['delay_js_inclusions']);
        $this->assertSame('https://cdn.example.org', $stored['cdn']['cdn_url']);
        $this->assertSame([ 'latin' ], $stored['fonts']['subsets'], 'the rest stays');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, get_option('perfmatters_options'));
    }

    /**
     * @group cache-flyingpress-live
     */
    public function test_flyingpress_settings_go_through_its_config_and_the_rollback_reloads_it(): void
    {
        $this->assertTrue(defined('FLYING_PRESS_VERSION'), 'FlyingPress is loaded');
        \FlyingPress\Config::update_config([ 'license_key' => 'live-fp-license-0123456789', 'js_delay_method' => 'defer', 'lazy_render' => true ]);
        $before = get_option('FLYING_PRESS_CONFIG');

        $read = $this->call('read', 'get-flyingpress-settings')['result'];
        $this->assertSame(FLYING_PRESS_VERSION, $read['version']);
        $this->assertSame('defer', $read['settings']['js_delay_method']);
        $this->assertStringNotContainsString('live-fp-license-0123456789', (string) wp_json_encode($read));

        $seen = [];
        add_action('flying_press_update_config:after', static function ($config) use (&$seen): void {
            $seen[] = $config['js_delay_method'];
        }, 1);

        $out = $this->call('write', 'set-flyingpress-settings', [ 'values' => [ 'js_delay_method' => 'user-interaction', 'lazy_render' => false ] ]);
        $this->assertSame('user-interaction', \FlyingPress\Config::$config['js_delay_method']);
        $this->assertFalse(\FlyingPress\Config::$config['lazy_render']);
        $this->assertSame('live-fp-license-0123456789', get_option('FLYING_PRESS_CONFIG')['license_key']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, get_option('FLYING_PRESS_CONFIG'));
        $this->assertSame('defer', \FlyingPress\Config::$config['js_delay_method'], 'the rollback reloads FlyingPress\'s Config');
        $this->assertSame([ 'user-interaction', 'defer' ], $seen, 'and its config listeners ran for both');

        $preload = $this->call('write', 'preload-flyingpress-cache');
        $this->assertFalse($preload['recoverable']);
        $this->assertTrue($preload['result']['started']);

        remove_all_actions('flying_press_update_config:after', 1);
    }
}
