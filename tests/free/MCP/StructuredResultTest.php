<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\MCP\Structured_Result;

/**
 * structuredContent must serialize as a JSON object (issue #133): the MCP
 * schema types it as `{ [key: string]: unknown }`, and the adapter assigns
 * a tool's return value to it verbatim, so a tool answering with a
 * top-level list produces a response strict clients reject outright.
 *
 * Normalization happens at the wire boundary, on the adapter's
 * mcp_adapter_tool_call_result filter, so tool contracts are unchanged for
 * internal callers. These tests pin both the transform and that boundary.
 */
class StructuredResultTest extends \WP_UnitTestCase
{
    private function encoded($value): string
    {
        return (string) wp_json_encode(Structured_Result::normalize($value));
    }

    public function test_a_list_result_is_wrapped_so_it_serializes_as_an_object(): void
    {
        $normalized = Structured_Result::normalize([['id' => 1], ['id' => 2]]);

        $this->assertSame([['id' => 1], ['id' => 2]], $normalized['data']);
        $this->assertStringStartsWith('{', $this->encoded([['id' => 1], ['id' => 2]]));
    }

    public function test_an_object_shaped_array_passes_through_untouched(): void
    {
        $result = ['id' => 7, 'title' => 'Hello'];

        $this->assertSame($result, Structured_Result::normalize($result));
    }

    public function test_an_empty_array_is_wrapped_because_php_cannot_tell_it_from_a_list(): void
    {
        // Unwrapped, [] serializes to `[]` - exactly the invalid shape this
        // guards against - so it must be wrapped even though it is
        // ambiguous.
        $this->assertSame(['data' => []], Structured_Result::normalize([]));
        $this->assertStringStartsWith('{', $this->encoded([]));
    }

    public function test_scalars_and_null_are_wrapped(): void
    {
        $this->assertSame(['data' => 'ok'], Structured_Result::normalize('ok'));
        $this->assertSame(['data' => 42], Structured_Result::normalize(42));
        $this->assertSame(['data' => true], Structured_Result::normalize(true));
        $this->assertSame(['data' => null], Structured_Result::normalize(null));
    }

    public function test_a_wp_error_is_never_wrapped_so_error_handling_still_sees_it(): void
    {
        $error = new \WP_Error('nope', 'Not allowed');

        $this->assertSame($error, Structured_Result::normalize($error));
    }

    public function test_objects_pass_through_untouched(): void
    {
        $object = new \stdClass();
        $object->id = 3;

        $this->assertSame($object, Structured_Result::normalize($object));
    }

    public function test_normalization_is_idempotent(): void
    {
        foreach ([[], [1, 2, 3], 'x', null, ['a' => 1]] as $value) {
            $once  = Structured_Result::normalize($value);
            $twice = Structured_Result::normalize($once);

            $this->assertSame($once, $twice);
        }
    }

    public function test_the_filter_callback_normalizes_the_adapter_result(): void
    {
        $subject = new Structured_Result();
        $subject->register();

        $this->assertSame(
            ['data' => ['a', 'b']],
            apply_filters('mcp_adapter_tool_call_result', ['a', 'b'])
        );
        $this->assertSame(
            ['ok' => true],
            apply_filters('mcp_adapter_tool_call_result', ['ok' => true])
        );

        remove_filter('mcp_adapter_tool_call_result', [$subject, 'filter_result'], 99);
    }
}
