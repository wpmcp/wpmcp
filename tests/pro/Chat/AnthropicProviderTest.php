<?php

namespace WPMCP\Tests\Pro\Chat;

use WPMCP\Pro\Chat\Anthropic_Provider;

/**
 * The one outbound LLM call (issue #73): where it goes, what it carries, and
 * that it defaults to a current Claude model. Intercepted at
 * pre_http_request, so no network is touched.
 */
class AnthropicProviderTest extends \WP_UnitTestCase
{
    /** @var array<int, array{url: string, args: array<string, mixed>}> */
    private array $captured = [];

    protected function tearDown(): void
    {
        remove_all_filters('pre_http_request');
        remove_all_filters('wpmcp_chat_model');
        parent::tearDown();
    }

    private function respond(int $code, array $body): void
    {
        add_filter('pre_http_request', function ($pre, $args, $url) use ($code, $body) {
            $this->captured[] = ['url' => $url, 'args' => $args];
            return [
                'headers'  => [],
                'body'     => wp_json_encode($body),
                'response' => ['code' => $code, 'message' => 'x'],
                'cookies'  => [],
                'filename' => null,
            ];
        }, 10, 3);
    }

    public function test_the_request_goes_to_the_messages_api_with_the_users_key(): void
    {
        $this->respond(200, ['content' => [['type' => 'text', 'text' => 'hi']], 'stop_reason' => 'end_turn']);

        $result = (new Anthropic_Provider())->send(
            'sk-ant-user-key',
            'system text',
            [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hello']]]],
            [['name' => 'load_tools', 'description' => 'd', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]]]
        );

        $this->assertSame('end_turn', $result['stop_reason']);
        $this->assertSame('hi', $result['content'][0]['text']);

        $this->assertCount(1, $this->captured);
        $this->assertSame('https://api.anthropic.com/v1/messages', $this->captured[0]['url']);
        $headers = $this->captured[0]['args']['headers'];
        $this->assertSame('sk-ant-user-key', $headers['x-api-key']);
        $this->assertSame(Anthropic_Provider::API_VERSION, $headers['anthropic-version']);

        $body = json_decode($this->captured[0]['args']['body'], true);
        $this->assertSame('claude-sonnet-5', $body['model']);
        $this->assertSame('system text', $body['system'][0]['text']);
        $this->assertSame('load_tools', $body['tools'][0]['name']);
        $this->assertStringNotContainsString('sk-ant-user-key', $this->captured[0]['args']['body'], 'The key belongs in the header only.');
    }

    public function test_the_model_defaults_to_the_current_claude_family_and_is_filterable(): void
    {
        $this->assertMatchesRegularExpression('/^claude-(sonnet-5|opus-5-5)$/', Anthropic_Provider::DEFAULT_MODEL);

        add_filter('wpmcp_chat_model', fn () => 'claude-opus-5-5');
        $this->assertSame('claude-opus-5-5', Anthropic_Provider::model());

        add_filter('wpmcp_chat_model', fn () => '   ', 20);
        $this->assertSame(Anthropic_Provider::DEFAULT_MODEL, Anthropic_Provider::model());
    }

    public function test_a_provider_error_is_returned_as_wp_error(): void
    {
        $this->respond(401, ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']]);

        $result = (new Anthropic_Provider())->send('sk-ant-bad', 's', [], []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('provider_error', $result->get_error_code());
        $this->assertSame('invalid x-api-key', $result->get_error_message());
        $this->assertSame(401, $result->get_error_data()['status']);
    }

    public function test_an_unreadable_response_is_an_error_not_an_empty_turn(): void
    {
        $this->respond(200, ['unexpected' => true]);

        $result = (new Anthropic_Provider())->send('k', 's', [], []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('provider_bad_response', $result->get_error_code());
    }
}
