<?php

namespace WPMCP\Tests\Free\Capabilities;

use WPMCP\Plugin;

/**
 * Capability gating for the Filesystem domain: every ability, reads
 * included, requires manage_options. Arbitrary file read/write inside the
 * WordPress install is equivalent to server-level access, so even reads sit
 * well above the default edit_posts capability. On multisite the reads take
 * manage_network_options instead (issue #559): the install holds every
 * site's files, so a sub-site administrator gets none of them
 * (SubSiteFilesystemReadTest).
 */
class FilesystemCapabilityTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    private const EXPECTED = [
        'wpmcp/read-file'      => 'manage_options',
        'wpmcp/list-directory' => 'manage_options',
        'wpmcp/search-files'   => 'manage_options',
        'wpmcp/write-file'     => 'manage_options',
        'wpmcp/edit-file'      => 'manage_options',
        'wpmcp/delete-file'    => 'manage_options',
    ];

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_registered_capability_matches_expected_map(): void
    {
        $expected = self::EXPECTED;
        if (is_multisite()) {
            foreach (['wpmcp/read-file', 'wpmcp/list-directory', 'wpmcp/search-files'] as $read) {
                $expected[ $read ] = 'manage_network_options';
            }
        }

        $abilities = [];
        foreach (Plugin::instance()->registrar()->all() as $ability) {
            $abilities[ $ability->name ] = $ability;
        }

        foreach ($expected as $name => $capability) {
            $this->assertArrayHasKey($name, $abilities, "Expected {$name} to be registered");
            $this->assertSame(
                $capability,
                $abilities[ $name ]->capability,
                "{$name} should require capability {$capability}"
            );
        }
    }

    public function test_read_ability_denies_editor_and_allows_manage_options(): void
    {
        $abilities = wp_get_abilities();

        $editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor);
        $this->assertFalse(
            $abilities['wpmcp/read-file']->check_permissions(),
            'wpmcp/read-file must deny an editor (no manage_options)'
        );

        $admin = self::factory()->user->create(['role' => 'administrator']);
        if (is_multisite()) {
            // Issue #559: on multisite only the network administrator reads files.
            grant_super_admin($admin);
        }
        wp_set_current_user($admin);
        $this->assertTrue(
            $abilities['wpmcp/read-file']->check_permissions(),
            'wpmcp/read-file must allow a user holding manage_options'
        );
        if (is_multisite()) {
            revoke_super_admin($admin);
        }
    }

    public function test_write_ability_denies_editor_and_allows_manage_options(): void
    {
        $abilities = wp_get_abilities();

        $editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor);
        $this->assertFalse(
            $abilities['wpmcp/write-file']->check_permissions(),
            'wpmcp/write-file must deny an editor (no manage_options)'
        );

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        $this->assertTrue(
            $abilities['wpmcp/write-file']->check_permissions(),
            'wpmcp/write-file must allow a user holding manage_options'
        );
    }
}
