<?php

namespace WPMCP\Tests\Free\Media;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Media\Upload_Media;

/**
 * upload-media with a url (issue #471): any type the caller may upload,
 * fetched from a public https URL through the remote guard (no private
 * hosts, no redirects, declared and actual size capped at the site's upload
 * limit), then sniffed and blocked exactly like a base64 upload. Every
 * fetch is answered by pre_http_request, so nothing touches the network.
 */
class UploadMediaUrlTest extends \WP_UnitTestCase
{
    private const PDF_URL = 'https://files.example.org/docs/brochure.pdf';

    private int $http_calls = 0;

    /** @var array{code?:int, headers?:array, body?:string} */
    private array $respond = [];

    /** @var array<string, string[]> host => addresses the resolver returns. */
    private array $dns = [];

    private string $temp_dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        add_filter('pre_http_request', [$this, 'serve'], 10, 3);
        add_filter('wpmcp_remote_host_addresses', [$this, 'resolve'], 10, 2);

        $this->temp_dir = trailingslashit(get_temp_dir()) . 'wpmcp-url-test-' . wp_generate_password(8, false) . '/';
        wp_mkdir_p($this->temp_dir);
        add_filter('wpmcp_upload_media_temp_dir', [$this, 'temp_dir']);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'serve'], 10);
        remove_filter('wpmcp_remote_host_addresses', [$this, 'resolve'], 10);
        remove_filter('wpmcp_upload_media_temp_dir', [$this, 'temp_dir']);
        foreach ((array) glob($this->temp_dir . '*') as $file) {
            @unlink((string) $file); // phpcs:ignore
        }
        @rmdir($this->temp_dir); // phpcs:ignore
        parent::tearDown();
    }

    public function temp_dir(): string
    {
        return $this->temp_dir;
    }

    public function resolve($addresses, string $host)
    {
        return $this->dns[ $host ] ?? ['93.184.216.34'];
    }

    public function serve($preempt, $parsed_args, $url)
    {
        $this->http_calls++;
        $body    = $this->respond['body'] ?? (string) file_get_contents(DIR_TESTDATA . '/images/wordpress-gsoc-flyer.pdf');
        $headers = $this->respond['headers'] ?? ['content-type' => 'application/pdf', 'content-length' => (string) strlen($body)];
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

    private function attachment_count(): int
    {
        return count(get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1]));
    }

    private function assert_refused(array $args, int $http_calls = -1): void
    {
        try {
            (new Upload_Media())->handle($args);
            $this->fail('Expected the url upload to be refused: ' . wp_json_encode($args));
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            if ($http_calls >= 0) {
                $this->assertSame($http_calls, $this->http_calls, 'Refused after the wrong number of requests.');
            }
        }
        $this->assertSame(0, $this->attachment_count(), 'A refused url upload must store nothing.');
        $this->assertSame([], (array) glob($this->temp_dir . '*'), 'A refused url upload must leave no temp file.');
    }

    public function test_imports_a_pdf_from_a_public_https_url(): void
    {
        $out = (new Upload_Media())->handle(['url' => self::PDF_URL, 'title' => 'Brochure']);

        $post = get_post($out['media_id']);
        $this->assertNotNull($post);
        $this->assertSame('application/pdf', $post->post_mime_type);
        $this->assertSame('Brochure', $post->post_title);
        $this->assertSame('application/pdf', $out['mime_type']);
        $this->assertStringEndsWith('.pdf', $out['file']);
        $this->assertStringContainsString('brochure', $out['file']);
        $this->assertSame(1, $this->http_calls);
        $this->assertSame([], (array) glob($this->temp_dir . '*'));
    }

    public function test_imports_an_mp4_from_a_public_https_url(): void
    {
        $body          = (string) file_get_contents(DIR_TESTDATA . '/uploads/small-video.mp4');
        $this->respond = ['body' => $body, 'headers' => ['content-type' => 'video/mp4', 'content-length' => (string) strlen($body)]];

        $out = (new Upload_Media())->handle(['url' => 'https://cdn.example.org/media/clip.mp4']);

        $this->assertSame('video/mp4', get_post_mime_type($out['media_id']));
        $this->assertSame($body, file_get_contents((string) get_attached_file($out['media_id'])));
    }

    public function test_url_import_is_undone_by_rollback_operation(): void
    {
        $out  = (new Upload_Media())->handle(['url' => self::PDF_URL]);
        $file = (string) get_attached_file($out['media_id']);
        $this->assertFileExists($file);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertNull(get_post($out['media_id']));
        $this->assertFileDoesNotExist($file);
    }

    public function test_filename_argument_names_the_file_when_the_url_has_none(): void
    {
        $out = (new Upload_Media())->handle(['url' => 'https://files.example.org/download?id=7', 'filename' => 'price-list.pdf']);
        $this->assertStringStartsWith('price-list', $out['file']);

        wp_delete_attachment($out['media_id'], true);
        $this->http_calls = 0;

        $this->assert_refused(['url' => 'https://files.example.org/download?id=8'], 0);
    }

    public function test_private_and_local_hosts_are_refused_before_any_request(): void
    {
        $this->dns['intranet.example.org'] = ['10.0.0.5'];
        $this->dns['meta.example.org']     = ['169.254.169.254'];

        foreach ([
            'https://intranet.example.org/a.pdf',
            'https://meta.example.org/a.pdf',
            'https://127.0.0.1/a.pdf',
            'https://192.168.1.10/a.pdf',
            'https://localhost/a.pdf',
            'https://[::1]/a.pdf',
        ] as $url) {
            $this->assert_refused(['url' => $url], 0);
        }
    }

    public function test_url_shape_is_checked_before_any_request(): void
    {
        foreach ([
            'http://files.example.org/a.pdf',
            'ftp://files.example.org/a.pdf',
            'https://user:pass@files.example.org/a.pdf',
            'https://files.example.org:8443/a.pdf',
            'not a url',
        ] as $url) {
            $this->assert_refused(['url' => $url], 0);
        }
    }

    public function test_blocked_extension_in_the_url_is_refused_before_any_request(): void
    {
        foreach (['shell.php', 'shell.php.pdf', 'page.html', 'icon.svg', 'run.sh'] as $name) {
            $this->assert_refused(['url' => 'https://files.example.org/' . $name], 0);
        }
        $this->assert_refused(['url' => self::PDF_URL, 'filename' => 'evil.phtml'], 0);
    }

    public function test_bytes_that_are_not_the_named_type_are_refused(): void
    {
        $php           = "<?php system(\$_GET['c']); ?>\n";
        $this->respond = ['body' => $php, 'headers' => ['content-type' => 'application/pdf', 'content-length' => (string) strlen($php)]];
        $this->assert_refused(['url' => 'https://files.example.org/fake.jpg']);

        $html          = '<html><script>alert(1)</script></html>';
        $this->respond = ['body' => $html, 'headers' => ['content-type' => 'image/png', 'content-length' => (string) strlen($html)]];
        $this->assert_refused(['url' => 'https://files.example.org/fake.png']);
    }

    public function test_redirects_are_never_followed(): void
    {
        $this->respond = ['code' => 302, 'headers' => ['location' => 'http://169.254.169.254/latest/'], 'body' => ''];
        $this->assert_refused(['url' => self::PDF_URL], 1);
    }

    public function test_declared_size_over_the_upload_limit_is_refused(): void
    {
        $this->respond = ['headers' => ['content-type' => 'application/pdf', 'content-length' => (string) (Upload_Media::max_bytes() + 1)]];
        $this->assert_refused(['url' => self::PDF_URL], 1);
    }

    public function test_actual_size_over_the_upload_limit_is_refused_even_when_the_header_lies(): void
    {
        $cap = static fn(): int => 1000;
        add_filter('wpmcp_upload_media_max_bytes', $cap);
        $this->respond = ['headers' => ['content-type' => 'application/pdf', 'content-length' => '500']];

        try {
            $this->assert_refused(['url' => self::PDF_URL], 1);
        } finally {
            remove_filter('wpmcp_upload_media_max_bytes', $cap);
        }
    }

    public function test_cap_is_the_site_upload_limit_not_the_stock_image_cap(): void
    {
        // The stock-image cap stays out of it: a file under the site's own
        // limit imports even when the remote image cap is tiny.
        $tiny = static fn(): int => 10;
        add_filter('wpmcp_remote_media_max_bytes', $tiny);
        try {
            $out = (new Upload_Media())->handle(['url' => self::PDF_URL]);
            $this->assertSame('application/pdf', get_post_mime_type($out['media_id']));
        } finally {
            remove_filter('wpmcp_remote_media_max_bytes', $tiny);
        }
    }

    public function test_remaining_upload_space_caps_the_download(): void
    {
        // On multisite, core folds the site's remaining quota into
        // wp_max_upload_size() through upload_size_limit; a site with less
        // space left than the file refuses it.
        $quota = static fn(): int => 2048;
        add_filter('upload_size_limit', $quota);
        try {
            $this->assertSame(2048, Upload_Media::max_bytes());
            $this->assert_refused(['url' => self::PDF_URL], 1);
        } finally {
            remove_filter('upload_size_limit', $quota);
        }
    }

    public function test_exactly_one_source_is_accepted(): void
    {
        $this->assert_refused(['url' => self::PDF_URL, 'data' => 'aGVsbG8=', 'filename' => 'a.txt'], 0);
        $this->assert_refused(['url' => self::PDF_URL, 'grant' => true, 'filename' => 'a.pdf'], 0);
        $this->assert_refused(['filename' => 'a.pdf'], 0);
    }
}
