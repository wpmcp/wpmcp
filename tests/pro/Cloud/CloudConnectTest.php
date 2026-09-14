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
}
