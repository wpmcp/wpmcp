<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\MCP\{Ability, Registrar};
use WPMCP\Pro\Gate;
use WPMCP\Tools\Elementor\Get_Elementor_Data;
use WPMCP\Tools\Elementor\Update_Element;
use WPMCP\Tools\Elementor\Add_Widget;
use WPMCP\Tools\Elementor\Remove_Element;
use WPMCP\Tools\Elementor\Move_Element;
use WPMCP\Tools\Elementor\Generate_Widget;

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

    private function make_update_element_ability(): Ability
    {
        return new Ability(
            'wpmcp/update-element',
            'pro',
            'Update an Elementor element\'s settings by id.',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'    => ['type' => 'integer'],
                    'element_id' => ['type' => 'string'],
                    'settings'   => ['type' => 'object'],
                ],
                'required'   => ['post_id', 'element_id', 'settings'],
            ],
            [new Update_Element(), 'handle'],
            'edit_posts',
            'elementor',
            'update'
        );
    }

    public function test_registrar_skips_update_element_when_free(): void
    {
        Gate::set_pro_for_tests(false);

        $registrar = new Registrar();
        $registrar->register($this->make_update_element_ability());

        $this->assertCount(0, $registrar->all());
    }

    public function test_registrar_keeps_update_element_when_pro(): void
    {
        Gate::set_pro_for_tests(true);

        $registrar = new Registrar();
        $registrar->register($this->make_update_element_ability());

        $names = array_map(fn($a) => $a->name, $registrar->all());
        $this->assertContains('wpmcp/update-element', $names);
    }

    private function make_add_widget_ability(): Ability
    {
        return new Ability(
            'wpmcp/add-widget',
            'pro',
            'Add a widget element as a child of a parent element.',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'     => ['type' => 'integer'],
                    'parent_id'   => ['type' => 'string'],
                    'widget_type' => ['type' => 'string'],
                    'settings'    => ['type' => 'object'],
                ],
                'required'   => ['post_id', 'parent_id', 'widget_type'],
            ],
            [new Add_Widget(), 'handle'],
            'edit_posts',
            'elementor',
            'update'
        );
    }

    public function test_registrar_skips_add_widget_when_free(): void
    {
        Gate::set_pro_for_tests(false);

        $registrar = new Registrar();
        $registrar->register($this->make_add_widget_ability());

        $this->assertCount(0, $registrar->all());
    }

    public function test_registrar_keeps_add_widget_when_pro(): void
    {
        Gate::set_pro_for_tests(true);

        $registrar = new Registrar();
        $registrar->register($this->make_add_widget_ability());

        $names = array_map(fn($a) => $a->name, $registrar->all());
        $this->assertContains('wpmcp/add-widget', $names);
    }

    private function make_remove_element_ability(): Ability
    {
        return new Ability(
            'wpmcp/remove-element',
            'pro',
            'Remove an element (and its children) by id.',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'    => ['type' => 'integer'],
                    'element_id' => ['type' => 'string'],
                ],
                'required'   => ['post_id', 'element_id'],
            ],
            [new Remove_Element(), 'handle'],
            'edit_posts',
            'elementor',
            'update'
        );
    }

    public function test_registrar_skips_remove_element_when_free(): void
    {
        Gate::set_pro_for_tests(false);

        $registrar = new Registrar();
        $registrar->register($this->make_remove_element_ability());

        $this->assertCount(0, $registrar->all());
    }

    public function test_registrar_keeps_remove_element_when_pro(): void
    {
        Gate::set_pro_for_tests(true);

        $registrar = new Registrar();
        $registrar->register($this->make_remove_element_ability());

        $names = array_map(fn($a) => $a->name, $registrar->all());
        $this->assertContains('wpmcp/remove-element', $names);
    }

    private function make_move_element_ability(): Ability
    {
        return new Ability(
            'wpmcp/move-element',
            'pro',
            'Reparent an element by id.',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'    => ['type' => 'integer'],
                    'element_id' => ['type' => 'string'],
                    'parent_id'  => ['type' => 'string'],
                ],
                'required'   => ['post_id', 'element_id', 'parent_id'],
            ],
            [new Move_Element(), 'handle'],
            'edit_posts',
            'elementor',
            'update'
        );
    }

    public function test_registrar_skips_move_element_when_free(): void
    {
        Gate::set_pro_for_tests(false);

        $registrar = new Registrar();
        $registrar->register($this->make_move_element_ability());

        $this->assertCount(0, $registrar->all());
    }

    public function test_registrar_keeps_move_element_when_pro(): void
    {
        Gate::set_pro_for_tests(true);

        $registrar = new Registrar();
        $registrar->register($this->make_move_element_ability());

        $names = array_map(fn($a) => $a->name, $registrar->all());
        $this->assertContains('wpmcp/move-element', $names);
    }

    private function make_generate_widget_ability(): Ability
    {
        return new Ability(
            'wpmcp/generate-widget',
            'pro',
            'Generate a widget element from a curated schema and insert it into a page.',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'     => ['type' => 'integer'],
                    'parent_id'   => ['type' => 'string'],
                    'widget_type' => ['type' => 'string'],
                    'settings'    => ['type' => 'object'],
                    'seed'        => ['type' => 'string'],
                ],
                'required'   => ['post_id', 'widget_type', 'settings'],
            ],
            [new Generate_Widget(), 'handle'],
            'edit_posts',
            'elementor',
            'create'
        );
    }

    public function test_registrar_skips_generate_widget_when_free(): void
    {
        Gate::set_pro_for_tests(false);

        $registrar = new Registrar();
        $registrar->register($this->make_generate_widget_ability());

        $this->assertCount(0, $registrar->all());
    }

    public function test_registrar_keeps_generate_widget_when_pro(): void
    {
        Gate::set_pro_for_tests(true);

        $registrar = new Registrar();
        $registrar->register($this->make_generate_widget_ability());

        $names = array_map(fn($a) => $a->name, $registrar->all());
        $this->assertContains('wpmcp/generate-widget', $names);
    }
}
