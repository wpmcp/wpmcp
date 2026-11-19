<?php

namespace WPMCP\Tests\Free\Governance;

use WPMCP\Admin\Opt_In_Settings_Page;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Governance\Opt_In_Settings;
use WPMCP\Tools\Meta\Option_Guard;
use WPMCP\Tools\Meta\Update_Option;

/**
 * Issue #823 part 4: the operations that are off by default behind an
 * opt-in filter can be switched on from the WP MCP admin, a group at a
 * time, by a site administrator (and network-wide by a network admin).
 *
 * Every toggle defaults off, a filter set in code wins (the toggle shows
 * "set in code" and cannot be changed), every change lands in the
 * governance audit log, and the stored toggles are locked against the
 * generic option tools, so an agent cannot switch on its own permissions.
 */
class OptInSettingsTest extends \WP_UnitTestCase
{
    private int $admin_id;

    protected function setUp(): void
    {
        parent::setUp();
        Opt_In_Settings::register();
        $this->admin_id = self::factory()->user->create(['role' => 'administrator']);
        if (is_multisite()) {
            grant_super_admin($this->admin_id);
        }
        wp_set_current_user($this->admin_id);
        delete_option(Opt_In_Settings::OPTION);
        delete_site_option(Opt_In_Settings::NETWORK_OPTION);
        delete_option(Governance_Audit_Log::OPTION);
    }

    protected function tearDown(): void
    {
        delete_option(Opt_In_Settings::OPTION);
        delete_site_option(Opt_In_Settings::NETWORK_OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        parent::tearDown();
    }

    private function post(string $filter, bool $enabled, array $extra = []): array
    {
        return array_merge([
            'wpmcp_opt_in_action' => 'toggle',
            '_wpnonce'            => wp_create_nonce(Opt_In_Settings_Page::NONCE_ACTION),
            'filter'              => $filter,
            'enabled'             => $enabled ? '1' : '0',
        ], $extra);
    }

    /**
     * Every opt_in_filter a pack declares, and the WooCommerce per-op
     * switch, has a group here, so a new default-off pack cannot ship
     * without its toggle. Found by scanning src/ the way the issue asks
     * (grep for opt_in_filter), resolving constants named in the same file.
     */
    public function test_every_opt_in_filter_in_src_has_a_group(): void
    {
        $root  = dirname(__DIR__, 3);
        $found = [ 'wpmcp_woo_op_enabled' => true ];
        $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (! preg_match_all("/'opt_in_filter'\s*=>\s*([^,\]\n]+)/", $code, $m)) {
                continue;
            }
            foreach ($m[1] as $expr) {
                $expr = trim($expr);
                if (preg_match("/^'([a-z0-9_]+)'$/", $expr, $lit)) {
                    $found[ $lit[1] ] = true;
                    continue;
                }
                if (preg_match('/^(?:self|([A-Za-z_]+))::([A-Z_]+)$/', $expr, $const)) {
                    $class = '' === $const[1] ? null : $const[1];
                    $src   = null === $class ? $code : (string) file_get_contents($file->getPath() . '/' . $class . '.php');
                    if (preg_match('/const\s+' . $const[2] . "\s*=\s*'([a-z0-9_]+)'/", $src, $val)) {
                        $found[ $val[1] ] = true;
                        continue;
                    }
                }
                // '(string) ($def[...])' style reads in the dispatcher itself are not declarations.
                if (false !== strpos($expr, '$')) {
                    continue;
                }
                $this->fail('Could not resolve opt_in_filter expression ' . $expr . ' in ' . $file->getPathname());
            }
        }

        $groups = Opt_In_Settings::all_groups();
        $this->assertSame([], array_values(array_diff(array_keys($found), array_keys($groups))), 'opt_in_filter values with no settings toggle');
        $this->assertSame([], array_values(array_diff(array_keys($groups), array_keys($found))), 'toggles for filters no pack declares');

        foreach ($groups as $filter => $group) {
            $owner = $root . '/' . $group['owner'];
            $this->assertFileExists($owner, $filter);
            $this->assertStringContainsString("'" . $filter . "'", (string) file_get_contents($owner), $filter . ' is not named by its owner file');
        }
    }

    /** The core tool gates the ability grid refuses to open stay code-only. */
    /** Every group the screen can list has a readable label (a group without one showed its raw filter name). */
    public function test_every_group_has_a_label(): void
    {
        $missing = array_values(array_diff(array_keys(Opt_In_Settings::all_groups()), array_keys(Opt_In_Settings::labels())));
        $this->assertSame([], $missing, 'Opt-in groups with no label in Opt_In_Settings::labels()');
    }

    public function test_code_only_gates_have_no_toggle(): void
    {
        $groups = Opt_In_Settings::all_groups();
        foreach ([ 'wpmcp_enable_db_writes', 'wpmcp_enable_fs_writes', 'wpmcp_allow_wp_cli', 'wpmcp_allow_php_exec', 'wpmcp_enable_option_write', 'wpmcp_integration_op_enabled' ] as $filter) {
            $this->assertArrayNotHasKey($filter, $groups);
        }
    }

    public function test_every_toggle_defaults_off(): void
    {
        foreach (array_keys(Opt_In_Settings::groups()) as $filter) {
            $this->assertFalse(Opt_In_Settings::is_on($filter), $filter);
            $this->assertFalse((bool) apply_filters($filter, false), $filter);
        }
        $this->assertFalse((bool) apply_filters('wpmcp_woo_op_enabled', false, 'subscriptions.cancel'));
    }

    public function test_site_toggle_switches_the_filter_on_and_is_audited(): void
    {
        $result = (new Opt_In_Settings_Page())->handle_request($this->post('wpmcp_enable_memberpress_write', true));

        $this->assertSame('toggle', $result['action'] ?? null, (string) ($result['error'] ?? ''));
        $this->assertTrue(Opt_In_Settings::is_on('wpmcp_enable_memberpress_write'));
        $this->assertTrue((bool) apply_filters('wpmcp_enable_memberpress_write', false));
        // Other groups stay off.
        $this->assertFalse((bool) apply_filters('wpmcp_enable_forms_write', false));

        $log = Governance_Audit_Log::list();
        $this->assertSame('opt-in:wpmcp_enable_memberpress_write', $log[0]['ability']);
        $this->assertTrue($log[0]['allowed']);
        $this->assertSame('user:' . wp_get_current_user()->user_login, $log[0]['identity']);

        (new Opt_In_Settings_Page())->handle_request($this->post('wpmcp_enable_memberpress_write', false));
        $this->assertFalse((bool) apply_filters('wpmcp_enable_memberpress_write', false));
        $log = Governance_Audit_Log::list();
        $this->assertSame('opt-in:wpmcp_enable_memberpress_write', $log[0]['ability']);
        $this->assertFalse($log[0]['allowed']);
    }

    public function test_woo_toggle_opens_default_off_ops_and_keeps_args(): void
    {
        Opt_In_Settings::set('wpmcp_woo_op_enabled', true, 'site');
        $this->assertTrue((bool) apply_filters('wpmcp_woo_op_enabled', false, 'subscriptions.cancel'));
        $this->assertTrue((bool) apply_filters('wpmcp_woo_op_enabled', true, 'products.update'));
    }

    public function test_a_filter_set_in_code_wins_and_locks_the_toggle(): void
    {
        Opt_In_Settings::set('wpmcp_enable_forms_write', true, 'site');
        add_filter('wpmcp_enable_forms_write', '__return_false');

        $this->assertTrue(Opt_In_Settings::set_in_code('wpmcp_enable_forms_write'));
        $this->assertFalse((bool) apply_filters('wpmcp_enable_forms_write', false), 'code said no');

        // A code filter that passes the value through still decides alone:
        // the stored toggle does not leak into it.
        remove_filter('wpmcp_enable_forms_write', '__return_false');
        $passthrough = static fn ($on) => $on;
        add_filter('wpmcp_enable_forms_write', $passthrough);
        $this->assertFalse((bool) apply_filters('wpmcp_enable_forms_write', false));

        $result = (new Opt_In_Settings_Page())->handle_request($this->post('wpmcp_enable_forms_write', false));
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('set in code', $result['error']);
        remove_filter('wpmcp_enable_forms_write', $passthrough);

        $row = (new Opt_In_Settings_Page())->rows('site')['wpmcp_enable_forms_write'] ?? null;
        $this->assertNotNull($row);
        $this->assertFalse($row['set_in_code']);
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $row = (new Opt_In_Settings_Page())->rows('site')['wpmcp_enable_forms_write'];
        $this->assertTrue($row['set_in_code']);
        remove_filter('wpmcp_enable_forms_write', '__return_true');
    }

    public function test_destructive_groups_carry_a_warning(): void
    {
        $rows = (new Opt_In_Settings_Page())->rows('site');
        $this->assertTrue($rows['wpmcp_woo_op_enabled']['destructive']);
        $this->assertNotSame('', $rows['wpmcp_woo_op_enabled']['warning']);
        $this->assertTrue($rows['wpmcp_enable_crm_send']['destructive']);
        $this->assertFalse($rows['wpmcp_enable_pods_write']['destructive']);
        $this->assertSame('', $rows['wpmcp_enable_pods_write']['warning']);
    }

    public function test_screen_renders_state_warning_and_locked_row(): void
    {
        Opt_In_Settings::set('wpmcp_enable_pods_write', true, 'site');
        add_filter('wpmcp_enable_acf_write', '__return_true');

        ob_start();
        (new Opt_In_Settings_Page())->render();
        $html = (string) ob_get_clean();
        remove_filter('wpmcp_enable_acf_write', '__return_true');

        $this->assertStringContainsString('wpmcp_enable_pods_write', $html);
        $this->assertStringContainsString('Switch off', $html);
        $this->assertStringContainsString('set in code', $html);
        $this->assertStringContainsString('undo cannot fully reverse', $html);
        $this->assertStringContainsString('disabled', $html);
    }

    public function test_refuses_without_capability_nonce_or_known_group(): void
    {
        $page = new Opt_In_Settings_Page();

        $this->assertArrayHasKey('error', $page->handle_request($this->post('wpmcp_enable_pods_write', true, [ '_wpnonce' => 'nope' ])));
        $this->assertArrayHasKey('error', $page->handle_request($this->post('wpmcp_enable_db_writes', true)));
        $this->assertArrayHasKey('error', $page->handle_request($this->post('', true)));

        $editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor);
        $this->assertArrayHasKey('error', $page->handle_request($this->post('wpmcp_enable_pods_write', true)));

        $this->assertFalse(Opt_In_Settings::is_on('wpmcp_enable_pods_write'));
        $this->assertFalse(Opt_In_Settings::is_on('wpmcp_enable_db_writes'));
        $this->assertSame([], Governance_Audit_Log::list());
    }

    public function test_network_scope_needs_a_network_admin_and_multisite(): void
    {
        $page = new Opt_In_Settings_Page();
        if (! is_multisite()) {
            $result = $page->handle_request($this->post('wpmcp_enable_pods_write', true), 'network');
            $this->assertArrayHasKey('error', $result);
            $this->assertFalse(Opt_In_Settings::is_on('wpmcp_enable_pods_write'));
            return;
        }

        $site_admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($site_admin);
        $this->assertArrayHasKey('error', $page->handle_request($this->post('wpmcp_enable_pods_write', true), 'network'));

        wp_set_current_user($this->admin_id);
        $result = $page->handle_request($this->post('wpmcp_enable_pods_write', true), 'network');
        $this->assertSame('toggle', $result['action'] ?? null, (string) ($result['error'] ?? ''));
        $this->assertTrue(Opt_In_Settings::is_on('wpmcp_enable_pods_write'));
        $this->assertSame([], (array) get_option(Opt_In_Settings::OPTION, []), 'network toggle is not a site toggle');

        // A test plugin still hooks the deprecated wpmu_new_blog.
        $this->setExpectedDeprecated('wpmu_new_blog');
        $blog = self::factory()->blog->create();
        switch_to_blog($blog);
        $this->assertTrue((bool) apply_filters('wpmcp_enable_pods_write', false), 'network-wide reaches every site');
        restore_current_blog();

        $log = Governance_Audit_Log::list();
        $this->assertSame('opt-in:wpmcp_enable_pods_write', $log[0]['ability']);
        $this->assertSame('network', $log[0]['reason']);
    }

    public function test_the_stored_toggles_are_locked_from_the_option_tools(): void
    {
        $this->assertTrue(Option_Guard::is_denylisted(Opt_In_Settings::OPTION));
        $this->assertTrue(Option_Guard::is_denylisted(Opt_In_Settings::NETWORK_OPTION));
        $this->assertTrue(Option_Guard::is_denylisted(' WPMCP_OPT_IN_GROUPS '));

        // Locked even when a site trims the filterable denylist.
        add_filter('wpmcp_option_denylist', '__return_empty_array');
        add_filter('wpmcp_option_denylist_patterns', '__return_empty_array');
        $this->assertTrue(Option_Guard::is_denylisted(Opt_In_Settings::OPTION));

        add_filter('wpmcp_enable_option_write', '__return_true');
        try {
            (new Update_Option())->handle([ 'name' => Opt_In_Settings::OPTION, 'value' => [ 'wpmcp_enable_db_writes' => 1 ] ]);
            $this->fail('update-option wrote the opt-in toggles');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('sensitive option', $e->getMessage());
        }
        $this->assertFalse(Opt_In_Settings::is_on('wpmcp_enable_memberpress_write'));
    }

    public function test_stored_values_for_unknown_filters_are_ignored(): void
    {
        update_option(Opt_In_Settings::OPTION, [ 'wpmcp_enable_db_writes' => 1, 'wpmcp_enable_pods_write' => 'yes' ]);
        $this->assertFalse(Opt_In_Settings::is_on('wpmcp_enable_db_writes'));
        $this->assertFalse((bool) apply_filters('wpmcp_enable_db_writes', false));
        $this->assertTrue(Opt_In_Settings::is_on('wpmcp_enable_pods_write'));
    }
}
