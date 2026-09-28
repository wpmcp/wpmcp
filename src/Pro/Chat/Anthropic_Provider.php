<?php

namespace WPMCP\Pro\Chat;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Claude Messages API client for the in-admin chat (issue #73).
 *
 * Opt-in and bring-your-own-key: nothing here runs until an administrator has
 * stored their own Anthropic API key on the chat screen and sent a message.
 * The request goes from this server to api.anthropic.com only (documented in
 * readme.txt under "External services"); the key is read from the per-user
 * Key_Vault for the duration of the request and never sent to the browser.
 *
 * The call is made with the WordPress HTTP API rather than a vendored SDK so
 * the plugin carries no extra dependency and the request honours the site's
 * proxy and transport configuration like every other outbound call it makes.
 */
final class Anthropic_Provider implements Chat_Provider
{
    public const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /** Current Claude family default; override with the wpmcp_chat_model filter. */
    public const DEFAULT_MODEL = 'claude-sonnet-5';

    public const API_VERSION = '2023-06-01';

    private const MAX_TOKENS = 16000;
    private const TIMEOUT    = 120;

    public static function model(): string
    {
        $model = (string) apply_filters('wpmcp_chat_model', self::DEFAULT_MODEL);
        return '' !== trim($model) ? trim($model) : self::DEFAULT_MODEL;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @return array<string, mixed>
     */
    public static function build_body(string $system, array $messages, array $tools): array
    {
        $body = [
            'model'      => self::model(),
            'max_tokens' => self::MAX_TOKENS,
            // One cache breakpoint on the system block caches the tool list
            // and the system prompt together (tools render first), which is
            // the part of every request that repeats turn after turn.
            'system'     => [[
                'type'          => 'text',
                'text'          => $system,
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'messages'   => $messages,
        ];
        if ([] !== $tools) {
            $body['tools'] = $tools;
        }
        return $body;
    }

    public function send(string $api_key, string $system, array $messages, array $tools)
    {
        $payload = wp_json_encode(self::build_body($system, $messages, $tools));
        if (false === $payload) {
            return new \WP_Error('provider_encoding_failed', 'The conversation could not be encoded for the provider.');
        }

        $response = wp_safe_remote_post(self::ENDPOINT, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'x-api-key'         => $api_key,
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ],
            'body'    => $payload,
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error('provider_unreachable', $response->get_error_message());
        }

        $code    = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $type    = is_array($decoded) ? (string) ($decoded['error']['type'] ?? '') : '';
            $message = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
            return new \WP_Error(
                'provider_error',
                '' !== $message ? $message : sprintf('The provider answered HTTP %d.', $code),
                ['status' => $code, 'type' => $type]
            );
        }

        if (! is_array($decoded) || ! isset($decoded['content']) || ! is_array($decoded['content'])) {
            return new \WP_Error('provider_bad_response', 'The provider returned a response this plugin cannot read.');
        }

        return [
            'content'     => array_values(array_filter($decoded['content'], 'is_array')),
            'stop_reason' => (string) ($decoded['stop_reason'] ?? ''),
        ];
    }
}
