<?php

namespace WPMCP\Tests\Free\MCP;

/**
 * Read tools whose default edit_posts gate was too loose for their domain are
 * tightened in Plugin::boot(). This exercises each ability's real
 * permission_callback (via check_permissions()): a subscriber holding only the
 * default caps is denied, while a user granted the tool's specific domain
 * capability is allowed. Caps are granted explicitly so the assertion does not
 * depend on WooCommerce being active in the test environment.
 */
class ReadToolCapabilitiesTest extends \WP_UnitTestCase
{
    /**
     * Ability name => the single capability that should now gate it.
     */
    private const EXPECTED = [
        'wpmcp/list-users'              => 'list_users',
        'wpmcp/get-user'                => 'list_users',
        'wpmcp/list-plugins'            => 'activate_plugins',
        'wpmcp/list-themes'             => 'activate_plugins',
        'wpmcp/list-products'           => 'manage_woocommerce',
        'wpmcp/get-product'             => 'manage_woocommerce',
        'wpmcp/list-product-categories' => 'manage_woocommerce',
    ];

    public function test_read_tools_are_gated_by_their_domain_capability(): void
    {
        $abilities = wp_get_abilities();

        // A subscriber holds none of these domain capabilities and must be denied.
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        foreach (self::EXPECTED as $name => $cap) {
            $this->assertArrayHasKey($name, $abilities, "Expected {$name} to be registered");
            $this->assertFalse(
                $abilities[ $name ]->check_permissions(),
                "{$name} must deny a subscriber (should require {$cap})"
            );
        }

        // A fresh user granted exactly the expected capability must be allowed,
        // proving the gate is that specific cap and not the old edit_posts.
        // On a network activate_plugins also needs the sub-site Plugins menu
        // the network administrator can turn on (issue #530).
        if (is_multisite()) {
            update_site_option('menu_items', [ 'plugins' => '1' ]);
        }
        foreach (self::EXPECTED as $name => $cap) {
            $user = self::factory()->user->create(['role' => 'subscriber']);
            get_user_by('id', $user)->add_cap($cap);
            wp_set_current_user($user);

            $this->assertTrue(
                $abilities[ $name ]->check_permissions(),
                "{$name} must allow a user holding {$cap}"
            );
        }
    }

    /**
     * The old default gate (edit_posts) must no longer, on its own, admit
     * these tools: an editor holds edit_posts but none of the tightened
     * domain caps, so every one of these reads must refuse an editor.
     */
    public function test_edit_posts_alone_no_longer_admits_these_tools(): void
    {
        $abilities = wp_get_abilities();

        $editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor);

        foreach (self::EXPECTED as $name => $cap) {
            $this->assertTrue(current_user_can('edit_posts'), 'Editor should hold edit_posts');
            $this->assertFalse(
                $abilities[ $name ]->check_permissions(),
                "{$name} must refuse an editor who only holds edit_posts (needs {$cap})"
            );
        }
    }
}
