<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\Connection_Page;
use WPMCP\Admin\Management_Access;

/**
 * Issue #479: who may manage WP MCP. By default every administrator; an
 * optional allowlist of administrator ids narrows it, and the
 * WPMCP_MANAGEMENT_ADMINS constant pins it from wp-config.php. An invalid
 * constant closes management instead of opening it. Agent execution is not
 * part of this policy.
 */
class ManagementAccessTest extends \WP_UnitTestCase
{
    private int $admin_a;
    private int $admin_b;
    private int $editor;

    protected function setUp(): void
    {
        parent::setUp();
        Management_Access::set_constant_for_tests(false);
        delete_option(Management_Access::OPTION);
        $this->admin_a = self::factory()->user->create(['role' => 'administrator']);
        $this->admin_b = self::factory()->user->create(['role' => 'administrator']);
        $this->editor  = self::factory()->user->create(['role' => 'editor']);
    }

    protected function tearDown(): void
    {
        Management_Access::set_constant_for_tests(false);
        delete_option(Management_Access::OPTION);
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_default_policy_lets_every_administrator_manage(): void
    {
        $policy = Management_Access::policy();
        $this->assertSame('default', $policy['source']);
        $this->assertSame('all', $policy['mode']);

        $this->assertTrue(user_can($this->admin_a, Management_Access::CAPABILITY));
        $this->assertTrue(user_can($this->admin_b, Management_Access::CAPABILITY));
        $this->assertFalse(user_can($this->editor, Management_Access::CAPABILITY));
        $this->assertFalse(Management_Access::can_manage(0));
    }

    public function test_allowlist_limits_management_to_listed_administrators(): void
    {
        update_option(Management_Access::OPTION, [$this->admin_a]);

        $this->assertTrue(Management_Access::can_manage($this->admin_a));
        $this->assertFalse(Management_Access::can_manage($this->admin_b));

        wp_set_current_user($this->admin_b);
        $this->assertFalse(current_user_can(Management_Access::CAPABILITY));
        // The rest of wp-admin is untouched.
        $this->assertTrue(current_user_can('manage_options'));
    }

    public function test_a_listed_editor_still_cannot_manage(): void
    {
        update_option(Management_Access::OPTION, [$this->admin_a, $this->editor]);

        $this->assertFalse(Management_Access::can_manage($this->editor));
    }

    public function test_a_role_editor_cannot_grant_the_capability_directly(): void
    {
        update_option(Management_Access::OPTION, [$this->admin_a]);
        get_userdata($this->admin_b)->add_cap(Management_Access::CAPABILITY);
        get_userdata($this->editor)->add_cap(Management_Access::CAPABILITY);

        $this->assertFalse(user_can($this->admin_b, Management_Access::CAPABILITY));
        $this->assertFalse(user_can($this->editor, Management_Access::CAPABILITY));
    }

    public function test_constant_overrides_the_stored_policy(): void
    {
        update_option(Management_Access::OPTION, [$this->admin_a]);

        Management_Access::set_constant_for_tests(true, (string) $this->admin_b);
        $this->assertSame('constant', Management_Access::policy()['source']);
        $this->assertFalse(Management_Access::can_manage($this->admin_a));
        $this->assertTrue(Management_Access::can_manage($this->admin_b));

        Management_Access::set_constant_for_tests(true, [$this->admin_a, (string) $this->admin_b]);
        $this->assertTrue(Management_Access::can_manage($this->admin_a));
        $this->assertTrue(Management_Access::can_manage($this->admin_b));

        Management_Access::set_constant_for_tests(true, ' ALL ');
        $this->assertSame('all', Management_Access::policy()['mode']);
        $this->assertTrue(Management_Access::can_manage($this->admin_b));

        Management_Access::set_constant_for_tests(true, $this->admin_a . ' , ' . $this->admin_b);
        $this->assertSame([$this->admin_a, $this->admin_b], Management_Access::policy()['ids']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function invalid_constant_values(): array
    {
        return [
            'empty string'      => [''],
            'word'              => ['nobody'],
            'mixed list'        => ['1,x'],
            'trailing comma'    => ['1,'],
            'zero'              => ['0'],
            'negative'          => [-1],
            'empty array'       => [[]],
            'array with zero'   => [[0]],
            'array with junk'   => [[1, 'two']],
            'boolean'           => [true],
            'null'              => [null],
            'float'             => [1.5],
        ];
    }

    /**
     * @dataProvider invalid_constant_values
     * @param mixed $value
     */
    public function test_an_invalid_constant_fails_closed($value): void
    {
        Management_Access::set_constant_for_tests(true, $value);

        $policy = Management_Access::policy();
        $this->assertSame('constant', $policy['source']);
        $this->assertSame('closed', $policy['mode']);
        $this->assertFalse(Management_Access::can_manage($this->admin_a));
        $this->assertFalse(Management_Access::can_manage($this->admin_b));
    }

    public function test_removing_the_constant_restores_the_stored_policy(): void
    {
        update_option(Management_Access::OPTION, [$this->admin_a]);
        Management_Access::set_constant_for_tests(true, 'all');
        $this->assertTrue(Management_Access::can_manage($this->admin_b));

        Management_Access::set_constant_for_tests(false);
        $this->assertSame([$this->admin_a], get_option(Management_Access::OPTION));
        $this->assertFalse(Management_Access::can_manage($this->admin_b));
    }

    public function test_a_corrupt_stored_policy_fails_closed(): void
    {
        update_option(Management_Access::OPTION, 'garbage');

        $this->assertSame('closed', Management_Access::policy()['mode']);
        $this->assertFalse(Management_Access::can_manage($this->admin_a));
    }

    public function test_save_stores_an_allowlist_and_an_empty_one_restores_the_default(): void
    {
        $saved = Management_Access::save('list', [$this->admin_a, (string) $this->admin_b], $this->admin_a, Management_Access::version());
        $this->assertTrue($saved);
        $this->assertSame([$this->admin_a, $this->admin_b], Management_Access::policy()['ids']);

        $saved = Management_Access::save('all', [], $this->admin_a, Management_Access::version());
        $this->assertTrue($saved);
        $this->assertSame('all', Management_Access::policy()['mode']);
        $this->assertTrue(Management_Access::can_manage($this->admin_b));
    }

    public function test_save_refuses_to_lock_out_the_user_making_it(): void
    {
        $saved = Management_Access::save('list', [$this->admin_b], $this->admin_a, Management_Access::version());

        $this->assertWPError($saved);
        $this->assertSame('wpmcp_management_self_lockout', $saved->get_error_code());
        $this->assertSame('all', Management_Access::policy()['mode']);
    }

    public function test_save_refuses_an_empty_allowlist(): void
    {
        $saved = Management_Access::save('list', [], $this->admin_a, Management_Access::version());

        $this->assertWPError($saved);
        $this->assertSame('all', Management_Access::policy()['mode']);
    }

    public function test_save_refuses_ids_that_are_not_administrators(): void
    {
        $saved = Management_Access::save('list', [$this->admin_a, $this->editor], $this->admin_a, Management_Access::version());
        $this->assertWPError($saved);
        $this->assertSame('wpmcp_management_not_admin', $saved->get_error_code());

        $saved = Management_Access::save('list', [$this->admin_a, 999999], $this->admin_a, Management_Access::version());
        $this->assertWPError($saved);
        $this->assertSame('all', Management_Access::policy()['mode']);
    }

    public function test_save_refuses_a_stale_form(): void
    {
        $stale = Management_Access::version();
        $this->assertTrue(Management_Access::save('list', [$this->admin_a, $this->admin_b], $this->admin_a, $stale));

        $saved = Management_Access::save('all', [], $this->admin_b, $stale);

        $this->assertWPError($saved);
        $this->assertSame('wpmcp_management_stale', $saved->get_error_code());
        $this->assertSame([$this->admin_a, $this->admin_b], Management_Access::policy()['ids']);
    }

    public function test_save_is_refused_while_the_constant_is_defined(): void
    {
        Management_Access::set_constant_for_tests(true, 'all');

        $saved = Management_Access::save('list', [$this->admin_a], $this->admin_a, Management_Access::version());

        $this->assertWPError($saved);
        $this->assertSame('wpmcp_management_constant', $saved->get_error_code());
        $this->assertFalse(get_option(Management_Access::OPTION));
    }

    public function test_cli_reset_restores_access_for_every_administrator(): void
    {
        update_option(Management_Access::OPTION, [$this->admin_a]);

        $this->assertTrue(Management_Access::reset());

        $this->assertSame('all', Management_Access::policy()['mode']);
        $this->assertTrue(Management_Access::can_manage($this->admin_b));
    }

    public function test_cli_reset_is_refused_while_the_constant_is_defined(): void
    {
        update_option(Management_Access::OPTION, [$this->admin_a]);
        Management_Access::set_constant_for_tests(true, 'nonsense');

        $reset = Management_Access::reset();

        $this->assertWPError($reset);
        $this->assertSame('wpmcp_management_constant', $reset->get_error_code());
        $this->assertSame([$this->admin_a], get_option(Management_Access::OPTION));
    }

    public function test_non_administrator_ids_in_the_constant_are_flagged(): void
    {
        Management_Access::set_constant_for_tests(true, [$this->admin_a, $this->editor, 999999]);

        $this->assertSame([$this->editor, 999999], Management_Access::non_admin_ids());
    }

    private function connection_post(array $extra): array
    {
        return array_merge([
            'wpmcp_connection_action' => 'management_access',
            '_wpnonce'                => wp_create_nonce(Connection_Page::NONCE_ACTION),
            'management_version'      => Management_Access::version(),
        ], $extra);
    }

    public function test_the_connection_screen_saves_the_policy(): void
    {
        wp_set_current_user($this->admin_a);

        $result = (new Connection_Page())->handle_request($this->connection_post([
            'management_mode' => 'list',
            'management_ids'  => [(string) $this->admin_a],
        ]));

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame([$this->admin_a], Management_Access::policy()['ids']);
    }

    public function test_the_connection_screen_reports_a_refused_save(): void
    {
        wp_set_current_user($this->admin_a);

        $result = (new Connection_Page())->handle_request($this->connection_post([
            'management_mode' => 'list',
            'management_ids'  => [(string) $this->admin_b],
        ]));

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('all', Management_Access::policy()['mode']);
    }

    public function test_an_off_list_administrator_cannot_use_any_connection_action(): void
    {
        update_option(Management_Access::OPTION, [$this->admin_a]);
        wp_set_current_user($this->admin_b);

        $result = (new Connection_Page())->handle_request($this->connection_post([
            'management_mode' => 'all',
        ]));

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([$this->admin_a], get_option(Management_Access::OPTION));
    }

    public function test_the_screen_shows_the_constant_read_only_and_flags_non_administrators(): void
    {
        Management_Access::set_constant_for_tests(true, $this->admin_a . ',' . $this->editor);
        wp_set_current_user($this->admin_a);
        set_current_screen('admin_page_' . Connection_Page::SLUG);

        ob_start();
        (new Connection_Page())->render();
        $html = ob_get_clean();

        $this->assertStringContainsString(Management_Access::CONSTANT, $html);
        $this->assertStringNotContainsString('name="management_mode"', $html);
        $this->assertStringContainsString('data-wpmcp-not-admin="' . $this->editor . '"', $html);
    }

    public function test_the_screen_offers_the_policy_form_without_the_constant(): void
    {
        wp_set_current_user($this->admin_a);
        set_current_screen('admin_page_' . Connection_Page::SLUG);

        ob_start();
        (new Connection_Page())->render();
        $html = ob_get_clean();

        $this->assertStringContainsString('name="management_mode"', $html);
        $this->assertStringContainsString('name="management_version" value="' . Management_Access::version() . '"', $html);
    }
}
