<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Site_Gate_Pack;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\Maintenance\Site_Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

/**
 * Issue #903: the site gate reader and toggles against the real coming soon
 * and maintenance plugins, all free on wordpress.org.
 *
 * It runs only in the local gate's live site gate leg (bin/test-local.sh
 * --live-site-gate), which sets WPMCP_LIVE_SITE_GATE=1, installs the five
 * plugins and runs PHPUnit once per plugin with WPMCP_SITE_GATE_PLUGIN naming
 * the one loaded (they all claim the front door, so never together). Each run
 * checks that what the reader expects is what that plugin really stores and
 * decides: its own defaults carry the keys read, and its own code agrees
 * with the state the reader reports and the toggles write.
 *
 * Everywhere else the plugins are absent and the test is skipped; the live
 * leg runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group site-gate-live
 */
class SiteGateLiveTest extends \WP_UnitTestCase
{
    private string $slug = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->slug = (string) getenv('WPMCP_SITE_GATE_PLUGIN');
        if ('' === $this->slug || ! getenv('WPMCP_LIVE_SITE_GATE')) {
            $this->markTestSkipped('Needs a real gate plugin (bin/test-local.sh --live-site-gate).');
        }
        Snapshot_Store::install();
        update_option('active_plugins', [ Site_Gate::PLUGINS[ $this->slug ] ]);
        add_filter('pre_http_request', static fn () => [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 200, 'message' => '' ], 'cookies' => [], 'filename' => null ], 1);
        add_filter(Site_Gate_Pack::ENABLE_FILTER, '__return_true');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        remove_all_filters('pre_http_request');
        remove_all_filters(Site_Gate_Pack::ENABLE_FILTER);
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function gate(): array
    {
        $out = Site_Gate::plugin_gate($this->slug);
        $this->assertIsArray($out);
        return $out;
    }

    private function toggle(string $op, array $args, bool $confirm = false): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if ($confirm) {
            $call['confirm'] = true;
        }
        return (new Theme_Integration())->handle_write($call);
    }

    public function test_the_loaded_plugin_is_read_and_agrees_with_its_own_code(): void
    {
        $method = 'check_' . str_replace('-', '_', $this->slug);
        $this->$method();
        $status = Site_Gate::status(false);
        $this->assertContains(Site_Gate::source_for($this->slug), array_column($status['gates'], 'source'), 'an active plugin is listed');
    }

    private function check_wp_maintenance_mode(): void
    {
        $this->assertTrue(class_exists('WP_Maintenance_Mode'));
        $defaults = \WP_Maintenance_Mode::get_instance()->default_settings();
        foreach ([ 'status', 'backend_role', 'frontend_role', 'exclude', 'bypass_bots' ] as $key) {
            $this->assertArrayHasKey($key, $defaults['general'], "the reader relies on general.{$key}");
        }
        $this->assertSame(0, $defaults['general']['status']);
        $this->assertFalse($this->gate()['active']);

        $defaults['general']['status']        = 1;
        $defaults['general']['frontend_role'] = [ 'editor' ];
        update_option('wpmm_settings', $defaults);

        $gate = $this->gate();
        $this->assertTrue($gate['active']);
        $this->assertSame(503, $gate['http_status']);
        $this->assertSame([ 'editor' ], $gate['bypass']['roles']);
        $this->assertTrue(Site_Gate::status(false)['public_site_gated']);

        $unsupported = $this->toggle('enable-site-gate', [ 'plugin' => 'auto' ], true);
        $this->assertSame('site_gate_plugin_inactive', $unsupported['error']['code'] ?? '', 'WP Maintenance Mode is read only here');
    }

    private function check_under_construction_page(): void
    {
        $this->assertTrue(class_exists('UCP'));
        $defaults = \UCP::default_options();
        foreach ([ 'status', 'whitelisted_roles', 'whitelisted_users', 'end_date', 'heading1' ] as $key) {
            $this->assertArrayHasKey($key, $defaults, "the reader relies on {$key}");
        }
        $this->assertFalse($this->gate()['active']);

        $out = $this->toggle('enable-site-gate', [ 'plugin' => 'under-construction-page' ], true);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($this->gate()['active']);

        // The plugin's own decision: a visitor is gated, the administrator who turned it on is not.
        wp_set_current_user(0);
        $this->assertTrue(\UCP::is_construction_mode_enabled(false), 'a visitor gets the page');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $this->assertFalse(\UCP::is_construction_mode_enabled(false), 'an administrator walks past it');
        $this->assertTrue(\UCP::is_construction_mode_enabled(true));

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertFalse(\UCP::is_construction_mode_enabled(true), 'undo opens the site');
        $this->assertFalse($this->gate()['active']);

        $off = $this->toggle('enable-site-gate', [ 'plugin' => 'under-construction-page' ], true);
        $this->assertTrue($this->gate()['active']);
        $closed = $this->toggle('disable-site-gate', []);
        $this->assertArrayNotHasKey('error', $closed);
        $this->assertFalse(\UCP::is_construction_mode_enabled(true));
        $this->assertArrayNotHasKey('error', $off);
    }

    private function check_maintenance(): void
    {
        $this->assertTrue(class_exists('MTNC'));
        $options = \MTNC::get_instance()->get_options();
        foreach ([ 'status', 'show_logged_in', 'ip_whitelist', 'blockse', 'direct_access_link', 'site_password', 'header_text' ] as $key) {
            $this->assertArrayHasKey($key, $options, "the reader relies on options.{$key}");
        }
        $this->assertFalse($this->gate()['active']);

        $options['status'] = '1';
        $this->assertTrue(\MTNC::get_instance()->update_options('options', $options));

        $gate = $this->gate();
        $this->assertTrue($gate['active']);
        $this->assertSame($options['blockse'] ? 503 : 200, $gate['http_status']);
        $this->assertSame('every logged-in user' === $gate['bypass']['logged_in_users'], '1' === (string) $options['show_logged_in']);
        $this->assertSame('1', (string) \MTNC::get_instance()->get_options()['status'], 'the plugin reads back what the reader saw');
    }

    private function check_cmp_coming_soon_maintenance(): void
    {
        $this->assertTrue(class_exists('CMP_Coming_Soon_and_Maintenance'));
        $cmp = \CMP_Coming_Soon_and_Maintenance::get_instance();
        $this->assertSame('0', $cmp->cmp_active());
        $this->assertFalse($this->gate()['active']);

        update_option('niteoCS_status', '1');
        update_option('niteoCS_activation', '1');
        $this->assertSame('1', $cmp->cmp_active());
        $this->assertSame('1', (string) $cmp->cmp_mode());

        $gate = $this->gate();
        $this->assertTrue($gate['active']);
        $this->assertSame('maintenance', $gate['mode']);
        $this->assertSame(503, $gate['http_status']);
        $this->assertContains('administrator', $gate['bypass']['roles'], 'the plugin lets administrators through');
        $this->assertSame('open', $gate['rest_api'], 'the plugin leaves REST open by default');
    }

    private function check_coming_soon(): void
    {
        $this->assertTrue(function_exists('seedprod_lite_save_settings'));
        $this->assertFalse($this->gate()['active']);

        $page = self::factory()->post->create([ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Soon', 'post_content_filtered' => wp_json_encode([ 'access_by_role' => [ 'administrator' ] ]) ]);
        update_option('seedprod_coming_soon_page_id', $page);
        update_option('seedprod_settings', wp_json_encode([ 'api_key' => '' ]));

        $out = $this->toggle('enable-site-gate', [ 'plugin' => 'coming-soon', 'mode' => 'coming_soon' ], true);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $gate = $this->gate();
        $this->assertTrue($gate['active']);
        $this->assertSame('coming_soon', $gate['mode']);

        // The plugin's own settings save, fed what a settings screen would send: it
        // keeps the flag we wrote and brings the page to the status that matches.
        $saved = $this->seedprod_save(json_decode((string) get_option('seedprod_settings'), true));
        $this->assertTrue($saved, 'SeedProd accepted its own settings save');
        $stored = json_decode((string) get_option('seedprod_settings'), true);
        $this->assertTrue($stored['enable_coming_soon_mode'], 'the plugin keeps the flag the toggle wrote');
        $this->assertSame('publish', get_post_status($page), 'and publishes the page for the mode that is on');

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertFalse($this->gate()['active']);
        $this->assertSame('', $this->toggle('disable-site-gate', [ 'plugin' => 'coming-soon' ])['error']['code'] ?? '', 'nothing left to disable after the undo');
    }

    /** Run SeedProd's own settings save the way its screen calls it. */
    private function seedprod_save(array $settings): bool
    {
        $_REQUEST['_ajax_nonce'] = wp_create_nonce('seedprod_nonce');
        $_POST['settings']       = wp_slash(wp_json_encode($settings));
        add_filter('wp_doing_ajax', '__return_true');
        $die = static function () {
            return static function (): void {
                throw new \RuntimeException('json_sent');
            };
        };
        add_filter('wp_die_ajax_handler', $die);
        add_filter('wp_die_handler', $die);
        ob_start();
        $sent = false;
        try {
            seedprod_lite_save_settings();
        } catch (\RuntimeException $e) {
            $sent = 'json_sent' === $e->getMessage();
        }
        $body = (string) ob_get_clean();
        remove_filter('wp_doing_ajax', '__return_true');
        remove_filter('wp_die_ajax_handler', $die);
        remove_filter('wp_die_handler', $die);
        unset($_REQUEST['_ajax_nonce'], $_POST['settings']);

        return $sent && false !== strpos($body, 'Settings Updated');
    }
}
