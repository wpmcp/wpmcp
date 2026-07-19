<?php

namespace WPMCP\Tests\Free\Media;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Media\Stock\Import_Stock_Image;

/**
 * import-stock-image (issue #64): SSRF-guarded sideload of a stock result
 * into the Media Library. The remote fetch is defended in depth: https-only,
 * host allowlist checked BEFORE any request, no redirect following, declared
 * and actual size caps, real-content sniffing (no polyglots), sanitized
 * filenames. The created attachment is recorded as a 'media_import' snapshot:
 * rolling the operation back deletes the imported attachment and its files.
 */
class ImportStockImageTest extends \WP_UnitTestCase
{
    private const IMAGE_URL = 'https://images.pexels.com/photos/123/wpmcp%20photo.jpeg?auto=compress&w=5000';

    private int $http_calls = 0;

    /** @var array{code?:int, headers?:array, body?:string} */
    private array $respond = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        add_filter('pre_http_request', [$this, 'serve'], 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'serve'], 10);
        parent::tearDown();
    }

    public function serve($preempt, $parsed_args, $url)
    {
        $this->http_calls++;
        $body    = $this->respond['body'] ?? (string) file_get_contents(DIR_TESTDATA . '/images/canola.jpg');
        $headers = $this->respond['headers'] ?? ['content-type' => 'image/jpeg', 'content-length' => (string) strlen($body)];
        $code    = $this->respond['code'] ?? 200;

        if (! empty($parsed_args['filename'])) {
            file_put_contents($parsed_args['filename'], $body);
            $body = '';
        }
        return [
            'headers'  => $headers,
            'body'     => $body,
            'response' => ['code' => $code, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => $parsed_args['filename'] ?? null,
        ];
    }

    public function test_imports_from_allowlisted_host_with_sanitized_filename(): void
    {
        $out = (new Import_Stock_Image())->handle([
            'image_url' => self::IMAGE_URL,
            'provider'  => 'pexels',
            'alt'       => 'A canola field',
        ]);

        $this->assertNotEmpty($out['operation_id']);
        $post = get_post($out['media_id']);
        $this->assertNotNull($post);
        $this->assertSame('attachment', $post->post_type);
        $this->assertSame('image/jpeg', $post->post_mime_type);
        $this->assertSame('A canola field', get_post_meta($out['media_id'], '_wp_attachment_image_alt', true));

        $file = basename((string) get_attached_file($out['media_id']));
        $this->assertStringContainsString('wpmcp-photo', $file);
        $this->assertStringNotContainsString('?', $file);
        $this->assertStringNotContainsString(' ', $file);
        $this->assertMatchesRegularExpression('/\.jpe?g$/', $file);
    }

    public function test_persists_attribution_and_license_metadata(): void
    {
        $out = (new Import_Stock_Image())->handle([
            'image_url'   => self::IMAGE_URL,
            'provider'    => 'pexels',
            'attribution' => 'Sam Shooter',
            'license'     => 'Pexels License',
            'license_url' => 'https://www.pexels.com/license/',
            'source_url'  => 'https://www.pexels.com/photo/123/',
        ]);

        $id = $out['media_id'];
        $this->assertSame('pexels', get_post_meta($id, '_wpmcp_stock_provider', true));
        $this->assertSame('Sam Shooter', get_post_meta($id, '_wpmcp_stock_attribution', true));
        $this->assertSame('Pexels License', get_post_meta($id, '_wpmcp_stock_license', true));
        $this->assertSame('https://www.pexels.com/license/', get_post_meta($id, '_wpmcp_stock_license_url', true));
        $this->assertSame('https://www.pexels.com/photo/123/', get_post_meta($id, '_wpmcp_stock_source_url', true));
    }

    public function test_rollback_deletes_the_imported_attachment_and_file(): void
    {
        $out  = (new Import_Stock_Image())->handle(['image_url' => self::IMAGE_URL, 'provider' => 'pexels']);
        $file = (string) get_attached_file($out['media_id']);
        $this->assertFileExists($file);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertNull(get_post($out['media_id']));
        $this->assertFileDoesNotExist($file);
    }

    public function test_rejects_plain_http_url(): void
    {
        try {
            (new Import_Stock_Image())->handle(['image_url' => 'http://images.pexels.com/photos/1/a.jpg']);
            $this->fail('Expected the http:// URL to be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(0, $this->http_calls);
        }
    }

    public function test_rejects_non_allowlisted_host_before_any_request(): void
    {
        try {
            (new Import_Stock_Image())->handle(['image_url' => 'https://internal-service.local/etc/img.jpg']);
            $this->fail('Expected the non-allowlisted host to be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(0, $this->http_calls);
        }
    }

    public function test_allowlist_matching_is_not_fooled_by_lookalike_hosts(): void
    {
        try {
            (new Import_Stock_Image())->handle(['image_url' => 'https://images.pexels.com.evil.example/a.jpg']);
            $this->fail('Expected the lookalike host to be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(0, $this->http_calls);
        }
    }

    public function test_rejects_redirect_responses(): void
    {
        $this->respond = [
            'code'    => 302,
            'headers' => ['location' => 'http://169.254.169.254/latest/meta-data/'],
            'body'    => '',
        ];

        $this->expectException(\RuntimeException::class);
        (new Import_Stock_Image())->handle(['image_url' => self::IMAGE_URL]);
    }

    public function test_rejects_oversize_declared_content_length(): void
    {
        $this->respond = [
            'headers' => ['content-type' => 'image/jpeg', 'content-length' => (string) (512 * 1024 * 1024)],
            'body'    => 'x',
        ];

        try {
            (new Import_Stock_Image())->handle(['image_url' => self::IMAGE_URL]);
            $this->fail('Expected the oversize download to be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertCount(0, get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1]));
        }
    }

    public function test_rejects_actual_bytes_exceeding_cap_even_when_header_lies(): void
    {
        $cap = function () {
            return 1000; // canola.jpg is far larger than this.
        };
        add_filter('wpmcp_remote_media_max_bytes', $cap);
        $this->respond = [
            'headers' => ['content-type' => 'image/jpeg', 'content-length' => '500'],
        ];

        try {
            (new Import_Stock_Image())->handle(['image_url' => self::IMAGE_URL]);
            $this->fail('Expected the lying content-length to be caught post-download.');
        } catch (\RuntimeException $e) {
            $this->assertCount(0, get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1]));
        } finally {
            remove_filter('wpmcp_remote_media_max_bytes', $cap);
        }
    }

    public function test_rejects_polyglot_content_that_is_not_an_image(): void
    {
        $this->respond = [
            'body'    => '<html><script>alert(1)</script></html>',
            'headers' => ['content-type' => 'image/jpeg', 'content-length' => '38'],
        ];

        try {
            (new Import_Stock_Image())->handle(['image_url' => self::IMAGE_URL]);
            $this->fail('Expected non-image bytes to be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertCount(0, get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1]));
        }
    }

    public function test_requires_image_url(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Import_Stock_Image())->handle([]);
    }
}
