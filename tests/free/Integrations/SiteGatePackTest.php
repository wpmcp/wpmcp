<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Site_Gate_Pack;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\Maintenance\Site_Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

/**
 * Issue #903: get-site-gate-status and the SeedProd and Under Construction
 * toggles on the theme pair. Writes are off until the site opts in, refuse a
 * lockout, a second gate and a missing page, probe the login screen and the
 * REST root after enabling, and are one option_set snapshot each.
 */
class SiteGatePackTest extends \WP_UnitTestCase
{
    /** @var array<string,int> url fragment => status the loopback answers. */
    private array $status = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        add_filter('pre_http_request', [ $this, 'serve' ], 1, 3);
        update_option('active_plugins', []);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [ $this, 'serve' ], 1);
        remove_all_filters(Site_Gate_Pack::ENABLE_FILTER);
        foreach ([ 'wpmcp_maintenance', 'seedprod_settings', 'seedprod_coming_soon_page_id', 'seedprod_maintenance_mode_page_id', 'ucp_options', 'active_plugins' ] as $option) {
            delete_option($option);
        }
        parent::tearDown();
    }

    public function serve($pre, $args, $url)
    {
        $code = 200;
        foreach ($this->status as $fragment => $status) {
            if (false !== strpos($url, $fragment)) {
                $code = $status;
            }
        }
        return [ 'headers' => [], 'body' => '', 'response' => [ 'code' => $code, 'message' => '' ], 'cookies' => [], 'filename' => null ];
    }

    private function activate(string $slug): void
    {
        update_option('active_plugins', array_merge((array) get_option('active_plugins'), [ Site_Gate::PLUGINS[ $slug ] ]));
    }

    private function opt_in(): void
    {
        add_filter(Site_Gate_Pack::ENABLE_FILTER, '__return_true');
    }

    private function read(string $op): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => [] ]);
    }

    private function write(string $op, array $args, bool $confirm = false): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if ($confirm) {
            $call['confirm'] = true;
        }
        return (new Theme_Integration())->handle_write($call);
    }

    private function code(array $out): string
    {
        return (string) ($out['error']['code'] ?? '');
    }

    private function seedprod_page(array $json = [], string $key = 'seedprod_coming_soon_page_id'): int
    {
        $page = self::factory()->post->create([ 'post_type' => 'page', 'post_status' => 'draft', 'post_content_filtered' => wp_json_encode($json) ]);
        update_option($key, $page);
        return $page;
    }

    public function test_the_ops_are_free_on_the_theme_pair_and_writes_are_off_until_opt_in(): void
    {
        $ops = array_column((new Theme_Integration())->catalog()['operations'], null, 'name');

        $this->assertSame('read', $ops['get-site-gate-status']['mode']);
        $this->assertSame('destructive', $ops['enable-site-gate']['mode']);
        $this->assertSame('write', $ops['disable-site-gate']['mode']);
        $this->assertTrue($ops['get-site-gate-status']['enabled']);
        $this->assertFalse($ops['enable-site-gate']['enabled']);

        $this->activate('under-construction-page');
        $out = $this->write('enable-site-gate', [ 'plugin' => 'under-construction-page' ], true);
        $this->assertSame('opt_in_required', $this->code($out));
        $this->assertStringContainsString(Site_Gate_Pack::ENABLE_FILTER, $out['error']['message']);
        $this->assertFalse(get_option('ucp_options'), 'a refused write changes nothing');
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_get_site_gate_status_answers_from_the_plugin_not_the_wpmcp_flag(): void
    {
        $this->activate('under-construction-page');
        update_option('ucp_options', [ 'status' => '1' ]);

        $out = $this->read('get-site-gate-status')['result'];

        $this->assertTrue($out['public_site_gated']);
        $this->assertSame([ 'ucp' ], $out['active_gates']);
        $this->assertArrayHasKey('probe', $out);
    }

    public function test_enabling_under_construction_asks_for_confirmation_then_writes_one_snapshotted_flag_and_undoes(): void
    {
        $this->opt_in();
        $this->activate('under-construction-page');
        update_option('ucp_options', [ 'status' => '0', 'heading1' => 'Keep me', 'whitelisted_roles' => [ 'administrator' ] ]);

        $ask = $this->write('enable-site-gate', [ 'plugin' => 'auto' ]);
        $this->assertSame('confirmation_required', $this->code($ask));
        $this->assertStringContainsString('Under Construction', $ask['error']['message']);
        $this->assertSame('0', get_option('ucp_options')['status'], 'the preview writes nothing');

        $out = $this->write('enable-site-gate', [ 'plugin' => 'auto' ], true);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertTrue($out['result']['enabled']);
        $this->assertTrue($out['result']['gate']['active']);
        $this->assertSame('1', get_option('ucp_options')['status']);
        $this->assertSame('Keep me', get_option('ucp_options')['heading1'], 'other settings are untouched');
        $this->assertArrayHasKey('cache_hint', $out['result']);

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame('0', get_option('ucp_options')['status']);
        $this->assertSame('Keep me', get_option('ucp_options')['heading1']);
    }

    public function test_enabling_refuses_when_the_acting_users_role_is_not_whitelisted(): void
    {
        $this->opt_in();
        $this->activate('under-construction-page');
        update_option('ucp_options', [ 'status' => '0', 'whitelisted_roles' => [ 'editor' ] ]);

        $out = $this->write('enable-site-gate', [ 'plugin' => 'under-construction-page' ], true);

        $this->assertSame('admin_not_bypassed', $this->code($out));
        $this->assertSame('0', get_option('ucp_options')['status']);
        $this->assertSame([], Snapshot_Store::list_by_session('default'));

        update_option('ucp_options', [ 'status' => '0', 'whitelisted_roles' => [ 'editor' ], 'whitelisted_users' => [ get_current_user_id() ] ]);
        $this->assertArrayNotHasKey('error', $this->write('enable-site-gate', [ 'plugin' => 'under-construction-page' ], true), 'a whitelisted user passes');
    }

    public function test_enabling_refuses_a_second_gate_an_ended_date_and_an_inactive_plugin(): void
    {
        $this->opt_in();
        $this->activate('under-construction-page');
        update_option('wpmcp_maintenance', [ 'enabled' => true ]);
        $this->assertSame('gate_already_active', $this->code($this->write('enable-site-gate', [ 'plugin' => 'under-construction-page' ], true)));

        delete_option('wpmcp_maintenance');
        update_option('ucp_options', [ 'status' => '0', 'end_date' => '2001-01-01 10:00' ]);
        $this->assertSame('end_date_passed', $this->code($this->write('enable-site-gate', [ 'plugin' => 'under-construction-page' ], true)));

        $this->assertSame('site_gate_plugin_inactive', $this->code($this->write('enable-site-gate', [ 'plugin' => 'coming-soon' ], true)));
    }

    public function test_enabling_an_already_active_gate_is_no_change(): void
    {
        $this->opt_in();
        $this->activate('under-construction-page');
        update_option('ucp_options', [ 'status' => '1' ]);

        $this->assertSame('no_change', $this->code($this->write('enable-site-gate', [ 'plugin' => 'under-construction-page' ], true)));
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_auto_needs_exactly_one_toggleable_plugin(): void
    {
        $this->opt_in();
        $this->assertSame('site_gate_plugin_inactive', $this->code($this->write('enable-site-gate', [ 'plugin' => 'auto' ], true)));

        $this->activate('under-construction-page');
        $this->activate('coming-soon');
        $this->assertSame('plugin_required', $this->code($this->write('enable-site-gate', [ 'plugin' => 'auto' ], true)));
    }

    public function test_seedprod_writes_its_json_string_keeps_the_licence_and_other_flags_and_undoes(): void
    {
        $this->opt_in();
        $this->activate('coming-soon');
        $page = $this->seedprod_page([ 'access_by_role' => [ 'administrator' ] ]);
        $before = wp_json_encode([ 'api_key' => 'SENTINEL-LICENCE', 'enable_coming_soon_mode' => false, 'enable_maintenance_mode' => false, 'enable_404_mode' => true ]);
        update_option('seedprod_settings', $before);

        $out = $this->write('enable-site-gate', [ 'plugin' => 'coming-soon', 'mode' => 'coming_soon' ], true);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $stored = json_decode((string) get_option('seedprod_settings'), true);
        $this->assertTrue($stored['enable_coming_soon_mode']);
        $this->assertFalse($stored['enable_maintenance_mode']);
        $this->assertTrue($stored['enable_404_mode'], 'other modes are untouched');
        $this->assertSame('SENTINEL-LICENCE', $stored['api_key'], 'the licence is kept');
        $this->assertStringNotContainsString('SENTINEL', (string) wp_json_encode($out), 'and never returned');
        $this->assertSame($page, $out['result']['gate']['page_id']);
        $this->assertArrayHasKey('note', $out['result']);

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame($before, get_option('seedprod_settings'), 'the JSON string comes back byte for byte');
    }

    public function test_seedprod_refuses_without_its_page_and_when_the_page_locks_the_user_out(): void
    {
        $this->opt_in();
        $this->activate('coming-soon');
        update_option('seedprod_settings', wp_json_encode([ 'enable_coming_soon_mode' => false ]));

        $this->assertSame('seedprod_page_missing', $this->code($this->write('enable-site-gate', [ 'plugin' => 'coming-soon' ], true)));

        $this->seedprod_page([ 'access_by_role' => [ 'subscriber' ] ]);
        $this->assertSame('admin_not_bypassed', $this->code($this->write('enable-site-gate', [ 'plugin' => 'coming-soon' ], true)));
        $this->assertFalse(json_decode((string) get_option('seedprod_settings'), true)['enable_coming_soon_mode']);
    }

    public function test_seedprod_maintenance_mode_uses_the_maintenance_page_option(): void
    {
        $this->opt_in();
        $this->activate('coming-soon');
        $this->seedprod_page([], 'seedprod_maintenance_mode_page_id');
        update_option('seedprod_settings', wp_json_encode([]));

        $out = $this->write('enable-site-gate', [ 'plugin' => 'coming-soon', 'mode' => 'maintenance' ], true);

        $stored = json_decode((string) get_option('seedprod_settings'), true);
        $this->assertTrue($stored['enable_maintenance_mode']);
        $this->assertFalse($stored['enable_coming_soon_mode']);
        $this->assertSame('maintenance', $out['result']['gate']['mode']);
    }

    public function test_a_login_screen_that_answers_503_puts_the_settings_back_and_voids_the_undo_point(): void
    {
        $this->opt_in();
        $this->activate('under-construction-page');
        update_option('ucp_options', [ 'status' => '0', 'whitelisted_roles' => [ 'administrator' ] ]);
        $this->status = [ 'wp-login.php' => 503 ];

        $out = $this->write('enable-site-gate', [ 'plugin' => 'under-construction-page' ], true);

        $this->assertSame('gate_blocked_login', $this->code($out));
        $this->assertSame('0', get_option('ucp_options')['status']);
        $this->assertSame([], Snapshot_Store::list_by_session('default'), 'no undo point is left for a write that was put back');
    }

    public function test_an_error_while_probing_puts_the_settings_back_and_rethrows(): void
    {
        $this->opt_in();
        $this->activate('under-construction-page');
        update_option('ucp_options', [ 'status' => '0' ]);
        add_filter('pre_http_request', static function () {
            throw new \RuntimeException('probe blew up');
        }, 0);

        try {
            $this->write('enable-site-gate', [ 'plugin' => 'under-construction-page' ], true);
            $this->fail('the error should propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('probe blew up', $e->getMessage());
        }

        $this->assertSame('0', get_option('ucp_options')['status'], 'the gate is not left on');
    }

    public function test_a_rest_root_that_answers_503_puts_the_settings_back(): void
    {
        $this->opt_in();
        $this->activate('under-construction-page');
        update_option('ucp_options', [ 'status' => '0' ]);
        $this->status = [ 'wp-json' => 503, 'rest_route' => 503 ];

        $out = $this->write('enable-site-gate', [ 'plugin' => 'under-construction-page' ], true);

        $this->assertSame('gate_blocked_rest', $this->code($out));
        $this->assertSame('0', get_option('ucp_options')['status']);
    }

    public function test_a_maintenance_mode_that_the_home_page_does_not_503_warns_about_the_cache(): void
    {
        $this->opt_in();
        $this->activate('coming-soon');
        $this->seedprod_page([], 'seedprod_maintenance_mode_page_id');
        update_option('seedprod_settings', wp_json_encode([]));

        $out = $this->write('enable-site-gate', [ 'plugin' => 'coming-soon', 'mode' => 'maintenance' ], true);

        $this->assertStringContainsString('cache', $out['result']['warning']);
    }

    public function test_disabling_needs_no_confirm_is_undoable_and_refuses_when_nothing_is_on(): void
    {
        $this->opt_in();
        $this->activate('under-construction-page');
        update_option('ucp_options', [ 'status' => '0' ]);
        $this->assertSame('no_change', $this->code($this->write('disable-site-gate', [])));

        update_option('ucp_options', [ 'status' => '1', 'heading1' => 'Keep' ]);
        $out = $this->write('disable-site-gate', []);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertFalse($out['result']['enabled']);
        $this->assertSame('0', get_option('ucp_options')['status']);
        $this->assertSame('Keep', get_option('ucp_options')['heading1']);

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame('1', get_option('ucp_options')['status']);
    }

    public function test_disabling_seedprod_clears_both_flags(): void
    {
        $this->opt_in();
        $this->activate('coming-soon');
        $this->seedprod_page();
        update_option('seedprod_settings', wp_json_encode([ 'api_key' => 'k', 'enable_coming_soon_mode' => true ]));

        $out = $this->write('disable-site-gate', [ 'plugin' => 'coming-soon' ]);

        $stored = json_decode((string) get_option('seedprod_settings'), true);
        $this->assertFalse($stored['enable_coming_soon_mode']);
        $this->assertFalse($stored['enable_maintenance_mode']);
        $this->assertSame('k', $stored['api_key']);
        $this->assertFalse($out['result']['gate']['active']);
    }
}
