<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * LiteSpeed Cache settings and targeted purge (issue #933, follow-up to
 * #692). LiteSpeed Cache 7.9 keeps every setting in its own option,
 * litespeed.conf.<id>, so a write snapshots the allowlisted options as one
 * option_set. LiteSpeed is not loaded in the shared test core: presence is
 * the wpmcp_litespeed_active filter and the options are seeded as the
 * plugin stores them (switches as booleans, lists as arrays, the JS defer
 * mode as 0, 1 or 2).
 */
class CacheSettingsLiteSpeedTest extends \WP_UnitTestCase
{
    private const QC_KEY = 'qc-api-key-0123456789abcdef';
    private const CF_KEY = 'cf-litespeed-key-fedcba9876';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_litespeed_active', '__return_true');
        add_filter('wpmcp_enable_cache_settings_write', '__return_true');

        $seed = [
            'cache'                => true,
            'optm-css_min'         => false,
            'optm-js_min'          => true,
            'optm-js_defer'        => 0,
            'media-lazy'           => true,
            'media-lazy_exc'       => [ 'hero.jpg' ],
            'cdn'                  => false,
            'api_key'              => self::QC_KEY,
            'cdn-cloudflare_key'   => self::CF_KEY,
            'cdn-cloudflare_email' => 'owner@example.org',
            'object-pswd'          => 'redis-secret-pass',
        ];
        foreach ($seed as $id => $value) {
            update_option('litespeed.conf.' . $id, $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ([ 'wpmcp_litespeed_active', 'wpmcp_enable_cache_settings_write', 'litespeed_purge_post', 'litespeed_purge_url' ] as $hook) {
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

    public function test_the_catalog_lists_the_litespeed_ops(): void
    {
        $ops = array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
        foreach ([ 'get-litespeed-settings' => 'read', 'set-litespeed-settings' => 'write', 'purge-litespeed-cache' => 'write' ] as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode']);
            $this->assertSame('manage_options', $ops[ $name ]['capability']);
        }
    }

    public function test_without_litespeed_a_call_answers_plugin_missing(): void
    {
        remove_all_filters('wpmcp_litespeed_active');
        add_filter('wpmcp_litespeed_active', '__return_false');
        $out = $this->read('get-litespeed-settings');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null);
    }

    public function test_read_reports_settings_and_masks_quic_cloud_and_cdn_keys(): void
    {
        $out  = $this->ok($this->read('get-litespeed-settings'));
        $json = (string) wp_json_encode($out);
        $res  = $out['result'];

        $this->assertTrue($res['settings']['optm-js_min']);
        $this->assertFalse($res['settings']['optm-css_min']);
        $this->assertSame(0, $res['settings']['optm-js_defer']);
        $this->assertSame([ 'hero.jpg' ], $res['settings']['media-lazy_exc']);
        $this->assertTrue($res['settings']['cache']);
        $this->assertTrue($res['summary']['lazy_load']);
        $this->assertTrue($res['summary']['minify_js']);
        $this->assertNotContains('cache', $res['writable'], 'the page cache switch is read-only');

        foreach ([ self::QC_KEY, self::CF_KEY, 'owner@example.org', 'redis-secret-pass' ] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $masked = array_column($res['masked'], 'set', 'name');
        $this->assertTrue($masked['api_key']);
        $this->assertTrue($masked['cdn-cloudflare_key']);
    }

    public function test_set_writes_each_option_and_rolls_back_every_one(): void
    {
        $out = $this->ok($this->write('set-litespeed-settings', [ 'values' => [ 'optm-css_min' => true, 'optm-js_defer' => 2, 'media-lazy_exc' => [ 'a.jpg', 'b.jpg' ] ] ]));
        $this->assertTrue($out['recoverable']);
        $this->assertTrue(get_option('litespeed.conf.optm-css_min'));
        $this->assertSame(2, get_option('litespeed.conf.optm-js_defer'));
        $this->assertSame([ 'a.jpg', 'b.jpg' ], get_option('litespeed.conf.media-lazy_exc'));
        $this->assertSame([ 'before' => false, 'after' => true ], $out['result']['changed']['optm-css_min']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertFalse(get_option('litespeed.conf.optm-css_min'));
        $this->assertSame(0, get_option('litespeed.conf.optm-js_defer'));
        $this->assertSame([ 'hero.jpg' ], get_option('litespeed.conf.media-lazy_exc'));
        $this->assertSame(self::QC_KEY, get_option('litespeed.conf.api_key'), 'credentials untouched');
    }

    public function test_set_refuses_a_credential_or_read_only_key_and_a_no_op(): void
    {
        foreach ([ 'api_key', 'cache', 'cdn' ] as $key) {
            $out = $this->write('set-litespeed-settings', [ 'values' => [ $key => true ] ]);
            $this->assertArrayHasKey('error', $out, $key);
        }
        $this->assertSame(true, get_option('litespeed.conf.cache'));
        $out = $this->write('set-litespeed-settings', [ 'values' => [ 'optm-js_min' => true ] ]);
        $this->assertSame('no_change', $out['error']['code'] ?? null);
    }

    public function test_purge_by_post_and_by_url_calls_litespeed_hooks(): void
    {
        $posts = [];
        $urls  = [];
        add_action('litespeed_purge_post', static function ($id) use (&$posts): void {
            $posts[] = $id;
        });
        add_action('litespeed_purge_url', static function ($url) use (&$urls): void {
            $urls[] = $url;
        });
        $post = self::factory()->post->create();

        $out = $this->ok($this->write('purge-litespeed-cache', [ 'post_id' => $post ]));
        $this->assertFalse($out['recoverable']);
        $this->assertSame([ $post ], $posts);

        $this->ok($this->write('purge-litespeed-cache', [ 'url' => home_url('/shop/') ]));
        $this->assertSame([ home_url('/shop/') ], $urls);

        $this->assertArrayHasKey('error', $this->write('purge-litespeed-cache', []));
        $this->assertArrayHasKey('error', $this->write('purge-litespeed-cache', [ 'post_id' => 999999 ]));
        $this->assertArrayHasKey('error', $this->write('purge-litespeed-cache', [ 'url' => 'https://other.example.net/x' ]));
        $this->assertCount(1, $posts);
        $this->assertCount(1, $urls);
    }
}
