<?php

namespace WPMCP\Tests\Free\Media;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Media\Upload_Svg;

/**
 * upload-svg (issue #64): add an SVG to the Media Library from raw markup or
 * a URL, behind the bundled fail-closed sanitizer. The created attachment is
 * recorded as a 'media_import' snapshot so rolling the operation back deletes
 * the import again (create → rollback = gone).
 */
class UploadSvgTest extends \WP_UnitTestCase
{
    private const BENIGN_SVG    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><!-- tool comment --><rect width="4" height="4" fill="#111"/></svg>';
    private const MALICIOUS_SVG = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>';
    private const SVG_URL       = 'https://svg.wpmcp.example/icons/arrow.svg';

    /** @var array<int, string> */
    private array $served = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        add_filter('wpmcp_remote_media_allowed_hosts', [$this, 'allow_test_host']);
        add_filter('pre_http_request', [$this, 'serve_svg'], 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_remote_media_allowed_hosts', [$this, 'allow_test_host']);
        remove_filter('pre_http_request', [$this, 'serve_svg'], 10);
        parent::tearDown();
    }

    public function allow_test_host(array $hosts): array
    {
        $hosts[] = 'svg.wpmcp.example';
        return $hosts;
    }

    public function serve_svg($preempt, $parsed_args, $url)
    {
        if (0 !== strpos((string) $url, 'https://svg.wpmcp.example/')) {
            return $preempt;
        }
        $body = $this->served[0] ?? self::BENIGN_SVG;
        if (! empty($parsed_args['filename'])) {
            file_put_contents($parsed_args['filename'], $body);
            $body = '';
        }
        return [
            'headers'  => ['content-type' => 'image/svg+xml', 'content-length' => strlen($this->served[0] ?? self::BENIGN_SVG)],
            'body'     => $body,
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => $parsed_args['filename'] ?? null,
        ];
    }

    public function test_uploads_sanitized_svg_from_raw_markup(): void
    {
        $out = (new Upload_Svg())->handle(['markup' => self::BENIGN_SVG, 'title' => 'Little Square']);

        $this->assertNotEmpty($out['operation_id']);
        $post = get_post($out['media_id']);
        $this->assertNotNull($post);
        $this->assertSame('attachment', $post->post_type);
        $this->assertSame('image/svg+xml', $post->post_mime_type);
        $this->assertStringEndsWith('.svg', (string) $out['url']);

        // The bytes on disk are the SANITIZED markup, not the raw input.
        $stored = (string) file_get_contents((string) get_attached_file($out['media_id']));
        $this->assertStringContainsString('<rect', $stored);
        $this->assertStringNotContainsString('tool comment', $stored);
    }

    public function test_rejects_malicious_markup_and_creates_nothing(): void
    {
        try {
            (new Upload_Svg())->handle(['markup' => self::MALICIOUS_SVG]);
            $this->fail('Expected the malicious SVG to be rejected.');
        } catch (\InvalidArgumentException $e) {
            // Expected.
        }

        $this->assertCount(0, get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1]));
    }

    public function test_requires_markup_or_url(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Upload_Svg())->handle([]);
    }

    public function test_uploads_svg_from_allowlisted_url(): void
    {
        $this->served = [self::BENIGN_SVG];

        $out = (new Upload_Svg())->handle(['url' => self::SVG_URL]);

        $post = get_post($out['media_id']);
        $this->assertNotNull($post);
        $this->assertSame('image/svg+xml', $post->post_mime_type);
    }

    public function test_rejects_malicious_svg_fetched_from_url(): void
    {
        $this->served = [self::MALICIOUS_SVG];

        try {
            (new Upload_Svg())->handle(['url' => self::SVG_URL]);
            $this->fail('Expected the fetched malicious SVG to be rejected.');
        } catch (\InvalidArgumentException $e) {
            // Expected.
        }

        $this->assertCount(0, get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1]));
    }

    public function test_rejects_url_from_non_allowlisted_host(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Upload_Svg())->handle(['url' => 'https://not-allowlisted.example/x.svg']);
    }

    public function test_rollback_deletes_the_uploaded_svg(): void
    {
        $out  = (new Upload_Svg())->handle(['markup' => self::BENIGN_SVG]);
        $file = (string) get_attached_file($out['media_id']);
        $this->assertFileExists($file);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertNull(get_post($out['media_id']));
        $this->assertFileDoesNotExist($file);
    }
}
