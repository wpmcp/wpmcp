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

    public function test_a_failed_probe_over_an_unreadable_vault_keeps_the_sealed_blob(): void
    {
        // A salt rotation leaves a blob that restoring the old salts would
        // recover. A mistyped reconnect must not be what destroys it.
        Cloud_Credentials::replace(['base_url' => 'https://cloud.example', 'api_key' => 'sk-old', 'refresh_token' => 'rt-1']);
        $sealed = (string) get_option(Cloud_Credentials::OPTION);

        $rotated = static fn () => 'a-freshly-generated-auth-salt';
        add_filter('salt', $rotated);
        try {
            $this->status = 401;
            $this->assertWPError((new Cloud_Connect())->handle(['url' => 'https://cloud.example', 'key' => 'sk-mistyped']));
        } finally {
            remove_filter('salt', $rotated);
        }

        $this->assertSame($sealed, get_option(Cloud_Credentials::OPTION));
        $this->assertSame('rt-1', Cloud_Credentials::all(true)['refresh_token'] ?? null);
    }

    public function test_a_failed_probe_keeps_the_previous_bundles_rejection_backoff(): void
    {
        Cloud_Credentials::replace(['base_url' => 'https://cloud.example', 'refresh_token' => 'rt-rejected']);
        $marker = ['rejected_at' => time()];
        update_option(\WPMCP\Cloud\Token_Refresher::HEALTH_OPTION, $marker, false);

        $this->status = 401;
        $this->assertWPError((new Cloud_Connect())->handle(['url' => 'https://cloud.example', 'key' => 'sk-mistyped']));

        // The restored bundle is the one the cloud already rejected; wiping
        // its backoff would have the next request re-present it.
        $this->assertSame($marker, get_option(\WPMCP\Cloud\Token_Refresher::HEALTH_OPTION));
    }

    public function test_a_failed_probe_on_an_unmigrated_legacy_site_keeps_it_connected(): void
    {
        update_option('wpmcp_cloud_url', 'https://cloud.example', false);
        update_option('wpmcp_cloud_key', 'sk-legacy', false);

        $this->status = 401;
        $this->assertWPError((new Cloud_Connect())->handle(['url' => 'https://cloud.example', 'key' => 'sk-mistyped']));

        $this->assertSame('sk-legacy', \WPMCP\Cloud\Cloud_Config::api_key());
        $this->assertFalse(get_option('wpmcp_cloud_key'), 'the key survives sealed, not in plaintext');
    }

    /** A cloud that moved: http and bare host redirect to https://www. */
    public function moved_cloud($pre, $args, $url)
    {
        $auth = (string) ($args['headers']['Authorization'] ?? '');
        $json = static fn (int $code, array $body, array $headers = []) => [
            'headers'  => $headers,
            'body'     => (string) wp_json_encode($body),
            'response' => ['code' => $code, 'message' => ''],
            'cookies'  => [],
            'filename' => null,
        ];
        if (0 !== strpos($url, 'https://www.cloud.example/')) {
            return $json(301, [], ['location' => 'https://www.cloud.example/wpmcp-cloud/v1/me']);
        }
        return '' === $auth
            ? $json(401, ['message' => 'Authentication required'])
            : $json(200, ['account' => ['id' => 1, 'email' => 'user@example.com', 'plan' => 'pro']]);
    }

    public function test_connect_stores_the_canonical_url_a_redirecting_cloud_points_to(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        add_filter('pre_http_request', [$this, 'moved_cloud'], 10, 3);

        try {
            $out = (new Cloud_Connect())->handle(['url' => 'http://cloud.example/', 'key' => 'sk-good']);
        } finally {
            remove_filter('pre_http_request', [$this, 'moved_cloud'], 10);
        }

        $this->assertIsArray($out);
        $this->assertTrue($out['connected']);
        $this->assertSame('https://www.cloud.example', \WPMCP\Cloud\Cloud_Config::base_url());
    }

    public function test_a_redirect_to_an_unrelated_page_is_not_adopted_as_the_cloud_url(): void
    {
        $this->assertSame(
            'https://cloud.example',
            \WPMCP\Cloud\Cloud_Client::canonical_base_url('https://cloud.example')
        ); // 200 from fake_http: kept as typed.

        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        $elsewhere = static fn () => [
            'headers'  => ['location' => 'http://login.example/signin'],
            'body'     => '',
            'response' => ['code' => 302, 'message' => ''],
            'cookies'  => [],
            'filename' => null,
        ];
        add_filter('pre_http_request', $elsewhere, 10, 3);
        try {
            $this->assertSame('https://cloud.example', \WPMCP\Cloud\Cloud_Client::canonical_base_url('https://cloud.example/'));
        } finally {
            remove_filter('pre_http_request', $elsewhere, 10);
        }
    }
}
