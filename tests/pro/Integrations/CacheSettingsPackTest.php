<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Cache_Settings_Pack;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/flyingpress-double.php';

/**
 * Cache and performance plugin settings (issue #692): paid-tier ops on the
 * theme dispatcher pair for WP Rocket, Perfmatters and FlyingPress.
 *
 * None of the three is loaded in the shared test core. Presence is driven
 * through each plugin's wpmcp_*_active filter, and each option is seeded in
 * the shape the real plugin stores (WP Rocket 3.24: switches as 1 or 0,
 * textareas as lists; Perfmatters 2.6.9: sections, a switch on as "1" and
 * off as an absent key; FlyingPress 5.6.6: a flat config with booleans,
 * written through the FlyingPress\Config double).
 *
 * Every write is off until the site opts in and is undone in a test.
 */
class CacheSettingsPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'get-wp-rocket-settings'    => 'read',
        'set-wp-rocket-settings'    => 'write',
        'preload-wp-rocket-cache'   => 'write',
        'get-perfmatters-settings'  => 'read',
        'set-perfmatters-settings'  => 'write',
        'get-flyingpress-settings'  => 'read',
        'set-flyingpress-settings'  => 'write',
        'preload-flyingpress-cache' => 'write',
    ];

    private const ACTIVE = [ 'wpmcp_wp_rocket_active', 'wpmcp_perfmatters_active', 'wpmcp_flyingpress_active' ];

    private const ROCKET_KEY = 'rocket-license-0123456789abcdef';
    private const CF_KEY     = 'cf-api-key-fedcba9876543210';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        foreach (self::ACTIVE as $filter) {
            add_filter($filter, '__return_true');
        }
        add_filter('wpmcp_enable_cache_settings_write', '__return_true');

        update_option('wp_rocket_settings', [
            'consumer_key'         => self::ROCKET_KEY,
            'consumer_email'       => 'owner@example.org',
            'secret_key'           => 'abcdef123456',
            'cloudflare_api_key'   => self::CF_KEY,
            'cloudflare_email'     => '',
            'lazyload'             => 1,
            'lazyload_iframes'     => 0,
            'minify_css'           => 0,
            'minify_js'            => 1,
            'delay_js'             => 0,
            'remove_unused_css'    => 1,
            'async_css'            => 0,
            'manual_preload'       => 1,
            'cdn'                  => 0,
            'cdn_cnames'           => [ 'cdn.example.org' ],
            'cdn_zone'             => [ 'images' ],
            'exclude_js'           => [ '/wp-includes/js/jquery/jquery.min.js' ],
            'delay_js_exclusions'  => [],
            'purge_cron_interval'  => 10,
            'purge_cron_unit'      => 'HOUR_IN_SECONDS',
            'critical_css'         => 'body{}',
        ]);
        update_option('perfmatters_options', [
            'disable_emojis' => '1',
            'header_code'    => '<script>secret()</script>',
            'login_url'      => 'hidden-door',
            'lazyload'       => [ 'lazy_loading' => '1', 'lazy_loading_exclusions' => [ 'hero.jpg' ] ],
            'assets'         => [ 'delay_js' => '1', 'delay_js_behavior' => 'all' ],
            'cdn'            => [ 'cdn_directories' => 'wp-content,wp-includes', 'cdn_exclusions' => '.php' ],
        ]);
        update_option('FLYING_PRESS_CONFIG', [
            'license_key'        => 'fp-license-1234567890',
            'cf_api_key'         => '',
            'flying_cdn_api_key' => 'fcdn-secret-abcdef',
            'css_js_minify'      => true,
            'js_delay'           => true,
            'js_delay_method'    => 'defer',
            'lazy_load'          => true,
            'lazy_load_exclusions' => [],
            'cdn'                => false,
            'cdn_type'           => 'custom',
            'cdn_url'            => '',
            'cdn_file_types'     => 'all',
        ]);
        \FlyingPress\Config::$config = get_option('FLYING_PRESS_CONFIG');
        \FlyingPress\Preload::$runs  = 0;
    }

    protected function tearDown(): void
    {
        foreach (array_merge(self::ACTIVE, [ 'wpmcp_enable_cache_settings_write', 'flying_press_update_config:after', 'update_option_wp_rocket_settings', 'rocket_reset_preload' ]) as $hook) {
            remove_all_filters($hook);
        }
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args = []): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    // ---------------------------------------------------------------
    // Catalog and availability
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_op_with_its_mode_and_manage_options(): void
    {
        $ops = array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            $this->assertSame('manage_options', $ops[ $name ]['capability'], $name);
        }
        $this->assertArrayNotHasKey('preload-perfmatters-cache', $ops, 'Perfmatters has no page cache');
    }

    public function test_without_pro_the_ops_are_not_in_the_catalog(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = array_column((new Theme_Integration())->catalog()['operations'], 'name');
        foreach (array_keys(self::OPS) as $name) {
            $this->assertNotContains($name, $ops);
        }
    }

    public function test_while_the_plugin_is_missing_a_call_answers_plugin_missing_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_wp_rocket_active');
        add_filter('wpmcp_wp_rocket_active', '__return_false');
        $out = $this->read('get-wp-rocket-settings');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate WP Rocket', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
        $ops = array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
        $this->assertFalse($ops['set-wp-rocket-settings']['dependency_met']);
        $this->assertTrue($ops['set-perfmatters-settings']['dependency_met']);
    }

    public function test_with_the_plugin_files_installed_but_inactive_it_answers_plugin_inactive(): void
    {
        remove_all_filters('wpmcp_perfmatters_active');
        add_filter('wpmcp_perfmatters_active', '__return_false');
        $dir     = WP_PLUGIN_DIR . '/perfmatters';
        $created = ! is_dir($dir);
        if ($created) {
            wp_mkdir_p($dir);
        }
        $file  = $dir . '/perfmatters.php';
        $owned = ! file_exists($file);
        if ($owned) {
            file_put_contents($file, "<?php\n// Plugin Name: Perfmatters\n");
        }
        try {
            $out = $this->read('get-perfmatters-settings');
        } finally {
            if ($owned) {
                unlink($file);
            }
            if ($created) {
                rmdir($dir);
            }
        }
        $this->assertSame('plugin_inactive', $out['error']['code'] ?? null);
        $this->assertStringContainsString('installed but not active', $out['error']['message']);
    }

    public function test_writes_are_off_until_the_site_opts_in_and_reads_say_so(): void
    {
        remove_all_filters('wpmcp_enable_cache_settings_write');
        foreach ([ 'set-wp-rocket-settings' => [ 'values' => [ 'cdn' => true ] ], 'preload-flyingpress-cache' => [] ] as $op => $args) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_cache_settings_write', $out['error']['message']);
        }
        $this->assertSame(0, get_option('wp_rocket_settings')['cdn']);
        $this->assertSame(0, \FlyingPress\Preload::$runs);
        $this->assertFalse($this->ok($this->read('get-wp-rocket-settings'))['result']['writes_enabled']);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_wp_rocket_read_reports_the_summary_and_settings_and_never_a_credential(): void
    {
        $settings = get_option('wp_rocket_settings');
        // A credential pasted into another field is scrubbed by value.
        $settings['exclude_css'] = [ '/x.css?k=' . self::CF_KEY ];
        update_option('wp_rocket_settings', $settings);

        $out  = $this->ok($this->read('get-wp-rocket-settings'))['result'];
        $json = (string) wp_json_encode($out);

        $this->assertSame([ 'lazy_load' => true, 'minify_css' => false, 'minify_js' => true, 'delay_js' => false, 'cdn' => false, 'preload' => true ], $out['summary']);
        $this->assertSame([ 'cdn.example.org' ], $out['settings']['cdn_cnames']);
        $this->assertSame([ 'images' ], $out['settings']['cdn_zone']);
        $this->assertSame('HOUR_IN_SECONDS', $out['settings']['purge_cron_unit']);
        $this->assertSame([ '/x.css?k=[redacted]' ], $out['settings']['exclude_css']);
        $this->assertArrayNotHasKey('critical_css', $out['settings']);
        $this->assertContains('delay_js', $out['writable']);
        $this->assertTrue($out['writes_enabled']);
        $this->assertTrue($out['preload_supported']);

        $credentials = array_column($out['masked'], 'set', 'name');
        $this->assertTrue($credentials['cloudflare_api_key']);
        $this->assertTrue($credentials['consumer_key']);
        $this->assertFalse($credentials['cloudflare_email']);
        foreach ([ self::ROCKET_KEY, self::CF_KEY, 'owner@example.org', 'abcdef123456' ] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_perfmatters_read_uses_section_keys_and_leaves_code_and_the_login_url_out(): void
    {
        $out  = $this->ok($this->read('get-perfmatters-settings'))['result'];
        $json = (string) wp_json_encode($out);

        $this->assertTrue($out['settings']['lazyload.lazy_loading']);
        $this->assertSame([ 'hero.jpg' ], $out['settings']['lazyload.lazy_loading_exclusions']);
        $this->assertSame('all', $out['settings']['assets.delay_js_behavior']);
        $this->assertFalse($out['settings']['assets.minify_css']);
        $this->assertSame([ 'lazy_load' => true, 'minify_css' => false, 'minify_js' => false, 'delay_js' => true, 'cdn' => false, 'preload' => null ], $out['summary']);
        $this->assertFalse($out['preload_supported']);
        $this->assertStringNotContainsString('secret()', $json);
        $this->assertStringNotContainsString('hidden-door', $json);
    }

    public function test_flyingpress_read_masks_the_license_and_cdn_keys(): void
    {
        $out  = $this->ok($this->read('get-flyingpress-settings'))['result'];
        $json = (string) wp_json_encode($out);

        $this->assertSame('defer', $out['settings']['js_delay_method']);
        $this->assertSame('custom', $out['settings']['cdn_type']);
        $credentials = array_column($out['masked'], 'set', 'name');
        $this->assertTrue($credentials['license_key']);
        $this->assertTrue($credentials['flying_cdn_api_key']);
        $this->assertFalse($credentials['cf_api_key']);
        $this->assertStringNotContainsString('fp-license-1234567890', $json);
        $this->assertStringNotContainsString('fcdn-secret-abcdef', $json);
    }

    // ---------------------------------------------------------------
    // Writes, each undone
    // ---------------------------------------------------------------

    public function test_wp_rocket_set_writes_through_the_option_and_rolls_back_exactly(): void
    {
        $before = get_option('wp_rocket_settings');
        $saves  = 0;
        add_action('update_option_wp_rocket_settings', static function () use (&$saves): void {
            ++$saves;
        });

        $out = $this->ok($this->write('set-wp-rocket-settings', [ 'values' => [
            'delay_js'            => true,
            'lazyload'            => true,
            'delay_js_exclusions' => [ ' jquery ', 'jquery', '<b>gtag</b>', '' ],
        ] ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame([ 'delay_js', 'delay_js_exclusions' ], array_keys($out['result']['changed']), 'unchanged lazyload is not reported');
        $this->assertSame([ 'before' => false, 'after' => true ], $out['result']['changed']['delay_js']);
        $this->assertStringContainsString('front end', $out['result']['check']);
        $this->assertTrue($out['result']['summary']['delay_js']);

        $stored = get_option('wp_rocket_settings');
        $this->assertSame(1, $stored['delay_js'], 'WP Rocket keeps a switch as 1 or 0');
        $this->assertSame([ 'jquery', 'gtag' ], $stored['delay_js_exclusions']);
        $this->assertSame(self::ROCKET_KEY, $stored['consumer_key'], 'the license stays');
        $this->assertSame(1, $saves);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, get_option('wp_rocket_settings'));
        $this->assertSame(2, $saves, 'the rollback goes through the same option save');
    }

    public function test_a_call_that_changes_nothing_or_names_an_unknown_key_is_refused_before_any_snapshot(): void
    {
        $out = $this->write('set-wp-rocket-settings', [ 'values' => [ 'lazyload' => true ] ]);
        $this->assertSame('no_change', $out['error']['code'] ?? null);

        $out = $this->write('set-wp-rocket-settings', [ 'values' => [ 'consumer_key' => 'x' ] ]);
        $this->assertSame('invalid_args', $out['error']['code'] ?? null, 'credentials are not writable');

        $out = $this->write('set-wp-rocket-settings', [ 'values' => [ 'purge_cron_unit' => 'YEAR' ] ]);
        $this->assertSame('invalid_args', $out['error']['code'] ?? null);

        $out = $this->write('set-perfmatters-settings', [ 'values' => [ 'header_code' => '<script>x</script>' ] ]);
        $this->assertSame('invalid_args', $out['error']['code'] ?? null, 'code boxes are not writable');

        $out = $this->write('set-flyingpress-settings', [ 'values' => [ 'cdn_url' => 'javascript:alert(1)' ] ]);
        $this->assertSame('invalid_args', $out['error']['code'] ?? null);
        $this->assertSame('', get_option('FLYING_PRESS_CONFIG')['cdn_url']);
    }

    public function test_wp_rocket_refuses_both_css_methods_and_pairs_new_cnames_with_zones(): void
    {
        $out = $this->write('set-wp-rocket-settings', [ 'values' => [ 'async_css' => true ] ]);
        $this->assertSame('wp_rocket_css_conflict', $out['error']['code'] ?? null);

        $this->ok($this->write('set-wp-rocket-settings', [ 'values' => [ 'async_css' => true, 'remove_unused_css' => false ] ]));

        $out = $this->ok($this->write('set-wp-rocket-settings', [ 'values' => [ 'cdn' => true, 'cdn_cnames' => [ 'a.cdn.example.org', 'b.cdn.example.org' ] ] ]));
        $this->assertSame([ 'all', 'all' ], get_option('wp_rocket_settings')['cdn_zone']);
        $this->assertArrayHasKey('cdn_zone', $out['result']['changed']);

        $out = $this->write('set-wp-rocket-settings', [ 'values' => [ 'cdn_zone' => [ 'css' ] ] ]);
        $this->assertSame('wp_rocket_cdn_zone_mismatch', $out['error']['code'] ?? null);
    }

    public function test_perfmatters_set_stores_switches_its_way_and_rolls_back_exactly(): void
    {
        $before = get_option('perfmatters_options');

        $out = $this->ok($this->write('set-perfmatters-settings', [ 'values' => [
            'lazyload.lazy_loading'   => false,
            'assets.minify_css'       => true,
            'assets.delay_js_behavior' => '',
            'cdn.enable_cdn'          => true,
            'cdn.cdn_url'             => 'https://cdn.example.org/',
        ] ]));
        $this->assertSame([ 'before' => true, 'after' => false ], $out['result']['changed']['lazyload.lazy_loading']);

        $stored = get_option('perfmatters_options');
        $this->assertArrayNotHasKey('lazy_loading', $stored['lazyload'], 'off is an absent key');
        $this->assertSame([ 'hero.jpg' ], $stored['lazyload']['lazy_loading_exclusions']);
        $this->assertSame('1', $stored['assets']['minify_css']);
        $this->assertArrayNotHasKey('delay_js_behavior', $stored['assets']);
        $this->assertSame('https://cdn.example.org', $stored['cdn']['cdn_url']);
        $this->assertSame('<script>secret()</script>', $stored['header_code'], 'code boxes are left as they are');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, get_option('perfmatters_options'));
    }

    public function test_flyingpress_set_goes_through_config_and_its_rollback_refreshes_config(): void
    {
        $before = get_option('FLYING_PRESS_CONFIG');
        $events = [];
        add_action('flying_press_update_config:after', static function ($config, $prev) use (&$events): void {
            $events[] = [ $config['js_delay_method'], $prev['js_delay_method'] ];
        }, 10, 2);

        $out = $this->ok($this->write('set-flyingpress-settings', [ 'values' => [ 'js_delay_method' => 'user-interaction', 'lazy_load_exclusions' => [ 'logo.svg' ] ] ]));
        $this->assertSame('user-interaction', get_option('FLYING_PRESS_CONFIG')['js_delay_method']);
        $this->assertSame('user-interaction', \FlyingPress\Config::$config['js_delay_method']);
        $this->assertSame('fp-license-1234567890', get_option('FLYING_PRESS_CONFIG')['license_key']);
        $this->assertSame([ [ 'user-interaction', 'defer' ] ], $events);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, get_option('FLYING_PRESS_CONFIG'));
        $this->assertSame('defer', \FlyingPress\Config::$config['js_delay_method'], 'the rollback reloads Config');
        $this->assertSame([ 'defer', 'user-interaction' ], $events[1] ?? null, 'and fires FlyingPress\'s own config action');
    }

    public function test_after_options_restored_ignores_other_options(): void
    {
        $fired = 0;
        add_action('flying_press_update_config:after', static function () use (&$fired): void {
            ++$fired;
        });
        Cache_Settings_Pack::after_options_restored([ 'blogname', 'wp_rocket_settings' ]);
        $this->assertSame(0, $fired);
    }

    // ---------------------------------------------------------------
    // Preload
    // ---------------------------------------------------------------

    public function test_wp_rocket_preload_needs_preload_on_and_resets_its_queue(): void
    {
        $resets = 0;
        add_action('rocket_reset_preload', static function () use (&$resets): void {
            ++$resets;
        });

        $out = $this->ok($this->write('preload-wp-rocket-cache'));
        $this->assertFalse($out['recoverable'], 'warming the cache is not snapshotted');
        $this->assertTrue($out['result']['started']);
        $this->assertSame(1, $resets);

        $settings                   = get_option('wp_rocket_settings');
        $settings['manual_preload'] = 0;
        update_option('wp_rocket_settings', $settings);
        $out = $this->write('preload-wp-rocket-cache');
        $this->assertSame('wp_rocket_preload_off', $out['error']['code'] ?? null);
        $this->assertSame(1, $resets);
    }

    public function test_flyingpress_preload_runs_its_own_preload(): void
    {
        $out = $this->ok($this->write('preload-flyingpress-cache'));
        $this->assertFalse($out['recoverable']);
        $this->assertSame(1, \FlyingPress\Preload::$runs);
    }
}
