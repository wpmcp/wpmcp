<?php

namespace WPMCP\Tests\Free\Capabilities;

use WPMCP\Plugin;

/**
 * Capability gating for the Elementor domain's free-tier tools: list-widgets
 * and get-widget-schema both require edit_posts. The five pro-tier Elementor
 * write tools (get-elementor-data, update-element, add-widget,
 * remove-element, move-element) share the same edit_posts capability but are
 * tier-gated (registered only when Gate::is_pro() is true) and are covered
 * separately by tests/pro/Elementor/ElementorDeepAbilitiesRegistrationTest.php.
 */
class ElementorCapabilityTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    private const EXPECTED = [
        'wpmcp/list-widgets'      => 'edit_posts',
        'wpmcp/get-widget-schema' => 'edit_posts',
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

    public function test_read_abilities_deny_subscriber_and_allow_edit_posts(): void
    {
        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        foreach (self::EXPECTED as $name => $capability) {
            $this->assertFalse(
                $abilities[ $name ]->check_permissions(),
                "{$name} must deny a subscriber"
            );
        }

        $author = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author);
        foreach (self::EXPECTED as $name => $capability) {
            $this->assertTrue(
                $abilities[ $name ]->check_permissions(),
                "{$name} must allow a user holding edit_posts"
            );
        }
    }
}
