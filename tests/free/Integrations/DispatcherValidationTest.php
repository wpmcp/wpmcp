<?php

namespace WPMCP\Tests\Free\Integrations;

/**
 * Dispatch-level validation: unknown/disabled operations and malformed args
 * must produce structured errors WITHOUT the op handler ever running (no
 * side effects), and destructive ops must demand confirm:true.
 */
class DispatcherValidationTest extends \WP_UnitTestCase
{
    private Fixture_Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        Fixture_Integration::reset();
        $this->integration = new Fixture_Integration();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
    }

    protected function tearDown(): void
    {
        Fixture_Integration::reset();
        parent::tearDown();
    }

    public function test_unknown_read_operation_returns_structured_error_without_side_effects(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'no-such-op' ]);

        $this->assertSame('unknown_operation', $out['error']['code']);
        $this->assertContains('ping', $out['error']['data']['operations']);
        $this->assertSame([], Fixture_Integration::$calls);
    }

    public function test_read_op_is_not_reachable_through_the_write_dispatcher(): void
    {
        $out = $this->integration->handle_write([ 'operation' => 'ping', 'args' => [ 'value' => 'x' ] ]);

        $this->assertSame('unknown_operation', $out['error']['code']);
        $this->assertSame([], Fixture_Integration::$calls);
    }

    public function test_write_op_is_not_reachable_through_the_read_dispatcher(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'set-content', 'args' => [ 'post_id' => 1, 'content' => 'x' ] ]);

        $this->assertSame('unknown_operation', $out['error']['code']);
        $this->assertSame([], Fixture_Integration::$calls);
    }

    public function test_missing_required_arg_returns_invalid_args_without_side_effects(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'ping', 'args' => [] ]);

        $this->assertSame('invalid_args', $out['error']['code']);
        $this->assertSame([], Fixture_Integration::$calls);
    }

    public function test_wrong_arg_type_returns_invalid_args_without_side_effects(): void
    {
        $out = $this->integration->handle_write([
            'operation' => 'set-content',
            'args'      => [ 'post_id' => 'not-an-int', 'content' => 'x' ],
        ]);

        $this->assertSame('invalid_args', $out['error']['code']);
        $this->assertSame([], Fixture_Integration::$calls);
    }

    public function test_valid_read_dispatches_to_the_handler(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'ping', 'args' => [ 'value' => 'hello' ] ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame('ping', $out['operation']);
        $this->assertSame([ 'pong' => 'hello' ], $out['result']);
        $this->assertCount(1, Fixture_Integration::$calls);
    }

    public function test_default_off_op_returns_operation_disabled(): void
    {
        $out = $this->integration->handle_write([ 'operation' => 'default-off-op' ]);

        $this->assertSame('operation_disabled', $out['error']['code']);
        $this->assertSame([], Fixture_Integration::$calls);
    }

    public function test_default_off_op_runs_once_a_site_opts_in_via_filter(): void
    {
        $enable = fn ($enabled, $integration, $op) => ('testint' === $integration && 'default-off-op' === $op) ? true : $enabled;
        add_filter('wpmcp_integration_op_enabled', $enable, 10, 3);
        try {
            $out = $this->integration->handle_write([ 'operation' => 'default-off-op' ]);
        } finally {
            remove_filter('wpmcp_integration_op_enabled', $enable);
        }

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame([ 'done' => true ], $out['result']);
    }

    public function test_destructive_op_without_confirm_returns_confirmation_required(): void
    {
        $out = $this->integration->handle_write([ 'operation' => 'nuke' ]);

        $this->assertSame('confirmation_required', $out['error']['code']);
        $this->assertSame([], Fixture_Integration::$calls);
    }

    public function test_destructive_op_with_confirm_true_runs(): void
    {
        $out = $this->integration->handle_write([ 'operation' => 'nuke', 'confirm' => true ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame([ 'nuked' => true ], $out['result']);
    }

    public function test_list_operations_exposes_the_catalog_with_per_op_schemas(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'list-operations' ]);

        $this->assertArrayNotHasKey('error', $out);
        $ops = [];
        foreach ($out['result']['operations'] as $op) {
            $ops[ $op['name'] ] = $op;
        }

        $this->assertSame('read', $ops['ping']['mode']);
        $this->assertSame([ 'value' ], $ops['ping']['input_schema']['required']);
        $this->assertSame('write', $ops['set-content']['mode']);
        $this->assertSame('destructive', $ops['nuke']['mode']);
        $this->assertTrue($ops['nuke']['requires_confirm']);
        $this->assertFalse($ops['default-off-op']['enabled']);
        $this->assertTrue($ops['ping']['enabled']);
        $this->assertSame('manage_options', $ops['guarded-op']['capability']);
        $this->assertTrue($out['result']['available']);
    }

    public function test_op_with_a_missing_dependency_returns_a_top_level_error(): void
    {
        Fixture_Integration::$dependency_present = false;

        $out = $this->integration->handle_read([ 'operation' => 'needs-dependency' ]);

        // The refusal must use the dispatcher's own error channel, not a
        // hand-rolled ['error' => ...] payload wrapped in a success envelope.
        $this->assertArrayNotHasKey('result', $out);
        $this->assertSame('companion_unavailable', $out['error']['code']);
        $this->assertSame('needs-dependency', $out['error']['data']['operation']);
        $this->assertSame([], Fixture_Integration::$calls, 'The handler must never run');
    }

    public function test_op_with_a_satisfied_dependency_runs(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'needs-dependency' ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame([ 'done' => true ], $out['result']);
    }

    public function test_catalog_flags_an_op_whose_dependency_is_missing(): void
    {
        Fixture_Integration::$dependency_present = false;

        $ops = array_column($this->integration->catalog()['operations'], null, 'name');

        $this->assertFalse($ops['needs-dependency']['dependency_met']);
        $this->assertTrue($ops['ping']['dependency_met']);
        $this->assertArrayNotHasKey(
            'available',
            $ops['ping'],
            'Per-op dependency state is dependency_met; "available" is the top-level host-plugin flag and must not be overloaded'
        );
    }

    /**
     * A dependency gate is the one place that must never degrade to
     * permissive. 'requires' => self::check() (the RESULT, not the callable)
     * is an easy misreading, and treating it as satisfied would delete the
     * gate and let the handler fatal on the very class it was guarding, while
     * the catalog still advertised the op as ready.
     */
    public function test_a_non_callable_requires_fails_closed(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'broken-requires' ]);

        $this->assertArrayNotHasKey('result', $out);
        $this->assertSame('dependency_check_invalid', $out['error']['code']);
        $this->assertSame('broken-requires', $out['error']['data']['operation']);
        $this->assertSame([], Fixture_Integration::$calls, 'The handler must never run behind an unverifiable gate');

        $ops = array_column($this->integration->catalog()['operations'], null, 'name');
        $this->assertFalse($ops['broken-requires']['dependency_met']);
    }

    /**
     * list-operations is the one op promised to answer for every integration,
     * so a dependency check that throws must degrade to "unavailable" rather
     * than take the whole catalog down with it.
     */
    public function test_a_throwing_requires_is_unavailable_not_fatal(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'list-operations' ]);

        $this->assertArrayNotHasKey('error', $out);
        $ops = array_column($out['result']['operations'], null, 'name');
        $this->assertFalse($ops['throwing-requires']['dependency_met']);
        $this->assertTrue($ops['ping']['dependency_met'], 'One broken gate must not poison the rest of the catalog');

        $refused = $this->integration->handle_read([ 'operation' => 'throwing-requires' ]);
        $this->assertSame('dependency_unavailable', $refused['error']['code']);
        $this->assertSame([], Fixture_Integration::$calls);
    }

    /**
     * A handler-raised refusal belongs on the dispatcher's top-level error
     * channel. Wrapped in a success envelope it is indistinguishable from an
     * empty result, which is exactly the anti-pattern the 'requires' hook was
     * added to remove.
     */
    public function test_a_handler_refusal_surfaces_as_a_top_level_error(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'refusing-op' ]);

        $this->assertArrayNotHasKey('result', $out);
        $this->assertSame('scope_unresolved', $out['error']['code']);
        $this->assertSame('Refusing to answer unscoped.', $out['error']['message']);
        $this->assertSame(7, $out['error']['data']['form_id'], 'The refusal carries its own data');
        $this->assertSame('refusing-op', $out['error']['data']['operation']);
    }

    public function test_unavailable_integration_returns_structured_error_not_fatal(): void
    {
        Fixture_Integration::$available = false;

        $read  = $this->integration->handle_read([ 'operation' => 'ping', 'args' => [ 'value' => 'x' ] ]);
        $write = $this->integration->handle_write([ 'operation' => 'set-content', 'args' => [ 'post_id' => 1, 'content' => 'x' ] ]);

        $this->assertSame('integration_unavailable', $read['error']['code']);
        $this->assertSame('integration_unavailable', $write['error']['code']);
        $this->assertSame([], Fixture_Integration::$calls);
    }

    public function test_list_operations_still_answers_when_unavailable(): void
    {
        Fixture_Integration::$available = false;

        $out = $this->integration->handle_read([ 'operation' => 'list-operations' ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertFalse($out['result']['available']);
    }
}
