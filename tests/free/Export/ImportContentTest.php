<?php

namespace WPMCP\Tests\Free\Export;

use WPMCP\Tools\Export\Export_Dir;
use WPMCP\Tools\Export\Export_Content;
use WPMCP\Tools\Export\Import_Content;

class ImportContentTest extends \WP_UnitTestCase
{
    private array $cleanup_files = [];
    private array $cleanup_posts = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup_files as $path) {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
        $this->cleanup_files = [];
        foreach ($this->cleanup_posts as $post_id) {
            wp_delete_post($post_id, true);
        }
        $this->cleanup_posts = [];
        parent::tearDown();
    }

    private function write_wxr_fixture(string $title = 'WPMCP Import Fixture Post'): string
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0"
    xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:wfw="http://wellformedweb.org/CommentAPI/"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:wp="http://wordpress.org/export/1.2/"
>
<channel>
    <title>Test Site</title>
    <item>
        <title>{$title}</title>
        <content:encoded><![CDATA[Hello from the import test.]]></content:encoded>
        <wp:post_type>post</wp:post_type>
        <wp:status>publish</wp:status>
        <wp:postmeta>
            <wp:meta_key>wpmcp_test_key</wp:meta_key>
            <wp:meta_value><![CDATA[wpmcp_test_value]]></wp:meta_value>
        </wp:postmeta>
    </item>
</channel>
</rss>
XML;

        return $this->write_file(trailingslashit(self::export_dir()) . 'wpmcp-import-test-' . wp_generate_password(8, false) . '.xml', $xml);
    }

    private static function export_dir(): string
    {
        Export_Dir::protect(Export_Dir::path());
        return Export_Dir::path();
    }

    private function write_file(string $path, string $contents): string
    {
        file_put_contents($path, $contents);
        $this->cleanup_files[] = $path;
        return $path;
    }

    /** A valid WXR file outside the exports directory (and outside the install). */
    private function write_outside_wxr(string $title = 'WPMCP outside marker'): string
    {
        $inside = $this->write_wxr_fixture($title);
        $path   = trailingslashit(get_temp_dir()) . 'wpmcp-outside-' . wp_generate_password(8, false) . '.xml';
        return $this->write_file($path, (string) file_get_contents($inside));
    }

    /** Run an import that must be refused and return the refusal message. */
    private function refusal_for(string $file): string
    {
        add_filter('wpmcp_enable_import', '__return_true');
        $before = (int) wp_count_posts('post')->publish;
        try {
            (new Import_Content())->handle(['file' => $file, 'confirm' => true]);
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($before, (int) wp_count_posts('post')->publish, 'A refused import created a post.');
            return $e->getMessage();
        }
        $this->fail("import-content accepted {$file}, which is outside the exports directory.");
    }

    // ---- Issue #603: confinement to the exports directory ------------------

    public function test_refuses_an_absolute_path_outside_the_exports_directory(): void
    {
        $file = $this->write_outside_wxr();

        $this->assertSame(Import_Content::OUTSIDE_MESSAGE, $this->refusal_for($file));
    }

    public function test_refuses_a_valid_wxr_elsewhere_in_the_install(): void
    {
        $inside = $this->write_wxr_fixture();
        $file   = $this->write_file(trailingslashit(WP_CONTENT_DIR) . 'wpmcp-603-' . wp_generate_password(8, false) . '.xml', (string) file_get_contents($inside));

        $this->assertSame(Import_Content::OUTSIDE_MESSAGE, $this->refusal_for($file));
    }

    public function test_refusal_is_identical_whether_or_not_the_path_exists(): void
    {
        $existing_xml = $this->write_outside_wxr();
        $messages     = [
            $this->refusal_for($existing_xml),
            $this->refusal_for('/etc/passwd'),
            $this->refusal_for('/etc/wpmcp-does-not-exist-603'),
            $this->refusal_for('/nonexistent-dir-603/x.xml'),
            $this->refusal_for('../../../wp-config.php'),
            $this->refusal_for('../../../wpmcp-does-not-exist-603.xml'),
            $this->refusal_for(ABSPATH . 'wp-config.php'),
        ];

        $this->assertCount(1, array_unique($messages), 'Refusals differ, which reveals whether a path exists: ' . implode(' | ', array_unique($messages)));
        $this->assertSame(Import_Content::OUTSIDE_MESSAGE, $messages[0]);
    }

    public function test_refuses_dot_dot_traversal_out_of_the_exports_directory(): void
    {
        $outside = $this->write_outside_wxr();
        $file    = trailingslashit(self::export_dir()) . str_repeat('../', 12) . ltrim($outside, '/');

        $this->assertSame(Import_Content::OUTSIDE_MESSAGE, $this->refusal_for($file));
    }

    public function test_refuses_a_symlink_in_the_exports_directory_that_leaves_it(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('symlink() is unavailable.');
        }
        $outside = $this->write_outside_wxr('WPMCP symlink marker');
        $link    = trailingslashit(self::export_dir()) . 'wpmcp-603-link-' . wp_generate_password(8, false) . '.xml';
        $this->assertTrue(symlink($outside, $link));
        $this->cleanup_files[] = $link;

        $this->assertSame(Import_Content::OUTSIDE_MESSAGE, $this->refusal_for($link));
        $this->assertSame(Import_Content::OUTSIDE_MESSAGE, $this->refusal_for(basename($link)));
    }

    public function test_refuses_stream_wrappers_and_urls(): void
    {
        $inside = $this->write_wxr_fixture();
        foreach ([
            'php://filter/resource=' . $inside,
            'file://' . $inside,
            'compress.zlib://' . $inside,
            'phar://' . $inside . '/x.xml',
            'data://text/plain;base64,' . base64_encode((string) file_get_contents($inside)),
            'http://example.com/export.xml',
            'https://example.com/export.xml',
            'ftp://example.com/export.xml',
            'glob://' . self::export_dir() . '/*.xml',
        ] as $file) {
            $this->assertSame(Import_Content::OUTSIDE_MESSAGE, $this->refusal_for($file), "Not refused: {$file}");
        }
    }

    public function test_refuses_a_non_xml_file_inside_the_exports_directory(): void
    {
        self::export_dir();

        $this->assertSame(Import_Content::OUTSIDE_MESSAGE, $this->refusal_for(trailingslashit(self::export_dir()) . 'index.php'));
    }

    public function test_imports_a_bare_file_name_from_the_exports_directory(): void
    {
        add_filter('wpmcp_enable_import', '__return_true');
        $file = $this->write_wxr_fixture('WPMCP Import By Name');

        $out = (new Import_Content())->handle(['file' => basename($file), 'confirm' => true]);
        $this->cleanup_posts = array_merge($this->cleanup_posts, $out['created_post_ids']);

        $this->assertSame(1, $out['imported_count']);
        $this->assertSame('WPMCP Import By Name', get_post($out['created_post_ids'][0])->post_title);
    }

    public function test_a_file_from_export_content_can_still_be_imported(): void
    {
        // Core's export_wp() runs once per process (ExportContentTest owns
        // that call), so the exporter echoes a WXR here; the file is still
        // written, named and placed by export-content itself.
        $wxr    = (string) file_get_contents($this->write_wxr_fixture('WPMCP 603 round trip'));
        $export = (new Export_Content(static function () use ($wxr): void {
            echo $wxr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test fixture.
        }))->handle([]);
        $this->cleanup_files[] = $export['file'];

        add_filter('wpmcp_enable_import', '__return_true');
        $out = (new Import_Content())->handle(['file' => $export['file'], 'confirm' => true]);
        $this->cleanup_posts = array_merge($this->cleanup_posts, $out['created_post_ids']);

        $this->assertSame(1, $out['imported_count']);
        $this->assertSame('WPMCP 603 round trip', get_post($out['created_post_ids'][0])->post_title);
    }

    public function test_refused_when_import_disabled(): void
    {
        $file = $this->write_wxr_fixture();

        $this->expectException(\RuntimeException::class);
        (new Import_Content())->handle(['file' => $file, 'confirm' => true]);
    }

    public function test_requires_confirm_when_enabled(): void
    {
        add_filter('wpmcp_enable_import', '__return_true');
        $file = $this->write_wxr_fixture();

        $this->expectException(\InvalidArgumentException::class);
        (new Import_Content())->handle(['file' => $file]);
    }

    public function test_imports_a_post_from_wxr_when_enabled_and_confirmed(): void
    {
        add_filter('wpmcp_enable_import', '__return_true');
        $file = $this->write_wxr_fixture('WPMCP Import Fixture Post Confirmed');

        $out = (new Import_Content())->handle(['file' => $file, 'confirm' => true]);

        $this->assertSame(1, $out['imported_count']);
        $this->assertCount(1, $out['created_post_ids']);
        $this->assertFalse($out['recoverable']);

        $post_id = $out['created_post_ids'][0];
        $this->cleanup_posts[] = $post_id;

        $post = get_post($post_id);
        $this->assertNotNull($post);
        $this->assertSame('WPMCP Import Fixture Post Confirmed', $post->post_title);
        $this->assertSame('publish', $post->post_status);
        $this->assertSame('post', $post->post_type);
        $this->assertStringContainsString('Hello from the import test.', $post->post_content);
        $this->assertSame('wpmcp_test_value', get_post_meta($post_id, 'wpmcp_test_key', true));
    }

    public function test_rejects_missing_file(): void
    {
        add_filter('wpmcp_enable_import', '__return_true');

        $this->expectException(\InvalidArgumentException::class);
        (new Import_Content())->handle(['file' => '/nonexistent/wpmcp-does-not-exist.xml', 'confirm' => true]);
    }

    public function test_rejects_a_missing_file_inside_the_exports_directory(): void
    {
        add_filter('wpmcp_enable_import', '__return_true');

        $this->expectException(\InvalidArgumentException::class);
        (new Import_Content())->handle(['file' => trailingslashit(self::export_dir()) . 'wpmcp-does-not-exist.xml', 'confirm' => true]);
    }
}
