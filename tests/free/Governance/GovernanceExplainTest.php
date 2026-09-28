<?php

namespace WPMCP\Tests\Free\Governance;

use WPMCP\Governance\Governance;
use WPMCP\MCP\Ability;

/**
 * Issue #78: Governance::explain() - the same six-layer AND-of-narrowing
 * walk as is_ability_enabled(), but reporting WHICH layer decided, so the
 * ability grid can show "disabled: governance toggle" vs "disabled:
 * wpmcp_domain_enabled filter" instead of a bare off state. Read-only:
 * explain() must always agree with is_ability_enabled().
 */
class GovernanceExplainTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        Governance::reset_for_tests();
        remove_all_filters('wpmcp_ability_enabled');
        remove_all_filters('wpmcp_domain_enabled');
        remove_all_filters('wpmcp_operation_enabled');
        parent::tearDown();
    }

    private function ability(string $name = 'wpmcp/get-post', string $domain = 'content', string $operation = 'read'): Ability
    {
        return new Ability($name, 'free', 'desc', [], fn () => [], 'edit_posts', $domain, $operation);
    }

    public function test_enabled_ability_has_no_deciding_layer(): void
    {
        $this->assertSame(
            ['enabled' => true, 'layer' => null],
            Governance::explain($this->ability())
        );
    }

    public function test_reports_the_stored_ability_toggle_layer(): void
    {
        Governance::set_ability_toggle('wpmcp/get-post', false);

        $this->assertSame(
            ['enabled' => false, 'layer' => 'ability_toggle'],
            Governance::explain($this->ability())
        );
    }

    public function test_reports_the_ability_filter_layer(): void
    {
        add_filter('wpmcp_ability_enabled', '__return_false');

        $this->assertSame(
            ['enabled' => false, 'layer' => 'ability_filter'],
            Governance::explain($this->ability())
        );
    }

    public function test_reports_the_stored_domain_toggle_layer(): void
    {
        Governance::set_domain_toggle('database', false);

        $this->assertSame(
            ['enabled' => false, 'layer' => 'domain_toggle'],
            Governance::explain($this->ability('wpmcp/delete-rows', 'database', 'delete'))
        );
    }

    public function test_reports_the_domain_filter_layer(): void
    {
        add_filter('wpmcp_domain_enabled', '__return_false');

        $this->assertSame(
            ['enabled' => false, 'layer' => 'domain_filter'],
            Governance::explain($this->ability())
        );
    }

    public function test_reports_the_stored_operation_toggle_layer(): void
    {
        Governance::set_operation_toggle('delete', false);

        $this->assertSame(
            ['enabled' => false, 'layer' => 'operation_toggle'],
            Governance::explain($this->ability('wpmcp/delete-post', 'content', 'delete'))
        );
    }

    public function test_reports_the_operation_filter_layer(): void
    {
        add_filter('wpmcp_operation_enabled', '__return_false');

        $this->assertSame(
            ['enabled' => false, 'layer' => 'operation_filter'],
            Governance::explain($this->ability())
        );
    }

    public function test_most_specific_layer_wins_when_several_would_disable(): void
    {
        Governance::set_ability_toggle('wpmcp/delete-rows', false);
        Governance::set_domain_toggle('database', false);

        $this->assertSame(
            'ability_toggle',
            Governance::explain($this->ability('wpmcp/delete-rows', 'database', 'delete'))['layer'],
            'explain() must report the first (most specific) layer that disables, matching is_ability_enabled()\'s short-circuit order.'
        );
    }

    public function test_explain_always_agrees_with_is_ability_enabled(): void
    {
        $cases = [
            $this->ability(),
            $this->ability('wpmcp/delete-rows', 'database', 'delete'),
        ];
        Governance::set_domain_toggle('database', false);

        foreach ($cases as $ability) {
            $this->assertSame(
                Governance::is_ability_enabled($ability),
                Governance::explain($ability)['enabled'],
                "explain() disagreed with is_ability_enabled() for {$ability->name}."
            );
        }
    }
}
