<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Governance;
use WPMCP\Governance\Opt_In_Gates;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Registrar;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\RateLimit\Rate_Limiter;
use WPMCP\Tools\Dispatch\Call_Tool;
use WPMCP\Tools\Dispatch\Get_Tool_Schema;
use WPMCP\Tools\Linking\Suggest_Internal_Links;
use WPMCP\Tools\Packages\Deactivate_Plugin;
use WPMCP\Tools\WooCommerce\Catalog\Op_Guard;

/**
 * Refusals that pointed the caller at the wrong fix (issue #631): each one
 * now says what is actually in the way and what to do about it.
 */
class RefusalMessagesTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Governance::reset_for_tests();
        Identity_Context::set_current_for_tests(null);
        delete_option(Identity_Store::OPTION);
        delete_option(Tool_Exposure::OPTION);
        Rate_Limiter::set_clock_override(fn() => 1_790_000_631);
        add_filter('wpmcp_rate_limit', fn() => 100000);
    }

    protected function tearDown(): void
    {
        Op_Guard::set_available_for_tests(null);
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_rate_limit');
        Rate_Limiter::set_clock_override(null);
        Identity_Context::set_current_for_tests(null);
        Governance::reset_for_tests();
        delete_option(Identity_Store::OPTION);
        delete_option(Tool_Exposure::OPTION);
        parent::tearDown();
    }

    private function as_administrator(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
    }

    private static function woo_ability(): Ability
    {
        return new Ability(
            'wpmcp/list-products',
            'free',
            'List products',
            ['type' => 'object'],
            fn () => [],
            'manage_woocommerce',
            'woocommerce',
            'read'
        );
    }

    public function test_a_woocommerce_tool_without_woocommerce_says_woocommerce_is_missing(): void
    {
        // A role with no WooCommerce capability, as an administrator has on
        // a site where WooCommerce never added them.
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        Op_Guard::set_available_for_tests(false);

        $denial = (new Registrar())->permission(self::woo_ability());

        $this->assertInstanceOf(\WP_Error::class, $denial);
        $this->assertSame('plugin_missing', $denial->get_error_code());
        $this->assertStringContainsString('Install and activate WooCommerce', $denial->get_error_message());
    }

    public function test_a_woocommerce_tool_with_woocommerce_keeps_the_plain_denial(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        Op_Guard::set_available_for_tests(true);

        $this->assertFalse((new Registrar())->permission(self::woo_ability()));
    }

    public function test_the_package_delete_and_update_tools_are_opt_in_gates(): void
    {
        $gates = [
            'wpmcp/delete-plugin' => 'wpmcp_enable_delete_plugin',
            'wpmcp/update-plugin' => 'wpmcp_enable_update_plugin',
            'wpmcp/delete-theme'  => 'wpmcp_enable_delete_theme',
            'wpmcp/update-theme'  => 'wpmcp_enable_update_theme',
        ];
        foreach ($gates as $ability => $filter) {
            $this->assertTrue(Opt_In_Gates::is_gated($ability), $ability);
            $this->assertSame($filter, Opt_In_Gates::filter_for($ability));
            $this->assertFalse(Opt_In_Gates::is_open($ability), $ability . ' is default-off');

            add_filter($filter, '__return_true');
            $this->assertTrue(Opt_In_Gates::is_open($ability), $ability . ' opens with its filter');
            remove_filter($filter, '__return_true');
        }
    }

    public function test_a_disabled_package_tool_names_its_filter_without_sounding_like_a_crash(): void
    {
        $this->as_administrator();

        $result = wp_get_ability('wpmcp/delete-plugin')->execute(['plugin' => 'hello.php', 'confirm' => true]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('opt_in_required', $result->get_error_code());
        $this->assertStringStartsWith('The delete-plugin tool is disabled.', $result->get_error_message());
        $this->assertStringContainsString("add_filter('wpmcp_enable_delete_plugin', '__return_true');", $result->get_error_message());
    }

    public function test_deactivating_a_protected_plugin_says_why_and_how(): void
    {
        try {
            (new Deactivate_Plugin())->handle(['plugin' => 'elementor/elementor.php']);
            $this->fail('Expected the protected plugin to be refused.');
        } catch (\RuntimeException $e) {
            $message = Registrar::plain_text($e->getMessage());
            $this->assertStringContainsString('Refusing to deactivate protected plugin "elementor/elementor.php".', $message);
            $this->assertStringContainsString("the site's page builder", $message);
            $this->assertStringContainsString('Plugins in wp-admin', $message);
        }
    }

    public function test_call_tool_on_a_pro_tool_without_a_license_says_it_needs_pro(): void
    {
        $this->as_administrator();
        Gate::set_pro_for_tests(false);

        $plugin   = Plugin::instance();
        $prop     = new \ReflectionProperty(Plugin::class, 'registrar');
        $original = $prop->getValue($plugin);
        $fresh    = new Registrar();
        $fresh->register(new Ability('wpmcp/test-pro-only', 'pro', 'A pro tool', ['type' => 'object'], fn () => []));
        $prop->setValue($plugin, $fresh);

        try {
            foreach ([ new Call_Tool(), new Get_Tool_Schema() ] as $tool) {
                $result = $tool->handle(['name' => 'wpmcp/test-pro-only']);
                $this->assertInstanceOf(\WP_Error::class, $result);
                $this->assertSame('pro_required', $result->get_error_code());
                $this->assertStringContainsString('WP MCP Pro', $result->get_error_message());
            }

            // A name nobody declared is still "no such tool".
            $this->assertSame('wpmcp_call_tool_unknown', (new Call_Tool())->handle(['name' => 'wpmcp/nope'])->get_error_code());
        } finally {
            $prop->setValue($plugin, $original);
        }
    }

    public function test_suggest_internal_links_on_a_draft_says_it_must_be_published(): void
    {
        $draft = self::factory()->post->create(['post_status' => 'draft']);

        $out = (new Suggest_Internal_Links())->handle(['post_id' => $draft]);

        $this->assertStringContainsString('is draft, not published', $out['error']);
        $this->assertStringContainsString('publish it first', $out['error']);
        $this->assertSame('Post not found.', (new Suggest_Internal_Links())->handle(['post_id' => 99999999])['error']);
    }
}
