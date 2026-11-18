<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Integration_Dispatcher;
use WPMCP\Integrations\Plugin_Refusal;

/**
 * Issue #980: while none of a pair's plugins is active (Plugin_Data's packs,
 * the block suites), a call to one of its operations answered the generic
 * integration_unavailable, although the operation knows which plugin it
 * needs. Such a call now gives that operation's own plugin_missing or
 * plugin_inactive refusal with the plugins link, the same answer it gives
 * while another of the pair's plugins is active. The capability checks
 * still come first, so a caller without the capability learns no plugin
 * name, and an op without a dependency check keeps the generic answer.
 */
class UnavailablePairOpRefusalTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    private static function pair(): Integration_Dispatcher
    {
        return new class () extends Integration_Dispatcher {
            public function integration(): string
            {
                return 'fixturedata';
            }

            public function is_available(): bool
            {
                return false;
            }

            protected function operations(): array
            {
                return [
                    'fixture-list-things' => [
                        'mode'        => 'read',
                        'description' => 'List things',
                        'capability'  => 'manage_options',
                        'requires'    => static fn () => Plugin_Refusal::make('plugin_missing', 'Fixture Things', 'fixture-things/fixture-things.php'),
                        'handler'     => static fn (): array => [ 'ran' => true ],
                    ],
                    'fixture-plain-read' => [
                        'mode'        => 'read',
                        'description' => 'Plain read',
                        'handler'     => static fn (): array => [ 'ran' => true ],
                    ],
                ];
            }
        };
    }

    public function test_an_op_with_a_dependency_names_its_plugin_while_the_pair_is_unavailable(): void
    {
        $out = self::pair()->handle_read([ 'operation' => 'fixture-list-things' ]);

        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Fixture Things', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
        $this->assertSame('fixture-list-things', $out['error']['data']['operation'] ?? null);
    }

    public function test_a_caller_without_the_capability_learns_no_plugin_name(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));

        $out = self::pair()->handle_read([ 'operation' => 'fixture-list-things' ]);

        $this->assertSame('operation_denied', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringNotContainsString('Fixture Things', (string) wp_json_encode($out));
    }

    public function test_an_op_without_a_dependency_keeps_the_generic_answer(): void
    {
        $out = self::pair()->handle_read([ 'operation' => 'fixture-plain-read' ]);

        $this->assertSame('integration_unavailable', $out['error']['code'] ?? null, (string) wp_json_encode($out));
    }

    public function test_nothing_runs_while_the_pair_is_unavailable(): void
    {
        $this->assertArrayNotHasKey('ran', self::pair()->handle_read([ 'operation' => 'fixture-plain-read' ]));
        $this->assertArrayNotHasKey('ran', self::pair()->handle_read([ 'operation' => 'fixture-list-things' ]));
    }
}
