<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\MCP\Tool_Exposure;
use WPMCP\Plugin;

/**
 * The mcp_adapter_tools_list integration for compact mode (issue #79).
 *
 * Compact mode is EXPOSURE-ONLY: the filter hides long-tail wpmcp tools from
 * the advertised tools/list, but never unregisters anything and never touches
 * tools that do not belong to this plugin's registered ability surface.
 * Hiding is not a permission boundary — a hidden tool called directly still
 * runs the full permission chain — it exists purely to cut tools/list token
 * cost for constrained clients.
 */
class ToolExposureToolsListFilterTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function tearDown(): void
    {
        delete_option(Tool_Exposure::OPTION);
        remove_all_filters('wpmcp_tool_exposure_mode');
        remove_all_filters('wpmcp_compact_exposed_abilities');
        parent::tearDown();
    }

    private function compact(): void
    {
        update_option(Tool_Exposure::OPTION, Tool_Exposure::MODE_COMPACT);
    }

    /** Tool-DTO stub with a getName() accessor, like the adapter's Tool DTO. */
    private function dto(string $name): object
    {
        return new class ($name) {
            public function __construct(private string $tool_name)
            {
            }
            public function getName(): string
            {
                return $this->tool_name;
            }
        };
    }

    public function test_plugin_boot_hooks_the_adapter_tools_list_filter(): void
    {
        $this->assertNotFalse(
            has_filter('mcp_adapter_tools_list'),
            'Plugin::boot() must hook mcp_adapter_tools_list so compact mode can shape tools/list.'
        );
    }

    public function test_full_mode_returns_the_tools_unchanged(): void
    {
        $tools = [$this->dto('wpmcp-get-page'), $this->dto('other-plugin-tool')];

        $this->assertSame($tools, (new Tool_Exposure())->filter_tools_list($tools));
    }

    public function test_compact_mode_hides_long_tail_wpmcp_tools_but_keeps_the_core_set(): void
    {
        $this->compact();

        $tools = [
            $this->dto('wpmcp-get-page'),
            $this->dto('wpmcp-call-tool'),
            $this->dto('wpmcp-list-tools'),
            $this->dto('wpmcp-get-tool-schema'),
            $this->dto('wpmcp-get-connection-info'),
            $this->dto('wpmcp-get-site-context'),
            $this->dto('wpmcp-delete-post'),
        ];

        $names = array_map(
            fn($t) => $t->getName(),
            (new Tool_Exposure())->filter_tools_list($tools)
        );

        $this->assertSame(
            [
                'wpmcp-call-tool',
                'wpmcp-list-tools',
                'wpmcp-get-tool-schema',
                'wpmcp-get-connection-info',
                'wpmcp-get-site-context',
            ],
            $names
        );
    }

    public function test_compact_mode_never_touches_tools_that_are_not_wpmcp_abilities(): void
    {
        $this->compact();

        $tools = [
            $this->dto('other-plugin-tool'),
            $this->dto('wpmcp-get-page'),
            ['name' => 'array-shaped-foreign-tool'],
            'unreadable-entry',
        ];

        $filtered = (new Tool_Exposure())->filter_tools_list($tools);

        $this->assertCount(3, $filtered);
        $this->assertSame('other-plugin-tool', $filtered[0]->getName());
        $this->assertSame('array-shaped-foreign-tool', $filtered[1]['name']);
        $this->assertSame('unreadable-entry', $filtered[2], 'Entries whose name cannot be read must pass through.');
    }

    public function test_compact_mode_reads_names_from_public_properties_and_arrays_too(): void
    {
        $this->compact();

        $prop_tool       = new \stdClass();
        $prop_tool->name = 'wpmcp-get-page';

        $filtered = (new Tool_Exposure())->filter_tools_list([
            $prop_tool,
            ['name' => 'wpmcp-get-connection-info'],
        ]);

        $this->assertCount(1, $filtered);
        $this->assertSame('wpmcp-get-connection-info', $filtered[0]['name']);
    }

    public function test_compact_surface_is_exactly_the_meta_tools_plus_connection_basics(): void
    {
        $this->compact();

        // Build a stub tool for EVERY registered wpmcp ability (name sanitized
        // the way the adapter does: '/' becomes '-').
        $tools = [];
        foreach (Plugin::instance()->registrar()->all() as $ability) {
            $tools[] = $this->dto(str_replace('/', '-', $ability->name));
        }

        $names = array_map(
            fn($t) => $t->getName(),
            (new Tool_Exposure())->filter_tools_list($tools)
        );
        sort($names);

        $expected = array_map(
            static fn(string $n): string => str_replace('/', '-', $n),
            Tool_Exposure::COMPACT_CORE
        );
        sort($expected);

        $this->assertSame($expected, $names);
    }

    public function test_compact_core_set_is_the_three_meta_tools_plus_connection_basics(): void
    {
        $core = Tool_Exposure::COMPACT_CORE;
        sort($core);

        $expected = [
            'wpmcp/call-tool',
            'wpmcp/get-connection-info',
            'wpmcp/get-site-context',
            'wpmcp/get-tool-schema',
            'wpmcp/list-tools',
        ];

        $this->assertSame($expected, $core);
    }

    public function test_curation_filter_can_add_abilities_but_never_remove_the_meta_tools(): void
    {
        $this->compact();

        add_filter('wpmcp_compact_exposed_abilities', function (array $names) {
            $names[] = 'wpmcp/list-operations';
            return array_diff($names, ['wpmcp/call-tool']);
        });

        $tools = [
            $this->dto('wpmcp-call-tool'),
            $this->dto('wpmcp-list-operations'),
            $this->dto('wpmcp-delete-post'),
        ];

        $names = array_map(
            fn($t) => $t->getName(),
            (new Tool_Exposure())->filter_tools_list($tools)
        );

        $this->assertContains('wpmcp-list-operations', $names, 'The curation filter must be able to widen exposure.');
        $this->assertContains('wpmcp-call-tool', $names, 'The meta-tools must always stay exposed in compact mode.');
        $this->assertNotContains('wpmcp-delete-post', $names);
    }

    public function test_non_array_input_passes_through_untouched(): void
    {
        $this->compact();

        $this->assertSame('whatever', (new Tool_Exposure())->filter_tools_list('whatever'));
    }
}
