<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\MCP\Wire_Schema;

/**
 * Wire_Schema on its own (issue #586): an empty properties map becomes a
 * JSON object, and data keywords are left alone. Asserted on the encoded
 * JSON, since that is what a client sees.
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
}
