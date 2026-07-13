<?php

namespace WPMCP\Tests\Free\Capabilities;

use WPMCP\Plugin;

/**
 * Capability gating for the Comments domain: list/get/moderate require
 * moderate_comments, while edit/delete require the stronger edit_comments,
 * matching WordPress core's own split between moderating and editing
 * comment content.
 */
class CommentsCapabilityTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    private const EXPECTED = [
        'wpmcp/list-comments'    => 'moderate_comments',
        'wpmcp/get-comment'      => 'moderate_comments',
        'wpmcp/moderate-comment' => 'moderate_comments',
        'wpmcp/edit-comment'     => 'edit_comments',
        'wpmcp/delete-comment'   => 'edit_comments',
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

    public function test_read_ability_denies_subscriber_and_allows_moderate_comments(): void
    {
        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse(
            $abilities['wpmcp/list-comments']->check_permissions(),
            'wpmcp/list-comments must deny a subscriber'
        );

        $editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor);
        $this->assertTrue(
            $abilities['wpmcp/list-comments']->check_permissions(),
            'wpmcp/list-comments must allow a user holding moderate_comments'
        );
    }

    public function test_moderate_ability_denies_subscriber_and_allows_moderate_comments(): void
    {
        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse(
            $abilities['wpmcp/moderate-comment']->check_permissions(),
            'wpmcp/moderate-comment must deny a subscriber'
        );

        $editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor);
        $this->assertTrue(
            $abilities['wpmcp/moderate-comment']->check_permissions(),
            'wpmcp/moderate-comment must allow a user holding moderate_comments'
        );
    }

    public function test_delete_ability_denies_subscriber_and_allows_edit_comments(): void
    {
        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse(
            $abilities['wpmcp/delete-comment']->check_permissions(),
            'wpmcp/delete-comment must deny a subscriber'
        );

        $user = self::factory()->user->create(['role' => 'subscriber']);
        get_user_by('id', $user)->add_cap('edit_comments');
        wp_set_current_user($user);
        $this->assertTrue(
            $abilities['wpmcp/delete-comment']->check_permissions(),
            'wpmcp/delete-comment must allow a user holding edit_comments'
        );
    }
}
