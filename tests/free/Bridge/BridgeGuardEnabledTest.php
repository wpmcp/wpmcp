<?php

namespace WPMCP\Tests\Free\Bridge;

use WPMCP\Governance\Opt_In_Gates;
use WPMCP\Tools\Bridge\Bridge_Guard;

/**
 * The third-party ability bridge (issue #194) is off unless explicitly
 * opted into: neither the WPMCP_ENABLE_ABILITY_BRIDGE constant nor the
 * wpmcp_enable_ability_bridge filter is set by default, so a fresh install
 * never exposes another plugin's abilities. Either seam being truthy is
 * sufficient to enable it, mirroring Wp_Cli_Guard::is_enabled(), and the
 * three bridge shells join Opt_In_Gates so the ability grid treats them
 * like every other default-off gate.
 */
class BridgeGuardEnabledTest extends \WP_UnitTestCase
{
    private const SHELLS = [
        'wpmcp/list-site-abilities',
        'wpmcp/get-site-ability',
        'wpmcp/execute-site-ability',
    ];

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_ability_bridge');
        parent::tearDown();
    }

    public function test_disabled_by_default(): void
    {
        $this->assertFalse(Bridge_Guard::is_enabled());
    }

    public function test_enabled_via_filter(): void
    {
        add_filter('wpmcp_enable_ability_bridge', '__return_true');
        $this->assertTrue(Bridge_Guard::is_enabled());
    }

    public function test_filter_can_force_disable_even_if_constant_were_set(): void
    {
        add_filter('wpmcp_enable_ability_bridge', '__return_false');
        $this->assertFalse(Bridge_Guard::is_enabled());
    }

    public function test_disabled_error_has_the_stable_code_every_bridge_tool_returns(): void
    {
        $error = Bridge_Guard::disabled_error();
        $this->assertSame('wpmcp_bridge_disabled', $error->get_error_code());
        $this->assertStringContainsString('wpmcp_enable_ability_bridge', $error->get_error_message());
    }

    public function test_every_bridge_shell_is_a_gated_ability_behind_the_bridge_filter(): void
    {
        foreach (self::SHELLS as $name) {
            $this->assertTrue(Opt_In_Gates::is_gated($name), $name);
            $this->assertSame('wpmcp_enable_ability_bridge', Opt_In_Gates::filter_for($name), $name);
            $this->assertFalse(Opt_In_Gates::is_open($name), "{$name} must read closed while the bridge is off.");
        }

        add_filter('wpmcp_enable_ability_bridge', '__return_true');
        foreach (self::SHELLS as $name) {
            $this->assertTrue(Opt_In_Gates::is_open($name), "{$name} must follow Bridge_Guard::is_enabled().");
        }
    }

    public function test_own_namespace_and_empty_names_are_never_foreign(): void
    {
        $this->assertFalse(Bridge_Guard::is_foreign(''));
        $this->assertFalse(Bridge_Guard::is_foreign('wpmcp/get-post'));
        $this->assertFalse(Bridge_Guard::is_foreign('wpmcp/call-tool'));
        foreach (self::SHELLS as $name) {
            $this->assertFalse(Bridge_Guard::is_foreign($name), "{$name} must not be reachable through itself.");
        }

        $this->assertTrue(Bridge_Guard::is_foreign('yoast/analyze-page'));
        // A namespace that merely starts with our letters is still foreign.
        $this->assertTrue(Bridge_Guard::is_foreign('wpmcpx/thing'));
    }

    public function test_owner_is_the_namespace_prefix(): void
    {
        $this->assertSame('yoast', Bridge_Guard::owner_of('yoast/analyze-page'));
        $this->assertSame('fluentcart', Bridge_Guard::owner_of('fluentcart/orders/list'));
        $this->assertSame('bare', Bridge_Guard::owner_of('bare'));
    }

    public function test_only_abilities_exposed_with_show_in_rest_are_bridgeable(): void
    {
        $ability_with_meta = static fn (array $meta): object => new class ($meta) {
            public function __construct(private array $meta)
            {
            }

            public function get_meta_item(string $key, $default_value = null)
            {
                return $this->meta[ $key ] ?? $default_value;
            }
        };

        $this->assertTrue(Bridge_Guard::is_bridgeable($ability_with_meta(['show_in_rest' => true])));
        $this->assertFalse(Bridge_Guard::is_bridgeable($ability_with_meta(['show_in_rest' => false])));
        // Core's default when the owner said nothing is false, and a truthy
        // non-boolean is not an opt-in either.
        $this->assertFalse(Bridge_Guard::is_bridgeable($ability_with_meta([])));
        $this->assertFalse(Bridge_Guard::is_bridgeable($ability_with_meta(['show_in_rest' => 1])));
        $this->assertFalse(Bridge_Guard::is_bridgeable(null));
        $this->assertFalse(Bridge_Guard::is_bridgeable(new \stdClass()));
    }
}
