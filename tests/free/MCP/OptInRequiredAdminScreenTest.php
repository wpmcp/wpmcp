<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Opt_In_Settings;
use WPMCP\MCP\Opt_In_Required;

/**
 * Issue #966: an opt_in_required refusal for a group the WP MCP > Opt-in
 * operations screen lists (#823) sends the site owner to that screen, with
 * the group label and URL, and keeps the filter as the code option. A
 * code-only gate keeps the "has no wp-admin setting" wording.
 */
class OptInRequiredAdminScreenTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Opt_In_Settings::register();
    }

    /** A group that ships in this build, preferring the theme group from the issue. */
    private static function listed_group(): string
    {
        $groups = Opt_In_Settings::groups();
        if ([] === $groups) {
            self::markTestSkipped('This build ships no opt-in group.');
        }
        return isset($groups['wpmcp_enable_theme_write']) ? 'wpmcp_enable_theme_write' : (string) array_key_first($groups);
    }

    public function test_a_listed_group_names_the_screen_label_and_url(): void
    {
        $filter  = self::listed_group();
        $label   = Opt_In_Settings::labels()[ $filter ];
        $message = Opt_In_Required::message('Operation "x" of the y integration is disabled.', $filter);

        $this->assertStringStartsWith('Operation "x" of the y integration is disabled. It is off by default.', $message);
        $this->assertStringContainsString('WP MCP > Opt-in operations', $message);
        $this->assertStringContainsString('"' . $label . '"', $message);
        $this->assertStringContainsString(admin_url('admin.php?page=wpmcp-opt-in'), $message);
        $this->assertStringContainsString(sprintf("add_filter('%s', '__return_true');", $filter), $message);
        $this->assertStringNotContainsString('no wp-admin setting', $message);
    }

    public function test_a_listed_group_error_carries_the_screen_in_data(): void
    {
        $filter = self::listed_group();
        $error  = Opt_In_Required::error('The thing is disabled.', $filter);
        $data   = $error->get_error_data();

        $this->assertSame(Opt_In_Required::CODE, $error->get_error_code());
        $this->assertSame($filter, $data['filter']);
        $this->assertSame('WP MCP > Opt-in operations', $data['admin_screen']);
        $this->assertSame(admin_url('admin.php?page=wpmcp-opt-in'), $data['admin_url']);
        $this->assertSame(Opt_In_Settings::labels()[ $filter ], $data['admin_group']);
    }

    public function test_a_code_only_gate_keeps_the_no_admin_setting_wording(): void
    {
        $message = Opt_In_Required::message('Database write tools are disabled.', 'wpmcp_enable_db_writes');
        $this->assertSame(
            "Database write tools are disabled. It is off by default and has no wp-admin setting; a site developer can switch it on with add_filter('wpmcp_enable_db_writes', '__return_true'); in a plugin or mu-plugin.",
            $message
        );

        $data = Opt_In_Required::error('Database write tools are disabled.', 'wpmcp_enable_db_writes')->get_error_data();
        $this->assertSame('wpmcp_enable_db_writes', $data['filter']);
        $this->assertArrayNotHasKey('admin_url', $data);
    }

    /** A group set in code cannot be changed on the screen, so the message says so. */
    public function test_a_listed_group_set_in_code_says_the_code_decides(): void
    {
        $filter = self::listed_group();
        add_filter($filter, '__return_false');
        try {
            $message = Opt_In_Required::message('The thing is disabled.', $filter);
            $this->assertStringContainsString('set in code on this site', $message);
            $this->assertStringContainsString(sprintf("add_filter('%s', '__return_true');", $filter), $message);
            $this->assertStringNotContainsString(admin_url('admin.php?page=wpmcp-opt-in'), $message);
            $this->assertArrayNotHasKey('admin_url', Opt_In_Required::error('The thing is disabled.', $filter)->get_error_data());
        } finally {
            remove_filter($filter, '__return_false');
        }
    }

    /** The per-operation snippet and the trailing sentence still follow the screen hint. */
    public function test_a_listed_group_keeps_the_snippet_and_warning(): void
    {
        $filter  = self::listed_group();
        $message = Opt_In_Required::message('Op is disabled.', $filter, '', 'To switch on this operation alone instead, use X.', 'custom_snippet();');

        $this->assertStringContainsString('custom_snippet(); in a plugin or mu-plugin.', $message);
        $this->assertStringEndsWith('To switch on this operation alone instead, use X.', $message);
    }
}
