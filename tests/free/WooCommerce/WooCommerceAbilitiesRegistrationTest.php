<?php

namespace WPMCP\Tests\Free\WooCommerce;

class WooCommerceAbilitiesRegistrationTest extends \WP_UnitTestCase
{
    private const TOOLS = [
        'wpmcp/list-products',
        'wpmcp/get-product',
        'wpmcp/create-product',
        'wpmcp/update-product',
        'wpmcp/delete-product',
        'wpmcp/list-product-categories',
        'wpmcp/list-orders',
        'wpmcp/get-order',
        'wpmcp/update-order-status',
        'wpmcp/add-order-note',
        'wpmcp/get-sales-report',
        'wpmcp/list-variations',
        'wpmcp/update-variation',
        'wpmcp/list-low-stock-products',
    ];

    public function test_all_woocommerce_tools_are_registered_as_free_abilities(): void
    {
        $names = array_keys(wp_get_abilities());

        foreach (self::TOOLS as $name) {
            $this->assertContains($name, $names, "Expected {$name} to be registered");
        }
    }

    public function test_woocommerce_abilities_have_description_and_category(): void
    {
        $abilities = wp_get_abilities();

        foreach (self::TOOLS as $name) {
            $ability = $abilities[ $name ];
            $this->assertNotEmpty($ability->get_description(), "Expected {$name} to have a description");
            $this->assertSame('wpmcp', $ability->get_category());
        }
    }
}
