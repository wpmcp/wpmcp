<?php

namespace WPMCP\Pro\Chat;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One model turn against an LLM provider (issue #73).
 *
 * The provider only ever proposes: it returns content blocks, and any
 * tool_use block in them is handed to Tool_Executor, which decides whether
 * and how it runs. Nothing a provider returns can execute anything by itself.
 */
interface Chat_Provider
{
    /**
     * @param string                           $api_key  The calling admin's own decrypted key.
     * @param string                           $system   Server-authored system prompt.
     * @param array<int, array<string, mixed>> $messages Provider-format message list.
     * @param array<int, array<string, mixed>> $tools    Provider-format tool definitions.
     * @return array{content: array<int, array<string, mixed>>, stop_reason: string}|\WP_Error
     */
    public function send(string $api_key, string $system, array $messages, array $tools);
}
