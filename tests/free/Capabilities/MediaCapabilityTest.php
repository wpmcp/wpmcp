<?php

namespace WPMCP\Tests\Free\Capabilities;

use WPMCP\Plugin;

/**
 * Capability gating for the Media domain: read, update, delete, and sideload
 * all require edit_posts, matching WordPress core's own media-library gate.
 */
class MediaCapabilityTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    private const EXPECTED = [
        'wpmcp/get-media'      => 'edit_posts',
        'wpmcp/update-media'   => 'edit_posts',
        'wpmcp/delete-media'   => 'edit_posts',
        'wpmcp/sideload-image' => 'edit_posts',
    ];

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_registered_capability_matches_expected_map(): void
    {
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
        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse(
            $abilities['wpmcp/get-media']->check_permissions(),
            'wpmcp/get-media must deny a subscriber'
        );

        $author = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author);
        $this->assertTrue(
            $abilities['wpmcp/get-media']->check_permissions(),
            'wpmcp/get-media must allow a user holding edit_posts'
        );
    }

    public function test_write_ability_denies_subscriber_and_allows_edit_posts(): void
    {
        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse(
            $abilities['wpmcp/update-media']->check_permissions(),
            'wpmcp/update-media must deny a subscriber'
        );

        $author = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author);
        $this->assertTrue(
            $abilities['wpmcp/update-media']->check_permissions(),
            'wpmcp/update-media must allow a user holding edit_posts'
        );
    }
}
