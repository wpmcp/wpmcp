<?php

namespace WPMCP\Tests\Free\Capabilities;

use WPMCP\Plugin;

/**
 * Capability gating for the ACF domain: list-field-groups, get-fields, and
 * update-fields all require edit_posts. Skipped when ACF is not active,
 * matching AcfAbilitiesRegistrationTest's guard, since Plugin::boot() only
 * registers these abilities when acf_get_field_groups() exists.
 */
class AcfCapabilityTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    private const EXPECTED = [
        'wpmcp/list-field-groups' => 'edit_posts',
        'wpmcp/get-fields'        => 'edit_posts',
        'wpmcp/update-fields'     => 'edit_posts',
    ];

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_registered_capability_matches_expected_map(): void
    {
        if (! wpmcp_acf_active()) {
            $this->markTestSkipped('ACF not active');
        }

        $abilities = [];
        foreach (Plugin::instance()->registrar()->all() as $ability) {
            $abilities[ $ability->name ] = $ability;
        }

        foreach (self::EXPECTED as $name => $capability) {
            $this->assertArrayHasKey($name, $abilities, "Expected {$name} to be registered");
            $this->assertSame(
                $capability,
                $abilities[ $name ]->capability,
                "{$name} should require capability {$capability}"
            );
        }
    }

    public function test_read_ability_denies_subscriber_and_allows_edit_posts(): void
    {
        if (! wpmcp_acf_active()) {
            $this->markTestSkipped('ACF not active');
        }

        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse(
            $abilities['wpmcp/get-fields']->check_permissions(),
            'wpmcp/get-fields must deny a subscriber'
        );

        $author = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author);
        $this->assertTrue(
            $abilities['wpmcp/get-fields']->check_permissions(),
            'wpmcp/get-fields must allow a user holding edit_posts'
        );
    }

    public function test_write_ability_denies_subscriber_and_allows_edit_posts(): void
    {
        if (! wpmcp_acf_active()) {
            $this->markTestSkipped('ACF not active');
        }

        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse(
            $abilities['wpmcp/update-fields']->check_permissions(),
            'wpmcp/update-fields must deny a subscriber'
        );

        $author = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author);
        $this->assertTrue(
            $abilities['wpmcp/update-fields']->check_permissions(),
            'wpmcp/update-fields must allow a user holding edit_posts'
        );
    }
}
