<?php

namespace WPMCP\Tests\Free\Media;

use WPMCP\Tools\Media\Sideload_Image;

class SideloadImageTest extends \WP_UnitTestCase
{
    private const FIXTURE_URL = 'https://example.com/fixtures/wpmcp-test-image.jpg';

    protected function setUp(): void
    {
        parent::setUp();
        // Avoid a real network round-trip: intercept the HTTP call
        // download_url() makes and stream a local fixture image's bytes
        // into the requested tmp file instead, exactly like a real download
        // would, but with the origin server. No skipped/mocked behavior on
        // the WordPress side, only the transport is faked.
        add_filter('pre_http_request', [$this, 'serve_fixture_image'], 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'serve_fixture_image'], 10);
        parent::tearDown();
    }

    public function serve_fixture_image($preempt, $parsed_args, $url)
    {
        if (self::FIXTURE_URL !== $url) {
            return $preempt;
        }
        $fixture = DIR_TESTDATA . '/images/canola.jpg';
        $body    = file_get_contents($fixture);
        if (! empty($parsed_args['filename'])) {
            file_put_contents($parsed_args['filename'], $body);
            $body = '';
        }
        return [
            'headers'  => [],
            'body'     => $body,
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => $parsed_args['filename'] ?? null,
        ];
    }

    public function test_sideloads_image_from_url_into_media_library(): void
    {
        $out = (new Sideload_Image())->handle(['url' => self::FIXTURE_URL]);

        $this->assertArrayHasKey('media_id', $out);
        $post = get_post($out['media_id']);
        $this->assertNotNull($post);
        $this->assertSame('attachment', $post->post_type);
        $this->assertStringStartsWith('image/', $post->post_mime_type);
        $this->assertNotEmpty($out['url']);
    }

    public function test_requires_url(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Sideload_Image())->handle([]);
    }
}
