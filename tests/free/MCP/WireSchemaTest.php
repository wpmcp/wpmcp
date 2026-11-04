<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\MCP\Wire_Schema;

/**
 * Wire_Schema on its own (issues #586, #591): every empty array in a
 * schema position becomes a JSON object, at any depth, and nothing else
 * changes. Asserted on the encoded JSON, since that is what a client sees.
 */
class WireSchemaTest extends \WP_UnitTestCase
{
    private function wire($schema): string
    {
        return (string) wp_json_encode(Wire_Schema::input($schema));
    }

    public function test_empty_properties_become_an_object(): void
    {
        $this->assertSame('{"type":"object","properties":{}}', $this->wire([ 'type' => 'object', 'properties' => [] ]));
    }

    public function test_an_empty_or_missing_input_schema_becomes_an_object_schema(): void
    {
        $this->assertSame('{"type":"object"}', $this->wire([]));
        $this->assertSame('{"type":"object"}', $this->wire(null));
        $this->assertSame('{"type":"object"}', $this->wire(new \stdClass()));
    }

    public function test_an_empty_property_schema_becomes_an_object(): void
    {
        $this->assertSame(
            '{"type":"object","properties":{"key":{"type":"string"},"value":{}}}',
            $this->wire([ 'type' => 'object', 'properties' => [ 'key' => [ 'type' => 'string' ], 'value' => [] ] ])
        );
    }

    public function test_nested_schemas_are_converted_at_any_depth(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'rows' => [
                    'type'  => 'array',
                    'items' => [
                        'type'                 => 'object',
                        'properties'           => [ 'cells' => [ 'type' => 'array', 'items' => [] ], 'meta' => [ 'type' => 'object', 'properties' => [] ] ],
                        'additionalProperties' => [],
                    ],
                ],
                'either' => [ 'anyOf' => [ [], [ 'type' => 'string' ] ] ],
                'map'    => [ 'type' => 'object', 'patternProperties' => [ '^x-' => [] ] ],
            ],
            '$defs' => [],
        ];

        $this->assertSame(
            '{"type":"object","properties":{"rows":{"type":"array","items":{"type":"object","properties":{"cells":{"type":"array","items":{}},"meta":{"type":"object","properties":{}}},"additionalProperties":{}}},'
            . '"either":{"anyOf":[{},{"type":"string"}]},"map":{"type":"object","patternProperties":{"^x-":{}}}},"$defs":{}}',
            $this->wire($schema)
        );
    }

    public function test_data_keywords_and_booleans_are_left_as_written(): void
    {
        $schema = [
            'type'                 => 'object',
            'properties'           => [
                'ids'  => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'default' => [] ],
                'mode' => [ 'type' => 'string', 'enum' => [ 'a', 'b' ], 'examples' => [] ],
            ],
            'required'             => [],
            'additionalProperties' => false,
        ];

        $this->assertSame(
            '{"type":"object","properties":{"ids":{"type":"array","items":{"type":"integer"},"default":[]},"mode":{"type":"string","enum":["a","b"],"examples":[]}},"required":[],"additionalProperties":false}',
            $this->wire($schema)
        );
    }

    public function test_object_shaped_schemas_are_read_like_arrays(): void
    {
        $schema = (object) [ 'type' => 'object', 'properties' => (object) [ 'value' => new \stdClass() ] ];

        $this->assertSame('{"type":"object","properties":{"value":{}}}', $this->wire($schema));
    }

    public function test_a_tuple_items_list_keeps_its_order(): void
    {
        $this->assertSame(
            '{"type":"object","properties":{"pair":{"type":"array","items":[{"type":"string"},{}]}}}',
            $this->wire([ 'type' => 'object', 'properties' => [ 'pair' => [ 'type' => 'array', 'items' => [ [ 'type' => 'string' ], [] ] ] ] ])
        );
    }
}
