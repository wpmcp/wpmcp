<?php

namespace WPMCP\Tests\Pro\Code;

use WPMCP\MCP\{Ability, Registrar};
use WPMCP\Pro\Gate;
use WPMCP\Tools\Code\Run_Php_Snippet;

/**
 * run-php-snippet (issue #45) is PRO tier: the single most dangerous
 * capability this plugin exposes, matching the run-wp-cli precedent
 * (issue #44) for gating an advanced/dangerous site-operations feature at
 * 'pro' rather than 'free'. Mirrors
 * tests/pro/Cli/RunWpCliAbilitiesRegistrationTest.php: Plugin::boot()
 * registers abilities once at wp_abilities_api_init, so this builds the
 * same Ability the boot path constructs and drives it through a fresh
 * Registrar directly.
 */
class RunPhpSnippetAbilitiesRegistrationTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function make_run_php_snippet_ability(): Ability
    {
        return new Ability(
            'wpmcp/run-php-snippet',
            'pro',
            'Run a guarded PHP snippet.',
            [
                'type'       => 'object',
                'properties' => ['code' => ['type' => 'string']],
                'required'   => ['code'],
            ],
            [new Run_Php_Snippet(), 'handle'],
            'manage_options',
            'code',
            'update'
        );
    }

    public function test_registrar_skips_run_php_snippet_when_free(): void
    {
        Gate::set_pro_for_tests(false);

        $registrar = new Registrar();
        $registrar->register($this->make_run_php_snippet_ability());

        $this->assertCount(0, $registrar->all());
    }

    public function test_registrar_keeps_run_php_snippet_when_pro(): void
    {
        Gate::set_pro_for_tests(true);

        $registrar = new Registrar();
        $registrar->register($this->make_run_php_snippet_ability());

        $names = array_map(fn($a) => $a->name, $registrar->all());
        $this->assertContains('wpmcp/run-php-snippet', $names);
    }
}
