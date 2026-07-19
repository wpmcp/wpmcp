<?php

namespace WPMCP\Tests\Free\Media;

use WPMCP\Tools\Media\Stock\Search_Stock_Images;
use WPMCP\Tools\Media\Stock\Stock_Key_Store;

/**
 * search-stock-images (issue #64): openly-licensed stock search across
 * providers, returning provider-attributed, license-carrying results in one
 * normalized shape. Openverse is keyless; Pexels/Unsplash are BYO-key.
 * All transport is mocked via pre_http_request — no network in CI.
 */
class SearchStockImagesTest extends \WP_UnitTestCase
{
    /** @var array{url:string,args:array}|null */
    private ?array $request = null;

    /** @var array|\WP_Error */
    private $respond_with = [];

    protected function setUp(): void
    {
        parent::setUp();
        add_filter('pre_http_request', [$this, 'capture_request'], 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'capture_request'], 10);
        delete_option(Stock_Key_Store::OPTION);
        parent::tearDown();
    }

    public function capture_request($preempt, $parsed_args, $url)
    {
        $this->request = ['url' => (string) $url, 'args' => (array) $parsed_args];
        if (is_wp_error($this->respond_with)) {
            return $this->respond_with;
        }
        return [
            'headers'  => ['content-type' => 'application/json'],
            'body'     => (string) wp_json_encode($this->respond_with),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    public function test_openverse_search_returns_provider_attributed_results(): void
    {
        $this->respond_with = [
            'result_count' => 1,
            'results'      => [[
                'id'                   => 'abc-123',
                'title'                => 'Red barn',
                'url'                  => 'https://upload.wikimedia.org/barn.jpg',
                'thumbnail'            => 'https://api.openverse.org/v1/images/abc-123/thumb/',
                'width'                => 1200,
                'height'               => 800,
                'license'              => 'by-sa',
                'license_url'          => 'https://creativecommons.org/licenses/by-sa/4.0/',
                'creator'              => 'Jane Photographer',
                'foreign_landing_url'  => 'https://commons.wikimedia.org/wiki/File:barn.jpg',
            ]],
        ];

        $out = (new Search_Stock_Images())->handle(['query' => 'red barn']);

        $this->assertSame('openverse', $out['provider']);
        $this->assertStringContainsString('api.openverse.org', $this->request['url']);

        $r = $out['results'][0];
        $this->assertSame('openverse', $r['provider']);
        $this->assertSame('abc-123', $r['id']);
        $this->assertSame('Red barn', $r['title']);
        $this->assertSame('https://upload.wikimedia.org/barn.jpg', $r['image_url']);
        $this->assertSame('by-sa', $r['license']);
        $this->assertSame('https://creativecommons.org/licenses/by-sa/4.0/', $r['license_url']);
        $this->assertSame('Jane Photographer', $r['attribution']);
        $this->assertSame('https://commons.wikimedia.org/wiki/File:barn.jpg', $r['source_url']);
        $this->assertSame(1200, $r['width']);
        $this->assertSame(800, $r['height']);
    }

    public function test_keyed_provider_without_key_refuses_with_guidance(): void
    {
        try {
            (new Search_Stock_Images())->handle(['query' => 'barn', 'provider' => 'pexels']);
            $this->fail('Expected a missing-key refusal.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('set-stock-key', $e->getMessage());
        }

        // Refused BEFORE any HTTP request was attempted.
        $this->assertNull($this->request);
    }

    public function test_pexels_search_sends_key_header_and_normalizes_results(): void
    {
        Stock_Key_Store::set('pexels', 'pexels-key-9');
        $this->respond_with = [
            'total_results' => 1,
            'photos'        => [[
                'id'           => 42,
                'alt'          => 'Green field',
                'width'        => 4000,
                'height'       => 3000,
                'url'          => 'https://www.pexels.com/photo/green-field-42/',
                'photographer' => 'Sam Shooter',
                'src'          => [
                    'original' => 'https://images.pexels.com/photos/42/field.jpeg',
                    'medium'   => 'https://images.pexels.com/photos/42/field.jpeg?h=350',
                ],
            ]],
        ];

        $out = (new Search_Stock_Images())->handle(['query' => 'field', 'provider' => 'pexels']);

        $this->assertStringContainsString('api.pexels.com', $this->request['url']);
        $this->assertSame('pexels-key-9', $this->request['args']['headers']['Authorization']);

        $r = $out['results'][0];
        $this->assertSame('pexels', $r['provider']);
        $this->assertSame('42', (string) $r['id']);
        $this->assertSame('https://images.pexels.com/photos/42/field.jpeg', $r['image_url']);
        $this->assertSame('Sam Shooter', $r['attribution']);
        $this->assertNotEmpty($r['license']);
        $this->assertNotEmpty($r['license_url']);
    }

    public function test_unsplash_search_sends_client_id_header(): void
    {
        Stock_Key_Store::set('unsplash', 'unsplash-key-7');
        $this->respond_with = [
            'total'   => 1,
            'results' => [[
                'id'              => 'u-9',
                'alt_description' => 'City at night',
                'width'           => 5000,
                'height'          => 3333,
                'urls'            => ['full' => 'https://images.unsplash.com/photo-9', 'small' => 'https://images.unsplash.com/photo-9?w=400'],
                'user'            => ['name' => 'Nadia Night'],
                'links'           => ['html' => 'https://unsplash.com/photos/u-9'],
            ]],
        ];

        $out = (new Search_Stock_Images())->handle(['query' => 'city', 'provider' => 'unsplash']);

        $this->assertStringContainsString('api.unsplash.com', $this->request['url']);
        $this->assertSame('Client-ID unsplash-key-7', $this->request['args']['headers']['Authorization']);
        $this->assertSame('Nadia Night', $out['results'][0]['attribution']);
    }

    public function test_requires_query(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Search_Stock_Images())->handle([]);
    }

    public function test_rejects_unknown_provider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Search_Stock_Images())->handle(['query' => 'x', 'provider' => 'clipart-heaven']);
    }

    public function test_per_page_is_capped(): void
    {
        $this->respond_with = ['result_count' => 0, 'results' => []];

        (new Search_Stock_Images())->handle(['query' => 'x', 'per_page' => 500]);

        $this->assertStringContainsString('page_size=30', $this->request['url']);
    }

    public function test_provider_transport_error_surfaces_as_runtime_exception(): void
    {
        $this->respond_with = new \WP_Error('http_request_failed', 'name resolution failed');

        $this->expectException(\RuntimeException::class);
        (new Search_Stock_Images())->handle(['query' => 'x']);
    }
}
