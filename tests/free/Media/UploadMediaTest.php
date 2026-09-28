<?php

namespace WPMCP\Tests\Free\Media;

use WPMCP\Plugin;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Media\Upload_Media;

/**
 * upload-media: add a file to the Media Library from base64 bytes the client
 * sends. The type is sniffed from the bytes by WordPress, never taken from
 * the client, and the created attachment is recorded as a 'media_import'
 * snapshot so rolling the operation back deletes it and its files again.
 */
class UploadMediaTest extends \WP_UnitTestCase
{
    /** A valid 1x1 PNG. */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    private function attachment_count(): int
    {
        return count(get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1]));
    }

    public function test_uploads_a_valid_image_with_title_alt_caption_and_parent(): void
    {
        $parent = self::factory()->post->create();

        $out = (new Upload_Media())->handle([
            'filename' => 'pixel.png',
            'data'     => self::PNG_BASE64,
            'title'    => 'One Pixel',
            'alt'      => 'A single pixel',
            'caption'  => 'Tiny',
            'post_id'  => $parent,
        ]);

        $this->assertNotEmpty($out['operation_id']);
        $post = get_post($out['media_id']);
        $this->assertNotNull($post);
        $this->assertSame('attachment', $post->post_type);
        $this->assertSame('image/png', $post->post_mime_type);
        $this->assertSame('One Pixel', $post->post_title);
        $this->assertSame('Tiny', $post->post_excerpt);
        $this->assertSame($parent, (int) $post->post_parent);
        $this->assertSame('A single pixel', get_post_meta($out['media_id'], '_wp_attachment_image_alt', true));
        $this->assertSame('image/png', $out['mime_type']);
        $this->assertStringEndsWith('.png', (string) $out['url']);

        $file = (string) get_attached_file($out['media_id']);
        $this->assertFileExists($file);
        $this->assertSame(base64_decode(self::PNG_BASE64, true), file_get_contents($file));
    }

    public function test_accepts_a_data_uri_prefix(): void
    {
        $out = (new Upload_Media())->handle([
            'filename' => 'pixel.png',
            'data'     => 'data:image/png;base64,' . self::PNG_BASE64,
        ]);

        $this->assertSame('image/png', get_post_mime_type($out['media_id']));
    }

    public function test_client_mime_hint_is_never_trusted(): void
    {
        $out = (new Upload_Media())->handle([
            'filename'  => 'pixel.jpg',
            'data'      => self::PNG_BASE64,
            'mime_type' => 'image/jpeg',
        ]);

        // The bytes are a PNG, so the stored type and extension follow them.
        $this->assertSame('image/png', get_post_mime_type($out['media_id']));
        $this->assertStringEndsWith('.png', (string) get_attached_file($out['media_id']));
    }

    public function test_mime_hint_supplies_a_missing_extension_but_is_still_verified(): void
    {
        $out = (new Upload_Media())->handle([
            'filename'  => 'pixel',
            'data'      => self::PNG_BASE64,
            'mime_type' => 'image/png',
        ]);

        $this->assertSame('image/png', get_post_mime_type($out['media_id']));
    }

    public function test_sanitizes_the_filename(): void
    {
        $out = (new Upload_Media())->handle([
            'filename' => '../../etc/My Pixel?.png',
            'data'     => self::PNG_BASE64,
        ]);

        $name = basename((string) get_attached_file($out['media_id']));
        $this->assertStringNotContainsString('..', $name);
        $this->assertStringNotContainsString(' ', $name);
        $this->assertStringNotContainsString('?', $name);
        $this->assertStringEndsWith('.png', $name);
    }

    public function test_rejects_bad_base64_and_creates_nothing(): void
    {
        try {
            (new Upload_Media())->handle(['filename' => 'x.png', 'data' => 'this is not base64 !!!']);
            $this->fail('Expected invalid base64 to be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('base64', $e->getMessage());
        }

        $this->assertSame(0, $this->attachment_count());
    }

    public function test_requires_filename_and_data(): void
    {
        try {
            (new Upload_Media())->handle(['data' => self::PNG_BASE64]);
            $this->fail('Expected a missing filename to be rejected.');
        } catch (\InvalidArgumentException $e) {
            // Expected.
        }

        $this->expectException(\InvalidArgumentException::class);
        (new Upload_Media())->handle(['filename' => 'x.png', 'data' => '']);
    }

    public function test_rejects_an_oversized_payload(): void
    {
        $cap = static fn(): int => 10;
        add_filter('wpmcp_upload_media_max_bytes', $cap);

        try {
            (new Upload_Media())->handle(['filename' => 'pixel.png', 'data' => self::PNG_BASE64]);
            $this->fail('Expected the oversized payload to be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('limit', $e->getMessage());
        } finally {
            remove_filter('wpmcp_upload_media_max_bytes', $cap);
        }

        $this->assertSame(0, $this->attachment_count());
    }

    public function test_default_max_bytes_follows_the_site_upload_limit(): void
    {
        $this->assertSame((int) wp_max_upload_size(), Upload_Media::max_bytes());
    }

    public function test_rejects_php_payload_renamed_to_jpg(): void
    {
        try {
            (new Upload_Media())->handle([
                'filename'  => 'photo.jpg',
                'data'      => base64_encode("<?php echo 'pwned'; system(\$_GET['c']); ?>\n"),
                'mime_type' => 'image/jpeg',
            ]);
            $this->fail('Expected a PHP payload named .jpg to be rejected.');
        } catch (\InvalidArgumentException $e) {
            // Expected.
        }

        $this->assertSame(0, $this->attachment_count());
    }

    public function test_rejects_executable_extensions(): void
    {
        foreach (['shell.php', 'shell.phtml', 'run.sh', 'app.exe', 'page.html', 'icon.svg'] as $filename) {
            try {
                (new Upload_Media())->handle([
                    'filename' => $filename,
                    'data'     => base64_encode("<?php echo 1; ?>\n"),
                ]);
                $this->fail("Expected {$filename} to be rejected.");
            } catch (\InvalidArgumentException $e) {
                // Expected.
            }
        }

        $this->assertSame(0, $this->attachment_count());
    }

    public function test_rollback_removes_the_attachment_and_its_file(): void
    {
        $out  = (new Upload_Media())->handle(['filename' => 'pixel.png', 'data' => self::PNG_BASE64]);
        $file = (string) get_attached_file($out['media_id']);
        $this->assertFileExists($file);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertNull(get_post($out['media_id']));
        $this->assertFileDoesNotExist($file);
    }

    public function test_is_registered_as_a_free_media_create_ability(): void
    {
        $this->assertArrayHasKey('wpmcp/upload-media', wp_get_abilities());

        $ability = Plugin::instance()->registrar()->get('wpmcp/upload-media');
        $this->assertNotNull($ability);
        $this->assertSame('free', $ability->tier);
        $this->assertSame('upload_files', $ability->capability);
        $this->assertSame('media', $ability->domain);
        $this->assertSame('create', $ability->operation);

        $manifest = require dirname(__DIR__, 2) . '/support/ability-manifest.php';
        $this->assertSame('free', $manifest['abilities']['wpmcp/upload-media'] ?? null);
    }
}
