<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\MCP\Modern_Http_Bridge;
use WPMCP\MCP\Output_Schemas;

/**
 * Issue #501: the seams where the released MCP Adapter plugin (0.7.0)
 * differs from the 0.6.x copy WP MCP bundles, pinned here so the default
 * suite guards them too, not only the adapter leg that loads the plugin.
 *
 *  - 0.7.0 speaks 2026-07-28 itself, so Modern_Http_Bridge leaves most of
 *    that revision to it. Its tools/call knows nothing of the confirm gates
 *    answered as elicitation or of the Tasks extension, and rejects
 *    continuation parameters for ability-backed tools, so a 2026-07-28
 *    tools/call on the WP MCP route must still be answered by the bridge.
 *  - 0.7.0 hands mcp_adapter_tools_list immutable schema records plus the
 *    selected schema, not 0.6.x DTOs with toArray()/fromArray(); the
 *    outputSchema must still reach the listing.
 */
class ReleasedAdapterSeamsTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    public function test_a_modern_tool_call_is_answered_by_the_bridge_on_every_adapter(): void
    {
        foreach ([ false, true ] as $adapter_speaks_modern) {
            $this->assertTrue(Modern_Http_Bridge::answers_here('tools/call', true, '2026-07-28', $adapter_speaks_modern), 'tools/call, adapter modern: ' . var_export($adapter_speaks_modern, true));
        }
    }

    public function test_the_rest_of_the_routing_is_unchanged(): void
    {
        // server/discover and tasks/* are always ours.
        $this->assertTrue(Modern_Http_Bridge::answers_here('server/discover', false, null, true));
        $this->assertTrue(Modern_Http_Bridge::answers_here('tasks/get', true, '2026-07-28', true));
        // Other 2026-07-28 methods go to an adapter that speaks the revision.
        $this->assertFalse(Modern_Http_Bridge::answers_here('tools/list', true, '2026-07-28', true));
        $this->assertTrue(Modern_Http_Bridge::answers_here('tools/list', true, '2026-07-28', false));
        // 2025-11-25 always belongs to the adapter.
        $this->assertFalse(Modern_Http_Bridge::answers_here('tools/call', false, '2025-11-25', true));
        $this->assertFalse(Modern_Http_Bridge::answers_here('tools/call', false, '2025-11-25', false));
        // A revision nobody serves is answered here only when the adapter cannot say so itself.
        $this->assertTrue(Modern_Http_Bridge::answers_here('tools/list', false, '1999-01-01', false));
        $this->assertFalse(Modern_Http_Bridge::answers_here('tools/list', false, '1999-01-01', true));
    }

    public function test_the_output_schema_reaches_a_schema_record_listing(): void
    {
        $record = Released_Adapter_Tool_Record::from([ 'name' => 'wpmcp-get-page', 'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ] ]);
        $other  = Released_Adapter_Tool_Record::from([ 'name' => 'someone-elses-tool', 'inputSchema' => [ 'type' => 'object' ] ]);

        $tools = (new Output_Schemas())->filter_tools_list([ $record, $other ], null, new Released_Adapter_Schema());

        $this->assertInstanceOf(Released_Adapter_Tool_Record::class, $tools[0], 'the record must stay a record for the adapter to project');
        $this->assertNotSame($record, $tools[0]);
        $this->assertEquals((object) Output_Schemas::for_ability('wpmcp/get-page'), $tools[0]->getOutputSchema());
        $this->assertEquals(new \stdClass(), $tools[0]->jsonSerialize()->inputSchema->properties, 'an empty properties map must stay an object');
        $this->assertSame($other, $tools[1], 'another server\'s tool passes through untouched');
    }

    public function test_a_record_that_already_declares_a_schema_or_a_schema_that_fails_is_kept(): void
    {
        $declared = Released_Adapter_Tool_Record::from([ 'name' => 'wpmcp-get-page', 'outputSchema' => [ 'type' => 'object', 'title' => 'theirs' ] ]);
        $plain    = Released_Adapter_Tool_Record::from([ 'name' => 'wpmcp-get-page' ]);

        $this->assertSame([ $declared ], (new Output_Schemas())->filter_tools_list([ $declared ], null, new Released_Adapter_Schema()));
        $this->assertSame([ $plain ], (new Output_Schemas())->filter_tools_list([ $plain ], null, new Released_Adapter_Schema(true)));
        $this->assertSame([ $plain ], (new Output_Schemas())->filter_tools_list([ $plain ], null, null), 'without the schema a record cannot be rebuilt');
    }
}

/**
 * Stands in for the released adapter's WP\McpSchema\Record\Tool: immutable,
 * serialized through jsonSerialize() as a stdClass, read through getters.
 */
final class Released_Adapter_Tool_Record implements \JsonSerializable
{
    private \stdClass $values;

    private function __construct(\stdClass $values)
    {
        $this->values = $values;
    }

    /** @param array<string,mixed> $values */
    public static function from(array $values): self
    {
        return new self(json_decode((string) wp_json_encode($values)));
    }

    public static function from_object(\stdClass $values): self
    {
        return new self($values);
    }

    public function getName(): string
    {
        return $this->values->name;
    }

    public function getOutputSchema(): ?\stdClass
    {
        return $this->values->outputSchema ?? null;
    }

    public function jsonSerialize(): \stdClass
    {
        return json_decode((string) wp_json_encode($this->values));
    }
}

/** Stands in for the released adapter's WP\McpSchema\Schema, which builds records from JSON. */
final class Released_Adapter_Schema
{
    public function __construct(private bool $fail = false)
    {
    }

    public function fromJson(string $root_class, string $json): object
    {
        if ($this->fail) {
            throw new \InvalidArgumentException('invalid record');
        }

        return $root_class::from_object(json_decode($json));
    }
}
