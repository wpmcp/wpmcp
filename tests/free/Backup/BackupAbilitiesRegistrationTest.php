<?php

namespace WPMCP\Tests\Free\Backup;

class BackupAbilitiesRegistrationTest extends \WP_UnitTestCase
{
    private const NAMES = [
        'wpmcp/trigger-backup',
        'wpmcp/get-backup-status',
        'wpmcp/list-backup-jobs',
        'wpmcp/cancel-backup-job',
        'wpmcp/get-backup-manifest',
        'wpmcp/delete-backup-archive',
    ];

    public function test_backup_tools_are_registered_as_free_abilities(): void
    {
        $names = array_keys(wp_get_abilities());

        foreach (self::NAMES as $name) {
            $this->assertContains($name, $names, "Expected {$name} to be registered");
        }
    }

    public function test_backup_abilities_have_description_and_category(): void
    {
        $abilities = wp_get_abilities();

        foreach (self::NAMES as $name) {
            $ability = $abilities[ $name ];
            $this->assertNotEmpty($ability->get_description(), "Expected {$name} to have a description");
            $this->assertSame('wpmcp', $ability->get_category());
        }
    }

    public function test_backup_abilities_deny_subscriber_and_allow_administrator(): void
    {
        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        foreach (self::NAMES as $name) {
            $this->assertFalse($abilities[ $name ]->check_permissions(), "{$name} must deny a subscriber");
        }

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        foreach (self::NAMES as $name) {
            $this->assertTrue($abilities[ $name ]->check_permissions(), "{$name} must allow an administrator");
        }
    }
}
