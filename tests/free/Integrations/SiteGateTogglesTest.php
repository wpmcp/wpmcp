<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Site_Gate_Pack;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\Maintenance\Site_Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

/**
 * Issue #903, second slice: enable-site-gate and disable-site-gate also
 * toggle WP Maintenance Mode (LightStart), Maintenance and CMP, each in the
 * plugin's own stored format (verified against the free plugins from
 * wordpress.org: wp-maintenance-mode 2.6.26, maintenance 4.33,
 * cmp-coming-soon-maintenance 4.1.22), with the same lockout refusal,
 * confirm, probe and one option_set snapshot as the first two.
 */
class SiteGateTogglesTest extends \WP_UnitTestCase
{
    private const OPTIONS = [
        'wpmcp_maintenance', 'seedprod_settings', 'ucp_options', 'wpmm_settings', 'wpmm_version', 'mtnc-options',
        'niteoCS_status', 'niteoCS_activation', 'niteoCS_roles', 'niteoCS_counter', 'niteoCS_countdown_action',
        'niteoCS_counter_date', 'active_plugins',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $GLOBALS['wpdb']->query('DELETE FROM ' . Snapshot_Store::table_name());
        add_filter('pre_http_request', [ $this, 'serve' ], 1, 3);
        update_option('active_plugins', []);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter(Site_Gate_Pack::ENABLE_FILTER, '__return_true');
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [ $this, 'serve' ], 1);
        remove_all_filters(Site_Gate_Pack::ENABLE_FILTER);
        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }
        remove_role('gate_manager');
        parent::tearDown();
    }

    public function serve($pre, $args, $url)
    {
        return [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 200, 'message' => '' ], 'cookies' => [], 'filename' => null ];
    }

    private function activate(string $slug): void
    {
        update_option('active_plugins', array_merge((array) get_option('active_plugins'), [ Site_Gate::PLUGINS[ $slug ] ]));
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

    /** An acting user who manages options but is not a super admin (no delete_users). */
    private function act_as_manager(): void
    {
        add_role('gate_manager', 'Gate manager', [ 'read' => true, 'manage_options' => true, 'edit_theme_options' => true, 'edit_posts' => true ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'gate_manager' ]));
    }

    private function wpmm(array $general = [], array $design = []): void
    {
        update_option('wpmm_version', '2.6.26');
        update_option('wpmm_settings', [
            'general' => $general + [ 'status' => 0, 'frontend_role' => [], 'backend_role' => [], 'exclude' => [ 'feed' ] ],
            'design'  => $design + [ 'heading' => 'Keep me' ],
        ]);
    }

    public function test_the_plugin_enum_lists_every_toggleable_plugin(): void
    {
        $ops  = array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
        $enum = $ops['enable-site-gate']['input_schema']['properties']['plugin']['enum'] ?? [];

        foreach ([ 'auto', 'coming-soon', 'under-construction-page', 'wp-maintenance-mode', 'maintenance', 'cmp-coming-soon-maintenance' ] as $slug) {
            $this->assertContains($slug, $enum);
        }
    }

    public function test_wp_maintenance_mode_writes_an_integer_status_keeps_the_rest_and_undoes(): void
    {
        $this->activate('wp-maintenance-mode');
        $this->wpmm();

        $ask = $this->write('enable-site-gate', [ 'plugin' => 'auto' ]);
        $this->assertSame('confirmation_required', $this->code($ask));
        $this->assertStringContainsString('WP Maintenance Mode', $ask['error']['message']);
        $this->assertSame(0, get_option('wpmm_settings')['general']['status'], 'the preview writes nothing');

        $out = $this->write('enable-site-gate', [ 'plugin' => 'auto' ], true);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $stored = get_option('wpmm_settings');
        $this->assertSame(1, $stored['general']['status'], 'the plugin compares the status with === 1');
        $this->assertNotEmpty($stored['general']['status_date']);
        $this->assertSame([ 'feed' ], $stored['general']['exclude']);
        $this->assertSame('Keep me', $stored['design']['heading']);
        $this->assertTrue($out['result']['gate']['active']);
        $this->assertSame('wp-maintenance-mode', $out['result']['plugin']);

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame(0, get_option('wpmm_settings')['general']['status']);
        $this->assertArrayNotHasKey('status_date', get_option('wpmm_settings')['general']);

        update_option('wpmm_settings', [ 'general' => [ 'status' => 1, 'exclude' => [ 'feed' ] ] ]);
        $off = $this->write('disable-site-gate', []);
        $this->assertArrayNotHasKey('error', $off, (string) wp_json_encode($off));
        $this->assertSame(0, get_option('wpmm_settings')['general']['status']);
        $this->assertSame([ 'feed' ], get_option('wpmm_settings')['general']['exclude']);
    }

    public function test_wp_maintenance_mode_refuses_a_user_it_would_lock_out_and_an_old_version(): void
    {
        $this->activate('wp-maintenance-mode');
        $this->act_as_manager();
        $this->wpmm([ 'frontend_role' => [ 'editor' ], 'backend_role' => [ 'editor' ] ]);

        $out = $this->write('enable-site-gate', [ 'plugin' => 'wp-maintenance-mode' ], true);
        $this->assertSame('admin_not_bypassed', $this->code($out));
        $this->assertSame(0, get_option('wpmm_settings')['general']['status']);
        $this->assertSame([], Snapshot_Store::list_by_session('default'));

        $this->wpmm([ 'frontend_role' => [ 'gate_manager' ], 'backend_role' => [ 'gate_manager' ] ]);
        $this->assertArrayNotHasKey('error', $this->write('enable-site-gate', [ 'plugin' => 'wp-maintenance-mode' ], true), 'a listed role passes');

        $this->wpmm();
        update_option('wpmm_version', '2.6.22');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $this->assertSame('plugin_version_unsupported', $this->code($this->write('enable-site-gate', [ 'plugin' => 'wp-maintenance-mode' ], true)));
    }

    public function test_maintenance_writes_its_nested_status_keeps_the_secrets_and_undoes(): void
    {
        $this->activate('maintenance');
        $before = [ 'options' => [ 'status' => '0', 'show_logged_in' => '1', 'site_password' => 'SENTINEL-PW', 'blockse' => '1', 'header_text' => 'Back soon' ], 'meta' => [ 'first_version' => '4.33' ] ];
        update_option('mtnc-options', $before);

        $out = $this->write('enable-site-gate', [ 'plugin' => 'maintenance' ], true);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $stored = get_option('mtnc-options');
        $this->assertSame('1', $stored['options']['status']);
        $this->assertSame('SENTINEL-PW', $stored['options']['site_password']);
        $this->assertSame($before['meta'], $stored['meta']);
        $this->assertStringNotContainsString('SENTINEL', (string) wp_json_encode($out), 'the password is never returned');
        $this->assertSame('maintenance', $out['result']['gate']['mode']);

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame($before, get_option('mtnc-options'));

        update_option('mtnc-options', [ 'options' => [ 'status' => '1', 'show_logged_in' => '1' ] ]);
        $this->assertArrayNotHasKey('error', $this->write('disable-site-gate', [ 'plugin' => 'maintenance' ]));
        $this->assertSame('0', get_option('mtnc-options')['options']['status']);
    }

    public function test_maintenance_refuses_when_logged_in_users_would_see_the_page_too(): void
    {
        $this->activate('maintenance');
        update_option('mtnc-options', [ 'options' => [ 'status' => '0', 'show_logged_in' => '0' ] ]);

        $out = $this->write('enable-site-gate', [ 'plugin' => 'maintenance' ], true);

        $this->assertSame('admin_not_bypassed', $this->code($out));
        $this->assertSame('0', get_option('mtnc-options')['options']['status']);
    }

    public function test_cmp_writes_status_and_mode_options_and_undoes_both(): void
    {
        $this->activate('cmp-coming-soon-maintenance');
        update_option('niteoCS_status', '0');
        update_option('niteoCS_activation', '2');

        $out = $this->write('enable-site-gate', [ 'plugin' => 'cmp-coming-soon-maintenance', 'mode' => 'maintenance' ], true);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame('1', get_option('niteoCS_status'));
        $this->assertSame('1', get_option('niteoCS_activation'), 'maintenance is CMP mode 1');
        $this->assertSame('maintenance', $out['result']['gate']['mode']);

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame('0', get_option('niteoCS_status'));
        $this->assertSame('2', get_option('niteoCS_activation'));

        update_option('niteoCS_status', '1');
        $off = $this->write('disable-site-gate', []);
        $this->assertArrayNotHasKey('error', $off, (string) wp_json_encode($off));
        $this->assertSame('0', get_option('niteoCS_status'));
        $this->assertSame('2', get_option('niteoCS_activation'), 'disabling leaves the mode alone');
    }

    public function test_cmp_without_a_mode_keeps_its_stored_mode(): void
    {
        $this->activate('cmp-coming-soon-maintenance');
        update_option('niteoCS_activation', '3');

        $out = $this->write('enable-site-gate', [ 'plugin' => 'cmp-coming-soon-maintenance' ], true);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame('3', get_option('niteoCS_activation'));
    }

    public function test_cmp_refuses_a_user_it_would_lock_out_and_a_countdown_that_already_ended(): void
    {
        $this->activate('cmp-coming-soon-maintenance');
        $this->act_as_manager();
        update_option('niteoCS_roles', wp_json_encode([ 'editor' ]));

        $this->assertSame('admin_not_bypassed', $this->code($this->write('enable-site-gate', [ 'plugin' => 'cmp-coming-soon-maintenance' ], true)));
        $this->assertSame('0', (string) get_option('niteoCS_status', '0'));

        update_option('niteoCS_roles', wp_json_encode([ 'gate_manager' ]));
        update_option('niteoCS_counter', '1');
        update_option('niteoCS_countdown_action', 'disable-cmp');
        update_option('niteoCS_counter_date', (string) (time() - 60));
        $this->assertSame('end_date_passed', $this->code($this->write('enable-site-gate', [ 'plugin' => 'cmp-coming-soon-maintenance' ], true)));

        update_option('niteoCS_countdown_action', 'no-action');
        $this->assertArrayNotHasKey('error', $this->write('enable-site-gate', [ 'plugin' => 'cmp-coming-soon-maintenance' ], true), 'a listed role passes');
    }

    public function test_auto_with_none_of_the_five_active_names_them_all(): void
    {
        $out = $this->write('enable-site-gate', [ 'plugin' => 'auto' ], true);

        $this->assertSame('site_gate_plugin_inactive', $this->code($out));
        foreach ([ 'SeedProd', 'Under Construction', 'WP Maintenance Mode', 'Maintenance', 'CMP' ] as $label) {
            $this->assertStringContainsString($label, $out['error']['message']);
        }
    }
}
