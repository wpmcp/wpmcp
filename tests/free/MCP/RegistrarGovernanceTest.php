<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\MCP\Ability;
use WPMCP\MCP\Registrar;

class RegistrarGovernanceTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_ability_enabled');
        remove_all_filters('wpmcp_domain_enabled');
        remove_all_filters('wpmcp_operation_enabled');
        parent::tearDown();
    }

    private function ability(string $name, string $domain = 'content', string $operation = 'read'): Ability
    {
        return new Ability($name, 'free', 'desc', [], fn() => [], 'edit_posts', $domain, $operation);
    }

    public function test_ability_disabled_by_name_filter_is_absent_from_all(): void
    {
        add_filter('wpmcp_ability_enabled', function (bool $enabled, string $name) {
            return 'wpmcp/delete-post' === $name ? false : $enabled;
        }, 10, 2);

        $r = new Registrar();
        $r->register($this->ability('wpmcp/delete-post', 'content', 'delete'));
        $r->register($this->ability('wpmcp/get-post', 'content', 'read'));

        $names = array_map(fn(Ability $a) => $a->name, $r->all());
        $this->assertNotContains('wpmcp/delete-post', $names);
        $this->assertContains('wpmcp/get-post', $names);
    }

    public function test_ability_disabled_by_domain_filter_removes_all_abilities_in_that_domain(): void
    {
        add_filter('wpmcp_domain_enabled', function (bool $enabled, string $domain) {
            return 'database' === $domain ? false : $enabled;
        }, 10, 2);

        $r = new Registrar();
        $r->register($this->ability('wpmcp/query', 'database', 'read'));
        $r->register($this->ability('wpmcp/delete-rows', 'database', 'delete'));
        $r->register($this->ability('wpmcp/get-post', 'content', 'read'));

        $names = array_map(fn(Ability $a) => $a->name, $r->all());
        $this->assertNotContains('wpmcp/query', $names);
        $this->assertNotContains('wpmcp/delete-rows', $names);
        $this->assertContains('wpmcp/get-post', $names);
    }

    public function test_ability_disabled_by_operation_filter_removes_deletes_across_domains(): void
    {
        add_filter('wpmcp_operation_enabled', function (bool $enabled, string $operation) {
            return 'delete' === $operation ? false : $enabled;
        }, 10, 2);

        $r = new Registrar();
        $r->register($this->ability('wpmcp/delete-post', 'content', 'delete'));
        $r->register($this->ability('wpmcp/delete-rows', 'database', 'delete'));
        $r->register($this->ability('wpmcp/get-post', 'content', 'read'));

        $names = array_map(fn(Ability $a) => $a->name, $r->all());
        $this->assertNotContains('wpmcp/delete-post', $names);
        $this->assertNotContains('wpmcp/delete-rows', $names);
        $this->assertContains('wpmcp/get-post', $names);
    }

    public function test_reads_stay_enabled_by_default(): void
    {
        $r = new Registrar();
        $r->register($this->ability('wpmcp/get-post', 'content', 'read'));

        $names = array_map(fn(Ability $a) => $a->name, $r->all());
        $this->assertContains('wpmcp/get-post', $names);
    }
}
