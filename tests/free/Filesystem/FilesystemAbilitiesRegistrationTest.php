<?php

namespace WPMCP\Tests\Free\Filesystem;

class FilesystemAbilitiesRegistrationTest extends \WP_UnitTestCase
{
    private const READ_NAMES = [
        'wpmcp/read-file',
        'wpmcp/list-directory',
        'wpmcp/search-files',
    ];

    private const WRITE_NAMES = [
        'wpmcp/write-file',
        'wpmcp/edit-file',
        'wpmcp/delete-file',
    ];

    public function test_all_six_filesystem_tools_are_registered(): void
    {
        $names = array_keys(wp_get_abilities());

        foreach (array_merge(self::READ_NAMES, self::WRITE_NAMES) as $name) {
            $this->assertContains($name, $names, "Expected {$name} to be registered");
        }
    }

    public function test_filesystem_abilities_have_description_and_category(): void
    {
        $abilities = wp_get_abilities();

        foreach (array_merge(self::READ_NAMES, self::WRITE_NAMES) as $name) {
            $ability = $abilities[ $name ];
            $this->assertNotEmpty($ability->get_description(), "Expected {$name} to have a description");
            $this->assertSame('wpmcp', $ability->get_category());
        }
    }

    /**
     * Raw filesystem access (even read-only) is at least as sensitive as raw
     * database access, so every filesystem tool -- reads and writes alike --
     * requires manage_options, matching the Database tools' gating.
     */
    public function test_all_filesystem_abilities_deny_subscriber_and_allow_administrator(): void
    {
        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        foreach (array_merge(self::READ_NAMES, self::WRITE_NAMES) as $name) {
            $this->assertFalse($abilities[ $name ]->check_permissions(), "{$name} must deny a subscriber");
        }

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        if (is_multisite()) {
            // Issue #559: on multisite a site administrator reads no files;
            // the network administrator does.
            foreach (self::READ_NAMES as $name) {
                $this->assertFalse($abilities[ $name ]->check_permissions(), "{$name} must deny a site administrator on multisite");
            }
            grant_super_admin($admin);
        }
        foreach (array_merge(self::READ_NAMES, self::WRITE_NAMES) as $name) {
            $this->assertTrue($abilities[ $name ]->check_permissions(), "{$name} must allow an administrator");
        }
        if (is_multisite()) {
            revoke_super_admin($admin);
        }
    }
}
