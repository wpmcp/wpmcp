<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Integrations\Block_Suites_Integration;
use WPMCP\Integrations\Forms_Integration;
use WPMCP\Integrations\Integration_Dispatcher;
use WPMCP\MCP\Registrar;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\Tools\Dispatch\Call_Tool;
use WPMCP\Tools\Dispatch\Get_Tool_Schema;

require_once __DIR__ . '/../../support/forms-adapters.php';

/**
 * Issue #790: a pair that registers only while its host plugin is loaded
 * (the forms adapters, the block suites, the plugin data packs) is, while
 * that plugin is off, named as needing the plugin by call-tool and
 * get-tool-schema, instead of "No wpmcp tool named ... is registered".
 */
class PluginInactiveRefusalTest extends \WP_UnitTestCase
{
    private Registrar $original;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->original = Plugin::instance()->registrar();
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Plugin::class, 'registrar'))->setValue(Plugin::instance(), $this->original);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** Runs Plugin's own registration loop over $integrations into a fresh Registrar, made the live one. */
    private static function register(array $integrations): Registrar
    {
        $fresh = new Registrar();
        (new \ReflectionProperty(Plugin::class, 'registrar'))->setValue(Plugin::instance(), $fresh);
        $method = new \ReflectionMethod(Plugin::class, 'register_integrations');
        $method->invoke(Plugin::instance(), $fresh, $integrations);
        return $fresh;
    }

    private static function forms_fixture(bool $available, string $tier = 'free'): Forms_Integration
    {
        return new class ($available, $tier) extends Forms_Integration {
            public function __construct(private bool $available, private string $fixture_tier)
            {
            }

            public function integration(): string
            {
                return 'fixtureforms';
            }

            public function is_available(): bool
            {
                return $this->available;
            }

            public function tier(): string
            {
                return $this->fixture_tier;
            }

            protected function summary(): string
            {
                return 'Fixture Forms (forms and entries)';
            }

            protected function operations(): array
            {
                return [];
            }
        };
    }

    public function test_a_forms_pair_with_its_plugin_inactive_names_the_plugin(): void
    {
        self::register([ self::forms_fixture(false) ]);

        foreach ([ 'wpmcp/fixtureforms-read', 'wpmcp/fixtureforms-write' ] as $name) {
            foreach ([ new Call_Tool(), new Get_Tool_Schema() ] as $tool) {
                $result = $tool->handle(['name' => $name, 'arguments' => ['operation' => 'list-forms']]);
                $this->assertInstanceOf(\WP_Error::class, $result);
                $this->assertSame('plugin_missing', $result->get_error_code());
                $this->assertSame(
                    "Fixture Forms is not active on this site, so {$name} cannot run. Install and activate Fixture Forms, then try again.",
                    $result->get_error_message()
                );
                $this->assertSame('Fixture Forms', $result->get_error_data()['plugin']);
            }
        }

        // A truly unknown name is still unknown.
        $this->assertSame('wpmcp_call_tool_unknown', (new Call_Tool())->handle(['name' => 'wpmcp/nope'])->get_error_code());
        $this->assertSame('wpmcp_get_tool_schema_unknown', (new Get_Tool_Schema())->handle(['name' => 'wpmcp/nope'])->get_error_code());
    }

    public function test_a_pair_with_its_plugin_active_is_registered_and_not_held(): void
    {
        $registrar = self::register([ self::forms_fixture(true) ]);

        $this->assertNotNull($registrar->get('wpmcp/fixtureforms-read'));
        $this->assertNull($registrar->plugin_inactive('wpmcp/fixtureforms-read'));
    }

    public function test_a_pair_a_site_filtered_off_while_its_plugin_is_loaded_stays_unknown(): void
    {
        $off = static fn (bool $register, string $slug): bool => 'fixtureforms' === $slug ? false : $register;
        add_filter('wpmcp_integration_should_register', $off, 10, 2);
        try {
            self::register([ self::forms_fixture(true) ]);
        } finally {
            remove_filter('wpmcp_integration_should_register', $off, 10);
        }

        $this->assertSame('wpmcp_call_tool_unknown', (new Call_Tool())->handle(['name' => 'wpmcp/fixtureforms-read'])->get_error_code());
    }

    public function test_a_pro_pair_on_an_unlicensed_install_still_says_it_needs_pro(): void
    {
        Gate::set_pro_for_tests(false);
        self::register([ self::forms_fixture(false, 'pro') ]);

        $this->assertSame('pro_required', (new Call_Tool())->handle(['name' => 'wpmcp/fixtureforms-read'])->get_error_code());
    }

    public function test_a_pair_that_works_with_several_plugins_lists_them(): void
    {
        $several = new class extends Integration_Dispatcher {
            public function integration(): string
            {
                return 'fixturesuite';
            }

            public function is_available(): bool
            {
                return false;
            }

            public function registers_only_when_available(): bool
            {
                return true;
            }

            protected function summary(): string
            {
                return 'Alpha Blocks, Beta Blocks';
            }

            protected function operations(): array
            {
                return [];
            }
        };
        self::register([ $several ]);

        $result = (new Call_Tool())->handle(['name' => 'wpmcp/fixturesuite-read']);
        $this->assertSame('plugin_missing', $result->get_error_code());
        $this->assertSame(
            'None of the plugins wpmcp/fixturesuite-read works with is active on this site (Alpha Blocks, Beta Blocks), so wpmcp/fixturesuite-read cannot run. Install and activate one of them, then try again.',
            $result->get_error_message()
        );
    }

    public function test_every_forms_adapter_names_its_own_plugin(): void
    {
        $expected = [
            'contactform7' => 'Contact Form 7',
            'forminator'   => 'Forminator',
            'metform'      => 'MetForm',
            'sureforms'    => 'SureForms',
            'wpforms'      => 'WPForms',
            'gravityforms' => 'Gravity Forms',
            'formidable'   => 'Formidable Forms',
            'ninjaforms'   => 'Ninja Forms',
            'fluentforms'  => 'Fluent Forms',
        ];
        foreach (wpmcp_forms_adapter_classes() as $slug => $class) {
            $this->assertSame([ 'name' => $expected[ $slug ], 'several' => false ], (new $class())->host_plugin(), $slug);
        }
    }

    public function test_the_block_suites_pair_lists_every_suite(): void
    {
        $host = (new Block_Suites_Integration())->host_plugin();

        $this->assertTrue($host['several']);
        foreach ([ 'Kadence Blocks', 'GenerateBlocks', 'Spectra', 'Otter Blocks' ] as $suite) {
            $this->assertStringContainsString($suite, $host['name']);
        }
    }
}
