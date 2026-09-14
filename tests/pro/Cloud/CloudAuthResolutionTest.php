<?php

namespace WPMCP\Tests\Pro\Cloud;

use WPMCP\Cloud\Cloud_Client;
use WPMCP\Cloud\Cloud_Config;
use WPMCP\Cloud\Cloud_Credentials;
use WPMCP\Cloud\Token_Refresher;

/**
 * Issue #141 phase 1: which credential Cloud_Client puts on the wire.
 *
 * Resolution order is fresh vault access token, then a Token_Refresher run
 * when the stored token is stale, then the phase A API key. Asserted through
 * the Authorization header of a faked request, because that header is the
 * only thing the cloud backend actually sees.
 */
class CloudAuthResolutionTest extends \WP_UnitTestCase
{
    /** @var array<int,array{url:string,auth:string}> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cloud_Credentials::clear();
        delete_option(Token_Refresher::HEALTH_OPTION);
        $this->requests = [];
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        Cloud_Credentials::clear();
        delete_option(Token_Refresher::HEALTH_OPTION);
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    public function fake_http($pre, $args, $url)
    {
        $this->requests[] = ['url' => $url, 'auth' => (string) ($args['headers']['Authorization'] ?? '')];

        $body = str_contains($url, Cloud_Client::TOKEN_PATH)
            ? ['access_token' => 'refreshed-access', 'refresh_token' => 'rt-2', 'expires_in' => 3600]
            : ['account' => ['id' => 1, 'email' => 'user@example.com', 'plan' => 'pro']];

        return [
            'headers'  => [],
            'body'     => (string) wp_json_encode($body),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    private function api_auth(): string
    {
        foreach ($this->requests as $request) {
            if (! str_contains($request['url'], Cloud_Client::TOKEN_PATH)) {
                return $request['auth'];
            }
        }
        return '';
    }

    public function test_a_fresh_vault_token_wins_over_the_api_key(): void
    {
        Cloud_Credentials::replace([
            'base_url'          => 'https://cloud.example',
            'api_key'           => 'sk-fallback',
            'access_token'      => 'fresh-access',
            'refresh_token'     => 'rt-1',
            'access_expires_at' => time() + 3600,
        ]);

        $this->assertIsArray((new Cloud_Client())->get('/me'));
        $this->assertSame('Bearer fresh-access', $this->api_auth());
        $this->assertCount(1, $this->requests, 'a fresh token must not trigger a refresh');
    }

    public function test_a_stale_token_is_refreshed_before_the_request(): void
    {
        Cloud_Credentials::replace([
            'base_url'          => 'https://cloud.example',
            'api_key'           => 'sk-fallback',
            'access_token'      => 'stale-access',
            'refresh_token'     => 'rt-1',
            'access_expires_at' => time() - 10,
        ]);

        $this->assertIsArray((new Cloud_Client())->get('/me'));
        $this->assertSame('Bearer refreshed-access', $this->api_auth());
        $this->assertSame('refreshed-access', Cloud_Credentials::all(true)['access_token']);
    }

    public function test_api_key_is_used_when_there_is_no_token_bundle(): void
    {
        Cloud_Config::set('https://cloud.example', 'sk-fallback');

        $this->assertIsArray((new Cloud_Client())->get('/me'));
        $this->assertSame('Bearer sk-fallback', $this->api_auth());
    }

    public function test_a_token_only_connection_counts_as_configured(): void
    {
        Cloud_Credentials::replace([
            'base_url'          => 'https://cloud.example',
            'access_token'      => 'fresh-access',
            'refresh_token'     => 'rt-1',
            'access_expires_at' => time() + 3600,
        ]);

        $this->assertTrue(Cloud_Config::is_configured(), 'an OAuth-only connection has no api_key');
        $this->assertIsArray((new Cloud_Client())->get('/me'));
        $this->assertSame('Bearer fresh-access', $this->api_auth());
    }

    public function test_reconnecting_to_another_cloud_drops_the_previous_token_bundle(): void
    {
        Cloud_Credentials::replace([
            'base_url'          => 'https://old-cloud.example',
            'api_key'           => 'sk-old',
            'access_token'      => 'old-tenant-access',
            'refresh_token'     => 'old-tenant-refresh',
            'access_expires_at' => time() + 3600,
            'client_id'         => 'old-client',
        ]);
        update_option(Token_Refresher::HEALTH_OPTION, ['rejected_at' => time()], false);

        Cloud_Config::set('https://new-cloud.example', 'sk-new');

        $all = Cloud_Credentials::all(true);
        $this->assertSame('https://new-cloud.example', $all['base_url']);
        $this->assertSame('sk-new', $all['api_key']);
        $this->assertSame('', (string) ($all['access_token'] ?? ''));
        $this->assertSame('', (string) ($all['refresh_token'] ?? ''));
        $this->assertSame('', (string) ($all['client_id'] ?? ''));
        $this->assertFalse(get_option(Token_Refresher::HEALTH_OPTION), 'a fresh connect clears the unhealthy marker');

        $this->assertIsArray((new Cloud_Client())->get('/me'));
        $this->assertSame('Bearer sk-new', $this->api_auth());
        foreach ($this->requests as $request) {
            $this->assertStringNotContainsString('old-tenant', $request['auth']);
            $this->assertStringStartsWith('https://new-cloud.example', $request['url']);
        }
    }

    public function test_a_token_only_connection_that_cannot_refresh_errors_instead_of_sending_an_empty_bearer(): void
    {
        Cloud_Credentials::replace([
            'base_url'          => 'https://cloud.example',
            'access_token'      => 'stale-access',
            'refresh_token'     => 'rt-1',
            'access_expires_at' => time() - 10,
        ]);
        // Inside the rejection backoff the refresher deliberately returns
        // nothing, and there is no API key behind it.
        update_option(Token_Refresher::HEALTH_OPTION, ['rejected_at' => time()], false);

        $out = (new Cloud_Client())->get('/me');

        $this->assertWPError($out);
        $this->assertSame('cloud_not_authenticated', $out->get_error_code());
        $this->assertStringContainsString('cloud-connect', $out->get_error_message());
        $this->assertSame([], $this->requests, 'no request may go out with an empty bearer credential');
    }
}
