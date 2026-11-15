<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Integrations\Integration_Dispatcher;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Registrar;

/**
 * Issue #875: a caller without the capability a tool needs is told which
 * capability it is, and a role that has it, instead of a bare denial.
 */
class CapabilityRefusalTest extends \WP_UnitTestCase
{
    public function test_a_subscriber_is_told_the_tool_and_the_capability(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'subscriber' ]));
        $ability = new Ability('wpmcp/update-builder-content', 'free', 'x', [ 'type' => 'object' ], fn () => [], 'edit_posts');

        $denial = (new Registrar())->permission($ability);

        $this->assertInstanceOf(\WP_Error::class, $denial);
        $this->assertSame('operation_denied', $denial->get_error_code());
        $this->assertStringContainsString('wpmcp-update-builder-content requires the "edit_posts" capability', $denial->get_error_message());
        $this->assertStringContainsString('Administrator', $denial->get_error_message());
        $this->assertSame('capability', $denial->get_error_data()['reason']);
        $this->assertSame('edit_posts', $denial->get_error_data()['capability']);
    }

    public function test_a_caller_with_the_capability_is_not_refused_by_this_path(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $ability = new Ability('wpmcp/x', 'free', 'x', [ 'type' => 'object' ], fn () => [], 'edit_posts');

        $this->assertTrue((new Registrar())->permission($ability));
    }

    public function test_a_dispatcher_refusal_names_the_operation_and_its_capability(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'subscriber' ]));
        $pair = new class extends Integration_Dispatcher {
            public function integration(): string
            {
                return 'capfixture';
            }

            public function is_available(): bool
            {
                return true;
            }

            protected function operations(): array
            {
                return [
                    'strong-write' => [
                        'mode'         => 'write',
                        'capability'   => 'manage_options',
                        'description'  => 'x',
                        'input_schema' => [ 'type' => 'object' ],
                        'handler'      => static fn (array $a): array => [],
                    ],
                ];
            }
        };
        [ , $write ] = $pair->abilities();

        $denial = (new Registrar())->permission($write, [ 'operation' => 'strong-write' ]);

        $this->assertInstanceOf(\WP_Error::class, $denial);
        $this->assertStringContainsString('Operation "strong-write" of wpmcp-capfixture-write requires the "manage_options" capability', $denial->get_error_message());
        $this->assertSame('strong-write', $denial->get_error_data()['operation']);

        // An unknown op falls back to the pair's own capability.
        $fallback = (new Registrar())->permission($write, [ 'operation' => 'nope' ]);
        $this->assertStringContainsString('wpmcp-capfixture-write requires the "edit_posts" capability', $fallback->get_error_message());
    }
}
