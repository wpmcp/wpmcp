<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\MCP\Tool_Exposure;
use WPMCP\Plugin;
use WPMCP\Tools\Dispatch\Get_Tool_Schema;
use WPMCP\Tools\Dispatch\List_Tools;

/**
 * The meta-tools that make the compact surface navigable (issue #79):
 * list-tools enumerates the registered surface with curated summaries,
 * get-tool-schema returns the full schema of one tool exactly as registered.
 */
class MetaToolsTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    private function registrar_ability(string $name): \WPMCP\MCP\Ability
    {
        $ability = Plugin::instance()->registrar()->get($name);
        $this->assertNotNull($ability, "Expected {$name} to be registered.");
        return $ability;
    }

    public function test_the_three_meta_abilities_are_registered_in_both_registries(): void
    {
        foreach (Tool_Exposure::META_ABILITIES as $name) {
            $this->assertNotNull(Plugin::instance()->registrar()->get($name), "{$name} missing from Registrar.");
            $this->assertArrayHasKey($name, wp_get_abilities(), "{$name} missing from the live Abilities API registry.");
        }
    }

    public function test_meta_abilities_are_free_tier_in_the_dispatch_domain(): void
    {
        foreach (Tool_Exposure::META_ABILITIES as $name) {
            $ability = $this->registrar_ability($name);
            $this->assertSame('free', $ability->tier, $name);
            $this->assertSame('dispatch', $ability->domain, $name);
        }
    }

    public function test_call_tool_is_honestly_annotated_as_a_potentially_destructive_dispatcher(): void
    {
        $call_tool = $this->registrar_ability('wpmcp/call-tool');

        $this->assertFalse($call_tool->read_only_hint, 'call-tool can dispatch writes, so it must not claim read-only.');
        $this->assertTrue($call_tool->destructive_hint, 'call-tool can dispatch deletes, so it must warn destructive.');
        $this->assertFalse($call_tool->idempotent_hint);
    }

    public function test_list_and_schema_meta_tools_are_read_only(): void
    {
        foreach (['wpmcp/list-tools', 'wpmcp/get-tool-schema'] as $name) {
            $ability = $this->registrar_ability($name);
            $this->assertSame('read', $ability->operation, $name);
            $this->assertTrue($ability->read_only_hint, $name);
        }
    }

    public function test_registrar_get_returns_null_for_unknown_names(): void
    {
        $this->assertNull(Plugin::instance()->registrar()->get('wpmcp/never-registered'));
    }

    public function test_list_tools_enumerates_every_registered_ability_with_curated_summaries(): void
    {
        $out = (new List_Tools())->handle([]);

        $registrar_names = array_map(
            fn($a) => $a->name,
            Plugin::instance()->registrar()->all()
        );
        sort($registrar_names);

        $this->assertSame(count($registrar_names), $out['total']);
        $listed = array_column($out['tools'], 'name');
        $this->assertSame($registrar_names, $listed, 'list-tools must enumerate exactly the registered surface, sorted.');

        foreach ($out['tools'] as $entry) {
            $this->assertArrayHasKey('summary', $entry);
            $this->assertArrayHasKey('domain', $entry);
            $this->assertArrayHasKey('operation', $entry);
            $this->assertArrayHasKey('tier', $entry);
            $this->assertArrayNotHasKey('description', $entry, 'Curated by default: full descriptions only behind full:true.');
            $this->assertLessThanOrEqual(
                List_Tools::SUMMARY_LENGTH,
                mb_strlen($entry['summary']),
                "Summary for {$entry['name']} exceeds the curated length."
            );
        }
    }

    public function test_list_tools_full_flag_returns_complete_descriptions_and_annotations(): void
    {
        $out = (new List_Tools())->handle(['full' => true]);

        $by_name = array_column($out['tools'], null, 'name');
        $ability = $this->registrar_ability('wpmcp/get-site-context');

        $this->assertSame($ability->description, $by_name['wpmcp/get-site-context']['description']);
        $this->assertSame(
            [
                'readOnlyHint'    => true,
                'destructiveHint' => false,
                'idempotentHint'  => true,
            ],
            $by_name['wpmcp/get-site-context']['annotations']
        );
    }

    public function test_list_tools_can_filter_by_domain(): void
    {
        $out = (new List_Tools())->handle(['domain' => 'dispatch']);

        $names = array_column($out['tools'], 'name');
        sort($names);
        $expected = Tool_Exposure::META_ABILITIES;
        sort($expected);

        $this->assertSame($expected, $names);
        $this->assertSame(count($expected), $out['total']);
    }

    public function test_get_tool_schema_returns_the_same_schema_as_direct_registration(): void
    {
        $out = (new Get_Tool_Schema())->handle(['name' => 'wpmcp/get-page']);

        $registrar_ability = $this->registrar_ability('wpmcp/get-page');
        $live_ability      = wp_get_ability('wpmcp/get-page');

        $this->assertSame('wpmcp/get-page', $out['name']);
        $this->assertSame($registrar_ability->input_schema, $out['input_schema']);
        $this->assertSame($live_ability->get_input_schema(), $out['input_schema']);
        $this->assertSame($registrar_ability->description, $out['description']);
        $this->assertSame(
            [
                'readOnlyHint'    => $registrar_ability->read_only_hint,
                'destructiveHint' => $registrar_ability->destructive_hint,
                'idempotentHint'  => $registrar_ability->idempotent_hint,
            ],
            $out['annotations']
        );
    }

    public function test_get_tool_schema_errors_on_unknown_or_missing_names(): void
    {
        $unknown = (new Get_Tool_Schema())->handle(['name' => 'wpmcp/never-registered']);
        $this->assertInstanceOf(\WP_Error::class, $unknown);

        $missing = (new Get_Tool_Schema())->handle([]);
        $this->assertInstanceOf(\WP_Error::class, $missing);
    }
}
