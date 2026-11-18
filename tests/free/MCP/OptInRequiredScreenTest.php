<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Opt_In_Settings;
use WPMCP\MCP\Opt_In_Required;

/**
 * Issue #966: an opt_in_required refusal said "has no wp-admin setting" for
 * every group, although WP MCP > Opt-in operations lists many of them (#823).
 * A group the screen lists points the site owner there, by screen and label,
 * and keeps the filter as the code option. A group set in code on this site
 * cannot be changed on the screen, so it, and a filter the screen does not
 * list, keep the developer-only wording.
 */
class OptInRequiredScreenTest extends \WP_UnitTestCase
{
    public function test_a_group_the_screen_lists_points_to_the_screen(): void
    {
        $message = Opt_In_Required::message('Operation "update-generatepress-layout" of the theme integration is disabled.', 'wpmcp_enable_theme_write');

        $this->assertStringContainsString('WP MCP > Opt-in operations', $message);
        $this->assertStringContainsString('Themes: settings, layouts and child themes', $message);
        $this->assertStringContainsString("add_filter('wpmcp_enable_theme_write', '__return_true');", $message);
        $this->assertStringNotContainsString('no wp-admin setting', $message);
    }

    public function test_a_filter_the_screen_does_not_list_keeps_the_developer_wording(): void
    {
        $message = Opt_In_Required::message('The delete-plugin tool is disabled.', 'wpmcp_enable_delete_plugin');

        $this->assertStringContainsString('has no wp-admin setting', $message);
        $this->assertStringNotContainsString('Opt-in operations', $message);
    }

    public function test_a_group_set_in_code_does_not_point_to_the_screen(): void
    {
        $this->assertArrayHasKey('wpmcp_enable_theme_write', Opt_In_Settings::groups());
        add_filter('wpmcp_enable_theme_write', '__return_false');
        try {
            $message = Opt_In_Required::message('Operation "x" is disabled.', 'wpmcp_enable_theme_write');
        } finally {
            remove_filter('wpmcp_enable_theme_write', '__return_false');
        }

        $this->assertStringNotContainsString('switch it on under WP MCP > Opt-in operations', $message);
        $this->assertStringContainsString('set in code on this site', $message);
    }

    public function test_the_error_carries_the_screen_in_its_data(): void
    {
        $error = Opt_In_Required::error('Operation "x" is disabled.', 'wpmcp_enable_theme_write');

        $this->assertSame('opt_in_required', $error->get_error_code());
        $this->assertSame('wpmcp_enable_theme_write', $error->get_error_data()['filter']);
        $this->assertStringContainsString('page=', (string) ($error->get_error_data()['settings_url'] ?? ''));
    }
}
