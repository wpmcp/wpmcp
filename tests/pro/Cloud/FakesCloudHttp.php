<?php

namespace WPMCP\Tests\Pro\Cloud;

use WPMCP\Cloud\Cloud_Credentials;

/**
 * The fake WP MCP Cloud shared by the cloud test cases: a connected site
 * (https://cloud.example with API key secret-key) and a pre_http_request
 * filter that records every request, asserts the Bearer header on each one,
 * and answers through $this->responder, so no live network is involved.
 *
 * Lives beside the tests rather than in tests/support so the dev autoloader
 * (WPMCP\Tests\Pro\ => tests/pro/) finds it without a bootstrap require.
 */
trait FakesCloudHttp
{
    /** @var array<int,array{url:string,path:string,method:string,body:mixed,headers:array}> */
    private array $requests = [];

    /**
     * fn (string $path, string $method, mixed $body, string $url, array $args): array
     * returning a pre_http_request response. Null answers 200 with {}.
     *
     * @var callable|null
     */
    private $responder = null;

    private function start_fake_cloud(): void
    {
        update_option('wpmcp_cloud_url', 'https://cloud.example');
        update_option('wpmcp_cloud_key', 'secret-key');
        $this->requests  = [];
        $this->responder = null;
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    private function stop_fake_cloud(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        delete_option('wpmcp_cloud_url');
        delete_option('wpmcp_cloud_key');
        Cloud_Credentials::clear();
    }

    /** Leave the site with no cloud connection at all. */
    private function disconnect_fake_cloud(): void
    {
        delete_option('wpmcp_cloud_url');
        delete_option('wpmcp_cloud_key');
        Cloud_Credentials::clear();
    }

    public function fake_http($pre, $args, $url)
    {
        $method = strtoupper((string) ($args['method'] ?? 'GET'));
        $body   = $args['body'] ?? null;
        if (is_string($body)) {
            $body = json_decode($body, true);
        }
        $path = (string) substr((string) $url, strlen('https://cloud.example/wpmcp-cloud/v1'));

        $this->requests[] = [
            'url'     => $url,
            'path'    => $path,
            'method'  => $method,
            'body'    => $body,
            'headers' => $args['headers'] ?? [],
        ];

        // Every request this suite makes carries the site's credential.
        $this->assertSame('Bearer secret-key', $args['headers']['Authorization'] ?? null);

        if (null !== $this->responder) {
            return ($this->responder)($path, $method, $body, $url, $args);
        }

        return self::cloud_json([]);
    }

    private static function cloud_json(array $data, int $code = 200): array
    {
        return [
            'headers'  => [],
            'body'     => wp_json_encode($data),
            'response' => ['code' => $code, 'message' => 'OK'],
        ];
    }
}
