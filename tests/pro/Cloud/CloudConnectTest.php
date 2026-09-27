<?php

namespace WPMCP\Tests\Pro\Cloud;

use WPMCP\Cloud\Cloud_Credentials;
use WPMCP\Tools\Cloud\Cloud_Connect;

/**
 * Issue #141 phase 1: cloud-connect has to write the credentials before it can
 * probe with them, and since the vault landed that write is a REPLACE over a
 * set that can hold a refresh token. A refresh token is not something the
 * operator can retype, so a probe that fails must put back exactly what was
 * there.
 */
class CloudConnectTest extends \WP_UnitTestCase
{
    private int $status = 200;

    protected function setUp(): void
    {
        parent::setUp();
        Cloud_Credentials::clear();
        $this->status = 200;
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        Cloud_Credentials::clear();
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    public function fake_http($pre, $args, $url)
    {
        return [
            'headers'  => [],
            'body'     => (string) wp_json_encode(
                200 === $this->status
                    ? ['account' => ['id' => 1, 'email' => 'user@example.com', 'plan' => 'pro']]
                    : ['message' => 'Invalid API key']
            ),
            'response' => ['code' => $this->status, 'message' => ''],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    public function test_a_failed_probe_restores_the_previous_credential_set(): void
    {
        Cloud_Credentials::replace([
            'base_url'          => 'https://cloud.example',
            'api_key'           => 'sk-working',
            'access_token'      => 'access-1',
            'refresh_token'     => 'rt-1',
            'access_expires_at' => time() + 3600,
            'client_id'         => 'client-1',
        ]);

        $this->status = 401;
        $out = (new Cloud_Connect())->handle(['url' => 'https://cloud.example', 'key' => 'sk-mistyped']);

        $this->assertWPError($out);

        $all = Cloud_Credentials::all(true);
        $this->assertSame('sk-working', $all['api_key']);
        $this->assertSame('rt-1', $all['refresh_token'], 'a mistyped key must not destroy a refresh token');
        $this->assertSame('client-1', $all['client_id']);
    }

    public function test_a_failed_first_connect_leaves_nothing_behind(): void
    {
        $this->status = 401;

        $this->assertWPError((new Cloud_Connect())->handle(['url' => 'https://cloud.example', 'key' => 'sk-bad']));
        $this->assertSame([], Cloud_Credentials::all(true));
    }

    public function test_a_successful_connect_stores_the_credentials(): void
    {
        $out = (new Cloud_Connect())->handle(['url' => 'https://cloud.example', 'key' => 'sk-good']);

        $this->assertIsArray($out);
        $this->assertTrue($out['connected']);
        $this->assertSame('sk-good', Cloud_Credentials::all(true)['api_key']);
    }

    public function test_a_connect_whose_seal_does_not_land_reports_it_and_keeps_the_previous_set(): void
    {
        Cloud_Credentials::replace(['base_url' => 'https://cloud.example', 'api_key' => 'sk-working', 'refresh_token' => 'rt-1']);

        $block = static fn ($value, $old) => $old;
        add_filter('pre_update_option_' . Cloud_Credentials::OPTION, $block, 10, 2);

        try {
            $out = (new Cloud_Connect())->handle(['url' => 'https://cloud.example', 'key' => 'sk-typed-by-the-operator']);
        } finally {
            remove_filter('pre_update_option_' . Cloud_Credentials::OPTION, $block, 10);
        }

        $this->assertWPError($out);
        $this->assertSame('cloud_credentials_not_stored', $out->get_error_code());
        $this->assertStringNotContainsString('sk-typed-by-the-operator', $out->get_error_message());
        $this->assertSame('rt-1', Cloud_Credentials::all(true)['refresh_token'] ?? null);
    }

    public function test_a_cloud_error_that_echoes_the_key_never_reaches_the_caller(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        $echo = static function ($pre, $args) {
            $auth = (string) ($args['headers']['Authorization'] ?? '');
            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode(['message' => 'Unknown key ' . substr($auth, 7)]),
                'response' => ['code' => 401, 'message' => ''],
                'cookies'  => [],
                'filename' => null,
            ];
        };
        add_filter('pre_http_request', $echo, 10, 2);

        try {
            $out = (new Cloud_Connect())->handle(['url' => 'https://cloud.example', 'key' => 'sk-must-not-leak']);
        } finally {
            remove_filter('pre_http_request', $echo, 10);
        }

        $this->assertWPError($out);
        $this->assertStringNotContainsString('sk-must-not-leak', $out->get_error_message());
        $this->assertStringNotContainsString('sk-must-not-leak', (string) wp_json_encode($out->get_error_data()));
    }
}
