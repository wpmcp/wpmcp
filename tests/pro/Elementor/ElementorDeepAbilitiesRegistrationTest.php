<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\MCP\{Ability, Registrar};
use WPMCP\Pro\Gate;
use WPMCP\Tools\Elementor\Get_Elementor_Data;

/**
 * Verifies the get-elementor-data ability is declared as pro-tier and that
 * Registrar (which every ability in Plugin::boot() goes through) only keeps
 * a 'pro' tier ability when Gate::is_pro() is true. Plugin::boot() itself
 * registers abilities once at wp_abilities_api_init, so it cannot be
 * re-exercised per-test against a toggled Gate; this test instead builds
 * the same Ability the boot path constructs and drives it through a fresh
 * Registrar, which is what actually enforces the pro gate.
 */
class ElementorDeepAbilitiesRegistrationTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function make_ability(): Ability
    {
        return new Ability(
            'wpmcp/get-elementor-data',
            'pro',
            'Return a page\'s parsed Elementor element tree.',
            [
                'type'       => 'object',
                'properties' => ['post_id' => ['type' => 'integer']],
                'required'   => ['post_id'],
            ],
            [new Get_Elementor_Data(), 'handle'],
            'edit_posts',
            'elementor',
            'read'
        );
    }

    public function test_registrar_skips_get_elementor_data_when_free(): void
    {
        Gate::set_pro_for_tests(false);

        $registrar = new Registrar();
        $registrar->register($this->make_ability());

        $this->assertCount(0, $registrar->all());
    }

    public function test_registrar_keeps_get_elementor_data_when_pro(): void
    {
        Gate::set_pro_for_tests(true);

        $registrar = new Registrar();
        $registrar->register($this->make_ability());

        $names = array_map(fn($a) => $a->name, $registrar->all());
        $this->assertContains('wpmcp/get-elementor-data', $names);
    }
}
