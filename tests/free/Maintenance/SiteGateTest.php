<?php

namespace WPMCP\Tests\Free\Maintenance;

use WPMCP\Maintenance\Site_Gate;
use WPMCP\Tools\Maintenance\Get_Maintenance_Status;

/**
 * Issue #903: the real state of the front door. The stored shapes below are
 * the ones the free plugins write (read from their wordpress.org source and
 * checked against the real plugins in the live site gate leg).
 */
class SiteGateTest extends \WP_UnitTestCase
{
    /** @var array<string, mixed> */
    private array $response = [ 'code' => 200, 'headers' => [] ];

    private bool $http_fails = false;

    protected function setUp(): void
    {
        parent::setUp();
        add_filter('pre_http_request', [ $this, 'serve' ], 1, 3);
        update_option('active_plugins', []);
        @unlink(ABSPATH . '.maintenance');
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [ $this, 'serve' ], 1);
        foreach ([ 'wpmcp_maintenance', 'seedprod_settings', 'seedprod_coming_soon_page_id', 'seedprod_maintenance_mode_page_id', 'wpmm_settings', 'ucp_options', 'mtnc-options', 'niteoCS_status', 'niteoCS_activation', 'niteoCS_roles', 'niteoCS_rest_api_status', 'active_plugins' ] as $option) {
            delete_option($option);
        }
        @unlink(ABSPATH . '.maintenance');
        parent::tearDown();
    }

    public function serve($pre, $args, $url)
    {
        if ($this->http_fails) {
            return new \WP_Error('http_request_failed', 'down');
        }
        return [ 'headers' => $this->response['headers'], 'body' => '', 'response' => [ 'code' => $this->response['code'], 'message' => '' ], 'cookies' => [], 'filename' => null ];
    }

    private function activate(string $slug): void
    {
        update_option('active_plugins', array_merge((array) get_option('active_plugins'), [ Site_Gate::PLUGINS[ $slug ] ]));
    }

    private function gate(array $status, string $source): array
    {
        foreach ($status['gates'] as $gate) {
            if ($source === $gate['source']) {
                return $gate;
            }
        }
        $this->fail("no {$source} gate in " . wp_json_encode($status));
    }

    public function test_a_quiet_site_is_not_gated(): void
    {
        $out = Site_Gate::status(true);

        $this->assertFalse($out['public_site_gated']);
        $this->assertSame('probe_200', $out['basis']);
        $this->assertSame([], $out['active_gates']);
        $this->assertSame([ 'wpmcp', 'wp_core' ], array_column($out['gates'], 'source'), 'plugins that are not active are not listed');
    }

    public function test_wpmcp_own_maintenance_is_reported_with_its_message_and_who_bypasses(): void
    {
        update_option('wpmcp_maintenance', [ 'enabled' => true, 'message' => 'Back soon', 'retry_after' => 600 ]);

        $gate = $this->gate(Site_Gate::status(false), 'wpmcp');

        $this->assertTrue($gate['active']);
        $this->assertSame(503, $gate['http_status']);
        $this->assertSame('Back soon', $gate['headline']);
        $this->assertSame(600, $gate['retry_after']);
        $this->assertSame('manage_options', $gate['bypass']['capability']);
    }

    public function test_the_core_maintenance_flag_is_active_when_fresh_and_stale_after_ten_minutes(): void
    {
        file_put_contents(ABSPATH . '.maintenance', '<?php $upgrading = ' . time() . '; ?>');
        $fresh = $this->gate(Site_Gate::status(false), 'wp_core');
        $this->assertTrue($fresh['active']);
        $this->assertFalse($fresh['stale']);
        $this->assertStringContainsString('nobody', $fresh['bypass']['logged_in_users']);

        file_put_contents(ABSPATH . '.maintenance', '<?php $upgrading = ' . (time() - 3600) . '; ?>');
        $stale = $this->gate(Site_Gate::status(false), 'wp_core');
        $this->assertFalse($stale['active']);
        $this->assertTrue($stale['stale']);
    }

    public function test_the_core_flag_file_is_read_as_text_never_executed(): void
    {
        file_put_contents(ABSPATH . '.maintenance', '<?php $GLOBALS["wpmcp_gate_ran"] = true; $upgrading = ' . time() . '; ?>');
        unset($GLOBALS['wpmcp_gate_ran']);

        Site_Gate::status(false);

        $this->assertArrayNotHasKey('wpmcp_gate_ran', $GLOBALS);
    }

    public function test_seedprod_json_string_option_and_page_json_give_mode_bypass_and_no_secrets(): void
    {
        $this->activate('coming-soon');
        $page = self::factory()->post->create([
            'post_type'             => 'page',
            'post_title'            => 'We are launching',
            'post_content_filtered' => wp_json_encode([ 'access_by_role' => [ 'administrator', 'editor' ], 'bypass_phrase' => 'SENTINEL-PHRASE' ]),
        ]);
        update_option('seedprod_coming_soon_page_id', $page);
        update_option('seedprod_settings', wp_json_encode([ 'api_key' => 'SENTINEL-LICENCE', 'enable_coming_soon_mode' => true, 'enable_maintenance_mode' => false ]));

        $out  = Site_Gate::status(false);
        $gate = $this->gate($out, 'seedprod');

        $this->assertTrue($gate['active']);
        $this->assertSame('coming_soon', $gate['mode']);
        $this->assertSame(200, $gate['http_status']);
        $this->assertSame($page, $gate['page_id']);
        $this->assertSame('We are launching', $gate['headline']);
        $this->assertSame([ 'administrator', 'editor' ], $gate['bypass']['roles']);
        $this->assertTrue($gate['bypass']['bypass_link_set']);
        $this->assertSame([ 'seedprod' ], $out['active_gates']);
        $json = (string) wp_json_encode($out);
        $this->assertStringNotContainsString('SENTINEL', $json, 'phrases and licence keys are never returned');
    }

    public function test_seedprod_maintenance_mode_answers_503_and_an_empty_role_list_means_every_logged_in_user(): void
    {
        $this->activate('coming-soon');
        $page = self::factory()->post->create([ 'post_type' => 'page', 'post_content_filtered' => '{}' ]);
        update_option('seedprod_maintenance_mode_page_id', $page);
        update_option('seedprod_settings', wp_json_encode([ 'enable_coming_soon_mode' => false, 'enable_maintenance_mode' => true ]));

        $gate = $this->gate(Site_Gate::status(false), 'seedprod');

        $this->assertSame('maintenance', $gate['mode']);
        $this->assertSame(503, $gate['http_status']);
        $this->assertSame('every logged-in user', $gate['bypass']['logged_in_users']);
    }

    public function test_seedprod_off_is_reported_inactive(): void
    {
        $this->activate('coming-soon');
        update_option('seedprod_settings', wp_json_encode([ 'enable_coming_soon_mode' => false, 'enable_maintenance_mode' => false ]));

        $this->assertFalse($this->gate(Site_Gate::status(false), 'seedprod')['active']);
        $this->assertFalse(Site_Gate::status(false)['public_site_gated']);
    }

    public function test_wp_maintenance_mode_status_roles_and_page(): void
    {
        $this->activate('wp-maintenance-mode');
        update_option('wpmm_settings', [
            'general' => [ 'status' => 1, 'backend_role' => [ 'editor' ], 'frontend_role' => [ 'shop_manager' ], 'exclude' => [ 'feed', 'wp-login' ], 'bypass_bots' => 1 ],
            'design'  => [ 'heading' => '<b>Down</b> for now', 'page_id' => 0 ],
        ]);

        $gate = $this->gate(Site_Gate::status(false), 'wpmm');

        $this->assertTrue($gate['active']);
        $this->assertSame(503, $gate['http_status']);
        $this->assertSame('Down for now', $gate['headline']);
        $this->assertSame([ 'shop_manager' ], $gate['bypass']['roles']);
        $this->assertSame([ 'editor' ], $gate['bypass']['backend_roles']);
        $this->assertSame(2, $gate['bypass']['excluded_urls']);
        $this->assertTrue($gate['bypass']['search_bots']);
    }

    public function test_under_construction_status_end_date_and_whitelist(): void
    {
        $this->activate('under-construction-page');
        update_option('ucp_options', [ 'status' => '1', 'whitelisted_roles' => [ 'administrator' ], 'whitelisted_users' => [ 5, 6 ], 'end_date' => '' ]);
        $on = $this->gate(Site_Gate::status(false), 'ucp');
        $this->assertTrue($on['active']);
        $this->assertSame(2, $on['bypass']['users']);

        update_option('ucp_options', [ 'status' => '1', 'end_date' => '2001-01-01 10:00' ]);
        $ended = $this->gate(Site_Gate::status(false), 'ucp');
        $this->assertFalse($ended['active'], 'a date in the past ends the gate');
        $this->assertTrue($ended['ended']);

        update_option('ucp_options', [ 'status' => '0' ]);
        $this->assertFalse($this->gate(Site_Gate::status(false), 'ucp')['active']);
    }

    public function test_maintenance_plugin_reads_status_from_the_options_subarray_and_counts_secrets(): void
    {
        $this->activate('maintenance');
        update_option('mtnc-options', [ 'options' => [ 'status' => '1', 'blockse' => '1', 'show_logged_in' => '1', 'ip_whitelist' => "10.0.0.1\n10.0.0.2\n", 'site_password' => 'SENTINEL-PASS', 'direct_access_link' => 'letmein' ] ]);

        $out  = Site_Gate::status(false);
        $gate = $this->gate($out, 'maintenance');

        $this->assertTrue($gate['active']);
        $this->assertSame(503, $gate['http_status']);
        $this->assertSame(2, $gate['bypass']['ip_count']);
        $this->assertTrue($gate['bypass']['password_set']);
        $this->assertTrue($gate['bypass']['bypass_link_set']);
        $this->assertSame('every logged-in user', $gate['bypass']['logged_in_users']);
        $json = (string) wp_json_encode($out);
        foreach ([ 'SENTINEL-PASS', '10.0.0.1', 'letmein' ] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_cmp_status_mode_roles_and_rest(): void
    {
        $this->activate('cmp-coming-soon-maintenance');
        update_option('niteoCS_status', '1');
        update_option('niteoCS_activation', '1');
        update_option('niteoCS_roles', '["editor"]');
        update_option('niteoCS_rest_api_status', '0');

        $gate = $this->gate(Site_Gate::status(false), 'cmp');

        $this->assertTrue($gate['active']);
        $this->assertSame('maintenance', $gate['mode']);
        $this->assertSame(503, $gate['http_status']);
        $this->assertSame([ 'editor', 'administrator' ], $gate['bypass']['roles']);
        $this->assertSame('closed to anonymous requests', $gate['rest_api']);

        update_option('niteoCS_status', '0');
        $this->assertFalse($this->gate(Site_Gate::status(false), 'cmp')['active']);
    }

    public function test_the_probe_is_the_truth_a_503_gates_the_site_even_with_no_known_gate(): void
    {
        $this->response = [ 'code' => 503, 'headers' => [ 'retry-after' => '120' ] ];

        $out = Site_Gate::status(true);

        $this->assertTrue($out['public_site_gated']);
        $this->assertSame('probe_503', $out['basis']);
        $this->assertSame('120', $out['probe']['retry_after']);
        $this->assertSame([], $out['active_gates'], 'a gate the plugin list does not know is reported by the probe alone');
    }

    public function test_an_active_gate_with_a_200_probe_stays_gated_but_a_failed_probe_is_unverified(): void
    {
        update_option('wpmcp_maintenance', [ 'enabled' => true ]);
        $this->assertSame('active_gate', Site_Gate::status(true)['basis']);

        $this->http_fails = true;
        $out = Site_Gate::status(true);
        $this->assertTrue($out['public_site_gated']);
        $this->assertSame('active_gate_unverified', $out['basis']);
        $this->assertFalse($out['probe']['reachable']);
    }

    public function test_two_active_gates_are_warned_about(): void
    {
        update_option('wpmcp_maintenance', [ 'enabled' => true ]);
        $this->activate('under-construction-page');
        update_option('ucp_options', [ 'status' => '1' ]);

        $out = Site_Gate::status(false);

        $this->assertSame([ 'wpmcp', 'ucp' ], $out['active_gates']);
        $this->assertArrayHasKey('warning', $out);
    }

    public function test_the_core_maintenance_tool_reports_the_plugin_gates_it_used_to_miss(): void
    {
        $this->activate('under-construction-page');
        update_option('ucp_options', [ 'status' => '1' ]);

        $out = (new Get_Maintenance_Status())->handle([]);

        $this->assertFalse($out['enabled'], 'enabled stays WP MCP\'s own flag');
        $this->assertTrue($out['site_gated'], 'but the site is closed by a plugin');
        $this->assertSame([ 'ucp' ], $out['active_gates']);
    }
}
