<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Opt_In_Gates;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Output_Schemas;
use WPMCP\MCP\Stdio_Transport;
use WPMCP\MCP\Tool_Annotations;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\Plugin;

/**
 * Issue #630: the tool annotations every Ability computes reach the client
 * on tools/list, and a tool with a site-level requirement says so in a
 * short description suffix and in _meta["com.wpmcp-pro/requires"].
 *
 * The snapshot test runs the real registry of this fixture site through the
 * wire filter, so every tool is checked, not a hand-picked few.
 */
class ToolAnnotationsWireTest extends \WP_UnitTestCase
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
        parent::tearDown();
    }

    /** @return array<int, array<string,mixed>> The fixture site's tools as the adapter hands them over. */
    private static function adapter_list(): array
    {
        $tools = [];
        foreach (Plugin::instance()->registrar()->all() as $ability) {
            $tools[] = [
                'name'        => Tool_Exposure::tool_name($ability->name),
                'description' => $ability->description,
                'inputSchema' => [ 'type' => 'object' ],
            ];
        }
        return $tools;
    }

    public function test_every_wpmcp_tool_carries_its_annotations_on_the_wire(): void
    {
        $abilities = Plugin::instance()->registrar()->all();
        $wire      = (new Output_Schemas())->filter_tools_list(self::adapter_list());

        $this->assertCount(count($abilities), $wire);
        $by_name = [];
        foreach ($wire as $tool) {
            $by_name[ $tool['name'] ] = $tool;
        }

        foreach ($abilities as $ability) {
            $tool = $by_name[ Tool_Exposure::tool_name($ability->name) ];
            $this->assertSame(
                [
                    'readOnlyHint'    => $ability->read_only_hint,
                    'destructiveHint' => $ability->destructive_hint,
                    'idempotentHint'  => $ability->idempotent_hint,
                ],
                $tool['annotations'],
                $ability->name
            );
            $this->assertArrayNotHasKey('title', $tool['annotations'], 'a title would repeat the description');
        }
    }

    public function test_a_gated_tool_names_its_opt_in_in_meta_and_once_in_the_description(): void
    {
        $checked = 0;
        foreach (Plugin::instance()->registrar()->all() as $ability) {
            $entry   = [ 'name' => Tool_Exposure::tool_name($ability->name), 'description' => $ability->description, 'inputSchema' => [ 'type' => 'object' ] ];
            $filter  = Opt_In_Gates::filter_for($ability->name);
            $tool    = (new Output_Schemas())->filter_tools_list([ $entry ])[0];

            if (null === $filter) {
                $this->assertArrayNotHasKey('_meta', $tool, $ability->name . ' has no requirement to declare');
                $this->assertSame($ability->description, $tool['description'], $ability->name);
                continue;
            }

            ++$checked;
            $this->assertSame([ 'opt_in' => $filter ], $tool['_meta'][ Tool_Annotations::META_KEY ], $ability->name);
            $this->assertSame(1, substr_count($tool['description'], $filter), $ability->name . ' names its opt-in exactly once');
            $suffix = substr($tool['description'], strlen($ability->description));
            $this->assertLessThanOrEqual(Tool_Annotations::SUFFIX_MAX + 2, strlen($suffix), $ability->name . ' (suffix plus its ". " joint)');
        }
        $this->assertGreaterThan(0, $checked, 'the fixture site registers gated tools');
    }

    public function test_the_suffix_is_built_from_requires_and_stays_short(): void
    {
        $this->assertSame('', Tool_Annotations::suffix([]));
        $this->assertSame(
            'Needs: WooCommerce active. Off until the site enables wpmcp_enable_db_writes.',
            Tool_Annotations::suffix([ 'plugin' => [ 'woocommerce' => 'WooCommerce' ], 'opt_in' => 'wpmcp_enable_db_writes' ])
        );
        $long = Tool_Annotations::suffix([ 'plugin' => array_fill_keys(range('a', 'z'), 'A Rather Long Plugin Name') ]);
        $this->assertLessThanOrEqual(Tool_Annotations::SUFFIX_MAX, strlen($long));
    }

    public function test_a_pro_tool_carries_no_tier_note(): void
    {
        // Listed only where Pro is active, so the tier can never be why a
        // listed tool fails; naming it on every Pro tool would only cost bytes.
        $ability = new Ability('wpmcp/fixture-pro', 'pro', 'Read a thing', [ 'type' => 'object' ], '__return_null');
        $tool    = Tool_Annotations::apply([ 'name' => 'wpmcp-fixture-pro', 'description' => 'Read a thing' ], $ability);

        $this->assertArrayNotHasKey('_meta', $tool);
        $this->assertSame('Read a thing', $tool['description']);
    }

    public function test_what_the_adapter_already_set_is_kept(): void
    {
        $ability = new Ability('wpmcp/fixture', 'free', 'Read a thing', [ 'type' => 'object' ], '__return_null');
        $tool    = Tool_Annotations::apply([
            'name'        => 'wpmcp-fixture',
            'annotations' => [ 'readOnlyHint' => false, 'title' => 'theirs' ],
            '_meta'       => [ 'other/key' => 1 ],
        ], $ability);

        $this->assertSame([ 'readOnlyHint' => false, 'title' => 'theirs' ], $tool['annotations']);
        $this->assertSame([ 'other/key' => 1 ], $tool['_meta']);
    }

    public function test_a_dto_listing_gets_annotations_even_when_it_has_an_output_schema(): void
    {
        if (! class_exists(\WP\McpSchema\Server\Tools\DTO\Tool::class)) {
            $this->markTestSkipped('the bundled adapter DTOs are not loaded');
        }
        $dto = \WP\McpSchema\Server\Tools\DTO\Tool::fromArray([
            'name'         => 'wpmcp-get-page',
            'description'  => 'Read one page',
            'inputSchema'  => [ 'type' => 'object' ],
            'outputSchema' => [ 'type' => 'object' ],
        ]);

        $out = (new Output_Schemas())->filter_tools_list([ $dto ])[0]->toArray();

        $this->assertSame([ 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true ], $out['annotations']);
    }

    public function test_stdio_tools_list_carries_annotations(): void
    {
        $id = self::factory()->user->create([ 'role' => 'administrator' ]);
        wp_set_current_user($id);

        $response = (new Stdio_Transport())->handle_request([ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [] ]);
        $tools    = $response['result']['tools'];

        $this->assertNotEmpty($tools);
        foreach ($tools as $tool) {
            $this->assertArrayHasKey('annotations', $tool, $tool['name']);
            $this->assertIsBool($tool['annotations']['readOnlyHint'], $tool['name']);
        }
    }
}
