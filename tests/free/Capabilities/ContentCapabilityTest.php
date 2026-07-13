<?php

namespace WPMCP\Tests\Free\Capabilities;

use WPMCP\Plugin;

/**
 * Capability gating for the Content domain: every content ability (list,
 * read, create, update, delete post types/taxonomies/posts) is registered
 * with capability edit_posts. Verifies both the declared capability on each
 * registered Ability and that the real permission_callback (via
 * check_permissions()) actually denies a user without edit_posts and allows
 * one with it.
 */
class ContentCapabilityTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    private const EXPECTED = [
        'wpmcp/list-post-types' => 'edit_posts',
        'wpmcp/list-taxonomies' => 'edit_posts',
        'wpmcp/create-post'     => 'edit_posts',
        'wpmcp/get-post'        => 'edit_posts',
        'wpmcp/update-post'     => 'edit_posts',
        'wpmcp/delete-post'     => 'edit_posts',
        'wpmcp/list-posts'      => 'edit_posts',
        'wpmcp/set-post-terms'  => 'edit_posts',
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
            $abilities['wpmcp/get-post']->check_permissions(),
            'wpmcp/get-post must deny a subscriber'
        );

        $author = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author);
        $this->assertTrue(
            $abilities['wpmcp/get-post']->check_permissions(),
            'wpmcp/get-post must allow a user holding edit_posts'
        );
    }

    public function test_write_ability_denies_subscriber_and_allows_edit_posts(): void
    {
        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse(
            $abilities['wpmcp/create-post']->check_permissions(),
            'wpmcp/create-post must deny a subscriber'
        );

        $author = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author);
        $this->assertTrue(
            $abilities['wpmcp/create-post']->check_permissions(),
            'wpmcp/create-post must allow a user holding edit_posts'
        );
    }
}
