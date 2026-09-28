<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Governance\Governance;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Registrar;
use WPMCP\Pro\Gate;

/**
 * Layered permission model: the dispatcher pair's own capability / governance
 * / pro gates apply through the ordinary Registrar path, PLUS per-operation
 * capability overrides and op-granular governance so one risky op can be
 * locked down (or switched off) without touching its siblings.
 */
class DispatcherPermissionsTest extends \WP_UnitTestCase
{
    private Fixture_Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        Fixture_Integration::reset();
        Governance::reset_for_tests();
        $this->integration = new Fixture_Integration();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
    }

    protected function tearDown(): void
    {
        Fixture_Integration::reset();
        Governance::reset_for_tests();
        parent::tearDown();
    }

    public function test_per_op_capability_override_denies_even_when_dispatcher_level_gate_allows(): void
    {
        // Editors hold edit_posts, the dispatcher-level capability, so the
        // ability itself is permitted - but guarded-op demands manage_options.
        $this->assertTrue(current_user_can($this->integration->capability()));

        $out = $this->integration->handle_write([ 'operation' => 'guarded-op' ]);

        $this->assertSame('operation_denied', $out['error']['code']);
        $this->assertSame('capability', $out['error']['data']['reason']);
        $this->assertSame([], Fixture_Integration::$calls);
    }

    public function test_per_op_capability_override_allows_a_sufficiently_privileged_user(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));

        $out = $this->integration->handle_write([ 'operation' => 'guarded-op' ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame([ 'done' => true ], $out['result']);
    }

    public function test_governance_can_disable_a_single_op_without_disabling_the_pair(): void
    {
        Governance::set_ability_toggle('wpmcp/testint-set-content', false);

        $post_id = self::factory()->post->create([ 'post_content' => 'ORIGINAL' ]);
        $denied  = $this->integration->handle_write([
            'operation' => 'set-content',
            'args'      => [ 'post_id' => $post_id, 'content' => 'MUTATED' ],
        ]);
        $sibling = $this->integration->handle_write([ 'operation' => 'no-target-write' ]);

        $this->assertSame('operation_denied', $denied['error']['code']);
        $this->assertSame('governance', $denied['error']['data']['reason']);
        $this->assertSame('ORIGINAL', get_post($post_id)->post_content);
        $this->assertArrayNotHasKey('error', $sibling);

        // The dispatcher pair itself stays enabled under Governance.
        foreach ($this->integration->abilities() as $ability) {
            $this->assertTrue(Governance::is_ability_enabled($ability));
        }
    }

    public function test_op_level_governance_filter_narrows_a_single_op(): void
    {
        $narrow = fn ($enabled, $name) => 'wpmcp/testint-ping' === $name ? false : $enabled;
        add_filter('wpmcp_ability_enabled', $narrow, 10, 2);
        try {
            $denied  = $this->integration->handle_read([ 'operation' => 'ping', 'args' => [ 'value' => 'x' ] ]);
            $sibling = $this->integration->handle_read([ 'operation' => 'list-operations' ]);
        } finally {
            remove_filter('wpmcp_ability_enabled', $narrow);
        }

        $this->assertSame('operation_denied', $denied['error']['code']);
        $this->assertArrayNotHasKey('error', $sibling);
    }

    public function test_abilities_returns_a_read_write_pair_with_expected_metadata(): void
    {
        $abilities = $this->integration->abilities();
        $this->assertCount(2, $abilities);

        [ $read, $write ] = $abilities;
        $this->assertInstanceOf(Ability::class, $read);
        $this->assertSame('wpmcp/testint-read', $read->name);
        $this->assertSame('wpmcp/testint-write', $write->name);
        $this->assertSame('read', $read->operation);
        $this->assertSame('update', $write->operation);
        $this->assertSame('testint', $read->domain);
        $this->assertSame('testint', $write->domain);
        $this->assertTrue($read->read_only_hint);
        $this->assertFalse($write->read_only_hint);
        // The fixture ships a destructive op, so the write half must carry the hint.
        $this->assertTrue($write->destructive_hint);
        $this->assertSame([ 'operation' ], $read->input_schema['required']);
    }

    public function test_pro_tier_dispatcher_is_not_registered_without_a_license(): void
    {
        $integration = new class extends Fixture_Integration {
            public function tier(): string
            {
                return 'pro';
            }
        };

        Gate::set_pro_for_tests(false);
        try {
            $registrar = new Registrar();
            foreach ($integration->abilities() as $ability) {
                $registrar->register($ability);
            }
            $this->assertSame([], $registrar->all());
        } finally {
            Gate::set_pro_for_tests(null);
        }
    }
}
