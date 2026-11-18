<?php

namespace WPMCP\Tests\Free\Database;

class DatabaseAbilitiesRegistrationTest extends \WP_UnitTestCase
{
    private const NAMES = [
        'wpmcp/list-tables',
        'wpmcp/describe-table',
        'wpmcp/query',
        'wpmcp/insert-row',
        'wpmcp/update-rows',
        'wpmcp/delete-rows',
    ];

    public function test_all_database_tools_are_registered_as_free_abilities(): void
    {
        $names = array_keys(wp_get_abilities());

        foreach (self::NAMES as $name) {
            $this->assertContains($name, $names, "Expected {$name} to be registered");
        }
    }

    public function test_database_abilities_have_description_and_category(): void
    {
        $abilities = wp_get_abilities();

        foreach (self::NAMES as $name) {
            $ability = $abilities[ $name ];
            $this->assertNotEmpty($ability->get_description(), "Expected {$name} to have a description");
            $this->assertSame('wpmcp', $ability->get_category());
        }
    }

    /**
     * All database tools (reads and writes alike) require manage_options:
     * raw table/row access is equivalent to phpMyAdmin-level access to the
     * site, so even the read tools are gated well above the default
     * edit_posts capability.
     */
    public function test_all_database_abilities_deny_subscriber_and_allow_administrator(): void
    {
        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        foreach (self::NAMES as $name) {
            $this->assertNotTrue($abilities[ $name ]->check_permissions(), "{$name} must deny a subscriber");
        }

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        foreach (self::NAMES as $name) {
            $this->assertTrue($abilities[ $name ]->check_permissions(), "{$name} must allow an administrator");
        }
    }
}
