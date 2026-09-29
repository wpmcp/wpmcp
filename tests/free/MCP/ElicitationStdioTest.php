<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Governance;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\MCP\Stdio_Transport;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\RateLimit\Rate_Limiter;

/**
 * Confirm gates as elicitation (issue #387), over stdio.
 *
 * A client that advertises form elicitation is asked for the confirmation
 * a confirm gate would otherwise refuse without; every other client keeps
 * the confirm:true argument exactly as before.
 *
 *  - 2026-07-28 is stateless, so the ask is a multi round-trip result: the
 *    tools/call answers resultType "input_required" with an
 *    elicitation/create input request and an opaque requestState, and the
 *    client retries the same call with inputResponses and that state.
 *  - 2025-11-25 over stdio is a live session, so the server sends
 *    elicitation/create to the client mid-call and waits for its answer.
 */
class ElicitationStdioTest extends \WP_UnitTestCase
{
    private Stdio_Transport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new Stdio_Transport();
        Governance::reset_for_tests();
        Identity_Context::set_current_for_tests(null);
        delete_option(Identity_Store::OPTION);
        delete_option(Tool_Exposure::OPTION);
        Rate_Limiter::set_clock_override(fn() => 1_790_000_387);
        add_filter('wpmcp_rate_limit', fn() => 100000);
        add_filter('wpmcp_enable_delete_comment', '__return_true');
        add_filter('wpmcp_enable_delete_post', '__return_true');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_rate_limit');
        remove_all_filters('wpmcp_enable_delete_comment');
        remove_all_filters('wpmcp_enable_delete_post');
        Rate_Limiter::set_clock_override(null);
        Identity_Context::set_current_for_tests(null);
        Governance::reset_for_tests();
        delete_option(Identity_Store::OPTION);
        delete_option(Tool_Exposure::OPTION);
        parent::tearDown();
    }

    /**
     * @param array<string,mixed>|object $capabilities The client's 2026 capabilities.
     * @param array<string,mixed>        $params
     * @return array<string,mixed> The decoded response, as a client reads it.
     */
    private function modern(string $method, array $params, $capabilities): array
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => $capabilities,
        ];

        $response = $this->transport->handle_request([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => $method,
            'params'  => $params,
        ]);
        $this->assertIsArray($response);

        return json_decode((string) wp_json_encode($response), true);
    }

    private function comment(): int
    {
        $post = self::factory()->post->create();
        return (int) self::factory()->comment->create(['comment_post_ID' => $post]);
    }

    private static function form_client(): array
    {
        return [ 'elicitation' => [ 'form' => new \stdClass() ] ];
    }

    /** The single elicitation input request an input_required result carries. */
    private function the_input_request(array $result): array
    {
        $this->assertSame('input_required', $result['resultType'] ?? null, (string) wp_json_encode($result));
        $this->assertIsArray($result['inputRequests'] ?? null);
        $this->assertCount(1, $result['inputRequests']);
        $this->assertIsString($result['requestState'] ?? null);
        $this->assertNotSame('', $result['requestState']);

        $key     = (string) array_key_first($result['inputRequests']);
        $request = $result['inputRequests'][ $key ];
        $this->assertSame('elicitation/create', $request['method']);

        return [ $key, $request ];
    }

    public function test_a_confirm_gate_becomes_an_input_required_elicitation(): void
    {
        $id = $this->comment();

        $result = $this->modern('tools/call', [
            'name'      => 'wpmcp-delete-comment',
            'arguments' => [ 'id' => $id ],
        ], self::form_client())['result'];

        [ , $request ] = $this->the_input_request($result);
        $params        = $request['params'];

        $this->assertSame('form', $params['mode'] ?? 'form');
        $this->assertStringContainsString('Deleting a comment is permanent', $params['message']);
        $this->assertStringNotContainsString('confirm:true', $params['message'], 'The human is not asked to type an argument.');
        $this->assertSame('object', $params['requestedSchema']['type']);
        $this->assertSame('boolean', $params['requestedSchema']['properties']['confirm']['type']);
        $this->assertSame(['confirm'], $params['requestedSchema']['required']);
        $this->assertArrayNotHasKey('content', $result, 'An input_required result is not a tool result.');
        $this->assertInstanceOf(\WP_Comment::class, get_comment($id), 'Nothing is deleted before the user answers.');
    }

    public function test_an_accepted_elicitation_runs_the_call_with_confirmation(): void
    {
        $id   = $this->comment();
        $call = [ 'name' => 'wpmcp-delete-comment', 'arguments' => [ 'id' => $id ] ];

        [ $key ] = $this->the_input_request($first = $this->modern('tools/call', $call, self::form_client())['result']);

        $retry = $call + [
            'inputResponses' => [ $key => [ 'action' => 'accept', 'content' => [ 'confirm' => true ] ] ],
            'requestState'   => $first['requestState'],
        ];
        $result = $this->modern('tools/call', $retry, self::form_client())['result'];

        $this->assertSame('complete', $result['resultType']);
        $this->assertFalse($result['isError'], (string) ($result['content'][0]['text'] ?? ''));
        $this->assertNull(get_comment($id), 'The confirmed call deleted the comment.');
    }

    /** @return iterable<string,array{0:array<string,mixed>}> */
    public function declined_answers(): iterable
    {
        yield 'decline' => [ [ 'action' => 'decline' ] ];
        yield 'cancel' => [ [ 'action' => 'cancel' ] ];
        yield 'accepted but unticked' => [ [ 'action' => 'accept', 'content' => [ 'confirm' => false ] ] ];
    }

    /** @dataProvider declined_answers */
    public function test_a_declined_elicitation_changes_nothing(array $answer): void
    {
        $id   = $this->comment();
        $call = [ 'name' => 'wpmcp-delete-comment', 'arguments' => [ 'id' => $id ] ];

        [ $key ] = $this->the_input_request($first = $this->modern('tools/call', $call, self::form_client())['result']);

        $result = $this->modern('tools/call', $call + [
            'inputResponses' => [ $key => $answer ],
            'requestState'   => $first['requestState'],
        ], self::form_client())['result'];

        $this->assertSame('complete', $result['resultType']);
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('not confirmed', strtolower($result['content'][0]['text']));
        $this->assertInstanceOf(\WP_Comment::class, get_comment($id));
    }

    /**
     * requestState binds the answer to the exact call it was asked for: an
     * acceptance cannot be replayed onto different arguments, and a forged
     * or foreign state is never taken as consent. Either way the server asks
     * again rather than acting.
     */
    public function test_an_answer_is_bound_to_the_call_it_was_asked_for(): void
    {
        $asked = $this->comment();
        $other = $this->comment();
        $call  = [ 'name' => 'wpmcp-delete-comment', 'arguments' => [ 'id' => $asked ] ];

        [ $key ] = $this->the_input_request($first = $this->modern('tools/call', $call, self::form_client())['result']);
        $accept  = [ $key => [ 'action' => 'accept', 'content' => [ 'confirm' => true ] ] ];

        $replayed = $this->modern('tools/call', [
            'name'           => 'wpmcp-delete-comment',
            'arguments'      => [ 'id' => $other ],
            'inputResponses' => $accept,
            'requestState'   => $first['requestState'],
        ], self::form_client())['result'];
        $this->the_input_request($replayed);

        $forged = $this->modern('tools/call', $call + [
            'inputResponses' => $accept,
            'requestState'   => 'forged.' . $first['requestState'],
        ], self::form_client())['result'];
        $this->the_input_request($forged);

        $missing = $this->modern('tools/call', $call + [ 'inputResponses' => $accept ], self::form_client())['result'];
        $this->the_input_request($missing);

        // Another user cannot redeem this user's acceptance either.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $foreign = $this->modern('tools/call', $call + [
            'inputResponses' => $accept,
            'requestState'   => $first['requestState'],
        ], self::form_client())['result'];
        $this->the_input_request($foreign);

        $this->assertInstanceOf(\WP_Comment::class, get_comment($asked));
        $this->assertInstanceOf(\WP_Comment::class, get_comment($other));
    }

    /** A gate that only applies to some calls (a permanent delete) is elicited only when it refuses. */
    public function test_a_conditional_gate_is_elicited_only_when_it_applies(): void
    {
        $trashed = self::factory()->post->create();
        $result  = $this->modern('tools/call', [
            'name'      => 'wpmcp-delete-post',
            'arguments' => [ 'post_id' => $trashed ],
        ], self::form_client())['result'];

        $this->assertSame('complete', $result['resultType']);
        $this->assertFalse($result['isError']);
        $this->assertSame('trash', get_post_status($trashed));

        $forced = self::factory()->post->create();
        $call   = [ 'name' => 'wpmcp-delete-post', 'arguments' => [ 'post_id' => $forced, 'force' => true ] ];

        [ $key ] = $this->the_input_request($first = $this->modern('tools/call', $call, self::form_client())['result']);
        $this->assertInstanceOf(\WP_Post::class, get_post($forced));

        $result = $this->modern('tools/call', $call + [
            'inputResponses' => [ $key => [ 'action' => 'accept', 'content' => [ 'confirm' => true ] ] ],
            'requestState'   => $first['requestState'],
        ], self::form_client())['result'];

        $this->assertFalse($result['isError'], (string) ($result['content'][0]['text'] ?? ''));
        $this->assertNull(get_post($forced));
    }

    /** Without the capability, the confirm argument is the whole contract, unchanged. */
    public function test_a_client_without_form_elicitation_keeps_the_argument_fallback(): void
    {
        $id = $this->comment();

        foreach ([ new \stdClass(), [ 'elicitation' => [ 'url' => new \stdClass() ] ] ] as $capabilities) {
            $result = $this->modern('tools/call', [
                'name'      => 'wpmcp-delete-comment',
                'arguments' => [ 'id' => $id ],
            ], $capabilities)['result'];

            $this->assertSame('complete', $result['resultType']);
            $this->assertTrue($result['isError']);
            $this->assertArrayNotHasKey('inputRequests', $result);
            $this->assertInstanceOf(\WP_Comment::class, get_comment($id));
        }

        $result = $this->modern('tools/call', [
            'name'      => 'wpmcp-delete-comment',
            'arguments' => [ 'id' => $id, 'confirm' => true ],
        ], new \stdClass())['result'];

        $this->assertFalse($result['isError'], (string) ($result['content'][0]['text'] ?? ''));
        $this->assertNull(get_comment($id));
    }

    /** An elicitation-capable client that passes confirm:true itself is not asked twice. */
    public function test_an_explicit_confirm_argument_still_counts(): void
    {
        $id = $this->comment();

        $result = $this->modern('tools/call', [
            'name'      => 'wpmcp-delete-comment',
            'arguments' => [ 'id' => $id, 'confirm' => true ],
        ], self::form_client())['result'];

        $this->assertSame('complete', $result['resultType']);
        $this->assertFalse($result['isError']);
        $this->assertNull(get_comment($id));
    }

    /** A refusal that is not a confirm gate is reported, never turned into a question. */
    public function test_other_refusals_are_not_elicited(): void
    {
        remove_all_filters('wpmcp_enable_delete_comment');
        $id = $this->comment();

        $result = $this->modern('tools/call', [
            'name'      => 'wpmcp-delete-comment',
            'arguments' => [ 'id' => $id ],
        ], self::form_client())['result'];

        $this->assertSame('complete', $result['resultType']);
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('disabled', $result['content'][0]['text']);
    }

    /** The dispatcher meta-tool reaches the same gate, and the confirmation lands on the dispatched call. */
    public function test_a_gate_reached_through_call_tool_is_elicited_too(): void
    {
        $id   = $this->comment();
        $call = [
            'name'      => 'wpmcp-call-tool',
            'arguments' => [ 'name' => 'wpmcp/delete-comment', 'arguments' => [ 'id' => $id ] ],
        ];

        [ $key ] = $this->the_input_request($first = $this->modern('tools/call', $call, self::form_client())['result']);

        $result = $this->modern('tools/call', $call + [
            'inputResponses' => [ $key => [ 'action' => 'accept', 'content' => [ 'confirm' => true ] ] ],
            'requestState'   => $first['requestState'],
        ], self::form_client())['result'];

        $this->assertFalse($result['isError'], (string) ($result['content'][0]['text'] ?? ''));
        $this->assertNull(get_comment($id));
    }

    // ------------------------------------------------------------ 2025-11-25

    private function initialize(array $capabilities): void
    {
        $response = $this->transport->handle_request([
            'jsonrpc' => '2.0',
            'id'      => 0,
            'method'  => 'initialize',
            'params'  => [
                'protocolVersion' => '2025-11-25',
                'capabilities'    => $capabilities,
                'clientInfo'      => [ 'name' => 'conformance', 'version' => '0.0.0' ],
            ],
        ]);
        $this->assertArrayHasKey('result', $response);
    }

    private function legacy_call(array $params): array
    {
        $response = $this->transport->handle_request([ 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => $params ]);
        return json_decode((string) wp_json_encode($response), true)['result'];
    }

    public function test_a_2025_session_is_asked_through_elicitation_create(): void
    {
        $this->initialize([ 'elicitation' => new \stdClass() ]);
        $id    = $this->comment();
        $asked = [];
        $this->transport->set_client_requester(function (string $method, array $params) use (&$asked, $id): ?array {
            $asked[] = [ $method, $params ];
            $this->assertInstanceOf(\WP_Comment::class, get_comment($id), 'Asked before acting.');
            return [ 'action' => 'accept', 'content' => [ 'confirm' => true ] ];
        });

        $result = $this->legacy_call([ 'name' => 'wpmcp-delete-comment', 'arguments' => [ 'id' => $id ] ]);

        $this->assertCount(1, $asked);
        $this->assertSame('elicitation/create', $asked[0][0]);
        $this->assertSame('boolean', $asked[0][1]['requestedSchema']['properties']['confirm']['type']);
        $this->assertFalse($result['isError'], (string) ($result['content'][0]['text'] ?? ''));
        $this->assertArrayNotHasKey('resultType', $result);
        $this->assertNull(get_comment($id));
    }

    public function test_a_2025_session_that_declines_changes_nothing(): void
    {
        $this->initialize([ 'elicitation' => [ 'form' => new \stdClass() ] ]);
        $id = $this->comment();
        $this->transport->set_client_requester(fn(string $method, array $params): ?array => [ 'action' => 'decline' ]);

        $result = $this->legacy_call([ 'name' => 'wpmcp-delete-comment', 'arguments' => [ 'id' => $id ] ]);

        $this->assertTrue($result['isError']);
        $this->assertInstanceOf(\WP_Comment::class, get_comment($id));
    }

    public function test_a_2025_session_without_the_capability_is_never_asked(): void
    {
        $this->initialize([]);
        $id = $this->comment();
        $this->transport->set_client_requester(function (): ?array {
            $this->fail('A client that did not advertise elicitation must not be sent elicitation/create.');
        });

        $result = $this->legacy_call([ 'name' => 'wpmcp-delete-comment', 'arguments' => [ 'id' => $id ] ]);

        $this->assertTrue($result['isError']);
        $this->assertInstanceOf(\WP_Comment::class, get_comment($id));
    }

    /** A client that never answers (stdin closed) gets the refusal, not a hang or an action. */
    public function test_a_2025_session_with_no_answer_falls_back_to_the_refusal(): void
    {
        $this->initialize([ 'elicitation' => new \stdClass() ]);
        $id = $this->comment();
        $this->transport->set_client_requester(fn(string $method, array $params): ?array => null);

        $result = $this->legacy_call([ 'name' => 'wpmcp-delete-comment', 'arguments' => [ 'id' => $id ] ]);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('confirm:true', $result['content'][0]['text']);
        $this->assertInstanceOf(\WP_Comment::class, get_comment($id));
    }

    /**
     * The serve loop's side of a server-to-client request: it writes the
     * request, then reads until the matching response, queueing whatever
     * else the client sent meanwhile for the main loop.
     */
    public function test_awaiting_a_client_response_queues_interleaved_messages(): void
    {
        $lines = [
            '{"jsonrpc":"2.0","method":"notifications/progress","params":{}}',
            '{"jsonrpc":"2.0","id":9,"method":"ping"}',
            '{"jsonrpc":"2.0","id":"someone-else","result":{}}',
            '{"jsonrpc":"2.0","id":"wpmcp-s2c-1","result":{"action":"accept","content":{"confirm":true}}}',
        ];
        $queue = new \SplQueue();

        $result = Stdio_Transport::await_response(
            static function () use (&$lines) {
                return [] === $lines ? false : array_shift($lines);
            },
            'wpmcp-s2c-1',
            $queue
        );

        $this->assertSame([ 'action' => 'accept', 'content' => [ 'confirm' => true ] ], $result);
        $this->assertCount(2, $queue, 'The notification and the ping wait for the main loop; a stray response is dropped.');
        $this->assertStringContainsString('notifications/progress', $queue->dequeue());
        $this->assertStringContainsString('"ping"', $queue->dequeue());

        $this->assertNull(Stdio_Transport::await_response(static fn() => false, 'wpmcp-s2c-2', new \SplQueue()), 'EOF is no answer.');
        $error = [ '{"jsonrpc":"2.0","id":"wpmcp-s2c-3","error":{"code":-32601,"message":"nope"}}' ];
        $this->assertNull(Stdio_Transport::await_response(static function () use (&$error) {
            return [] === $error ? false : array_shift($error);
        }, 'wpmcp-s2c-3', new \SplQueue()), 'An error response is no answer.');
    }
}
