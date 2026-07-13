<?php

namespace WPMCP\Tests\Free\Capabilities;

use WPMCP\Plugin;

/**
 * Capability gating for the WooCommerce domain: product and sales-report
 * tools require manage_woocommerce, while order tools require the narrower
 * edit_shop_orders, matching WooCommerce's own capability split between
 * store management and order handling. WooCommerce abilities are registered
 * unconditionally (they degrade at call time if WooCommerce is absent), so
 * these assertions run against the live registry with no skip guard needed.
 */
class WooCommerceCapabilityTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    private const EXPECTED = [
        'wpmcp/list-products'           => 'manage_woocommerce',
        'wpmcp/get-product'             => 'manage_woocommerce',
        'wpmcp/create-product'          => 'manage_woocommerce',
        'wpmcp/update-product'          => 'manage_woocommerce',
        'wpmcp/delete-product'          => 'manage_woocommerce',
        'wpmcp/list-product-categories' => 'manage_woocommerce',
        'wpmcp/get-sales-report'        => 'manage_woocommerce',
        'wpmcp/list-orders'             => 'edit_shop_orders',
        'wpmcp/get-order'               => 'edit_shop_orders',
        'wpmcp/update-order-status'     => 'edit_shop_orders',
        'wpmcp/add-order-note'          => 'edit_shop_orders',
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

    public function test_product_read_ability_denies_subscriber_and_allows_manage_woocommerce(): void
    {
        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse(
            $abilities['wpmcp/list-products']->check_permissions(),
            'wpmcp/list-products must deny a subscriber'
        );

        $user = self::factory()->user->create(['role' => 'subscriber']);
        get_user_by('id', $user)->add_cap('manage_woocommerce');
        wp_set_current_user($user);
        $this->assertTrue(
            $abilities['wpmcp/list-products']->check_permissions(),
            'wpmcp/list-products must allow a user holding manage_woocommerce'
        );
    }

    public function test_product_write_ability_denies_subscriber_and_allows_manage_woocommerce(): void
    {
        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse(
            $abilities['wpmcp/create-product']->check_permissions(),
            'wpmcp/create-product must deny a subscriber'
        );

        $user = self::factory()->user->create(['role' => 'subscriber']);
        get_user_by('id', $user)->add_cap('manage_woocommerce');
        wp_set_current_user($user);
        $this->assertTrue(
            $abilities['wpmcp/create-product']->check_permissions(),
            'wpmcp/create-product must allow a user holding manage_woocommerce'
        );
    }

    public function test_order_ability_denies_subscriber_and_allows_edit_shop_orders(): void
    {
        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse(
            $abilities['wpmcp/list-orders']->check_permissions(),
            'wpmcp/list-orders must deny a subscriber'
        );

        $user = self::factory()->user->create(['role' => 'subscriber']);
        get_user_by('id', $user)->add_cap('edit_shop_orders');
        wp_set_current_user($user);
        $this->assertTrue(
            $abilities['wpmcp/list-orders']->check_permissions(),
            'wpmcp/list-orders must allow a user holding edit_shop_orders'
        );
    }
}
