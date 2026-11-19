<?php

namespace WPMCP\Tests\Free\Governance;

use WPMCP\Admin\Ability_Grid_Page;
use WPMCP\Admin\New_Tools_Notice;
use WPMCP\Governance\Default_Seeder;
use WPMCP\Governance\Governance;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Governance\New_Tools_Policy;
use WPMCP\Plugin;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Governance\Get_Governance_Settings;
use WPMCP\Tools\Governance\Update_Governance_Settings;
use WPMCP\Tools\Rollback_Operation;

/**
 * Issue #853: "Switch on new tools after updates".
 *
 * Off by default. When on, the versioned defaults seeder (#78) leaves a
 * newly shipped default-off ability switched on, except the abilities that
 * are never switched on automatically (code execution, file writes,
 * database writes, user and plugin management, anything behind an opt-in
 * filter), and never one the owner already switched off by hand. The
 * setting is audited, undoable over MCP, and the Dashboard notice after the
 * update lists the added tools with their state.
 */
class NewToolsPolicyTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Governance::reset_for_tests();
        delete_option(Default_Seeder::VERSION_OPTION);
        delete_option(Governance_Audit_Log::OPTION);
    }

    protected function tearDown(): void
    {
        Default_Seeder::set_versions_for_tests(null);
        Governance::reset_for_tests();
        delete_option(Default_Seeder::VERSION_OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        parent::tearDown();
    }

    /** Version 1 is already applied; version 2 is the "update". */
    private function bump(array $new_names): void
    {
        Default_Seeder::set_versions_for_tests([1 => []]);
        Default_Seeder::seed();
        Default_Seeder::set_versions_for_tests([1 => [], 2 => $new_names]);
        Default_Seeder::seed();
    }

    public function test_setting_is_off_by_default_and_new_tools_arrive_off(): void
    {
        $this->assertFalse(New_Tools_Policy::is_on());

        $this->bump(['wpmcp/list-widgets-new']);

        $this->assertFalse(Governance::ability_toggles()['wpmcp/list-widgets-new']);
        $this->assertSame(['wpmcp/list-widgets-new' => 'off'], New_Tools_Policy::last_added()['tools']);
    }

    public function test_setting_on_switches_on_new_safe_tools_only(): void
    {
        update_option(New_Tools_Policy::OPTION, true);

        $this->bump([
            'wpmcp/list-widgets-new',
            'wpmcp/run-php-snippet',   // code execution, opt-in gated
            'wpmcp/run-shell-new',     // code execution by name
            'wpmcp/write-file',        // file writes
            'wpmcp/upload-file-new',   // file writes by name
            'wpmcp/insert-row',        // database writes
            'wpmcp/update-rows-new',   // database writes by name
            'wpmcp/create-user',       // user management
            'wpmcp/set-user-role-new', // user management by name
            'wpmcp/install-plugin',    // plugin management
            'wpmcp/activate-plugin',   // plugin management
            'wpmcp/switch-theme',      // theme management
        ]);

        $toggles = Governance::ability_toggles();
        $this->assertArrayNotHasKey(
            'wpmcp/list-widgets-new',
            $toggles,
            'A safe new tool is left on with no stored decision, so the owner\'s next toggle is the only one.'
        );
        foreach (array_keys($toggles) as $name) {
            $this->assertFalse($toggles[ $name ], $name . ' must stay off.');
        }
        $this->assertCount(11, $toggles);

        $added = New_Tools_Policy::last_added();
        $this->assertSame(2, $added['version']);
        $this->assertSame('on', $added['tools']['wpmcp/list-widgets-new']);
        $this->assertSame('never_auto', $added['tools']['wpmcp/write-file']);
    }

    public function test_a_tool_switched_off_by_hand_stays_off_with_the_setting_on(): void
    {
        update_option(New_Tools_Policy::OPTION, true);
        Governance::set_ability_toggle('wpmcp/list-widgets-new', false);

        $this->bump(['wpmcp/list-widgets-new']);

        $this->assertFalse(Governance::ability_toggles()['wpmcp/list-widgets-new']);
        $this->assertSame('kept', New_Tools_Policy::last_added()['tools']['wpmcp/list-widgets-new']);
    }

    public function test_never_auto_list_covers_every_opt_in_gated_ability(): void
    {
        foreach (['wpmcp/run-wp-cli', 'wpmcp/delete-file', 'wpmcp/delete-rows', 'wpmcp/execute-site-ability', 'wpmcp/install-package-from-zip'] as $name) {
            $this->assertFalse(New_Tools_Policy::may_auto_enable($name), $name);
        }
        $this->assertTrue(New_Tools_Policy::may_auto_enable('wpmcp/list-posts'));
    }

    public function test_an_update_with_no_new_tools_leaves_no_notice(): void
    {
        $this->bump([]);

        $this->assertNull(New_Tools_Policy::last_added());
    }

    public function test_update_governance_settings_sets_audits_and_undoes_the_setting(): void
    {
        $out = (new Update_Governance_Settings())->handle(['auto_enable_new_tools' => true]);

        $this->assertTrue($out['updated']['auto_enable_new_tools']);
        $this->assertIsString($out['operation_id']);
        $this->assertTrue(New_Tools_Policy::is_on());

        $log = Governance_Audit_Log::list(5);
        $this->assertSame(New_Tools_Policy::AUDIT_SUBJECT, $log[0]['ability']);
        $this->assertTrue($log[0]['allowed']);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        wp_cache_delete(New_Tools_Policy::OPTION, 'options');
        $this->assertFalse(New_Tools_Policy::is_on());
    }

    public function test_update_governance_settings_skips_a_non_boolean_setting(): void
    {
        $out = (new Update_Governance_Settings())->handle(['auto_enable_new_tools' => 'yes']);

        $this->assertSame('auto_enable_new_tools', $out['skipped'][0]['key']);
        $this->assertNull($out['operation_id']);
        $this->assertFalse(New_Tools_Policy::is_on());
    }

    public function test_get_governance_settings_reports_the_setting(): void
    {
        $this->assertFalse((new Get_Governance_Settings())->handle([])['auto_enable_new_tools']);

        update_option(New_Tools_Policy::OPTION, true);

        $this->assertTrue((new Get_Governance_Settings())->handle([])['auto_enable_new_tools']);
    }

    public function test_tools_screen_switches_the_setting_and_audits_it(): void
    {
        Plugin::instance()->declared_abilities();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $result = (new Ability_Grid_Page())->handle_request([
            'wpmcp_grid_action' => 'toggle_auto_enable_new',
            '_wpnonce'          => wp_create_nonce(Ability_Grid_Page::NONCE_ACTION),
            'enabled'           => '1',
        ]);

        $this->assertSame('toggle_auto_enable_new', $result['action']);
        $this->assertIsString($result['operation_id']);
        $this->assertTrue(New_Tools_Policy::is_on());
        $this->assertSame(New_Tools_Policy::AUDIT_SUBJECT, Governance_Audit_Log::list(1)[0]['ability']);
    }

    public function test_dashboard_notice_lists_added_tools_and_their_state(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option(New_Tools_Policy::OPTION, true);
        $this->bump(['wpmcp/list-widgets-new', 'wpmcp/write-file']);

        ob_start();
        New_Tools_Notice::render_for_screen('dashboard');
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('wpmcp/list-widgets-new', $html);
        $this->assertStringContainsString('wpmcp/write-file', $html);
        $this->assertStringContainsString('switched on', $html);
        $this->assertStringContainsString('never switched on automatically', $html);

        ob_start();
        New_Tools_Notice::render_for_screen('edit-post');
        $this->assertSame('', (string) ob_get_clean(), 'The notice shows on the Dashboard only.');

        New_Tools_Policy::dismiss_notice();
        ob_start();
        New_Tools_Notice::render_for_screen('dashboard');
        $this->assertSame('', (string) ob_get_clean());
    }
}
