<?php

namespace WPMCP\Tests\Support;

use WPMCP\Pro\Chat\Chat_Provider;

/**
 * Scripted stand-in for the LLM provider in the chat tests (issue #73).
 *
 * Each send() pops the next scripted response (or WP_Error) and records the
 * request it was given, so a test can assert both what the model was told
 * (system prompt, tool list, message history) and what the server did with
 * a proposed tool call.
 */
final class Fake_Chat_Provider implements Chat_Provider
{
    /** @var array<int, array<string, mixed>|\WP_Error> */
    private array $script;

    /** @var array<int, array<string, mixed>> */
    public array $requests = [];

    /** @param array<int, array<string, mixed>|\WP_Error> $script */
    public function __construct(array $script = [])
    {
        $this->script = $script;
    }

    /** @param array<string, mixed>|\WP_Error $response */
    public function queue($response): void
    {
        $this->script[] = $response;
    }

    public function send(string $api_key, string $system, array $messages, array $tools)
    {
        $this->requests[] = [
            'api_key'  => $api_key,
            'system'   => $system,
            'messages' => $messages,
            'tools'    => $tools,
        ];
        if ([] === $this->script) {
            return ['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn'];
        }
        return array_shift($this->script);
    }

    /** A text-only reply. */
    public static function text(string $text): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn'];
    }

    /**
     * A reply proposing tool calls.
     *
     * @param array<int, array{0: string, 1: string, 2: array<string, mixed>}> $calls [id, tool name, input]
     */
    public static function tool_calls(array $calls, string $text = ''): array
    {
        $content = [];
        if ('' !== $text) {
            $content[] = ['type' => 'text', 'text' => $text];
        }
        foreach ($calls as [$id, $name, $input]) {
            $content[] = ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input];
        }
        return ['content' => $content, 'stop_reason' => 'tool_use'];
    }
}
