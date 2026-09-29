<?php

namespace WPMCP\Tests\Free\Capabilities;

use WPMCP\Plugin;

/**
 * Capability gating for the Comments domain. Every comment tool gates on
 * moderate_comments, the capability core's administrator and editor roles
 * carry. Editing and deleting a specific comment additionally require the
 * edit_comment meta capability for that comment inside the handler, the same
 * per-comment check wp-admin makes (issue #409: these two once asked for
 * "edit_comments", which WordPress does not define, so nobody could run them).
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
        'wpmcp/edit-comment'     => 'moderate_comments',
        'wpmcp/delete-comment'   => 'moderate_comments',
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

    /**
     * @dataProvider provide_edit_and_delete
     */
    public function test_administrator_and_editor_pass_the_edit_and_delete_gate(string $name): void
    {
        $abilities = wp_get_abilities();

        foreach (['administrator', 'editor'] as $role) {
            wp_set_current_user(self::factory()->user->create(['role' => $role]));
            $this->assertTrue(
                $abilities[ $name ]->check_permissions(),
                "{$name} must allow an {$role}"
            );
        }
    }

    /**
     * @dataProvider provide_edit_and_delete
     */
    public function test_subscriber_and_contributor_fail_the_edit_and_delete_gate(string $name): void
    {
        $abilities = wp_get_abilities();

        foreach (['subscriber', 'contributor'] as $role) {
            wp_set_current_user(self::factory()->user->create(['role' => $role]));
            $this->assertFalse(
                $abilities[ $name ]->check_permissions(),
                "{$name} must deny a {$role}"
            );
        }
    }

    /** @return array<string,array{string}> */
    public static function provide_edit_and_delete(): array
    {
        return [
            'edit-comment'   => ['wpmcp/edit-comment'],
            'delete-comment' => ['wpmcp/delete-comment'],
        ];
    }
}
