<?php

namespace WPMCP\Tests\Free\Capabilities;

use WPMCP\Plugin;

/**
 * Capability gating for the Core domain: get-page, update-blocks, and the
 * safety audit-log/rollback tools (list-operations, rollback-operation,
 * rollback-session) all require edit_posts.
 */
class CoreCapabilityTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    private const EXPECTED = [
        'wpmcp/get-page'           => 'edit_posts',
        'wpmcp/update-blocks'      => 'edit_posts',
        'wpmcp/list-operations'    => 'edit_posts',
        'wpmcp/rollback-operation' => 'edit_posts',
        'wpmcp/rollback-session'   => 'edit_posts',
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
        $this->assertNotTrue(
            $abilities['wpmcp/get-page']->check_permissions(),
            'wpmcp/get-page must deny a subscriber'
        );

        $author = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author);
        $this->assertTrue(
            $abilities['wpmcp/get-page']->check_permissions(),
            'wpmcp/get-page must allow a user holding edit_posts'
        );
    }

    public function test_write_ability_denies_subscriber_and_allows_edit_posts(): void
    {
        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertNotTrue(
            $abilities['wpmcp/rollback-operation']->check_permissions(),
            'wpmcp/rollback-operation must deny a subscriber'
        );

        $author = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author);
        $this->assertTrue(
            $abilities['wpmcp/rollback-operation']->check_permissions(),
            'wpmcp/rollback-operation must allow a user holding edit_posts'
        );
    }
}
