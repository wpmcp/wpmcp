<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\MCP\Registrar;
use WPMCP\Plugin;
use WPMCP\Tools\Dispatch\Call_Tool;

/**
 * Issue #875 (redesign of #931): the registered permission callback returns
 * a bool, never a WP_Error, because WP_Ability::execute() flags a WP_Error
 * there as incorrect usage and replaces it with a generic error. The refusal
 * naming the missing capability reaches a standalone check through
 * wp_ability_permission_result, and call-tool through explain_denial().
 * Any unexpected incorrect-usage notice fails these tests on its own.
 */
class RefusalThroughCoreTest extends \WP_UnitTestCase
{
    private function as_editor(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
    }

    private function needs_permission_filter(): void
    {
        if (! class_exists('WP_Filter_Sentinel', false)) {
            $this->markTestSkipped('wp_ability_permission_result is WordPress 7.1.');
        }
    }

    public function test_the_registered_callback_returns_false_not_a_wp_error(): void
    {
        $this->needs_permission_filter();
        $this->as_editor();
        $ability = Plugin::instance()->registrar()->get('wpmcp/get-settings');
        $this->assertNotNull($ability);

        $this->assertFalse(Plugin::instance()->registrar()->permission_callback($ability, []));
    }

    public function test_a_standalone_check_names_the_capability(): void
    {
        $this->as_editor();

        $denial = wp_get_ability('wpmcp/get-settings')->check_permissions([]);

        $this->assertInstanceOf(\WP_Error::class, $denial);
        $this->assertSame('operation_denied', $denial->get_error_code());
        $this->assertStringContainsString('wpmcp-get-settings requires the "manage_options" capability', $denial->get_error_message());
    }

    public function test_execute_refuses_with_cores_code_and_no_incorrect_usage(): void
    {
        $this->as_editor();

        $result = wp_get_ability('wpmcp/get-settings')->execute([]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('ability_invalid_permissions', $result->get_error_code());
    }

    public function test_call_tool_keeps_the_code_and_names_the_capability(): void
    {
        $this->as_editor();

        $result = (new Call_Tool())->handle([ 'name' => 'wpmcp/get-settings' ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('ability_invalid_permissions', $result->get_error_code());
        $this->assertStringContainsString('requires the "manage_options" capability', $result->get_error_message());
        $this->assertSame('operation_denied', $result->get_error_data()['refusal']);
        $this->assertSame('manage_options', $result->get_error_data()['capability']);
    }

    public function test_a_grant_after_a_denial_leaves_no_refusal_behind(): void
    {
        $this->as_editor();
        wp_get_ability('wpmcp/get-settings')->check_permissions([]);

        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $this->assertTrue(wp_get_ability('wpmcp/get-settings')->check_permissions([]));
        $this->assertTrue(Registrar::permission_result(true, 'wpmcp/get-settings'));
    }

    public function test_another_filters_denial_is_left_alone(): void
    {
        $this->needs_permission_filter();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $deny = static fn ($p, $name) => 'wpmcp/get-settings' === $name ? false : $p;
        add_filter('wp_ability_permission_result', $deny, 5, 2);

        $this->assertFalse(wp_get_ability('wpmcp/get-settings')->check_permissions([]));
        remove_filter('wp_ability_permission_result', $deny, 5);
    }
}
