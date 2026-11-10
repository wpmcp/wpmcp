<?php

namespace WPMCP\Tests\Free\Connect;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\MCP\Registrar;
use WPMCP\MCP\Tool_Search;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\Tools\Connect\List_Tool_Catalog;
use WPMCP\Tools\Dispatch\List_Tools;

/**
 * Issue #732: list-tool-catalog and list-tools both took a search term and
 * ignored it, so search:"elementor" returned every tool, and integrations
 * that live as ops inside a dispatcher pair (Pods and TablePress on
 * plugin-data-read/-write, Redirection on theme-read/-write) could not be
 * found at all.
 */
class ToolSearchTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_pods_active');
        remove_all_filters('wpmcp_tablepress_active');
        parent::tearDown();
    }

    /** @return array<string, array<string, mixed>> name => catalog entry */
    private static function catalog_entries(array $out): array
    {
        $by_name = [];
        foreach ($out['domains'] as $entries) {
            foreach ($entries as $entry) {
                $by_name[ $entry['name'] ] = $entry;
            }
        }
        return $by_name;
    }

    /** Asserts every listed tool names the term in its name, description or ops. */
    private function assert_every_hit_mentions(string $term, array $names, Registrar $registrar): void
    {
        foreach ($names as $name) {
            $ability = $registrar->get($name);
            $this->assertNotNull($ability, $name);
            $haystack = strtolower($ability->name . ' ' . $ability->description . ' ' . implode(' ', Tool_Search::operation_names($ability)));
            $this->assertStringContainsString($term, $haystack, "{$name} was returned for search \"{$term}\" without mentioning it.");
        }
    }

    private static function plugin_data_registrar(): Registrar
    {
        add_filter('wpmcp_pods_active', '__return_true');
        $registrar = new Registrar();
        foreach ((new Plugin_Data_Integration())->abilities() as $ability) {
            $registrar->register($ability);
        }
        foreach ((new Theme_Integration())->abilities() as $ability) {
            $registrar->register($ability);
        }
        return $registrar;
    }

    public function test_catalog_search_elementor_returns_only_elementor_tools(): void
    {
        $registrar = Plugin::instance()->registrar();
        $all       = (new List_Tool_Catalog())->handle([]);
        $out       = (new List_Tool_Catalog())->handle([ 'search' => 'elementor' ]);
        $entries   = self::catalog_entries($out);

        $this->assertArrayHasKey('wpmcp/regenerate-elementor-css', $entries);
        $this->assertLessThan($all['total'], $out['total']);
        $this->assertSame(count($entries), $out['total']);
        $this->assertNotContains('wpmcp/get-page', array_keys($entries));
        $this->assert_every_hit_mentions('elementor', array_keys($entries), $registrar);
    }

    public function test_catalog_search_is_case_insensitive(): void
    {
        $lower = (new List_Tool_Catalog())->handle([ 'search' => 'elementor' ]);
        $upper = (new List_Tool_Catalog())->handle([ 'search' => 'ELEMENTOR' ]);

        $this->assertSame($lower, $upper);
    }

    public function test_catalog_search_pods_returns_the_plugin_data_pair_with_its_pods_operations(): void
    {
        $registrar = self::plugin_data_registrar();
        $out       = (new List_Tool_Catalog())->handle([ 'search' => 'pods' ], $registrar);
        $entries   = self::catalog_entries($out);

        $this->assertSame([ 'wpmcp/plugin-data-read', 'wpmcp/plugin-data-write' ], array_keys($entries));

        $read_ops = $entries['wpmcp/plugin-data-read']['matched_operations'];
        $this->assertContains('pods-list-fields', $read_ops);
        $this->assertContains('pods-get-fields', $read_ops);
        $this->assertContains('pods-update-fields', $entries['wpmcp/plugin-data-write']['matched_operations']);
        foreach (array_merge($read_ops, $entries['wpmcp/plugin-data-write']['matched_operations']) as $op) {
            $this->assertStringContainsString('pods', $op);
        }
        $this->assertNotContains('pods-update-fields', $read_ops, 'The read half lists only ops it answers.');
    }

    public function test_catalog_search_finds_redirection_inside_the_theme_pair(): void
    {
        $out     = (new List_Tool_Catalog())->handle([ 'search' => 'redirection' ], self::plugin_data_registrar());
        $entries = self::catalog_entries($out);

        $this->assertSame([ 'wpmcp/theme-read', 'wpmcp/theme-write' ], array_keys($entries));
        $this->assertContains('list-redirection-redirects', $entries['wpmcp/theme-read']['matched_operations']);
        $this->assertContains('create-redirection-redirect', $entries['wpmcp/theme-write']['matched_operations']);
    }

    public function test_catalog_search_finds_tablepress_inside_the_plugin_data_pair_on_the_paid_tier(): void
    {
        Gate::set_pro_for_tests(true);
        add_filter('wpmcp_tablepress_active', '__return_true');

        $out     = (new List_Tool_Catalog())->handle([ 'search' => 'tablepress' ], self::plugin_data_registrar());
        $entries = self::catalog_entries($out);

        $this->assertArrayHasKey('wpmcp/plugin-data-read', $entries);
        $this->assertNotEmpty($entries['wpmcp/plugin-data-read']['matched_operations']);
        foreach ($entries['wpmcp/plugin-data-read']['matched_operations'] as $op) {
            $this->assertStringContainsString('tablepress', $op);
        }
    }

    public function test_a_name_or_description_hit_carries_no_matched_operations(): void
    {
        $entries = self::catalog_entries((new List_Tool_Catalog())->handle([ 'search' => 'regenerate-elementor-css' ]));

        $this->assertArrayHasKey('wpmcp/regenerate-elementor-css', $entries);
        $this->assertArrayNotHasKey('matched_operations', $entries['wpmcp/regenerate-elementor-css']);
    }

    public function test_catalog_search_with_no_hit_returns_an_empty_catalog(): void
    {
        $out = (new List_Tool_Catalog())->handle([ 'search' => 'zz-no-such-tool-zz' ]);

        $this->assertSame([], $out['domains']);
        $this->assertSame(0, $out['total']);
    }

    public function test_empty_search_lists_everything(): void
    {
        $this->assertSame(
            (new List_Tool_Catalog())->handle([]),
            (new List_Tool_Catalog())->handle([ 'search' => '  ' ])
        );
    }

    public function test_search_combines_with_the_domain_filter(): void
    {
        $out = (new List_Tool_Catalog())->handle([ 'search' => 'elementor', 'domain' => 'core' ]);

        $this->assertSame([], array_diff(array_keys($out['domains']), [ 'core' ]));
    }

    public function test_list_tools_search_elementor_returns_only_elementor_tools(): void
    {
        $registrar = Plugin::instance()->registrar();
        $all       = (new List_Tools())->handle([]);
        $out       = (new List_Tools())->handle([ 'search' => 'elementor' ]);
        $names     = array_column($out['tools'], 'name');

        $this->assertContains('wpmcp/regenerate-elementor-css', $names);
        $this->assertLessThan($all['total'], $out['total']);
        $this->assertSame(count($names), $out['total']);
        $this->assert_every_hit_mentions('elementor', $names, $registrar);
    }

    public function test_list_tools_search_pods_returns_the_plugin_data_pair_with_its_pods_operations(): void
    {
        $out   = (new List_Tools())->handle([ 'search' => 'pods' ], self::plugin_data_registrar());
        $tools = array_combine(array_column($out['tools'], 'name'), $out['tools']);

        $this->assertSame([ 'wpmcp/plugin-data-read', 'wpmcp/plugin-data-write' ], array_keys($tools));
        $this->assertContains('pods-list-fields', $tools['wpmcp/plugin-data-read']['matched_operations']);
        $this->assertContains('pods-get-fields', $tools['wpmcp/plugin-data-read']['matched_operations']);
    }

    public function test_both_tools_advertise_search_in_their_schema(): void
    {
        $registrar = Plugin::instance()->registrar();
        foreach ([ 'wpmcp/list-tool-catalog', 'wpmcp/list-tools' ] as $name) {
            $this->assertSame([ 'type' => 'string' ], $registrar->get($name)->input_schema['properties']['search'] ?? null, $name);
        }
    }
}
